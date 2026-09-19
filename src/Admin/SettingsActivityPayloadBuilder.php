<?php
/**
 * Activity-tab settings payload assembly.
 *
 * @package Aculect\AICompanion\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Activity\ActivityRepository;

/**
 * Builds the read-only Activity settings payload without owning page orchestration.
 */
final class SettingsActivityPayloadBuilder {

	private const PER_PAGE      = 50;
	private const FILTER_LENGTH = 100;

	/**
	 * Create the Activity payload builder.
	 *
	 * @param ActivityRepository|null $repository Optional repository seam for tests.
	 */
	public function __construct(
		private ?ActivityRepository $repository = null
	) {
	}

	/**
	 * Build the paginated Activity payload.
	 *
	 * @param array<string, mixed> $request      Read-only request query values.
	 * @param string               $settings_url Settings app base URL.
	 * @return array<string, mixed>
	 */
	public function build( array $request, string $settings_url ): array {
		$repository  = $this->repository ?? new ActivityRepository();
		$filters     = $this->filters( $request );
		$total       = $repository->count( $filters );
		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page        = min( max( 1, (int) $filters['page'] ), $total_pages );
		$filters     = array_merge(
			$filters,
			array(
				'page'     => $page,
				'per_page' => self::PER_PAGE,
			)
		);

		return array(
			'summary'    => $repository->summary( $filters ),
			'items'      => $repository->list( $filters ),
			'total'      => $total,
			'page'       => $page,
			'perPage'    => self::PER_PAGE,
			'totalPages' => $total_pages,
			'filters'    => $filters,
			'prevUrl'    => $page > 1 ? $this->page_url( $filters, $page - 1, $settings_url ) : '',
			'nextUrl'    => $page < $total_pages
				? $this->page_url( $filters, $page + 1, $settings_url )
				: '',
		);
	}

	/**
	 * Return the compatibility empty Activity payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_payload(): array {
		return array(
			'summary'    => array(),
			'items'      => array(),
			'total'      => 0,
			'page'       => 1,
			'perPage'    => self::PER_PAGE,
			'totalPages' => 1,
			'filters'    => array(
				'page'      => 1,
				'action'    => '',
				'status'    => '',
				'user_id'   => 0,
				'assistant' => '',
				'search'    => '',
				'range'     => '7d',
			),
			'prevUrl'    => '',
			'nextUrl'    => '',
		);
	}

	/**
	 * Normalize Activity filters from read-only request values.
	 *
	 * @param array<string, mixed> $request Read-only request query values.
	 * @return array<string, mixed>
	 */
	private function filters( array $request ): array {
		$range = sanitize_key( $this->scalar_string( $request['activity_range'] ?? '7d' ) );
		if ( ! in_array( $range, array( '24h', '7d', '30d', '90d', 'all' ), true ) ) {
			$range = '7d';
		}

		return array(
			'page'      => max( 1, absint( is_scalar( $request['activity_page'] ?? null ) ? $request['activity_page'] : 1 ) ),
			'action'    => $this->text_filter( $request['activity_action'] ?? '' ),
			'status'    => substr( sanitize_key( $this->scalar_string( $request['activity_status'] ?? '' ) ), 0, self::FILTER_LENGTH ),
			'user_id'   => absint( is_scalar( $request['activity_user'] ?? null ) ? $request['activity_user'] : 0 ),
			'assistant' => $this->text_filter( $request['activity_assistant'] ?? '' ),
			'search'    => $this->text_filter( $request['activity_search'] ?? '' ),
			'range'     => $range,
		);
	}

	/**
	 * Normalize one bounded text filter.
	 *
	 * @param mixed $value Raw request value.
	 */
	private function text_filter( mixed $value ): string {
		$text = sanitize_text_field( $this->scalar_string( $value ) );
		if ( '' === $text || 1 !== preg_match( '//u', $text ) ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, self::FILTER_LENGTH );
		}

		$matched = preg_match( '/^.{0,' . self::FILTER_LENGTH . '}/us', $text, $matches );

		return 1 === $matched ? $matches[0] : '';
	}

	/**
	 * Convert only scalar request values into unslashed text.
	 *
	 * @param mixed $value Raw request value.
	 */
	private function scalar_string( mixed $value ): string {
		return is_scalar( $value ) ? wp_unslash( (string) $value ) : '';
	}

	/**
	 * Build one Activity pagination URL.
	 *
	 * @param array<string, mixed> $filters      Activity filters.
	 * @param int                  $page         Page number.
	 * @param string               $settings_url Settings app base URL.
	 */
	private function page_url( array $filters, int $page, string $settings_url ): string {
		return add_query_arg(
			array_filter(
				array(
					'page'               => 'aculect-ai-companion',
					'tab'                => 'activity',
					'activity_page'      => max( 1, $page ),
					'activity_action'    => (string) ( $filters['action'] ?? '' ),
					'activity_status'    => (string) ( $filters['status'] ?? '' ),
					'activity_user'      => (int) ( $filters['user_id'] ?? 0 ),
					'activity_assistant' => (string) ( $filters['assistant'] ?? '' ),
					'activity_search'    => (string) ( $filters['search'] ?? '' ),
					'activity_range'     => (string) ( $filters['range'] ?? '7d' ),
				),
				static fn( mixed $value ): bool => '' !== $value && 0 !== $value
			),
			$settings_url
		);
	}
}

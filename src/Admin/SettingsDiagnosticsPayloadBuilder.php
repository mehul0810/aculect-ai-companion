<?php
/**
 * Diagnostics-tab settings payload assembly.
 *
 * @package Aculect\AICompanion\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use Aculect\AICompanion\Diagnostics\LogRepository;
use Aculect\AICompanion\Diagnostics\LogSettings;

/**
 * Builds the diagnostic settings payload without owning page orchestration.
 */
final class SettingsDiagnosticsPayloadBuilder {

	private const PER_PAGE = 50;

	/**
	 * Create the diagnostics payload builder.
	 *
	 * @param string $settings_url Settings app base URL.
	 */
	public function __construct(
		private string $settings_url
	) {
	}

	/**
	 * Build diagnostic settings and optionally load the current log page.
	 *
	 * @param bool $include_logs Whether to load paginated log rows.
	 * @return array<string, mixed>
	 */
	public function build( bool $include_logs = false ): array {
		$enabled  = LogSettings::is_enabled();
		$oauth    = new ClientRepository();
		$capacity = $oauth->capacity_status();

		return array(
			'loggingEnabled' => $enabled,
			'retentionDays'  => LogSettings::retention_days(),
			'oauthClients'   => array(
				'capacity'    => $capacity,
				'recoverable' => $capacity['recoverable'] > 0
					? $oauth->list_recoverable_clients()
					: array(),
			),
			'logs'           => $enabled && $include_logs
				? $this->logs_payload()
				: $this->empty_logs_payload(),
		);
	}

	/**
	 * Return an empty log payload when logging is disabled.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_logs_payload(): array {
		return array(
			'items'      => array(),
			'total'      => 0,
			'page'       => 1,
			'perPage'    => self::PER_PAGE,
			'totalPages' => 1,
			'prevUrl'    => '',
			'nextUrl'    => '',
		);
	}

	/**
	 * Return a paginated diagnostic log payload.
	 *
	 * @return array<string, mixed>
	 */
	private function logs_payload(): array {
		$repository = new LogRepository();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter.
		$page        = isset( $_GET['logs_page'] ) ? max( 1, absint( $_GET['logs_page'] ) ) : 1;
		$total       = $repository->count();
		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page        = min( $page, $total_pages );

		return array(
			'items'      => $repository->list( $page, self::PER_PAGE ),
			'total'      => $total,
			'page'       => $page,
			'perPage'    => self::PER_PAGE,
			'totalPages' => $total_pages,
			'prevUrl'    => $page > 1 ? $this->logs_page_url( $page - 1 ) : '',
			'nextUrl'    => $page < $total_pages ? $this->logs_page_url( $page + 1 ) : '',
		);
	}

	/**
	 * Build a Logs tab pagination URL.
	 *
	 * @param int $page Log page.
	 */
	private function logs_page_url( int $page ): string {
		return add_query_arg(
			array(
				'page'      => 'aculect-ai-companion',
				'tab'       => 'logs',
				'logs_page' => max( 1, $page ),
			),
			$this->settings_url
		);
	}
}

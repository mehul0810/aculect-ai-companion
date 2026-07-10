<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Rank Math-backed redirect and 404 inspection abilities.
 */
final class RankMathRedirectAbilities extends AbstractAbilityService {

	private const RANK_MATH_PLUGINS      = array( 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php' );
	private const MAX_PER_PAGE           = 50;
	private const DEFAULT_PER_PAGE       = 10;
	private const REDIRECT_STATUS_VALUES = array( 'any', 'active', 'inactive' );
	private const REDIRECT_ORDER_FIELDS  = array( 'id', 'url_to', 'header_code', 'hits', 'created', 'last_accessed' );
	private const NOT_FOUND_ORDER_FIELDS = array( 'id', 'uri', 'accessed', 'times_accessed' );
	private const MATCH_TYPES            = array( 'exact', 'start', 'contains', 'end' );
	private const REDIRECT_CODES         = array( 301, 302, 307, 410, 451 );
	private const MAINTENANCE_CODES      = array( 410, 451 );
	private const MAX_CREATE_ITEMS       = 25;

	/**
	 * List bounded Rank Math redirects.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_redirects( array $args ): array {
		$availability = $this->redirects_availability();
		if ( isset( $availability['error'] ) ) {
			return $availability;
		}

		$query  = array(
			'orderby' => $this->allowed_value( (string) ( $args['orderby'] ?? 'id' ), self::REDIRECT_ORDER_FIELDS, 'id' ),
			'order'   => $this->order( (string) ( $args['order'] ?? 'DESC' ) ),
			'limit'   => $this->per_page( $args ),
			'paged'   => $this->page( $args ),
			'search'  => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
			'status'  => $this->allowed_value( (string) ( $args['status'] ?? 'any' ), self::REDIRECT_STATUS_VALUES, 'any' ),
		);
		$result = $this->call_rank_math_redirects( 'get_redirections', $query );

		$rows = is_array( $result['redirections'] ?? null ) ? $result['redirections'] : array();

		return array(
			'status'   => 'ready',
			'source'   => 'rank_math',
			'module'   => 'redirections',
			'page'     => $query['paged'],
			'per_page' => $query['limit'],
			'total'    => absint( $result['count'] ?? count( $rows ) ),
			'items'    => array_map( array( $this, 'public_redirect' ), $rows ),
		);
	}

	/**
	 * List bounded Rank Math 404 monitor records.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_recent_404s( array $args ): array {
		$availability = $this->not_found_availability();
		if ( isset( $availability['error'] ) ) {
			return $availability;
		}

		$query  = array(
			'orderby' => $this->allowed_value( (string) ( $args['orderby'] ?? 'accessed' ), self::NOT_FOUND_ORDER_FIELDS, 'accessed' ),
			'order'   => $this->order( (string) ( $args['order'] ?? 'DESC' ) ),
			'limit'   => $this->per_page( $args ),
			'paged'   => $this->page( $args ),
			'search'  => sanitize_text_field( (string) ( $args['search'] ?? '' ) ),
			'uri'     => sanitize_text_field( (string) ( $args['uri'] ?? '' ) ),
		);
		$result = $this->call_rank_math_monitor( 'get_logs', $query );

		$rows = is_array( $result['logs'] ?? null ) ? $result['logs'] : array();

		return array(
			'status'   => 'ready',
			'source'   => 'rank_math',
			'module'   => '404_monitor',
			'page'     => $query['paged'],
			'per_page' => $query['limit'],
			'total'    => absint( $result['count'] ?? count( $rows ) ),
			'items'    => array_map( array( $this, 'public_not_found_log' ), $rows ),
		);
	}

	/**
	 * Validate a proposed Rank Math redirect before creation.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function validate_redirect( array $args ): array {
		$availability = $this->redirects_availability();
		if ( isset( $availability['error'] ) ) {
			return $availability;
		}

		return $this->validated_redirect_proposal( $args );
	}

	/**
	 * Create one or more Rank Math redirects through Rank Math's object API.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function create_redirect( array $args ): array {
		$availability = $this->redirects_availability();
		if ( isset( $availability['error'] ) ) {
			return $availability;
		}

		if ( ! class_exists( '\RankMath\Redirections\Redirection' ) ) {
			return $this->error( 'module_unavailable', 'Rank Math Redirection object support is not available on this site.' );
		}

		$items   = $this->redirect_create_items( $args );
		$dry_run = $this->is_dry_run( $args );
		if ( array() === $items ) {
			return $this->error( 'invalid_items', 'Provide a redirect proposal or a non-empty items array.' );
		}

		$results = array();
		foreach ( $items as $index => $item ) {
			$results[] = $this->create_redirect_item( $item, $index, $dry_run );
		}

		$created = count(
			array_filter(
				$results,
				static fn ( array $result ): bool => 'created' === ( $result['status'] ?? '' )
			)
		);
		$errors  = count(
			array_filter(
				$results,
				static fn ( array $result ): bool => 'error' === ( $result['status'] ?? '' )
			)
		);
		$valid   = count( $results ) - $errors;

		return array(
			'status'  => $dry_run ? ( $errors > 0 ? 'preview_partial' : 'preview' ) : ( $errors > 0 ? ( $created > 0 ? 'partial' : 'failed' ) : 'completed' ),
			'source'  => 'rank_math',
			'module'  => 'redirections',
			'dry_run' => $dry_run,
			'total'   => count( $results ),
			'created' => $created,
			'valid'   => $valid,
			'errors'  => $errors,
			'results' => $results,
			'id'      => 1 === count( $results ) && isset( $results[0]['id'] ) ? $results[0]['id'] : 0,
			'next'    => $dry_run && 0 === $errors ? 'Repeat this call without dry_run after reviewing the preview and satisfying MCP write confirmation policy.' : '',
		);
	}

	/**
	 * Validate and normalize a redirect proposal.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function validated_redirect_proposal( array $args ): array {
		$source = $this->normalized_source( (string) ( $args['source'] ?? '' ) );
		if ( '' === $source ) {
			return $this->error( 'invalid_source', 'Provide a local redirect source path.' );
		}

		$match_type = $this->allowed_value( (string) ( $args['match_type'] ?? 'exact' ), self::MATCH_TYPES, '' );
		if ( '' === $match_type ) {
			return $this->error( 'unsupported_match_type', 'Supported match types are exact, start, contains, and end.' );
		}

		$code = absint( $args['redirect_code'] ?? 301 );
		if ( ! in_array( $code, self::REDIRECT_CODES, true ) ) {
			return $this->error( 'unsupported_redirect_code', 'Supported redirect codes are 301, 302, 307, 410, and 451.' );
		}

		$destination = $this->normalized_destination( (string) ( $args['destination'] ?? '' ), $code );
		if ( is_array( $destination ) ) {
			return $destination;
		}

		$conflicts = $this->redirect_conflicts( $source );

		return array(
			'status'        => array() === $conflicts ? 'valid' : 'conflict',
			'source'        => 'rank_math',
			'module'        => 'redirections',
			'proposal'      => array(
				'source'        => $source,
				'match_type'    => $match_type,
				'destination'   => $destination,
				'redirect_code' => $code,
				'ignore_case'   => ! empty( $args['ignore_case'] ),
			),
			'conflicts'     => $conflicts,
			'can_create'    => array() === $conflicts,
			'next_required' => 'Use a create ability only after reviewing this validation result.',
		);
	}

	/**
	 * Build bounded create items from singular or batch arguments.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return list<array<string, mixed>>
	 */
	private function redirect_create_items( array $args ): array {
		if ( isset( $args['items'] ) && is_array( $args['items'] ) ) {
			return array_values(
				array_filter(
					array_slice( $args['items'], 0, self::MAX_CREATE_ITEMS ),
					'is_array'
				)
			);
		}

		return array(
			array_intersect_key(
				$args,
				array_flip( array( 'source', 'destination', 'redirect_code', 'match_type', 'ignore_case' ) )
			),
		);
	}

	/**
	 * Validate and create or preview a single redirect item.
	 *
	 * @param array<string, mixed> $item    Redirect proposal.
	 * @param int                  $index   Zero-based item index.
	 * @param bool                 $dry_run Whether to preview only.
	 * @return array<string, mixed>
	 */
	private function create_redirect_item( array $item, int $index, bool $dry_run ): array {
		$validation = $this->validated_redirect_proposal( $item );
		if ( isset( $validation['error'] ) ) {
			return array(
				'index'   => $index,
				'status'  => 'error',
				'error'   => $validation['error'],
				'message' => $validation['message'],
			);
		}

		if ( false === ( $validation['can_create'] ?? false ) ) {
			return array(
				'index'     => $index,
				'status'    => 'error',
				'error'     => 'redirect_conflict',
				'message'   => 'An existing Rank Math redirect already matches this source.',
				'proposal'  => $validation['proposal'],
				'conflicts' => $validation['conflicts'],
			);
		}

		if ( $dry_run ) {
			return array(
				'index'    => $index,
				'status'   => 'valid',
				'proposal' => $validation['proposal'],
			);
		}

		return $this->save_redirect_item( $validation['proposal'], $index );
	}

	/**
	 * Persist one redirect through the Rank Math Redirection object.
	 *
	 * @param array<string, mixed> $proposal Validated redirect proposal.
	 * @param int                  $index    Zero-based item index.
	 * @return array<string, mixed>
	 */
	private function save_redirect_item( array $proposal, int $index ): array {
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- PHPStan needs a class-string annotation for the optional Rank Math class.
		/** @var class-string<object> $redirection_class */
		$redirection_class = '\RankMath\Redirections\Redirection';
		try {
			$redirection = ( new \ReflectionClass( $redirection_class ) )->newInstance(
				array(
					'id'          => 0,
					'sources'     => array(),
					'url_to'      => '',
					'header_code' => (string) absint( $proposal['redirect_code'] ?? 301 ),
					'hits'        => '0',
					'status'      => 'active',
					'created'     => '',
					'updated'     => '',
				)
			);
		} catch ( \ReflectionException ) {
			return array(
				'index'   => $index,
				'status'  => 'error',
				'error'   => 'module_unavailable',
				'message' => 'Rank Math Redirection object support is not available on this site.',
			);
		}

		if ( ! is_object( $redirection ) ) {
			return array(
				'index'   => $index,
				'status'  => 'error',
				'error'   => 'module_unavailable',
				'message' => 'Rank Math Redirection object could not be initialized.',
			);
		}

		if ( ! is_callable( array( $redirection, 'add_source' ) )
			|| ! is_callable( array( $redirection, 'add_destination' ) )
			|| ! is_callable( array( $redirection, 'save' ) )
		) {
			return array(
				'index'   => $index,
				'status'  => 'error',
				'error'   => 'module_unavailable',
				'message' => 'Rank Math Redirection object does not expose the required create methods.',
			);
		}

		$ignore_case = ! empty( $proposal['ignore_case'] ) ? 'case' : '';
		call_user_func(
			array( $redirection, 'add_source' ),
			(string) $proposal['source'],
			(string) $proposal['match_type'],
			$ignore_case
		);
		call_user_func( array( $redirection, 'add_destination' ), (string) $proposal['destination'] );

		if ( is_callable( array( $redirection, 'is_infinite_loop' ) ) && true === call_user_func( array( $redirection, 'is_infinite_loop' ) ) ) {
			return array(
				'index'    => $index,
				'status'   => 'error',
				'error'    => 'redirect_loop',
				'message'  => 'Rank Math detected an infinite redirect loop for this proposal.',
				'proposal' => $proposal,
			);
		}

		$id = absint( call_user_func( array( $redirection, 'save' ) ) );
		if ( 0 >= $id ) {
			return array(
				'index'    => $index,
				'status'   => 'error',
				'error'    => 'create_failed',
				'message'  => 'Rank Math did not create the redirect.',
				'proposal' => $proposal,
			);
		}

		return array(
			'index'    => $index,
			'status'   => 'created',
			'id'       => $id,
			'proposal' => $proposal,
		);
	}

	/**
	 * Check Rank Math redirection support.
	 *
	 * @return array<string, mixed>
	 */
	private function redirects_availability(): array {
		if ( ! $this->is_rank_math_active() ) {
			return $this->error( 'plugin_unavailable', 'Rank Math is not active on this site.' );
		}

		if ( ! class_exists( '\RankMath\Redirections\DB' ) ) {
			return $this->error( 'module_unavailable', 'Rank Math Redirections support is not available on this site.' );
		}

		if ( ! $this->rank_math_capability( 'rank_math_redirections', 'redirections' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to access Rank Math redirections.' );
		}

		return array( 'status' => 'ready' );
	}

	/**
	 * Check Rank Math 404 monitor support.
	 *
	 * @return array<string, mixed>
	 */
	private function not_found_availability(): array {
		if ( ! $this->is_rank_math_active() ) {
			return $this->error( 'plugin_unavailable', 'Rank Math is not active on this site.' );
		}

		if ( ! class_exists( '\RankMath\Monitor\DB' ) ) {
			return $this->error( 'module_unavailable', 'Rank Math 404 Monitor support is not available on this site.' );
		}

		if ( ! $this->rank_math_capability( 'rank_math_404_monitor', '404_monitor' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to access Rank Math 404 monitor data.' );
		}

		return array( 'status' => 'ready' );
	}

	/**
	 * Determine whether Rank Math is active.
	 */
	private function is_rank_math_active(): bool {
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath' ) ) {
			return true;
		}

		$active = get_option( 'active_plugins', array() );
		if ( is_array( $active ) && array() !== array_intersect( self::RANK_MATH_PLUGINS, $active ) ) {
			return true;
		}

		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
			$network_active = get_site_option( 'active_sitewide_plugins', array() );
			return is_array( $network_active ) && array() !== array_intersect( self::RANK_MATH_PLUGINS, array_keys( $network_active ) );
		}

		return false;
	}

	/**
	 * Check WordPress and Rank Math helper capabilities when available.
	 *
	 * @param string $wp_capability        WordPress capability.
	 * @param string $rank_math_capability Rank Math helper capability.
	 */
	private function rank_math_capability( string $wp_capability, string $rank_math_capability ): bool {
		if ( current_user_can( $wp_capability ) ) {
			return true;
		}

		return class_exists( '\RankMath\Helper' )
			&& is_callable( array( '\RankMath\Helper', 'has_cap' ) )
			&& true === \RankMath\Helper::has_cap( $rank_math_capability );
	}

	/**
	 * Convert a redirect row into public output.
	 *
	 * @param array<string, mixed> $row Rank Math row.
	 * @return array<string, mixed>
	 */
	private function public_redirect( array $row ): array {
		return array(
			'id'            => absint( $row['id'] ?? 0 ),
			'sources'       => $this->public_sources( $row['sources'] ?? array() ),
			'destination'   => esc_url_raw( (string) ( $row['url_to'] ?? '' ) ),
			'redirect_code' => absint( $row['header_code'] ?? 0 ),
			'status'        => sanitize_key( (string) ( $row['status'] ?? '' ) ),
			'hits'          => absint( $row['hits'] ?? 0 ),
			'created'       => sanitize_text_field( (string) ( $row['created'] ?? '' ) ),
			'updated'       => sanitize_text_field( (string) ( $row['updated'] ?? '' ) ),
			'last_accessed' => sanitize_text_field( (string) ( $row['last_accessed'] ?? '' ) ),
		);
	}

	/**
	 * Convert a 404 monitor row into public output.
	 *
	 * @param array<string, mixed> $row Rank Math row.
	 * @return array<string, mixed>
	 */
	private function public_not_found_log( array $row ): array {
		$uri = $this->redacted_uri( (string) ( $row['uri'] ?? '' ) );

		return array(
			'id'             => absint( $row['id'] ?? 0 ),
			'uri'            => $uri['uri'],
			'query_redacted' => $uri['query_redacted'],
			'times_accessed' => absint( $row['times_accessed'] ?? 0 ),
			'last_seen'      => sanitize_text_field( (string) ( $row['accessed'] ?? '' ) ),
			'referer'        => esc_url_raw( (string) ( $row['referer'] ?? '' ) ),
			'user_agent'     => sanitize_text_field( (string) ( $row['user_agent'] ?? '' ) ),
		);
	}

	/**
	 * Normalize serialized Rank Math source data.
	 *
	 * @param mixed $sources Raw sources field.
	 * @return list<array<string, mixed>>
	 */
	private function public_sources( mixed $sources ): array {
		if ( is_string( $sources ) ) {
			$unserialized = preg_match( '/^(a|s|i|b|d|N);|^(a|s|i|b|d|O|C):/', $sources )
				// nosemgrep: Rank Math stores redirect source arrays as serialized DB values here; classes are disallowed and non-array results are discarded.
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Rank Math stores redirect source arrays as serialized data; classes are disallowed.
				? unserialize( $sources, array( 'allowed_classes' => false ) )
				: false;
			$sources = false === $unserialized ? array() : $unserialized;
		}

		if ( ! is_array( $sources ) ) {
			return array();
		}

		$public = array();
		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			$public[] = array(
				'pattern'    => sanitize_text_field( (string) ( $source['pattern'] ?? '' ) ),
				'match_type' => sanitize_key( (string) ( $source['comparison'] ?? 'exact' ) ),
				'ignore'     => sanitize_key( (string) ( $source['ignore'] ?? '' ) ),
			);
		}

		return $public;
	}

	/**
	 * Return conflicts for a normalized source path.
	 *
	 * @param string $source Normalized redirect source.
	 * @return list<array<string, mixed>>
	 */
	private function redirect_conflicts( string $source ): array {
		if ( ! is_callable( array( '\RankMath\Redirections\DB', 'match_redirections_source' ) ) ) {
			return array();
		}

		$matches = $this->call_rank_math_redirects( 'match_redirections_source', $source );
		if ( ! is_array( $matches ) ) {
			return array();
		}

		return array_map( array( $this, 'public_redirect' ), $matches );
	}

	/**
	 * Call a Rank Math redirections method after availability checks.
	 *
	 * @param string $method Method name.
	 * @param mixed  $arg    Method argument.
	 * @return array<string, mixed>|list<array<string, mixed>>
	 */
	private function call_rank_math_redirects( string $method, mixed $arg ): array {
		$callback = array( '\RankMath\Redirections\DB', $method );
		if ( ! is_callable( $callback ) ) {
			return array();
		}

		$result = call_user_func( $callback, $arg );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Call a Rank Math monitor method after availability checks.
	 *
	 * @param string $method Method name.
	 * @param mixed  $arg    Method argument.
	 * @return array<string, mixed>
	 */
	private function call_rank_math_monitor( string $method, mixed $arg ): array {
		$callback = array( '\RankMath\Monitor\DB', $method );
		if ( ! is_callable( $callback ) ) {
			return array();
		}

		$result = call_user_func( $callback, $arg );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Normalize and validate redirect source input.
	 *
	 * @param string $source Raw redirect source.
	 */
	private function normalized_source( string $source ): string {
		$source = trim( sanitize_text_field( $source ) );
		if ( '' === $source || '/' === $source ) {
			return '';
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host      = wp_parse_url( $source, PHP_URL_HOST );
		if ( is_string( $host ) && is_string( $home_host ) && strtolower( $host ) !== strtolower( $home_host ) ) {
			return '';
		}

		$path = wp_parse_url( $source, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path || '/' === $path ) {
			$path = str_starts_with( $source, '/' ) ? $source : '';
		}

		return '' === $path ? '' : ltrim( $path, '/' );
	}

	/**
	 * Normalize and validate redirect destination input.
	 *
	 * @param string $destination Raw redirect destination.
	 * @param int    $code        Redirect code.
	 * @return string|array<string, string>
	 */
	private function normalized_destination( string $destination, int $code ): string|array {
		if ( in_array( $code, self::MAINTENANCE_CODES, true ) ) {
			return '';
		}

		$destination = trim( wp_strip_all_tags( $destination ) );
		if ( '' === $destination ) {
			return $this->error( 'invalid_destination', 'Provide a destination URL for redirect codes that require one.' );
		}

		if ( str_starts_with( $destination, '/' ) ) {
			return home_url( $destination );
		}

		$scheme = wp_parse_url( $destination, PHP_URL_SCHEME );
		if ( ! is_string( $scheme ) || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
			return $this->error( 'unsafe_destination', 'Destination must be a relative path or an http/https URL.' );
		}

		return esc_url_raw( $destination );
	}

	/**
	 * Redact query strings from recent 404 URIs.
	 *
	 * @param string $uri Raw 404 URI.
	 * @return array{uri: string, query_redacted: bool}
	 */
	private function redacted_uri( string $uri ): array {
		$decoded      = rawurldecode( $uri );
		$query_offset = strpos( $decoded, '?' );
		if ( false === $query_offset ) {
			return array(
				'uri'            => sanitize_text_field( $decoded ),
				'query_redacted' => false,
			);
		}

		return array(
			'uri'            => sanitize_text_field( substr( $decoded, 0, $query_offset ) . '?[redacted]' ),
			'query_redacted' => true,
		);
	}

	/**
	 * Return bounded page.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function page( array $args ): int {
		return max( 1, absint( $args['page'] ?? 1 ) );
	}

	/**
	 * Return bounded per-page value.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function per_page( array $args ): int {
		$per_page = absint( $args['per_page'] ?? self::DEFAULT_PER_PAGE );
		if ( 0 >= $per_page ) {
			return self::DEFAULT_PER_PAGE;
		}

		return min( self::MAX_PER_PAGE, $per_page );
	}

	/**
	 * Return allowed order direction.
	 *
	 * @param string $order Raw order direction.
	 */
	private function order( string $order ): string {
		return 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
	}

	/**
	 * Return a whitelisted scalar value.
	 *
	 * @param string $value   Raw value.
	 * @param array  $allowed Allowed values.
	 * @param string $default Default value.
	 * @phpstan-param list<string> $allowed
	 */
	private function allowed_value( string $value, array $allowed, string $default ): string {
		$value = sanitize_key( $value );
		return in_array( $value, $allowed, true ) ? $value : $default;
	}
}

<?php
/**
 * Diagnostics payload wpdb test double.
 *
 * @package Aculect\AICompanion\Tests\Support
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Support;

/**
 * Minimal wpdb double for diagnostics payload read paths.
 */
final class FakeSettingsDiagnosticsPayloadWpdb {

	/**
	 * WordPress table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'wp_';

	/**
	 * Number of diagnostic log rows.
	 *
	 * @var int
	 */
	public int $log_total = 0;

	/**
	 * Prepared and executed query markers.
	 *
	 * @var string[]
	 */
	public array $queries = array();

	/**
	 * Return a prepared query marker.
	 *
	 * @param string $query Query template.
	 * @param mixed  ...$args Query values.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$prepared        = trim( $query . ' ' . implode( ' ', array_map( 'strval', $args ) ) );
		$this->queries[] = $prepared;

		return $prepared;
	}

	/**
	 * Return count-style values for the exercised tables.
	 *
	 * @param string $query Prepared query.
	 */
	public function get_var( string $query ): int {
		$this->queries[] = $query;

		if ( str_contains( $query, 'wp_aculect_ai_companion_logs' ) ) {
			return $this->log_total;
		}

		return 0;
	}

	/**
	 * Return no rows while recording the query.
	 *
	 * @param string $query Prepared query.
	 * @param string $output Output format.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );
		$this->queries[] = $query;

		return array();
	}

	/**
	 * Check whether a query marker contains a fragment.
	 *
	 * @param string $fragment Query fragment.
	 */
	public function has_query_fragment( string $fragment ): bool {
		foreach ( $this->queries as $query ) {
			if ( str_contains( $query, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}

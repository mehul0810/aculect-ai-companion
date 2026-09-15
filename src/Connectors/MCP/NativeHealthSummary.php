<?php
/**
 * Bounded projection of WordPress native health results.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Never mistakes missing or malformed cached health results for success.
 */
final class NativeHealthSummary {

	/**
	 * Read native aggregate counts without executing tests or exposing debug data.
	 *
	 * @return array<string, mixed>
	 */
	public static function read(): array {
		$raw    = get_transient( 'health-check-site-status-result' );
		$result = array(
			'status'         => 'unavailable',
			'freshness'      => 'unknown',
			'tests_executed' => false,
			'counts'         => array(),
		);
		if ( ! is_string( $raw ) || strlen( $raw ) > 2048 ) {
			return $result;
		}
		$decoded = json_decode( $raw, true, 4 );
		if ( ! is_array( $decoded ) ) {
			return $result;
		}
		$counts = array();
		foreach ( array( 'good', 'recommended', 'critical' ) as $key ) {
			$value = $decoded[ $key ] ?? null;
			if ( ! is_int( $value ) || $value < 0 || $value > 100000 ) {
				return $result;
			}
			$counts[ $key ] = $value;
		}
		$result['status'] = 'cached';
		$result['counts'] = $counts;
		return $result;
	}
}

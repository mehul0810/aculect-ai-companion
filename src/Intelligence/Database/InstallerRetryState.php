<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Database;

/**
 * Persists bounded, support-safe intelligence schema repair backoff.
 */
final class InstallerRetryState {

	private const OPTION       = 'aculect_ai_companion_intelligence_install_retry';
	private const MAX_ATTEMPTS = 3;
	private const DELAYS       = array( 60, 300, 3600 );

	/**
	 * Return whether an automatic or forced repair may run now.
	 *
	 * @param bool $force Whether to bypass automatic retry bounds.
	 */
	public static function allows_attempt( bool $force = false ): bool {
		if ( $force ) {
			return true;
		}

		$state = self::status();

		return ! $state['blocked'] && ( 0 === $state['next_retry_at'] || $state['next_retry_at'] <= time() );
	}

	/**
	 * Record one support-safe schema repair failure.
	 *
	 * @param string[] $missing Missing logical table keys.
	 * @param string   $reason  Bounded machine-readable reason.
	 */
	public static function record_failure( array $missing, string $reason ): void {
		$current  = self::status();
		$attempts = min( self::MAX_ATTEMPTS, $current['attempts'] + 1 );
		$blocked  = $attempts >= self::MAX_ATTEMPTS;

		update_option(
			self::OPTION,
			array(
				'attempts'       => $attempts,
				'blocked'        => $blocked,
				'last_failed_at' => time(),
				'next_retry_at'  => $blocked ? 0 : time() + self::DELAYS[ $attempts - 1 ],
				'missing_tables' => array_slice( array_values( array_unique( array_map( 'sanitize_key', $missing ) ) ), 0, 6 ),
				'reason'         => sanitize_key( $reason ),
			),
			false
		);
	}

	/**
	 * Clear repair backoff after a successful repair or uninstall.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Return normalized repair state for boot gating and diagnostics.
	 *
	 * @return array{attempts:int,blocked:bool,last_failed_at:int,next_retry_at:int,missing_tables:list<string>,reason:string}
	 */
	public static function status(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'attempts'       => min( self::MAX_ATTEMPTS, absint( $stored['attempts'] ?? 0 ) ),
			'blocked'        => true === ( $stored['blocked'] ?? false ),
			'last_failed_at' => absint( $stored['last_failed_at'] ?? 0 ),
			'next_retry_at'  => absint( $stored['next_retry_at'] ?? 0 ),
			'missing_tables' => array_slice( array_values( array_filter( array_map( 'sanitize_key', (array) ( $stored['missing_tables'] ?? array() ) ) ) ), 0, 6 ),
			'reason'         => sanitize_key( (string) ( $stored['reason'] ?? '' ) ),
		);
	}
}

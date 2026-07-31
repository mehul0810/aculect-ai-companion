<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AuthCodeRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\RefreshTokenRepository;

/**
 * Opportunistic maintenance for OAuth protocol storage.
 */
final class StorageMaintenance {

	private const OPTION_LAST_PRUNED_AT                = 'aculect_ai_companion_oauth_last_pruned_at';
	private const OPTION_PRUNE_LOCK_EXPIRES_AT         = 'aculect_ai_companion_oauth_prune_lock_expires_at';
	private const OPTION_PRUNE_FAILURE_RETRY_AFTER     = 'aculect_ai_companion_oauth_prune_failure_retry_after';
	private const DEFAULT_PRUNE_INTERVAL               = 12 * 3600;
	private const DEFAULT_PRUNE_LOCK_TTL               = 5 * 60;
	private const DEFAULT_PRUNE_FAILURE_RETRY_INTERVAL = 5 * 60;
	private const DEFAULT_PRUNE_BATCH_SIZE             = 500;
	private const DEFAULT_PRUNE_BATCH_CUTOFF           = 'now';
	private const DEFAULT_REVOKED_CLIENT_PRUNE_CUTOFF  = '-30 days';

	/**
	 * Run pruning if the throttled maintenance window has elapsed.
	 */
	public static function maybe_prune(): void {
		$interval = (int) apply_filters( 'aculect_ai_companion_oauth_prune_interval', self::DEFAULT_PRUNE_INTERVAL );
		$interval = max( 0, $interval );
		$last_run = (int) get_option( self::OPTION_LAST_PRUNED_AT, 0 );
		$now      = time();

		if ( $last_run > 0 && ( $now - $last_run ) < $interval ) {
			return;
		}

		$failure_retry_after = (int) get_option( self::OPTION_PRUNE_FAILURE_RETRY_AFTER, 0 );
		if ( $failure_retry_after > $now ) {
			return;
		}

		$lock_token = self::acquire_prune_lock( $now );
		if ( '' === $lock_token ) {
			return;
		}

		try {
			self::finalize_prune( self::prune(), $lock_token );
		} finally {
			self::delete_prune_lock_if_value( $lock_token );
		}
	}

	/**
	 * Publish a prune outcome only while the exact lease is still owned.
	 *
	 * @param array{auth_codes: int, access_tokens: int, refresh_tokens: int, clients: int}|false $result Prune result.
	 * @param string                                                                              $lock_token Claimed lease token.
	 */
	private static function finalize_prune( array|false $result, string $lock_token ): void {
		$finalization_token = self::begin_owned_finalization( $lock_token );
		if ( '' === $finalization_token ) {
			return;
		}

		$finalized_at = time();
		$stored       = false;

		if ( false !== $result ) {
			update_option( self::OPTION_LAST_PRUNED_AT, $finalized_at, false );
			$stored = (int) get_option( self::OPTION_LAST_PRUNED_AT, 0 ) === $finalized_at;
			if ( $stored ) {
				delete_option( self::OPTION_PRUNE_FAILURE_RETRY_AFTER );
			}
		} else {
			$retry_after = $finalized_at + self::prune_failure_retry_interval();
			update_option( self::OPTION_PRUNE_FAILURE_RETRY_AFTER, $retry_after, false );
			$stored = (int) get_option( self::OPTION_PRUNE_FAILURE_RETRY_AFTER, 0 ) === $retry_after;
		}

		if ( $stored ) {
			self::delete_prune_lock_if_value( $finalization_token );
		}
	}

	/**
	 * Prune expired OAuth rows immediately.
	 *
	 * @return array{auth_codes: int, access_tokens: int, refresh_tokens: int, clients: int}|false
	 */
	public static function prune(): array|false {
		$expired_cutoff        = gmdate( 'Y-m-d H:i:s', self::expired_rows_cutoff_timestamp() );
		$revoked_client_cutoff = gmdate( 'Y-m-d H:i:s', self::revoked_client_cutoff_timestamp() );
		$batch_size            = self::prune_batch_size();

		$results = array(
			'auth_codes'     => ( new AuthCodeRepository() )->prune_expired( $expired_cutoff, $batch_size ),
			'access_tokens'  => ( new AccessTokenRepository() )->prune_expired( $expired_cutoff, $batch_size ),
			'refresh_tokens' => ( new RefreshTokenRepository() )->prune_expired( $expired_cutoff, $batch_size ),
			'clients'        => ( new ClientRepository() )->prune_revoked_clients( $revoked_client_cutoff, $batch_size ),
		);

		if ( in_array( false, $results, true ) ) {
			return false;
		}

		return array(
			'auth_codes'     => (int) $results['auth_codes'],
			'access_tokens'  => (int) $results['access_tokens'],
			'refresh_tokens' => (int) $results['refresh_tokens'],
			'clients'        => (int) $results['clients'],
		);
	}

	/**
	 * Delete maintenance options during full uninstall cleanup.
	 */
	public static function delete_options(): void {
		delete_option( self::OPTION_LAST_PRUNED_AT );
		delete_option( self::OPTION_PRUNE_LOCK_EXPIRES_AT );
		delete_option( self::OPTION_PRUNE_FAILURE_RETRY_AFTER );
	}

	/**
	 * Return the bounded delay before retrying a failed prune.
	 */
	private static function prune_failure_retry_interval(): int {
		$interval = (int) apply_filters(
			'aculect_ai_companion_oauth_prune_failure_retry_interval',
			self::DEFAULT_PRUNE_FAILURE_RETRY_INTERVAL
		);

		return min( self::DEFAULT_PRUNE_INTERVAL, max( 1, $interval ) );
	}

	/**
	 * Return the cutoff timestamp used for pruning expired protocol rows.
	 */
	private static function expired_rows_cutoff_timestamp(): int {
		$cutoff = (string) apply_filters( 'aculect_ai_companion_oauth_prune_cutoff', self::DEFAULT_PRUNE_BATCH_CUTOFF );

		return self::cutoff_timestamp( $cutoff );
	}

	/**
	 * Return the cutoff timestamp used for pruning revoked DCR clients.
	 */
	private static function revoked_client_cutoff_timestamp(): int {
		$cutoff = (string) apply_filters(
			'aculect_ai_companion_oauth_revoked_client_prune_cutoff',
			self::DEFAULT_REVOKED_CLIENT_PRUNE_CUTOFF
		);

		return self::cutoff_timestamp( $cutoff );
	}

	/**
	 * Convert a relative or absolute cutoff string into a timestamp.
	 *
	 * @param string $cutoff Relative or absolute cutoff.
	 */
	private static function cutoff_timestamp( string $cutoff ): int {
		if ( 'now' === $cutoff || '' === $cutoff ) {
			return time();
		}

		$timestamp = strtotime( $cutoff );

		return false === $timestamp ? time() : $timestamp;
	}

	/**
	 * Return the max rows each pruning query may delete in one request.
	 */
	private static function prune_batch_size(): int {
		$batch_size = (int) apply_filters( 'aculect_ai_companion_oauth_prune_batch_size', self::DEFAULT_PRUNE_BATCH_SIZE );

		return min( 1000, max( 1, $batch_size ) );
	}

	/**
	 * Acquire a short lock so concurrent requests do not run the same cleanup.
	 *
	 * @param int $now Current Unix timestamp.
	 */
	private static function acquire_prune_lock( int $now ): string {
		$lock_token = self::prune_lock_token( $now + self::DEFAULT_PRUNE_LOCK_TTL );
		if ( self::add_prune_lock( $lock_token ) ) {
			return $lock_token;
		}

		$current = self::read_prune_lock();
		if ( self::prune_lock_expires_at( $current ) >= $now ) {
			return '';
		}

		return self::update_prune_lock_if_value( $current, $lock_token ) ? $lock_token : '';
	}

	/**
	 * Fence an active owner before publishing its throttle state.
	 *
	 * @param string $lock_token Claimed lease token.
	 */
	private static function begin_owned_finalization( string $lock_token ): string {
		if ( self::prune_lock_expires_at( $lock_token ) < time() ) {
			return '';
		}

		$finalization_token = 'finalize:' . self::prune_lock_token( time() + self::DEFAULT_PRUNE_LOCK_TTL );

		return self::update_prune_lock_if_value( $lock_token, $finalization_token ) ? $finalization_token : '';
	}

	/**
	 * Add a unique lock only when no lock row exists.
	 *
	 * @param string $lock_token Unique expiring token.
	 */
	private static function add_prune_lock( string $lock_token ): bool {
		if ( self::uses_test_options() ) {
			return add_option( self::OPTION_PRUNE_LOCK_EXPIRES_AT, $lock_token, '', false );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- INSERT IGNORE is the lease acquisition CAS; Core add_option() can overwrite on a duplicate-key race.
		$added = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::OPTION_PRUNE_LOCK_EXPIRES_AT,
				$lock_token
			)
		);
		self::invalidate_prune_lock_cache();

		return 1 === (int) $added;
	}

	/**
	 * Read the authoritative current lock value.
	 */
	private static function read_prune_lock(): string {
		if ( self::uses_test_options() ) {
			return (string) get_option( self::OPTION_PRUNE_LOCK_EXPIRES_AT, '' );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Lease reclaim requires the authoritative database owner.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION_PRUNE_LOCK_EXPIRES_AT
			)
		);

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Replace the lock only when the exact expected owner still holds it.
	 *
	 * @param string $old_value Expected owner token.
	 * @param string $new_value Replacement owner token.
	 */
	private static function update_prune_lock_if_value( string $old_value, string $new_value ): bool {
		if ( self::uses_test_options() ) {
			$current = (string) get_option( self::OPTION_PRUNE_LOCK_EXPIRES_AT, '' );
			if ( ! hash_equals( $old_value, $current ) ) {
				return false;
			}

			return update_option( self::OPTION_PRUNE_LOCK_EXPIRES_AT, $new_value, false );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Conditional update is the lease-owner CAS.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_value,
				self::OPTION_PRUNE_LOCK_EXPIRES_AT,
				$old_value
			)
		);
		self::invalidate_prune_lock_cache();

		return 1 === (int) $updated;
	}

	/**
	 * Delete the lock only when the exact expected owner still holds it.
	 *
	 * @param string $lock_token Expected owner token.
	 */
	private static function delete_prune_lock_if_value( string $lock_token ): bool {
		if ( self::uses_test_options() ) {
			$current = (string) get_option( self::OPTION_PRUNE_LOCK_EXPIRES_AT, '' );
			if ( ! hash_equals( $lock_token, $current ) ) {
				return false;
			}

			return delete_option( self::OPTION_PRUNE_LOCK_EXPIRES_AT );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete prevents a stale worker from releasing a successor lease.
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::OPTION_PRUNE_LOCK_EXPIRES_AT,
				'option_value' => $lock_token,
			),
			array( '%s', '%s' )
		);
		self::invalidate_prune_lock_cache();

		return 1 === (int) $deleted;
	}

	/**
	 * Return the expiry suffix of a lock token, including legacy integer values.
	 *
	 * @param string $lock_token Lock token.
	 */
	private static function prune_lock_expires_at( string $lock_token ): int {
		$parts = explode( ':', $lock_token );

		return absint( end( $parts ) );
	}

	/**
	 * Create a unique expiring lock token.
	 *
	 * @param int $expires_at Expiry timestamp.
	 */
	private static function prune_lock_token( int $expires_at ): string {
		return bin2hex( random_bytes( 16 ) ) . ':' . $expires_at;
	}

	/**
	 * Check whether the lightweight PHPUnit option store is active.
	 */
	private static function uses_test_options(): bool {
		return isset( $GLOBALS['aculect_ai_companion_test_options'] )
			&& is_array( $GLOBALS['aculect_ai_companion_test_options'] );
	}

	/**
	 * Invalidate both positive and negative option-cache entries.
	 */
	private static function invalidate_prune_lock_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_PRUNE_LOCK_EXPIRES_AT, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}

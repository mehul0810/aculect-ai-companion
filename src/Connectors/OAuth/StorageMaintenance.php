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

	private const OPTION_LAST_PRUNED_AT               = 'aculect_ai_companion_oauth_last_pruned_at';
	private const OPTION_PRUNE_LOCK_EXPIRES_AT        = 'aculect_ai_companion_oauth_prune_lock_expires_at';
	private const DEFAULT_PRUNE_INTERVAL              = 12 * 3600;
	private const DEFAULT_PRUNE_LOCK_TTL              = 5 * 60;
	private const DEFAULT_PRUNE_BATCH_SIZE            = 500;
	private const DEFAULT_PRUNE_BATCH_CUTOFF          = 'now';
	private const DEFAULT_REVOKED_CLIENT_PRUNE_CUTOFF = '-30 days';

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

		if ( ! self::acquire_prune_lock( $now ) ) {
			return;
		}

		try {
			self::prune();
			update_option( self::OPTION_LAST_PRUNED_AT, $now, false );
		} finally {
			self::release_prune_lock();
		}
	}

	/**
	 * Prune expired OAuth rows immediately.
	 *
	 * @return array{auth_codes: int, access_tokens: int, refresh_tokens: int, clients: int}
	 */
	public static function prune(): array {
		$expired_cutoff        = gmdate( 'Y-m-d H:i:s', self::expired_rows_cutoff_timestamp() );
		$revoked_client_cutoff = gmdate( 'Y-m-d H:i:s', self::revoked_client_cutoff_timestamp() );
		$batch_size            = self::prune_batch_size();

		return array(
			'auth_codes'     => ( new AuthCodeRepository() )->prune_expired( $expired_cutoff, $batch_size ),
			'access_tokens'  => ( new AccessTokenRepository() )->prune_expired( $expired_cutoff, $batch_size ),
			'refresh_tokens' => ( new RefreshTokenRepository() )->prune_expired( $expired_cutoff, $batch_size ),
			'clients'        => ( new ClientRepository() )->prune_revoked_clients( $revoked_client_cutoff, $batch_size ),
		);
	}

	/**
	 * Delete maintenance options during full uninstall cleanup.
	 */
	public static function delete_options(): void {
		delete_option( self::OPTION_LAST_PRUNED_AT );
		delete_option( self::OPTION_PRUNE_LOCK_EXPIRES_AT );
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
	private static function acquire_prune_lock( int $now ): bool {
		$expires_at = (int) get_option( self::OPTION_PRUNE_LOCK_EXPIRES_AT, 0 );
		if ( $expires_at > $now ) {
			return false;
		}

		if ( $expires_at > 0 ) {
			delete_option( self::OPTION_PRUNE_LOCK_EXPIRES_AT );
		}

		return add_option(
			self::OPTION_PRUNE_LOCK_EXPIRES_AT,
			$now + self::DEFAULT_PRUNE_LOCK_TTL,
			'',
			false
		);
	}

	/**
	 * Release the pruning lock after this request completes.
	 */
	private static function release_prune_lock(): void {
		delete_option( self::OPTION_PRUNE_LOCK_EXPIRES_AT );
	}
}

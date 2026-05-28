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
	private const DEFAULT_PRUNE_INTERVAL              = 12 * 3600;
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

		self::prune();
		update_option( self::OPTION_LAST_PRUNED_AT, $now, false );
	}

	/**
	 * Prune expired OAuth rows immediately.
	 *
	 * @return array{auth_codes: int, access_tokens: int, refresh_tokens: int, clients: int}
	 */
	public static function prune(): array {
		$expired_cutoff        = gmdate( 'Y-m-d H:i:s', self::expired_rows_cutoff_timestamp() );
		$revoked_client_cutoff = gmdate( 'Y-m-d H:i:s', self::revoked_client_cutoff_timestamp() );

		return array(
			'auth_codes'     => ( new AuthCodeRepository() )->prune_expired( $expired_cutoff ),
			'access_tokens'  => ( new AccessTokenRepository() )->prune_expired( $expired_cutoff ),
			'refresh_tokens' => ( new RefreshTokenRepository() )->prune_expired( $expired_cutoff ),
			'clients'        => ( new ClientRepository() )->prune_revoked_clients( $revoked_client_cutoff ),
		);
	}

	/**
	 * Delete maintenance options during full uninstall cleanup.
	 */
	public static function delete_options(): void {
		delete_option( self::OPTION_LAST_PRUNED_AT );
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
}

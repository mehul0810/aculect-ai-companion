<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Repositories;

use Aculect\AICompanion\Connectors\OAuth\Database\Installer;
use Aculect\AICompanion\Connectors\OAuth\Entities\RefreshTokenEntity;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * Persists OAuth refresh tokens and revocation state.
 *
 * Refresh tokens are stored by hash only and are queried directly because
 * rotation/revocation must be visible immediately across requests.
 */
final class RefreshTokenRepository implements RefreshTokenRepositoryInterface {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth refresh tokens use a dedicated custom table and must read/write fresh token state.

	private const DEFAULT_PRUNE_BATCH_SIZE = 500;

	/**
	 * Create a refresh-token entity for league/oauth2-server.
	 */
	public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
		return new RefreshTokenEntity();
	}

	/**
	 * Store a newly issued refresh token by hash and linked access-token hash.
	 *
	 * @param RefreshTokenEntityInterface $refreshTokenEntity Issued refresh token.
	 */
	public function persistNewRefreshToken( RefreshTokenEntityInterface $refreshTokenEntity ): void {
		global $wpdb;

		$table = Installer::table_names()['refresh_tokens'];
		$wpdb->insert(
			$table,
			array(
				'token_hash'        => $this->hash_identifier( $refreshTokenEntity->getIdentifier() ),
				'access_token_hash' => $this->hash_identifier( $refreshTokenEntity->getAccessToken()->getIdentifier() ),
				'revoked'           => 0,
				'expires_at'        => $refreshTokenEntity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Revoke a refresh token.
	 *
	 * @param string $tokenId Raw refresh token identifier.
	 */
	public function revokeRefreshToken( string $tokenId ): void {
		global $wpdb;

		$table = Installer::table_names()['refresh_tokens'];
		$wpdb->update( $table, array( 'revoked' => 1 ), array( 'token_hash' => $this->hash_identifier( $tokenId ) ), array( '%d' ), array( '%s' ) );
	}

	/**
	 * Check whether a refresh token is missing, revoked, or expired.
	 *
	 * @param string $tokenId Raw refresh token identifier.
	 * @return bool
	 */
	public function isRefreshTokenRevoked( string $tokenId ): bool {
		global $wpdb;

		$table = Installer::table_names()['refresh_tokens'];
		$row   = $wpdb->get_row(
			$wpdb->prepare( 'SELECT revoked, expires_at FROM %i WHERE token_hash = %s', $table, $this->hash_identifier( $tokenId ) ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return true;
		}

		if ( '1' === (string) $row['revoked'] ) {
			return true;
		}

		return strtotime( (string) $row['expires_at'] ) < time();
	}

	/**
	 * Revoke all refresh tokens issued from an access token.
	 *
	 * @param string $access_token_id Raw access token identifier.
	 */
	public function revoke_by_access_token_id( string $access_token_id ): void {
		global $wpdb;

		$table = Installer::table_names()['refresh_tokens'];
		$wpdb->update(
			$table,
			array( 'revoked' => 1 ),
			array( 'access_token_hash' => $this->hash_identifier( $access_token_id ) ),
			array( '%d' ),
			array( '%s' )
		);
	}

	/**
	 * Revoke every refresh token.
	 */
	public function revoke_all(): void {
		global $wpdb;

		$table = Installer::table_names()['refresh_tokens'];
		$wpdb->update( $table, array( 'revoked' => 1 ), array( 'revoked' => 0 ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Delete expired refresh-token rows.
	 *
	 * Revoked refresh tokens are preserved until expiry so revocation decisions
	 * remain immediate while the token could otherwise still be presented.
	 *
	 * @param string|null $cutoff Optional UTC cutoff in Y-m-d H:i:s format.
	 * @param int         $limit  Maximum rows to delete in this pass.
	 * @return int Number of deleted rows.
	 */
	public function prune_expired( ?string $cutoff = null, int $limit = self::DEFAULT_PRUNE_BATCH_SIZE ): int {
		global $wpdb;

		$table  = Installer::table_names()['refresh_tokens'];
		$cutoff = null !== $cutoff && '' !== $cutoff ? $cutoff : gmdate( 'Y-m-d H:i:s' );
		$limit  = $this->normalized_batch_limit( $limit );
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at < %s LIMIT %d',
				$table,
				$cutoff,
				$limit
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Keep maintenance deletes bounded.
	 *
	 * @param int $limit Requested row limit.
	 */
	private function normalized_batch_limit( int $limit ): int {
		return min( 1000, max( 1, $limit ) );
	}

	/**
	 * Hash raw token material before database lookup or storage.
	 *
	 * @param string $identifier Raw protocol identifier.
	 * @return string
	 */
	private function hash_identifier( string $identifier ): string {
		return hash( 'sha256', $identifier );
	}
}

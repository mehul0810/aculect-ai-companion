<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Repositories;

use Aculect\AICompanion\Connectors\OAuth\Database\BoundedPruner;
use Aculect\AICompanion\Connectors\OAuth\Database\Installer;
use Aculect\AICompanion\Connectors\OAuth\BoundClientGuard;
use Aculect\AICompanion\Connectors\OAuth\Entities\RefreshTokenEntity;
use Aculect\AICompanion\Connectors\OAuth\IssuerBinding;
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

		BoundClientGuard::assert_current( $refreshTokenEntity->getAccessToken()->getClient() );

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

		$tables = Installer::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT refresh_tokens.revoked, refresh_tokens.expires_at
				FROM %i refresh_tokens
				INNER JOIN %i access_tokens ON access_tokens.token_hash = refresh_tokens.access_token_hash
				INNER JOIN %i clients ON clients.client_id = access_tokens.client_id
				WHERE refresh_tokens.token_hash = %s
				AND clients.issuer_hash = %s
				AND clients.revoked = 0',
				$tables['refresh_tokens'],
				$tables['access_tokens'],
				$tables['clients'],
				$this->hash_identifier( $tokenId ),
				IssuerBinding::hash()
			),
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
	 * Return support-safe stored context for an internal refresh-token ID.
	 *
	 * The raw identifier is used only to derive the existing storage hash. A
	 * revoked row does not prove whether rotation, disconnect, or another
	 * revocation path caused the state, so no revocation reason is inferred.
	 *
	 * @param string $tokenId Decrypted League refresh-token identifier.
	 * @return array{}|array{refresh_token_state: string, connection_id?: int, connection_client_id?: string, provider?: string}
	 */
	public function support_context_from_token_id( string $tokenId ): array {
		global $wpdb;

		if ( '' === $tokenId ) {
			return array();
		}

		$tables = Installer::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT refresh_tokens.revoked, refresh_tokens.expires_at,
					access_tokens.id AS connection_id, access_tokens.client_id, clients.provider
				FROM %i refresh_tokens
				INNER JOIN %i access_tokens ON access_tokens.token_hash = refresh_tokens.access_token_hash
				INNER JOIN %i clients ON clients.client_id = access_tokens.client_id
				WHERE refresh_tokens.token_hash = %s
				AND clients.issuer_hash = %s
				AND clients.revoked = 0
				LIMIT 1',
				$tables['refresh_tokens'],
				$tables['access_tokens'],
				$tables['clients'],
				$this->hash_identifier( $tokenId ),
				IssuerBinding::hash()
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array( 'refresh_token_state' => 'not_found' );
		}

		$expires_at = strtotime( (string) ( $row['expires_at'] ?? '' ) );
		if ( '1' === (string) ( $row['revoked'] ?? '0' ) ) {
			$state = 'revoked';
		} elseif ( false === $expires_at || $expires_at < time() ) {
			$state = 'expired';
		} else {
			$state = 'active_in_storage';
		}

		$context       = array( 'refresh_token_state' => $state );
		$connection_id = absint( $row['connection_id'] ?? 0 );
		if ( $connection_id > 0 ) {
			$context['connection_id'] = $connection_id;
		}

		$client_id = (string) ( $row['client_id'] ?? '' );
		if ( '' !== $client_id ) {
			$context['connection_client_id'] = $client_id;
		}

		$provider = sanitize_key( (string) ( $row['provider'] ?? '' ) );
		if ( '' !== $provider ) {
			$context['provider'] = $provider;
		}

		return $context;
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
	 * @return int|false Number of deleted rows, or false on database failure.
	 */
	public function prune_expired( ?string $cutoff = null, int $limit = self::DEFAULT_PRUNE_BATCH_SIZE ): int|false {
		$table  = Installer::table_names()['refresh_tokens'];
		$cutoff = null !== $cutoff && '' !== $cutoff ? $cutoff : gmdate( 'Y-m-d H:i:s' );
		$limit  = $this->normalized_batch_limit( $limit );
		$ids    = BoundedPruner::candidate_ids(
			'SELECT id FROM %i WHERE expires_at < %s ORDER BY expires_at ASC, id ASC LIMIT %d',
			array( $table, $cutoff, $limit ),
			$limit
		);

		if ( false === $ids ) {
			return false;
		}

		return BoundedPruner::delete_ids( $table, $ids, 'expires_at < %s', array( $cutoff ) );
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

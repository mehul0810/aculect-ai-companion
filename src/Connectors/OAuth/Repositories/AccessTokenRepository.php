<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Repositories;

use Aculect\AICompanion\Connectors\OAuth\Database\Installer;
use Aculect\AICompanion\Connectors\OAuth\Entities\AccessTokenEntity;
use Aculect\AICompanion\Connectors\OAuth\RequestContext;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

/**
 * Persists OAuth access tokens and active connector session state.
 *
 * Access tokens are stored by SHA-256 hash only. Reads intentionally bypass
 * object cache because MCP authorization and revocation decisions must reflect
 * the latest database state on every request.
 */
final class AccessTokenRepository implements AccessTokenRepositoryInterface {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token repositories use dedicated custom tables and must read/write fresh token state.

	private const DEFAULT_TOUCH_INTERVAL_SECONDS = 300;

	/**
	 * Create an access token entity for league/oauth2-server.
	 *
	 * @param ClientEntityInterface $clientEntity   OAuth client.
	 * @param array                 $scopes         Granted scope entities.
	 * @param string|null           $userIdentifier WordPress user ID, when user-bound.
	 * @return AccessTokenEntityInterface
	 */
	public function getNewToken(
		ClientEntityInterface $clientEntity,
		array $scopes,
		?string $userIdentifier = null
	): AccessTokenEntityInterface {
		$token = new AccessTokenEntity();
		$token->setClient( $clientEntity );

		foreach ( $scopes as $scope ) {
			$token->addScope( $scope );
		}

		if ( null !== $userIdentifier && '' !== $userIdentifier ) {
			$token->setUserIdentifier( $userIdentifier );
		}

		return $token;
	}

	/**
	 * Store a newly issued access token by hash, resource, scopes, and expiry.
	 *
	 * @param AccessTokenEntityInterface $accessTokenEntity Issued token entity.
	 */
	public function persistNewAccessToken( AccessTokenEntityInterface $accessTokenEntity ): void {
		global $wpdb;

		$table  = Installer::table_names()['access_tokens'];
		$scopes = array();
		foreach ( $accessTokenEntity->getScopes() as $scope ) {
			$scopes[] = $scope->getIdentifier();
		}

		$wpdb->insert(
			$table,
			array(
				'token_hash' => $this->hash_identifier( $accessTokenEntity->getIdentifier() ),
				'client_id'  => $accessTokenEntity->getClient()->getIdentifier(),
				'user_id'    => null !== $accessTokenEntity->getUserIdentifier() ? (int) $accessTokenEntity->getUserIdentifier() : null,
				'scopes'     => wp_json_encode( $scopes ),
				'resource'   => RequestContext::resource(),
				'revoked'    => 0,
				'expires_at' => $accessTokenEntity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Revoke an access token and the refresh tokens linked to it.
	 *
	 * @param string $tokenId Raw OAuth token identifier.
	 */
	public function revokeAccessToken( string $tokenId ): void {
		global $wpdb;

		$table = Installer::table_names()['access_tokens'];
		$wpdb->update( $table, array( 'revoked' => 1 ), array( 'token_hash' => $this->hash_identifier( $tokenId ) ), array( '%d' ), array( '%s' ) );
		( new RefreshTokenRepository() )->revoke_by_access_token_id( $tokenId );
	}

	/**
	 * Check whether an access token is missing, revoked, or expired.
	 *
	 * @param string $tokenId Raw OAuth token identifier.
	 * @return bool
	 */
	public function isAccessTokenRevoked( string $tokenId ): bool {
		global $wpdb;

		$table = Installer::table_names()['access_tokens'];
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
	 * Return MCP authorization context for a validated access-token identifier.
	 *
	 * @param string $token_id Raw OAuth token identifier.
	 * @return array<string, mixed>
	 */
	public function context_from_token_id( string $token_id ): array {
		global $wpdb;

		$tables = Installer::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT access_tokens.*, clients.client_name, clients.provider
                FROM %i access_tokens
                LEFT JOIN %i clients ON clients.client_id = access_tokens.client_id
                WHERE access_tokens.token_hash = %s
                LIMIT 1',
				$tables['access_tokens'],
				$tables['clients'],
				$this->hash_identifier( $token_id )
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || '1' === (string) $row['revoked'] || strtotime( (string) $row['expires_at'] ) < time() ) {
			return array();
		}

		$this->touch( $token_id, (string) ( $row['last_used_at'] ?? '' ) );
		$scopes = json_decode( (string) ( $row['scopes'] ?? '[]' ), true );

		return array(
			'token_id'    => $token_id,
			'user_id'     => (int) ( $row['user_id'] ?? 0 ),
			'client_id'   => (string) ( $row['client_id'] ?? '' ),
			'client_name' => (string) ( $row['client_name'] ?? '' ),
			'provider'    => (string) ( $row['provider'] ?? 'mcp' ),
			'scopes'      => is_array( $scopes ) ? array_values( array_map( 'strval', $scopes ) ) : array(),
			'resource'    => (string) ( $row['resource'] ?? '' ),
			'expires_at'  => (string) ( $row['expires_at'] ?? '' ),
		);
	}

	/**
	 * List active connector sessions for the admin settings screen.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_active_sessions(): array {
		return $this->list_sessions_by_revoked_state( false );
	}

	/**
	 * List revoked connector sessions for the admin settings screen.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_revoked_sessions(): array {
		return $this->list_sessions_by_revoked_state( true );
	}

	/**
	 * List connector sessions for one token lifecycle state.
	 *
	 * @param bool $revoked Whether to list revoked sessions.
	 * @return array<int, array<string, mixed>>
	 */
	private function list_sessions_by_revoked_state( bool $revoked ): array {
		global $wpdb;

		$tables = Installer::table_names();
		if ( $revoked ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT access_tokens.id, access_tokens.client_id, access_tokens.user_id, access_tokens.scopes,
                    access_tokens.resource, access_tokens.expires_at, access_tokens.created_at, access_tokens.last_used_at,
                    clients.client_name, clients.provider
                FROM %i access_tokens
                LEFT JOIN %i clients ON clients.client_id = access_tokens.client_id
                WHERE access_tokens.revoked = 1
                ORDER BY access_tokens.created_at DESC
                LIMIT 100',
					$tables['access_tokens'],
					$tables['clients']
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT access_tokens.id, access_tokens.client_id, access_tokens.user_id, access_tokens.scopes,
                    access_tokens.resource, access_tokens.expires_at, access_tokens.created_at, access_tokens.last_used_at,
                    clients.client_name, clients.provider
                FROM %i access_tokens
                LEFT JOIN %i clients ON clients.client_id = access_tokens.client_id
                WHERE access_tokens.revoked = 0 AND access_tokens.expires_at >= %s
                ORDER BY access_tokens.created_at DESC
                LIMIT 100',
					$tables['access_tokens'],
					$tables['clients'],
					gmdate( 'Y-m-d H:i:s' )
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ) use ( $revoked ): array {
				$scopes     = json_decode( (string) ( $row['scopes'] ?? '[]' ), true );
				$user_id    = (int) ( $row['user_id'] ?? 0 );
				$user       = get_user_by( 'id', $user_id );
				$user_roles = array();

				if ( $user ) {
					$role_definitions = wp_roles()->roles;
					foreach ( (array) $user->roles as $role ) {
						$user_roles[] = isset( $role_definitions[ $role ]['name'] )
							? translate_user_role( $role_definitions[ $role ]['name'] )
							: (string) $role;
					}
				}

				return array(
					'id'           => (int) $row['id'],
					'client_id'    => (string) ( $row['client_id'] ?? '' ),
					'client_name'  => (string) ( $row['client_name'] ?? 'MCP Client' ),
					'provider'     => (string) ( $row['provider'] ?? 'mcp' ),
					'user_id'      => $user_id,
					'user'         => $user ? $user->display_name : __( 'Unknown user', 'aculect-ai-companion' ),
					'user_roles'   => array_values( array_unique( $user_roles ) ),
					'scopes'       => is_array( $scopes ) ? array_values( array_map( 'strval', $scopes ) ) : array(),
					'resource'     => (string) ( $row['resource'] ?? '' ),
					'status'       => $revoked ? 'revoked' : 'active',
					'created_at'   => (string) ( $row['created_at'] ?? '' ),
					'last_used_at' => (string) ( $row['last_used_at'] ?? '' ),
					'expires_at'   => (string) ( $row['expires_at'] ?? '' ),
				);
			},
			$rows
		);
	}

	/**
	 * Determine whether at least one non-expired session is active.
	 */
	public function has_active_tokens(): bool {
		global $wpdb;

		$table = Installer::table_names()['access_tokens'];
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE revoked = 0 AND expires_at >= %s',
				$table,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Return active session counts grouped by WordPress user ID.
	 *
	 * @return array<int, int>
	 */
	public function active_session_counts_by_user(): array {
		global $wpdb;

		$table = Installer::table_names()['access_tokens'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, COUNT(*) AS active_count
				FROM %i
				WHERE revoked = 0 AND expires_at >= %s AND user_id IS NOT NULL
				GROUP BY user_id',
				$table,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$counts = array();
		foreach ( $rows as $row ) {
			$user_id = absint( $row['user_id'] ?? 0 );
			if ( $user_id > 0 ) {
				$counts[ $user_id ] = absint( $row['active_count'] ?? 0 );
			}
		}

		return $counts;
	}

	/**
	 * Revoke one admin-visible connector session.
	 *
	 * @param int $session_id Access-token table primary key.
	 */
	public function revoke_session( int $session_id ): void {
		global $wpdb;

		$tables = Installer::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT token_hash FROM %i WHERE id = %d', $tables['access_tokens'], $session_id ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return;
		}

		$wpdb->update( $tables['access_tokens'], array( 'revoked' => 1 ), array( 'id' => $session_id ), array( '%d' ), array( '%d' ) );
		$wpdb->update( $tables['refresh_tokens'], array( 'revoked' => 1 ), array( 'access_token_hash' => (string) $row['token_hash'] ), array( '%d' ), array( '%s' ) );
	}

	/**
	 * Revoke every active access and refresh token.
	 */
	public function revoke_all(): void {
		global $wpdb;

		$tables = Installer::table_names();
		$wpdb->update( $tables['access_tokens'], array( 'revoked' => 1 ), array( 'revoked' => 0 ), array( '%d' ), array( '%d' ) );
		$wpdb->update( $tables['refresh_tokens'], array( 'revoked' => 1 ), array( 'revoked' => 0 ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Revoke every active access and refresh token for one WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Number of access-token sessions revoked.
	 */
	public function revoke_user( int $user_id ): int {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		$tables = Installer::table_names();
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i refresh_tokens
				SET refresh_tokens.revoked = 1
				WHERE refresh_tokens.revoked = 0
				AND refresh_tokens.access_token_hash IN (
					SELECT access_tokens.token_hash
					FROM %i access_tokens
					WHERE access_tokens.user_id = %d
				)',
				$tables['refresh_tokens'],
				$tables['access_tokens'],
				$user_id
			)
		);

		$result = $wpdb->update(
			$tables['access_tokens'],
			array( 'revoked' => 1 ),
			array(
				'user_id' => $user_id,
				'revoked' => 0,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Delete expired access-token rows.
	 *
	 * Revoked access tokens are preserved until they expire so active revocation
	 * checks remain immediate and admin session state stays understandable.
	 *
	 * @param string|null $cutoff Optional UTC cutoff in Y-m-d H:i:s format.
	 * @return int Number of deleted rows.
	 */
	public function prune_expired( ?string $cutoff = null ): int {
		global $wpdb;

		$table  = Installer::table_names()['access_tokens'];
		$cutoff = null !== $cutoff && '' !== $cutoff ? $cutoff : gmdate( 'Y-m-d H:i:s' );
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at < %s',
				$table,
				$cutoff
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Record last token usage for the admin connection list.
	 *
	 * @param string $token_id Raw OAuth token identifier.
	 * @param string $last_used_at Existing UTC last-used timestamp.
	 */
	private function touch( string $token_id, string $last_used_at ): void {
		if ( ! $this->should_touch( $last_used_at ) ) {
			return;
		}

		global $wpdb;

		$table = Installer::table_names()['access_tokens'];
		$wpdb->update(
			$table,
			array( 'last_used_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'token_hash' => $this->hash_identifier( $token_id ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Determine whether a token's last-used timestamp should be updated.
	 *
	 * @param string $last_used_at Existing UTC last-used timestamp.
	 * @param int    $now          Current Unix timestamp for tests.
	 */
	private function should_touch( string $last_used_at, int $now = 0 ): bool {
		$interval = (int) apply_filters( 'aculect_ai_companion_oauth_token_touch_interval', self::DEFAULT_TOUCH_INTERVAL_SECONDS );
		$interval = max( 0, $interval );

		if ( '' === $last_used_at ) {
			return true;
		}

		$last_used_timestamp = strtotime( $last_used_at );
		if ( false === $last_used_timestamp ) {
			return true;
		}

		$now = $now > 0 ? $now : time();

		return ( $now - $last_used_timestamp ) >= $interval;
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

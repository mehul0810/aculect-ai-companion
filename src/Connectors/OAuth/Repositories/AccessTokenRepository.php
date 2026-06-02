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
 * Persists OAuth access tokens and connector session state.
 *
 * Access tokens are stored by SHA-256 hash only. Reads intentionally bypass
 * object cache because MCP authorization and revocation decisions must reflect
 * the latest database state on every request.
 */
final class AccessTokenRepository implements AccessTokenRepositoryInterface {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth token repositories use dedicated custom tables and must read/write fresh token state.

	private const DEFAULT_TOUCH_INTERVAL_SECONDS = 300;
	private const DEFAULT_PRUNE_BATCH_SIZE       = 500;

	/**
	 * Request-local write permission state captured during refresh-token rotation.
	 *
	 * League revokes the old access token before persisting the replacement. This
	 * map lets the new access-token row inherit the trusted connection setting
	 * without expanding the public OAuth token payload.
	 *
	 * @var array<string, int>
	 */
	private static array $pending_write_permission_transfers = array();

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

		$resource                 = RequestContext::resource();
		$write_permission_enabled = $this->consume_pending_write_permission_transfer(
			(string) $accessTokenEntity->getClient()->getIdentifier(),
			$accessTokenEntity->getUserIdentifier(),
			$resource
		);

		$wpdb->insert(
			$table,
			array(
				'token_hash'               => $this->hash_identifier( $accessTokenEntity->getIdentifier() ),
				'client_id'                => $accessTokenEntity->getClient()->getIdentifier(),
				'user_id'                  => null !== $accessTokenEntity->getUserIdentifier() ? (int) $accessTokenEntity->getUserIdentifier() : null,
				'scopes'                   => wp_json_encode( $scopes ),
				'resource'                 => $resource,
				'revoked'                  => 0,
				'write_permission_enabled' => $write_permission_enabled,
				'expires_at'               => $accessTokenEntity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' )
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
		$this->capture_write_permission_transfer( $tokenId );
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
			'token_id'                 => $token_id,
			'user_id'                  => (int) ( $row['user_id'] ?? 0 ),
			'client_id'                => (string) ( $row['client_id'] ?? '' ),
			'client_name'              => (string) ( $row['client_name'] ?? '' ),
			'provider'                 => (string) ( $row['provider'] ?? 'mcp' ),
			'scopes'                   => is_array( $scopes ) ? array_values( array_map( 'strval', $scopes ) ) : array(),
			'resource'                 => (string) ( $row['resource'] ?? '' ),
			'expires_at'               => (string) ( $row['expires_at'] ?? '' ),
			'write_permission_enabled' => '1' === (string) ( $row['write_permission_enabled'] ?? '0' ),
		);
	}

	/**
	 * List refreshable connector sessions for the admin settings screen.
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
                    access_tokens.write_permission_enabled, clients.client_name, clients.provider
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
                    access_tokens.resource, access_tokens.expires_at AS access_token_expires_at,
                    active_refresh.expires_at AS connection_expires_at, access_tokens.created_at,
                    access_tokens.last_used_at, access_tokens.write_permission_enabled, clients.client_name, clients.provider
                FROM %i access_tokens
                INNER JOIN (
                    SELECT access_token_hash, MAX(expires_at) AS expires_at
                    FROM %i
                    WHERE revoked = 0 AND expires_at >= %s
                    GROUP BY access_token_hash
                ) active_refresh ON active_refresh.access_token_hash = access_tokens.token_hash
                LEFT JOIN %i clients ON clients.client_id = access_tokens.client_id
                WHERE access_tokens.revoked = 0
                ORDER BY active_refresh.expires_at DESC, access_tokens.created_at DESC
                LIMIT 100',
					$tables['access_tokens'],
					$tables['refresh_tokens'],
					gmdate( 'Y-m-d H:i:s' ),
					$tables['clients']
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
					'id'                       => (int) $row['id'],
					'client_id'                => (string) ( $row['client_id'] ?? '' ),
					'client_name'              => (string) ( $row['client_name'] ?? 'MCP Client' ),
					'provider'                 => (string) ( $row['provider'] ?? 'mcp' ),
					'user_id'                  => $user_id,
					'user'                     => $user ? $user->display_name : __( 'Unknown user', 'aculect-ai-companion' ),
					'user_roles'               => array_values( array_unique( $user_roles ) ),
					'scopes'                   => is_array( $scopes ) ? array_values( array_map( 'strval', $scopes ) ) : array(),
					'resource'                 => (string) ( $row['resource'] ?? '' ),
					'status'                   => $revoked ? 'revoked' : 'active',
					'created_at'               => (string) ( $row['created_at'] ?? '' ),
					'last_used_at'             => (string) ( $row['last_used_at'] ?? '' ),
					'expires_at'               => (string) ( $row['connection_expires_at'] ?? $row['expires_at'] ?? '' ),
					'write_permission_enabled' => '1' === (string) ( $row['write_permission_enabled'] ?? '0' ),
				);
			},
			$rows
		);
	}

	/**
	 * Determine whether at least one refreshable session is active.
	 */
	public function has_active_tokens(): bool {
		return $this->active_token_count() > 0;
	}

	/**
	 * Count active connections that can still refresh access.
	 */
	public function active_token_count(): int {
		global $wpdb;

		$tables = Installer::table_names();
		$count  = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT access_tokens.id)
				FROM %i access_tokens
				INNER JOIN %i refresh_tokens ON refresh_tokens.access_token_hash = access_tokens.token_hash
				WHERE access_tokens.revoked = 0
				AND refresh_tokens.revoked = 0
				AND refresh_tokens.expires_at >= %s',
				$tables['access_tokens'],
				$tables['refresh_tokens'],
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return (int) $count;
	}

	/**
	 * Return active session counts grouped by WordPress user ID.
	 *
	 * @return array<int, int>
	 */
	public function active_session_counts_by_user(): array {
		global $wpdb;

		$tables = Installer::table_names();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT access_tokens.user_id, COUNT(DISTINCT access_tokens.id) AS active_count
				FROM %i access_tokens
				INNER JOIN %i refresh_tokens ON refresh_tokens.access_token_hash = access_tokens.token_hash
				WHERE access_tokens.revoked = 0
				AND refresh_tokens.revoked = 0
				AND refresh_tokens.expires_at >= %s
				AND access_tokens.user_id IS NOT NULL
				GROUP BY access_tokens.user_id',
				$tables['access_tokens'],
				$tables['refresh_tokens'],
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
	 * Enable or disable direct write execution for one active connector session.
	 *
	 * Revoked or non-refreshable rows are intentionally ignored so the setting
	 * only applies while the connection is still available to the assistant.
	 *
	 * @param int  $session_id Access-token table primary key.
	 * @param bool $enabled    Whether direct writes are allowed.
	 * @return bool Whether a row was updated.
	 */
	public function set_write_permission( int $session_id, bool $enabled ): bool {
		$session_id = absint( $session_id );
		if ( $session_id <= 0 ) {
			return false;
		}

		global $wpdb;

		$tables = Installer::table_names();
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i access_tokens
				INNER JOIN %i refresh_tokens ON refresh_tokens.access_token_hash = access_tokens.token_hash
				SET access_tokens.write_permission_enabled = %d
				WHERE access_tokens.id = %d
				AND access_tokens.revoked = 0
				AND refresh_tokens.revoked = 0
				AND refresh_tokens.expires_at >= %s',
				$tables['access_tokens'],
				$tables['refresh_tokens'],
				$enabled ? 1 : 0,
				$session_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return false !== $result && (int) $result > 0;
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
	 * Delete expired access-token rows that no longer anchor active refresh.
	 *
	 * Expired access-token rows are kept while a non-revoked refresh token can
	 * still renew them so admin session state and revocation remain available
	 * for the full connection window.
	 *
	 * @param string|null $cutoff Optional UTC cutoff in Y-m-d H:i:s format.
	 * @param int         $limit  Maximum rows to delete in this pass.
	 * @return int Number of deleted rows.
	 */
	public function prune_expired( ?string $cutoff = null, int $limit = self::DEFAULT_PRUNE_BATCH_SIZE ): int {
		global $wpdb;

		$tables = Installer::table_names();
		$cutoff = null !== $cutoff && '' !== $cutoff ? $cutoff : gmdate( 'Y-m-d H:i:s' );
		$limit  = $this->normalized_batch_limit( $limit );
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
				WHERE expires_at < %s
				AND token_hash NOT IN (
					SELECT access_token_hash
					FROM %i
					WHERE revoked = 0
					AND expires_at >= %s
				)
				ORDER BY expires_at ASC
				LIMIT %d',
				$tables['access_tokens'],
				$cutoff,
				$tables['refresh_tokens'],
				$cutoff,
				$limit
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Capture write permission state from an access token before refresh rotation revokes it.
	 *
	 * @param string $token_id Raw OAuth token identifier.
	 */
	private function capture_write_permission_transfer( string $token_id ): void {
		global $wpdb;

		$table = Installer::table_names()['access_tokens'];
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT client_id, user_id, resource, write_permission_enabled FROM %i WHERE token_hash = %s LIMIT 1',
				$table,
				$this->hash_identifier( $token_id )
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || '1' !== (string) ( $row['write_permission_enabled'] ?? '0' ) ) {
			return;
		}

		self::$pending_write_permission_transfers[ $this->write_permission_transfer_key(
			(string) ( $row['client_id'] ?? '' ),
			isset( $row['user_id'] ) ? (string) $row['user_id'] : null,
			(string) ( $row['resource'] ?? '' )
		) ] = 1;
	}

	/**
	 * Return pending write permission state for a replacement access token.
	 *
	 * @param string      $client_id Client identifier.
	 * @param string|null $user_id   User identifier.
	 * @param string      $resource  Resource URL.
	 */
	private function consume_pending_write_permission_transfer( string $client_id, ?string $user_id, string $resource ): int {
		$key = $this->write_permission_transfer_key( $client_id, $user_id, $resource );
		if ( ! isset( self::$pending_write_permission_transfers[ $key ] ) ) {
			return 0;
		}

		$enabled = self::$pending_write_permission_transfers[ $key ];
		unset( self::$pending_write_permission_transfers[ $key ] );

		return $enabled;
	}

	/**
	 * Build a request-local key for refresh-token permission transfer.
	 *
	 * @param string      $client_id Client identifier.
	 * @param string|null $user_id   User identifier.
	 * @param string      $resource  Resource URL.
	 */
	private function write_permission_transfer_key( string $client_id, ?string $user_id, string $resource ): string {
		return implode( '|', array( $client_id, (string) $user_id, $resource ) );
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

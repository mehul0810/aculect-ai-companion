<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Repositories;

use Quark\Connectors\OAuth\Database\Installer;
use Quark\Connectors\OAuth\Entities\AccessTokenEntity;
use Quark\Connectors\OAuth\RequestContext;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

final class AccessTokenRepository implements AccessTokenRepositoryInterface {

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

	public function revokeAccessToken( string $tokenId ): void {
		global $wpdb;

		$table = Installer::table_names()['access_tokens'];
		$wpdb->update( $table, array( 'revoked' => 1 ), array( 'token_hash' => $this->hash_identifier( $tokenId ) ), array( '%d' ), array( '%s' ) );
		( new RefreshTokenRepository() )->revoke_by_access_token_id( $tokenId );
	}

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

		$this->touch( $token_id );
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

	public function list_active_sessions(): array {
		global $wpdb;

		$tables = Installer::table_names();
		$rows   = $wpdb->get_results(
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

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $row ): array {
				$scopes = json_decode( (string) ( $row['scopes'] ?? '[]' ), true );
				$user   = get_user_by( 'id', (int) ( $row['user_id'] ?? 0 ) );

				return array(
					'id'           => (int) $row['id'],
					'client_id'    => (string) ( $row['client_id'] ?? '' ),
					'client_name'  => (string) ( $row['client_name'] ?? 'MCP Client' ),
					'provider'     => (string) ( $row['provider'] ?? 'mcp' ),
					'user'         => $user ? $user->display_name : __( 'Unknown user', 'quark' ),
					'scopes'       => is_array( $scopes ) ? array_values( array_map( 'strval', $scopes ) ) : array(),
					'resource'     => (string) ( $row['resource'] ?? '' ),
					'created_at'   => (string) ( $row['created_at'] ?? '' ),
					'last_used_at' => (string) ( $row['last_used_at'] ?? '' ),
					'expires_at'   => (string) ( $row['expires_at'] ?? '' ),
				);
			},
			$rows
		);
	}

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

	public function revoke_all(): void {
		global $wpdb;

		$tables = Installer::table_names();
		$wpdb->update( $tables['access_tokens'], array( 'revoked' => 1 ), array( 'revoked' => 0 ), array( '%d' ), array( '%d' ) );
		$wpdb->update( $tables['refresh_tokens'], array( 'revoked' => 1 ), array( 'revoked' => 0 ), array( '%d' ), array( '%d' ) );
	}

	private function touch( string $token_id ): void {
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

	private function hash_identifier( string $identifier ): string {
		return hash( 'sha256', $identifier );
	}
}

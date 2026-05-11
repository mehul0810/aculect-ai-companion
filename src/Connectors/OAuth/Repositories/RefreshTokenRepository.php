<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Repositories;

use Quark\Connectors\OAuth\Database\Installer;
use Quark\Connectors\OAuth\Entities\RefreshTokenEntity;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

final class RefreshTokenRepository implements RefreshTokenRepositoryInterface {

	public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
		return new RefreshTokenEntity();
	}

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

	public function revokeRefreshToken( string $tokenId ): void {
		global $wpdb;

		$table = Installer::table_names()['refresh_tokens'];
		$wpdb->update( $table, array( 'revoked' => 1 ), array( 'token_hash' => $this->hash_identifier( $tokenId ) ), array( '%d' ), array( '%s' ) );
	}

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

	public function revoke_all(): void {
		global $wpdb;

		$table = Installer::table_names()['refresh_tokens'];
		$wpdb->update( $table, array( 'revoked' => 1 ), array( 'revoked' => 0 ), array( '%d' ), array( '%d' ) );
	}

	private function hash_identifier( string $identifier ): string {
		return hash( 'sha256', $identifier );
	}
}

<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Repositories;

use Quark\Connectors\OAuth\Database\Installer;
use Quark\Connectors\OAuth\Entities\AuthCodeEntity;
use Quark\Connectors\OAuth\RequestContext;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth auth codes use a dedicated custom table and must not be cached.
final class AuthCodeRepository implements AuthCodeRepositoryInterface {

	public function getNewAuthCode(): AuthCodeEntityInterface {
		return new AuthCodeEntity();
	}

	public function persistNewAuthCode( AuthCodeEntityInterface $authCodeEntity ): void {
		global $wpdb;

		$table  = Installer::table_names()['auth_codes'];
		$scopes = array();
		foreach ( $authCodeEntity->getScopes() as $scope ) {
			$scopes[] = $scope->getIdentifier();
		}

		$wpdb->insert(
			$table,
			array(
				'code_hash'  => $this->hash_identifier( $authCodeEntity->getIdentifier() ),
				'client_id'  => $authCodeEntity->getClient()->getIdentifier(),
				'user_id'    => (int) $authCodeEntity->getUserIdentifier(),
				'scopes'     => wp_json_encode( $scopes ),
				'resource'   => RequestContext::resource(),
				'revoked'    => 0,
				'expires_at' => $authCodeEntity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	public function revokeAuthCode( string $codeId ): void {
		global $wpdb;

		$table = Installer::table_names()['auth_codes'];
		$wpdb->update( $table, array( 'revoked' => 1 ), array( 'code_hash' => $this->hash_identifier( $codeId ) ), array( '%d' ), array( '%s' ) );
	}

	public function isAuthCodeRevoked( string $codeId ): bool {
		global $wpdb;

		$table = Installer::table_names()['auth_codes'];
		$row   = $wpdb->get_row(
			$wpdb->prepare( 'SELECT revoked, expires_at FROM %i WHERE code_hash = %s', $table, $this->hash_identifier( $codeId ) ),
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

	private function hash_identifier( string $identifier ): string {
		return hash( 'sha256', $identifier );
	}
}

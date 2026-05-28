<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Repositories;

use Aculect\AICompanion\Connectors\OAuth\Database\Installer;
use Aculect\AICompanion\Connectors\OAuth\Entities\AuthCodeEntity;
use Aculect\AICompanion\Connectors\OAuth\RequestContext;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

/**
 * Stores short-lived authorization codes for the OAuth authorization flow.
 *
 * Codes are stored only as hashes and are checked directly from the custom
 * table so single-use and expiration decisions are always current.
 */
final class AuthCodeRepository implements AuthCodeRepositoryInterface {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth auth codes use a dedicated custom table and must not be cached.

	private const DEFAULT_PRUNE_BATCH_SIZE = 500;

	/**
	 * Create a new authorization code entity for league/oauth2-server.
	 */
	public function getNewAuthCode(): AuthCodeEntityInterface {
		return new AuthCodeEntity();
	}

	/**
	 * Store an issued authorization code by hash, user, scopes, resource, and expiry.
	 *
	 * @param AuthCodeEntityInterface $authCodeEntity Issued authorization code.
	 */
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

	/**
	 * Mark an authorization code as used or revoked.
	 *
	 * @param string $codeId Raw authorization code identifier.
	 */
	public function revokeAuthCode( string $codeId ): void {
		global $wpdb;

		$table = Installer::table_names()['auth_codes'];
		$wpdb->update( $table, array( 'revoked' => 1 ), array( 'code_hash' => $this->hash_identifier( $codeId ) ), array( '%d' ), array( '%s' ) );
	}

	/**
	 * Check whether an authorization code is missing, used, or expired.
	 *
	 * @param string $codeId Raw authorization code identifier.
	 * @return bool
	 */
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

	/**
	 * Delete expired authorization-code rows.
	 *
	 * Authorization codes are short-lived and single-use. Revoked codes are kept
	 * only until their normal expiry window has passed.
	 *
	 * @param string|null $cutoff Optional UTC cutoff in Y-m-d H:i:s format.
	 * @param int         $limit  Maximum rows to delete in this pass.
	 * @return int Number of deleted rows.
	 */
	public function prune_expired( ?string $cutoff = null, int $limit = self::DEFAULT_PRUNE_BATCH_SIZE ): int {
		global $wpdb;

		$table  = Installer::table_names()['auth_codes'];
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
	 * Hash raw code material before database lookup or storage.
	 *
	 * @param string $identifier Raw protocol identifier.
	 * @return string
	 */
	private function hash_identifier( string $identifier ): string {
		return hash( 'sha256', $identifier );
	}
}

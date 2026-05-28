<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Repositories;

use DateTimeImmutable;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\Database\Installer;
use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

/**
 * Persists OAuth clients registered through Dynamic Client Registration.
 *
 * Clients are stored in a custom table so redirect URIs, provider labels, and
 * revocation can be managed independently of WordPress users and options.
 */
final class ClientRepository implements ClientRepositoryInterface {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth clients use a dedicated custom table and need immediate revocation/registration state.

	/**
	 * Load a non-revoked OAuth client entity by client ID.
	 *
	 * @param string $clientIdentifier OAuth client ID.
	 * @return ClientEntityInterface|null
	 */
	public function getClientEntity( string $clientIdentifier ): ?ClientEntityInterface {
		global $wpdb;

		$table = Installer::table_names()['clients'];
		$row   = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE client_id = %s AND revoked = 0', $table, $clientIdentifier ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Validate a public or confidential OAuth client.
	 *
	 * @param string      $clientIdentifier OAuth client ID.
	 * @param string|null $clientSecret     Raw client secret, if supplied.
	 * @param string|null $grantType        Requested grant type.
	 * @return bool
	 */
	public function validateClient( string $clientIdentifier, ?string $clientSecret, ?string $grantType ): bool {
		unset( $grantType );

		$client = $this->getClientEntity( $clientIdentifier );
		if ( ! $client instanceof ClientEntity ) {
			return false;
		}

		if ( ! $client->isConfidential() ) {
			return true;
		}

		$secret_hash = $client->getClientSecretHash();
		if ( '' === (string) $clientSecret || '' === (string) $secret_hash ) {
			return false;
		}

		return wp_check_password( (string) $clientSecret, (string) $secret_hash );
	}

	/**
	 * Create a DCR client and return its one-time plaintext credentials.
	 *
	 * @param string   $name          Client display name.
	 * @param string[] $redirect_uris Valid redirect URIs.
	 * @param bool     $confidential  Whether the client receives a secret.
	 * @param int|null $user_id       Optional owning WordPress user.
	 * @return array<string, string|null>|null
	 */
	public function create_client( string $name, array $redirect_uris, bool $confidential = true, ?int $user_id = null ): ?array {
		global $wpdb;

		$table         = Installer::table_names()['clients'];
		$client_id     = $this->generate_client_id();
		$client_secret = $confidential ? $this->generate_client_secret() : null;
		$secret_hash   = $client_secret ? wp_hash_password( $client_secret ) : null;
		$provider      = Helpers::provider_from_client( $name, $redirect_uris );
		$encoded_uris  = $this->encoded_redirect_uris( $redirect_uris );

		if ( null === $encoded_uris ) {
			return null;
		}

		$this->revoke_unused_duplicate_clients_by_fingerprint(
			$provider,
			$encoded_uris,
			gmdate( 'Y-m-d H:i:s' )
		);

		$result = $wpdb->insert(
			$table,
			array(
				'client_id'          => $client_id,
				'client_secret_hash' => $secret_hash,
				'client_name'        => $name,
				'provider'           => $provider,
				'redirect_uris'      => $encoded_uris,
				'user_id'            => $user_id,
				'is_confidential'    => $confidential ? 1 : 0,
				'revoked'            => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);

		if ( false === $result ) {
			return null;
		}

		return array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'provider'      => $provider,
		);
	}

	/**
	 * Return registered, non-revoked clients for diagnostics.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_clients(): array {
		global $wpdb;

		$table = Installer::table_names()['clients'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE revoked = 0 ORDER BY created_at DESC LIMIT 100', $table ),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'public_row' ), $rows ) : array();
	}

	/**
	 * Revoke one OAuth client.
	 *
	 * @param string $client_id OAuth client ID.
	 */
	public function revoke_client( string $client_id ): void {
		global $wpdb;

		$table = Installer::table_names()['clients'];
		$wpdb->update( $table, array( 'revoked' => 1 ), array( 'client_id' => $client_id ), array( '%d' ), array( '%s' ) );
	}

	/**
	 * Revoke unused active clients that match a new DCR registration fingerprint.
	 *
	 * This bounds repeated connector retries without rejecting valid Dynamic Client
	 * Registration requests. Clients with live access tokens or authorization codes
	 * remain active so in-flight and approved connections are not broken.
	 *
	 * @param string      $provider      Provider slug.
	 * @param string[]    $redirect_uris Valid redirect URIs.
	 * @param string|null $now           Optional UTC timestamp for tests.
	 * @return int Number of client rows revoked.
	 */
	public function revoke_unused_duplicate_clients( string $provider, array $redirect_uris, ?string $now = null ): int {
		$encoded_uris = $this->encoded_redirect_uris( $redirect_uris );
		if ( null === $encoded_uris ) {
			return 0;
		}

		return $this->revoke_unused_duplicate_clients_by_fingerprint(
			sanitize_key( $provider ),
			$encoded_uris,
			$this->normalized_cutoff( $now )
		);
	}

	/**
	 * Delete revoked DCR clients older than the retention cutoff.
	 *
	 * @param string|null $cutoff Optional UTC cutoff in Y-m-d H:i:s format.
	 * @return int Number of deleted rows.
	 */
	public function prune_revoked_clients( ?string $cutoff = null ): int {
		global $wpdb;

		$table  = Installer::table_names()['clients'];
		$cutoff = $this->normalized_cutoff( $cutoff );
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE revoked = 1 AND updated_at < %s',
				$table,
				$cutoff
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Convert a database row into a League OAuth client entity.
	 *
	 * @param array<string, mixed> $row Client row.
	 * @return ClientEntity
	 */
	private function hydrate( array $row ): ClientEntity {
		$client = new ClientEntity();
		$client->setIdentifier( (string) $row['client_id'] );
		$client->setName( (string) $row['client_name'] );
		$client->setRedirectUri( $this->redirect_uris_from_row( $row ) );
		$client->setConfidential( '1' === (string) $row['is_confidential'] );
		$client->setUserId( null !== $row['user_id'] ? (int) $row['user_id'] : null );
		$client->setClientSecretHash( (string) ( $row['client_secret_hash'] ?? '' ) );
		$client->setProvider( (string) ( $row['provider'] ?? 'mcp' ) );

		if ( ! empty( $row['created_at'] ) ) {
			$client->setCreatedAt( new DateTimeImmutable( (string) $row['created_at'] ) );
		}

		return $client;
	}

	/**
	 * Convert a client row into safe public diagnostics data.
	 *
	 * @param array<string, mixed> $row Client row.
	 * @return array<string, mixed>
	 */
	private function public_row( array $row ): array {
		return array(
			'client_id'     => (string) $row['client_id'],
			'client_name'   => (string) $row['client_name'],
			'provider'      => (string) ( $row['provider'] ?? 'mcp' ),
			'redirect_uris' => $this->redirect_uris_from_row( $row ),
			'created_at'    => (string) ( $row['created_at'] ?? '' ),
		);
	}

	/**
	 * Decode redirect URIs stored as JSON.
	 *
	 * @param array<string, mixed> $row Client row.
	 * @return string[]
	 */
	private function redirect_uris_from_row( array $row ): array {
		$decoded = json_decode( (string) ( $row['redirect_uris'] ?? '[]' ), true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $decoded ) ) );
	}

	/**
	 * Encode redirect URIs exactly as stored for fingerprint comparisons.
	 *
	 * @param string[] $redirect_uris Valid redirect URIs.
	 */
	private function encoded_redirect_uris( array $redirect_uris ): ?string {
		$encoded = wp_json_encode( array_values( array_map( 'strval', $redirect_uris ) ) );

		return false === $encoded ? null : $encoded;
	}

	/**
	 * Revoke matching duplicate clients that have no live token or auth code.
	 *
	 * @param string $provider     Provider slug.
	 * @param string $encoded_uris JSON encoded redirect URI list.
	 * @param string $now          UTC timestamp in Y-m-d H:i:s format.
	 * @return int Number of client rows revoked.
	 */
	private function revoke_unused_duplicate_clients_by_fingerprint( string $provider, string $encoded_uris, string $now ): int {
		global $wpdb;

		$tables = Installer::table_names();
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i clients
				SET clients.revoked = 1
				WHERE clients.revoked = 0
				AND clients.provider = %s
				AND clients.redirect_uris = %s
				AND clients.client_id NOT IN (
					SELECT active_tokens.client_id
					FROM %i active_tokens
					WHERE active_tokens.revoked = 0
					AND active_tokens.expires_at >= %s
				)
				AND clients.client_id NOT IN (
					SELECT active_codes.client_id
					FROM %i active_codes
					WHERE active_codes.revoked = 0
					AND active_codes.expires_at >= %s
				)',
				$tables['clients'],
				$provider,
				$encoded_uris,
				$tables['access_tokens'],
				$now,
				$tables['auth_codes'],
				$now
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Normalize an optional UTC cutoff timestamp.
	 *
	 * @param string|null $cutoff Optional UTC cutoff.
	 */
	private function normalized_cutoff( ?string $cutoff ): string {
		return null !== $cutoff && '' !== $cutoff ? $cutoff : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Generate a stable-prefixed random client ID.
	 */
	private function generate_client_id(): string {
		return 'aculect_ai_companion_dcr_' . bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Generate a high-entropy client secret for confidential clients.
	 */
	private function generate_client_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}

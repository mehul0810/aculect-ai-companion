<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Repositories;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationFingerprint;
use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationResult;
use Aculect\AICompanion\Connectors\OAuth\Database\BoundedPruner;
use Aculect\AICompanion\Connectors\OAuth\Database\Installer;
use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use DateTimeImmutable;
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

	private const DUPLICATE_CLEANUP_BATCH_SIZE = 25;
	private const STALE_CLIENT_MIN_AGE_HOURS   = 24;
	private const DEFAULT_PRUNE_BATCH_SIZE     = 500;
	private const RECOVERY_LIST_LIMIT          = 20;

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
		return $this->create_client_result( $name, $redirect_uris, $confidential, $user_id )->client();
	}

	/**
	 * Create a DCR client and preserve the reason a registration was rejected.
	 *
	 * @param string   $name          Client display name.
	 * @param string[] $redirect_uris Valid redirect URIs.
	 * @param bool     $confidential  Whether the client receives a secret.
	 * @param int|null $user_id       Optional owning WordPress user.
	 */
	public function create_client_result( string $name, array $redirect_uris, bool $confidential = true, ?int $user_id = null ): ClientRegistrationResult {
		global $wpdb;

		$table                    = Installer::table_names()['clients'];
		$provider                 = Helpers::provider_from_client( $name, $redirect_uris );
		$encoded_uris             = ClientRegistrationFingerprint::encoded_redirect_uris( $redirect_uris );
		$registration_fingerprint = ClientRegistrationFingerprint::from_redirect_uris( $redirect_uris );

		if ( null === $encoded_uris || null === $registration_fingerprint ) {
			return ClientRegistrationResult::invalid_metadata();
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$this->revoke_unused_duplicate_clients_by_fingerprint(
			$provider,
			$registration_fingerprint,
			$now
		);

		if ( $this->count_active_clients() >= $this->max_active_clients() ) {
			return ClientRegistrationResult::capacity_exceeded();
		}

		$client_id     = $this->generate_client_id();
		$client_secret = $confidential ? $this->generate_client_secret() : null;
		$secret_hash   = $client_secret ? wp_hash_password( $client_secret ) : null;

		$result = $wpdb->insert(
			$table,
			array(
				'client_id'                => $client_id,
				'client_secret_hash'       => $secret_hash,
				'client_name'              => $name,
				'provider'                 => $provider,
				'redirect_uris'            => $encoded_uris,
				'registration_fingerprint' => $registration_fingerprint,
				'user_id'                  => $user_id,
				'is_confidential'          => $confidential ? 1 : 0,
				'revoked'                  => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);

		if ( false === $result ) {
			return ClientRegistrationResult::storage_failure();
		}

		return ClientRegistrationResult::created(
			array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'provider'      => $provider,
			)
		);
	}

	/**
	 * Count non-revoked OAuth clients.
	 */
	public function count_active_clients(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table; bounded scalar count.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE revoked = 0', Installer::table_names()['clients'] )
		);
	}

	/**
	 * Return the cap on concurrently registered active clients.
	 *
	 * Dynamic Client Registration is unauthenticated, so an unbounded client
	 * table is a storage-exhaustion vector. 100 covers realistic multi-user
	 * sites with several AI clients each; filterable for larger fleets.
	 */
	public function max_active_clients(): int {
		return max( 1, (int) apply_filters( 'aculect_ai_companion_max_active_oauth_clients', 100 ) );
	}

	/**
	 * Return a sanitized registration-capacity summary for administrators.
	 *
	 * @return array{active:int,maximum:int,available:int,recoverable:int,status:string}
	 */
	public function capacity_status(): array {
		$active      = $this->count_active_clients();
		$maximum     = $this->max_active_clients();
		$recoverable = $this->count_stale_unused_clients();

		return array(
			'active'      => $active,
			'maximum'     => $maximum,
			'available'   => max( 0, $maximum - $active ),
			'recoverable' => $recoverable,
			'status'      => $active >= $maximum ? 'exhausted' : ( $active >= (int) ceil( $maximum * 0.8 ) ? 'warning' : 'available' ),
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
	 * Return bounded, sanitized stale registrations eligible for admin recovery.
	 *
	 * @return array<int, array{client_id:string,client_name:string,provider:string,redirect_hosts:string[],created_at:string}>
	 */
	public function list_recoverable_clients(): array {
		global $wpdb;

		$tables = Installer::table_names();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT clients.client_id, clients.client_name, clients.provider, clients.redirect_uris, clients.created_at
				FROM %i clients
				WHERE clients.revoked = 0
				AND clients.created_at < %s
				AND clients.client_id NOT IN (
					SELECT active_tokens.client_id
					FROM %i active_tokens
					WHERE active_tokens.revoked = 0
					AND active_tokens.expires_at >= %s
				)
				AND clients.client_id NOT IN (
					SELECT refreshable_tokens.client_id
					FROM %i refreshable_tokens
					INNER JOIN %i active_refresh
						ON active_refresh.access_token_hash = refreshable_tokens.token_hash
					WHERE active_refresh.revoked = 0
					AND active_refresh.expires_at >= %s
				)
				AND clients.client_id NOT IN (
					SELECT active_codes.client_id
					FROM %i active_codes
					WHERE active_codes.revoked = 0
					AND active_codes.expires_at >= %s
				)
				ORDER BY clients.created_at ASC
				LIMIT %d',
				$tables['clients'],
				$this->stale_client_cutoff(),
				$tables['access_tokens'],
				$now,
				$tables['access_tokens'],
				$tables['refresh_tokens'],
				$now,
				$tables['auth_codes'],
				$now,
				self::RECOVERY_LIST_LIMIT
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			fn( array $row ): array => array(
				'client_id'      => (string) ( $row['client_id'] ?? '' ),
				'client_name'    => sanitize_text_field( (string) ( $row['client_name'] ?? '' ) ),
				'provider'       => sanitize_key( (string) ( $row['provider'] ?? 'mcp' ) ),
				'redirect_hosts' => $this->redirect_hosts_from_row( $row ),
				'created_at'     => sanitize_text_field( (string) ( $row['created_at'] ?? '' ) ),
			),
			$rows
		);
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
	 * Revoke one client only when it still satisfies the stale recovery policy.
	 *
	 * @param string $client_id OAuth client ID.
	 */
	public function revoke_stale_client( string $client_id ): bool {
		global $wpdb;

		$client_id = sanitize_text_field( $client_id );
		if ( '' === $client_id ) {
			return false;
		}

		$tables = Installer::table_names();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i clients
				SET clients.revoked = 1
				WHERE clients.client_id = %s
				AND clients.revoked = 0
				AND clients.created_at < %s
				AND clients.client_id NOT IN (
					SELECT active_tokens.client_id
					FROM %i active_tokens
					WHERE active_tokens.revoked = 0
					AND active_tokens.expires_at >= %s
				)
				AND clients.client_id NOT IN (
					SELECT refreshable_tokens.client_id
					FROM %i refreshable_tokens
					INNER JOIN %i active_refresh
						ON active_refresh.access_token_hash = refreshable_tokens.token_hash
					WHERE active_refresh.revoked = 0
					AND active_refresh.expires_at >= %s
				)
				AND clients.client_id NOT IN (
					SELECT active_codes.client_id
					FROM %i active_codes
					WHERE active_codes.revoked = 0
					AND active_codes.expires_at >= %s
				)',
				$tables['clients'],
				$client_id,
				$this->stale_client_cutoff(),
				$tables['access_tokens'],
				$now,
				$tables['access_tokens'],
				$tables['refresh_tokens'],
				$now,
				$tables['auth_codes'],
				$now
			)
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Remove unused clients that match a new DCR registration fingerprint.
	 *
	 * This bounds repeated connector retries without rejecting valid Dynamic Client
	 * Registration requests. Clients with live access tokens, refresh tokens, or
	 * authorization codes remain so in-flight and approved connections are not broken.
	 *
	 * @param string      $provider      Provider slug.
	 * @param string[]    $redirect_uris Valid redirect URIs.
	 * @param string|null $now           Optional UTC timestamp for tests.
	 * @return int Number of client rows removed.
	 */
	public function revoke_unused_duplicate_clients( string $provider, array $redirect_uris, ?string $now = null ): int {
		$registration_fingerprint = ClientRegistrationFingerprint::from_redirect_uris( $redirect_uris );
		if ( null === $registration_fingerprint ) {
			return 0;
		}

		return $this->revoke_unused_duplicate_clients_by_fingerprint(
			sanitize_key( $provider ),
			$registration_fingerprint,
			$this->normalized_cutoff( $now )
		);
	}

	/**
	 * Delete revoked DCR clients older than the retention cutoff.
	 *
	 * @param string|null $cutoff Optional UTC cutoff in Y-m-d H:i:s format.
	 * @param int         $limit  Maximum rows to delete in this pass.
	 * @return int|false Number of deleted rows, or false on database failure.
	 */
	public function prune_revoked_clients( ?string $cutoff = null, int $limit = self::DEFAULT_PRUNE_BATCH_SIZE ): int|false {
		$table  = Installer::table_names()['clients'];
		$cutoff = $this->normalized_cutoff( $cutoff );
		$limit  = $this->normalized_batch_limit( $limit );
		$ids    = BoundedPruner::candidate_ids(
			'SELECT id FROM %i WHERE revoked = 1 AND updated_at < %s ORDER BY updated_at ASC, id ASC LIMIT %d',
			array( $table, $cutoff, $limit ),
			$limit
		);

		if ( false === $ids ) {
			return false;
		}

		return BoundedPruner::delete_ids(
			$table,
			$ids,
			'revoked = 1 AND updated_at < %s',
			array( $cutoff )
		);
	}

	/**
	 * Count active registrations eligible for stale-client recovery.
	 */
	public function count_stale_unused_clients(): int {
		global $wpdb;

		$tables = Installer::table_names();
		$now    = gmdate( 'Y-m-d H:i:s' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*)
				FROM %i clients
				WHERE clients.revoked = 0
				AND clients.created_at < %s
				AND clients.client_id NOT IN (
					SELECT active_tokens.client_id
					FROM %i active_tokens
					WHERE active_tokens.revoked = 0
					AND active_tokens.expires_at >= %s
				)
				AND clients.client_id NOT IN (
					SELECT refreshable_tokens.client_id
					FROM %i refreshable_tokens
					INNER JOIN %i active_refresh
						ON active_refresh.access_token_hash = refreshable_tokens.token_hash
					WHERE active_refresh.revoked = 0
					AND active_refresh.expires_at >= %s
				)
				AND clients.client_id NOT IN (
					SELECT active_codes.client_id
					FROM %i active_codes
					WHERE active_codes.revoked = 0
					AND active_codes.expires_at >= %s
				)',
				$tables['clients'],
				$this->stale_client_cutoff(),
				$tables['access_tokens'],
				$now,
				$tables['access_tokens'],
				$tables['refresh_tokens'],
				$now,
				$tables['auth_codes'],
				$now
			)
		);
	}

	/**
	 * Convert a database row into a League OAuth client entity.
	 *
	 * @param array<string, mixed> $row Client row.
	 * @return ClientEntity
	 * @throws \UnexpectedValueException When the stored client row is missing a client ID.
	 */
	private function hydrate( array $row ): ClientEntity {
		$client    = new ClientEntity();
		$client_id = trim( (string) $row['client_id'] );
		if ( '' === $client_id ) {
			throw new \UnexpectedValueException( 'OAuth client row is missing a client_id.' );
		}

		$client->setIdentifier( $client_id );
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
	 * Return only redirect hosts for safe admin diagnostics.
	 *
	 * @param array<string, mixed> $row Client row.
	 * @return string[]
	 */
	private function redirect_hosts_from_row( array $row ): array {
		$hosts = array();
		foreach ( $this->redirect_uris_from_row( $row ) as $uri ) {
			$host = wp_parse_url( $uri, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Remove matching duplicate clients that have no live credential.
	 *
	 * @param string $provider                 Provider slug.
	 * @param string $registration_fingerprint Canonical registration fingerprint.
	 * @param string $now                      UTC timestamp in Y-m-d H:i:s format.
	 * @return int Number of client rows removed.
	 */
	private function revoke_unused_duplicate_clients_by_fingerprint( string $provider, string $registration_fingerprint, string $now ): int {
		global $wpdb;

		$tables = Installer::table_names();
		$limit  = $this->normalized_batch_limit( self::DUPLICATE_CLEANUP_BATCH_SIZE );
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i
				WHERE client_id IN (
					SELECT duplicate_clients.client_id
					FROM (
						SELECT clients.client_id
						FROM %i clients
						WHERE clients.provider = %s
						AND clients.registration_fingerprint = %s
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
						)
						AND clients.client_id NOT IN (
							SELECT refreshable_tokens.client_id
							FROM %i refreshable_tokens
							INNER JOIN %i active_refresh
								ON active_refresh.access_token_hash = refreshable_tokens.token_hash
							WHERE active_refresh.revoked = 0
							AND active_refresh.expires_at >= %s
						)
						ORDER BY clients.created_at ASC
						LIMIT %d
					) duplicate_clients
				)',
				$tables['clients'],
				$tables['clients'],
				$provider,
				$registration_fingerprint,
				$tables['access_tokens'],
				$now,
				$tables['auth_codes'],
				$now,
				$tables['access_tokens'],
				$tables['refresh_tokens'],
				$now,
				$limit
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Return the UTC cutoff before which an unused registration is stale.
	 */
	private function stale_client_cutoff(): string {
		$hours = (int) apply_filters( 'aculect_ai_companion_stale_oauth_client_hours', self::STALE_CLIENT_MIN_AGE_HOURS );
		$hours = min( 24 * 30, max( 1, $hours ) );

		return gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );
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
	 * Keep maintenance writes bounded.
	 *
	 * @param int $limit Requested row limit.
	 */
	private function normalized_batch_limit( int $limit ): int {
		return min( 1000, max( 1, $limit ) );
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

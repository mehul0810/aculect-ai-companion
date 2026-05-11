<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Repositories;

use DateTimeImmutable;
use Quark\Connectors\Helpers;
use Quark\Connectors\OAuth\Database\Installer;
use Quark\Connectors\OAuth\Entities\ClientEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

final class ClientRepository implements ClientRepositoryInterface {

	public function getClientEntity( string $clientIdentifier ): ?ClientEntityInterface {
		global $wpdb;

		$table = Installer::table_names()['clients'];
		$row   = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE client_id = %s AND revoked = 0', $table, $clientIdentifier ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

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

	public function create_client( string $name, array $redirect_uris, bool $confidential = true, ?int $user_id = null ): ?array {
		global $wpdb;

		$table         = Installer::table_names()['clients'];
		$client_id     = $this->generate_client_id();
		$client_secret = $confidential ? $this->generate_client_secret() : null;
		$secret_hash   = $client_secret ? wp_hash_password( $client_secret ) : null;
		$provider      = Helpers::provider_from_client( $name, $redirect_uris );
		$encoded_uris  = wp_json_encode( array_values( $redirect_uris ) );

		if ( false === $encoded_uris ) {
			return null;
		}

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

	public function list_clients(): array {
		global $wpdb;

		$table = Installer::table_names()['clients'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE revoked = 0 ORDER BY created_at DESC LIMIT 100', $table ),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'public_row' ), $rows ) : array();
	}

	public function revoke_client( string $client_id ): void {
		global $wpdb;

		$table = Installer::table_names()['clients'];
		$wpdb->update( $table, array( 'revoked' => 1 ), array( 'client_id' => $client_id ), array( '%d' ), array( '%s' ) );
	}

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

	private function public_row( array $row ): array {
		return array(
			'client_id'     => (string) $row['client_id'],
			'client_name'   => (string) $row['client_name'],
			'provider'      => (string) ( $row['provider'] ?? 'mcp' ),
			'redirect_uris' => $this->redirect_uris_from_row( $row ),
			'created_at'    => (string) ( $row['created_at'] ?? '' ),
		);
	}

	private function redirect_uris_from_row( array $row ): array {
		$decoded = json_decode( (string) ( $row['redirect_uris'] ?? '[]' ), true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', $decoded ) ) );
	}

	private function generate_client_id(): string {
		return 'quark_dcr_' . bin2hex( random_bytes( 16 ) );
	}

	private function generate_client_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}
}

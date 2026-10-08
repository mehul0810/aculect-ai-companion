<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;

/**
 * Resolve the client identity that is safe to present on OAuth consent.
 *
 * DCR client names and stored provider attribution are self-reported metadata.
 * Only the selected, validated callback can promote a client to a known label.
 */
final class ConsentClientIdentity {

	/**
	 * Documented provider-controlled callback destinations.
	 *
	 * Paths remain exact and ports are forbidden so alternate parser forms do
	 * not inherit a trusted provider label.
	 *
	 * @var array<string, array{host: string, path: string, label: string}>
	 */
	private const TRUSTED_CALLBACKS = array(
		'chatgpt' => array(
			'host'  => 'chatgpt.com',
			'path'  => '/connector_platform_oauth_redirect',
			'label' => 'ChatGPT',
		),
		'claude'  => array(
			'host'  => 'claude.ai',
			'path'  => '/api/mcp/auth_callback',
			'label' => 'Claude',
		),
		'cursor'  => array(
			'host'  => 'www.cursor.com',
			'path'  => '/agents/mcp/oauth/callback',
			'label' => 'Cursor',
		),
	);

	private function __construct(
		private readonly string $label,
		private readonly string $declared_name,
		private readonly string $client_identifier,
		private readonly string $redirect_uri,
		private readonly bool $verified
	) {
	}

	/**
	 * Build a consent identity from the selected authorization callback.
	 *
	 * @param ClientEntity $client            Registered OAuth client.
	 * @param string       $client_identifier OAuth client identifier.
	 * @param string       $redirect_uri      Selected, validated callback.
	 */
	public static function from_authorization( ClientEntity $client, string $client_identifier, string $redirect_uri ): self {
		$trusted = self::trusted_callback( $redirect_uri );
		$name    = trim( (string) $client->getName() );

		return new self(
			null !== $trusted ? $trusted['label'] : __( 'Unverified OAuth client', 'aculect-ai-companion' ),
			'' !== $name ? $name : __( 'Not provided', 'aculect-ai-companion' ),
			$client_identifier,
			$redirect_uri,
			null !== $trusted
		);
	}

	/**
	 * Return the identity label safe for prominent consent copy.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Return the self-reported DCR client name.
	 */
	public function declared_name(): string {
		return $this->declared_name;
	}

	/**
	 * Return the OAuth client identifier.
	 */
	public function client_identifier(): string {
		return $this->client_identifier;
	}

	/**
	 * Return the selected callback shown to the approving user.
	 */
	public function redirect_uri(): string {
		return $this->redirect_uri;
	}

	/**
	 * Return whether the callback proves a known provider destination.
	 */
	public function is_verified(): bool {
		return $this->verified;
	}

	/**
	 * Resolve an exact provider-owned callback without trusting DCR metadata.
	 *
	 * @param string $redirect_uri Selected callback URI.
	 * @return array{host: string, path: string, label: string}|null
	 */
	private static function trusted_callback( string $redirect_uri ): ?array {
		$parts = wp_parse_url( $redirect_uri );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );
		if (
			'https' !== $scheme
			|| '' === $host
			|| isset( $parts['port'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| array_key_exists( 'query', $parts )
			|| array_key_exists( 'fragment', $parts )
		) {
			return null;
		}

		foreach ( self::TRUSTED_CALLBACKS as $callback ) {
			if ( hash_equals( $callback['host'], $host ) && hash_equals( $callback['path'], $path ) ) {
				return $callback;
			}
		}

		return null;
	}
}

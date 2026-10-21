<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;

/**
 * Resolve the callback destination and client details shown on OAuth consent.
 *
 * DCR client names and stored provider attribution are self-reported metadata.
 * A recognized callback identifies a return destination, not who registered
 * or initiated the client.
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
	private const RECOGNIZED_CALLBACKS = array(
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
		private readonly bool $recognized_callback
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
		$recognized = self::recognized_callback( $redirect_uri );
		$name       = trim( (string) $client->getName() );

		return new self(
			null !== $recognized ? $recognized['label'] : __( 'Unverified OAuth client', 'aculect-ai-companion' ),
			'' !== $name ? $name : __( 'Not provided', 'aculect-ai-companion' ),
			$client_identifier,
			$redirect_uri,
			null !== $recognized
		);
	}

	/**
	 * Return a recognized return-destination label or generic fallback.
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
	 * Return whether the callback matches a documented provider destination.
	 */
	public function has_recognized_callback(): bool {
		return $this->recognized_callback;
	}

	/**
	 * Recognize an exact provider-owned callback without trusting DCR metadata.
	 *
	 * @param string $redirect_uri Selected callback URI.
	 * @return array{host: string, path: string, label: string}|null
	 */
	private static function recognized_callback( string $redirect_uri ): ?array {
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

		foreach ( self::RECOGNIZED_CALLBACKS as $callback ) {
			if ( hash_equals( $callback['host'], $host ) && hash_equals( $callback['path'], $path ) ) {
				return $callback;
			}
		}

		return null;
	}
}

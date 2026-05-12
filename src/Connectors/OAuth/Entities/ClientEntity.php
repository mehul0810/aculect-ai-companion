<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Entities;

use DateTimeImmutable;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

/**
 * League OAuth client entity with Quark-specific metadata.
 */
final class ClientEntity implements ClientEntityInterface {

	use ClientTrait;
	use EntityTrait;

	private ?int $user_id                  = null;
	private ?string $client_secret_hash    = null;
	private string $provider               = 'mcp';
	private ?DateTimeImmutable $created_at = null;

	/**
	 * Set the client display name.
	 *
	 * @param string $name Client display name.
	 */
	public function setName( string $name ): void {
		$this->name = $name;
	}

	/**
	 * Set registered redirect URI values.
	 *
	 * @param string|string[] $uri Redirect URI value.
	 */
	public function setRedirectUri( $uri ): void {
		$this->redirectUri = $uri;
	}

	/**
	 * Set whether the client must authenticate with a secret.
	 *
	 * @param bool $is_confidential Whether the client is confidential.
	 */
	public function setConfidential( bool $is_confidential ): void {
		$this->isConfidential = $is_confidential;
	}

	/**
	 * Set the optional owning WordPress user ID.
	 *
	 * @param int|null $user_id WordPress user ID.
	 */
	public function setUserId( ?int $user_id ): void {
		$this->user_id = $user_id;
	}

	/**
	 * Return the optional owning WordPress user ID.
	 */
	public function getUserId(): ?int {
		return $this->user_id;
	}

	/**
	 * Set the hashed client secret.
	 *
	 * @param string|null $client_secret_hash WordPress password hash.
	 */
	public function setClientSecretHash( ?string $client_secret_hash ): void {
		$this->client_secret_hash = $client_secret_hash;
	}

	/**
	 * Return the hashed client secret.
	 */
	public function getClientSecretHash(): ?string {
		return $this->client_secret_hash;
	}

	/**
	 * Set the inferred provider slug.
	 *
	 * @param string $provider Provider slug.
	 */
	public function setProvider( string $provider ): void {
		$this->provider = $provider;
	}

	/**
	 * Return the inferred provider slug.
	 */
	public function getProvider(): string {
		return $this->provider;
	}

	/**
	 * Set the client creation time.
	 *
	 * @param DateTimeImmutable|null $created_at Creation time.
	 */
	public function setCreatedAt( ?DateTimeImmutable $created_at ): void {
		$this->created_at = $created_at;
	}

	/**
	 * Return the client creation time.
	 */
	public function getCreatedAt(): ?DateTimeImmutable {
		return $this->created_at;
	}
}

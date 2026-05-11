<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Entities;

use DateTimeImmutable;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

final class ClientEntity implements ClientEntityInterface {

	use ClientTrait;
	use EntityTrait;

	private ?int $user_id                  = null;
	private ?string $client_secret_hash    = null;
	private string $provider               = 'mcp';
	private ?DateTimeImmutable $created_at = null;

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

	public function setConfidential( bool $is_confidential ): void {
		$this->isConfidential = $is_confidential;
	}

	public function setUserId( ?int $user_id ): void {
		$this->user_id = $user_id;
	}

	public function getUserId(): ?int {
		return $this->user_id;
	}

	public function setClientSecretHash( ?string $client_secret_hash ): void {
		$this->client_secret_hash = $client_secret_hash;
	}

	public function getClientSecretHash(): ?string {
		return $this->client_secret_hash;
	}

	public function setProvider( string $provider ): void {
		$this->provider = $provider;
	}

	public function getProvider(): string {
		return $this->provider;
	}

	public function setCreatedAt( ?DateTimeImmutable $created_at ): void {
		$this->created_at = $created_at;
	}

	public function getCreatedAt(): ?DateTimeImmutable {
		return $this->created_at;
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

/**
 * Describes the outcome of one Dynamic Client Registration persistence attempt.
 */
final class ClientRegistrationResult {

	public const CREATED           = 'created';
	public const CAPACITY_EXCEEDED = 'capacity_exceeded';
	public const INVALID_METADATA  = 'invalid_metadata';
	public const STORAGE_FAILURE   = 'storage_failure';

	/**
	 * Create one immutable registration result.
	 *
	 * @param string                          $status Registration outcome.
	 * @param array<string, string|null>|null $client One-time credentials on success.
	 */
	private function __construct(
		private string $status,
		private ?array $client = null
	) {
	}

	/**
	 * Return a successful registration result.
	 *
	 * @param array<string, string|null> $client One-time credentials.
	 */
	public static function created( array $client ): self {
		return new self( self::CREATED, $client );
	}

	/**
	 * Return a capacity-exhaustion result.
	 */
	public static function capacity_exceeded(): self {
		return new self( self::CAPACITY_EXCEEDED );
	}

	/**
	 * Return an invalid-storage-metadata result.
	 */
	public static function invalid_metadata(): self {
		return new self( self::INVALID_METADATA );
	}

	/**
	 * Return a storage-failure result.
	 */
	public static function storage_failure(): self {
		return new self( self::STORAGE_FAILURE );
	}

	/**
	 * Return the stable internal outcome code.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Return one-time client credentials when registration succeeded.
	 *
	 * @return array<string, string|null>|null
	 */
	public function client(): ?array {
		return $this->client;
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\ExecutionClaims;

/**
 * Closed result of resolving one authoritative execution claim.
 */
final readonly class ExecutionClaimDecision {

	public const ACQUIRED    = 'acquired';
	public const REPLAY      = 'replay';
	public const IN_PROGRESS = 'in_progress';
	public const KEY_REUSE   = 'key_reuse';
	public const INVALID     = 'invalid';
	public const MISSING     = 'missing';
	public const UNCERTAIN   = 'uncertain';

	/**
	 * Construct one closed decision.
	 *
	 * @param string                    $type        Closed decision type.
	 * @param ExecutionClaim|null       $claim       Acquired ownership handle.
	 * @param array<string, mixed>|null $result      Authoritative replay result.
	 * @param int                       $retry_after Bounded retry delay.
	 */
	private function __construct(
		public string $type,
		private ?ExecutionClaim $claim = null,
		private ?array $result = null,
		public int $retry_after = 0
	) {}

	public static function acquired( ExecutionClaim $claim ): self {
		return new self( self::ACQUIRED, $claim );
	}

	/**
	 * Build an authoritative replay decision.
	 *
	 * @param array<string, mixed> $result Stored successful result.
	 */
	public static function replay( array $result ): self {
		$result['replayed'] = true;
		return new self( self::REPLAY, null, $result );
	}

	public static function in_progress( int $retry_after ): self {
		return new self( self::IN_PROGRESS, null, null, min( 30, max( 1, $retry_after ) ) );
	}

	public static function key_reuse(): self {
		return new self( self::KEY_REUSE );
	}

	public static function invalid(): self {
		return new self( self::INVALID );
	}

	public static function missing(): self {
		return new self( self::MISSING );
	}

	public static function uncertain(): self {
		return new self( self::UNCERTAIN );
	}

	public function claim(): ?ExecutionClaim {
		return $this->claim;
	}

	/**
	 * Return a detached replay result.
	 *
	 * @return array<string, mixed>|null
	 */
	public function result(): ?array {
		return null === $this->result ? null : self::detach( $this->result );
	}

	/**
	 * Detach a result from internal state.
	 *
	 * @param array<string, mixed> $value Result value.
	 * @return array<string, mixed>
	 */
	private static function detach( array $value ): array {
		$json = wp_json_encode( $value );
		if ( ! is_string( $json ) ) {
			return array();
		}

		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}

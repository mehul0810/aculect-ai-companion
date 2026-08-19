<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\ExecutionClaims;

/**
 * Exact database ownership proof for one claimed write execution.
 */
final readonly class ExecutionClaim {

	/**
	 * Construct an exact ownership proof.
	 *
	 * @param int    $id          Internal claim row ID.
	 * @param string $owner_token Request-local owner token. Never persist or log raw.
	 * @param int    $fence       Monotonically increasing ownership fence.
	 *
	 * @throws \InvalidArgumentException When ownership data is invalid.
	 */
	public function __construct(
		private int $id,
		private string $owner_token,
		private int $fence
	) {
		if ( 0 >= $id || 32 !== strlen( $owner_token ) || 0 >= $fence ) {
			throw new \InvalidArgumentException( 'Invalid execution claim ownership.' );
		}
	}

	public function id(): int {
		return $this->id;
	}

	public function fence(): int {
		return $this->fence;
	}

	public function owner_hash(): string {
		return hash( 'sha256', $this->owner_token );
	}
}

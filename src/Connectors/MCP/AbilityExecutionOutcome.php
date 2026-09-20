<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Transport-neutral result of one policy-preserving ability execution.
 *
 * The MCP controller maps this small closed outcome set into legacy/current
 * JSON-RPC response shapes and headers without owning execution policy.
 */
final readonly class AbilityExecutionOutcome {

	/**
	 * Create a closed, transport-neutral execution outcome.
	 *
	 * @param string               $type Outcome discriminator.
	 * @param array<string, mixed> $data Outcome-specific, transport-safe data.
	 */
	public function __construct(
		public string $type,
		public array $data = array()
	) {}

	/**
	 * Create an outcome object from internal gateway data.
	 *
	 * @param array<string, mixed> $outcome Internal gateway outcome.
	 */
	public static function from_array( array $outcome ): self {
		$type = isset( $outcome['type'] ) && is_string( $outcome['type'] ) ? $outcome['type'] : '';
		unset( $outcome['type'] );

		return new self( $type, $outcome );
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\ExecutionClaims;

/**
 * Authoritative storage boundary for one-time write execution.
 */
interface ExecutionClaimStoreInterface {

	/**
	 * Resolve or acquire a claim for the supplied hashed aliases.
	 *
	 * @param string                    $payload_hash          Exact call payload hash.
	 * @param string                    $tool_hash             Internal tool hash.
	 * @param string                    $identity_hash         Authenticated identity hash.
	 * @param string|null               $confirmation_key_hash Confirmation-token hash.
	 * @param string|null               $idempotency_key_hash  Identity-bound idempotency hash.
	 * @param bool                      $allow_create          Whether a new execution may be claimed.
	 * @param array<string, mixed>|null $legacy_result         Valid legacy completed result.
	 * @param bool                      $legacy_key_reuse      Legacy different-payload evidence.
	 * @param int                       $completed_retention   Completed-result retention seconds.
	 */
	public function claim(
		string $payload_hash,
		string $tool_hash,
		string $identity_hash,
		?string $confirmation_key_hash,
		?string $idempotency_key_hash,
		bool $allow_create,
		?array $legacy_result,
		bool $legacy_key_reuse,
		int $completed_retention
	): ExecutionClaimDecision;

	public function mark_running( ExecutionClaim $claim ): bool;

	/**
	 * Persist an authoritative successful result.
	 *
	 * @param ExecutionClaim       $claim               Exact ownership proof.
	 * @param array<string, mixed> $result              Successful tool result.
	 * @param int                  $completed_retention Completed-result retention seconds.
	 */
	public function complete( ExecutionClaim $claim, array $result, int $completed_retention ): bool;

	public function release( ExecutionClaim $claim ): bool;

	public function mark_uncertain( ExecutionClaim $claim ): bool;

	public function prune_completed( int $limit = 500 ): int|false;
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Support;

use Aculect\AICompanion\Connectors\MCP\ExecutionClaims\ExecutionClaim;
use Aculect\AICompanion\Connectors\MCP\ExecutionClaims\ExecutionClaimDecision;
use Aculect\AICompanion\Connectors\MCP\ExecutionClaims\ExecutionClaimStoreInterface;

/**
 * Deterministic claim-store test double for gateway composition tests.
 */
final class InMemoryExecutionClaimStore implements ExecutionClaimStoreInterface {

	/**
	 * Claim rows keyed by row ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = array();

	/**
	 * Alias hashes mapped to row IDs.
	 *
	 * @var array<string, int>
	 */
	private array $aliases = array();

	private int $next_id = 1;

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
	): ExecutionClaimDecision {
		unset( $tool_hash, $identity_hash, $completed_retention );
		$keys = array_values( array_filter( array( $confirmation_key_hash, $idempotency_key_hash ), 'is_string' ) );
		foreach ( $keys as $key ) {
			$id = $this->aliases[ $key ] ?? 0;
			if ( 0 >= $id ) {
				continue;
			}
			$row = $this->rows[ $id ];
			if ( ! hash_equals( (string) $row['payload_hash'], $payload_hash ) ) {
				return null !== $idempotency_key_hash && $key === $idempotency_key_hash
					? ExecutionClaimDecision::key_reuse()
					: ExecutionClaimDecision::invalid();
			}
			if ( 'completed' === $row['state'] ) {
				return ExecutionClaimDecision::replay( (array) $row['result'] );
			}
			if ( 'uncertain' === $row['state'] ) {
				return ExecutionClaimDecision::uncertain();
			}

			return ExecutionClaimDecision::in_progress( 5 );
		}

		if ( null !== $legacy_result ) {
			return ExecutionClaimDecision::replay( $legacy_result );
		}
		if ( $legacy_key_reuse ) {
			return ExecutionClaimDecision::key_reuse();
		}
		if ( ! $allow_create || array() === $keys ) {
			return ExecutionClaimDecision::missing();
		}

		$id                = $this->next_id++;
		$token             = str_repeat( chr( ( $id % 250 ) + 1 ), 32 );
		$this->rows[ $id ] = array(
			'payload_hash' => $payload_hash,
			'state'        => 'claimed',
			'result'       => array(),
		);
		foreach ( $keys as $key ) {
			$this->aliases[ $key ] = $id;
		}

		return ExecutionClaimDecision::acquired( new ExecutionClaim( $id, $token, 1 ) );
	}

	public function mark_running( ExecutionClaim $claim ): bool {
		if ( 'claimed' !== ( $this->rows[ $claim->id() ]['state'] ?? '' ) ) {
			return false;
		}
		$this->rows[ $claim->id() ]['state'] = 'running';
		return true;
	}

	public function complete( ExecutionClaim $claim, array $result, int $completed_retention ): bool {
		unset( $completed_retention );
		if ( 'running' !== ( $this->rows[ $claim->id() ]['state'] ?? '' ) ) {
			return false;
		}
		$this->rows[ $claim->id() ]['state']  = 'completed';
		$this->rows[ $claim->id() ]['result'] = $result;
		return true;
	}

	public function release( ExecutionClaim $claim ): bool {
		if ( 'running' !== ( $this->rows[ $claim->id() ]['state'] ?? '' ) ) {
			return false;
		}
		unset( $this->rows[ $claim->id() ] );
		foreach ( $this->aliases as $key => $id ) {
			if ( $claim->id() === $id ) {
				unset( $this->aliases[ $key ] );
			}
		}
		return true;
	}

	public function mark_uncertain( ExecutionClaim $claim ): bool {
		if ( 'running' !== ( $this->rows[ $claim->id() ]['state'] ?? '' ) ) {
			return false;
		}
		$this->rows[ $claim->id() ]['state'] = 'uncertain';
		return true;
	}

	public function prune_completed( int $limit = 500 ): int|false {
		unset( $limit );
		return 0;
	}
}

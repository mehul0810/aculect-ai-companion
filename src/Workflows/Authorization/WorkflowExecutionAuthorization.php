<?php
/**
 * Request-local authorization marker for workflow-owned native writes.
 *
 * @package Aculect\AICompanion\Workflows\Authorization
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Authorization;

use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;

/**
 * Proves that a nested native ability call was authorized by the workflow
 * connector, rather than by a caller-controlled authentication flag.
 *
 * The marker is deliberately an in-process object. It cannot be represented
 * in MCP JSON and is only active for the short callback that advances one
 * durable workflow step.
 */
final class WorkflowExecutionAuthorization {

	private const TTL_SECONDS = 600;

	/**
	 * Issue one short-lived request-local marker.
	 *
	 * @param string               $run_id       Durable workflow run ID.
	 * @param WorkflowPlan         $plan         Exact workflow plan.
	 * @param array<string, mixed> $auth         Authenticated request context.
	 * @return self
	 */
	public static function issue( string $run_id, WorkflowPlan $plan, array $auth ): self {
		$allowed_tools = array();
		foreach ( (array) ( $plan->identity()['steps'] ?? array() ) as $raw_step ) {
			$step = is_object( $raw_step ) ? get_object_vars( $raw_step ) : $raw_step;
			if ( ! is_array( $step ) ) {
				continue;
			}
			$ability = strtolower( (string) ( $step['ability_id'] ?? '' ) );
			if ( '' !== $ability ) {
				$allowed_tools[] = str_replace( array( '/', '-' ), array( '.', '_' ), $ability );
			}
		}

		return new self(
			$run_id,
			$plan->hash(),
			(int) ( $auth['user_id'] ?? 0 ),
			self::identity_value( $auth['client_id'] ?? '' ),
			self::identity_value( $auth['provider'] ?? 'mcp' ),
			array_values( array_unique( $allowed_tools ) ),
			time() + self::TTL_SECONDS
		);
	}

	/**
	 * Create an immutable request-local authorization marker.
	 *
	 * @param string $run_id       Durable workflow run ID.
	 * @param string $plan_hash    Exact workflow plan hash.
	 * @param int    $user_id      Authenticated user ID.
	 * @param string $client_id    Authenticated client ID.
	 * @param string $provider     Authenticated provider ID.
	 * @param array  $allowed_tools Internal ability IDs bound to the plan.
	 * @param int    $expires_at   Unix expiry timestamp.
	 * @phpstan-param list<string> $allowed_tools
	 */
	private function __construct(
		private string $run_id,
		private string $plan_hash,
		private int $user_id,
		private string $client_id,
		private string $provider,
		private array $allowed_tools,
		private int $expires_at
	) {
	}

	/**
	 * Whether this marker authorizes the exact nested native call.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<string, mixed> $auth Authenticated request context.
	 */
	public function allows( string $tool, array $auth ): bool {
		if ( time() >= $this->expires_at || $this->user_id < 1 || ! in_array( $tool, $this->allowed_tools, true ) ) {
			return false;
		}

		return (int) ( $auth['user_id'] ?? 0 ) === $this->user_id
			&& hash_equals( $this->client_id, self::identity_value( $auth['client_id'] ?? '' ) )
			&& hash_equals( $this->provider, self::identity_value( $auth['provider'] ?? 'mcp' ) );
	}

	/** Return the marker's bound run ID for diagnostics-free internal checks. */
	public function run_id(): string {
		return $this->run_id;
	}

	/** Return the marker's bound plan hash for diagnostics-free internal checks. */
	public function plan_hash(): string {
		return $this->plan_hash;
	}

	/**
	 * Normalize an identity field without retaining caller-provided payloads.
	 *
	 * @param mixed $value Candidate identity value.
	 */
	private static function identity_value( mixed $value ): string {
		return is_scalar( $value ) ? substr( trim( (string) $value ), 0, 128 ) : '';
	}
}

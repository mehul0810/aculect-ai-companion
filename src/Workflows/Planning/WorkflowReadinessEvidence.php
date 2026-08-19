<?php
/**
 * Immutable workflow readiness evidence.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Binds dependency and validation checks to one exact plan.
 *
 * Evidence contains only plan identity, requirement IDs, and missing IDs. It
 * never carries raw workflow input, step arguments, provider output, secrets,
 * or credentials.
 */
final readonly class WorkflowReadinessEvidence {

	/**
	 * Create readiness evidence.
	 *
	 * @param string      $plan_hash            Bound plan hash.
	 * @param array       $adapter_requirements Exact adapter requirements.
	 * @param array       $ability_requirements Exact ability requirements.
	 * @param array       $validation_rule_ids  Exact validation rules.
	 * @param bool        $requirements_checked Requirements checked successfully.
	 * @param bool        $validation_checked   Validation checked successfully.
	 * @param array       $missing_adapter_ids  Sorted missing adapter IDs.
	 * @param array       $missing_ability_ids  Sorted missing ability IDs.
	 * @param string|null $workflow_id     Optional exact workflow identity.
	 * @param int|null    $definition_revision Optional exact definition revision.
	 * @param string|null $definition_checksum Optional exact definition checksum.
	 * @param string|null $normalized_input_hash Optional exact normalized input hash.
	 * @throws WorkflowPlanningException When evidence identity is malformed.
	 * @phpstan-param list<mixed> $adapter_requirements
	 * @phpstan-param list<string> $ability_requirements
	 * @phpstan-param list<string> $validation_rule_ids
	 * @phpstan-param list<string> $missing_adapter_ids
	 * @phpstan-param list<string> $missing_ability_ids
	 */
	public function __construct(
		private string $plan_hash,
		private array $adapter_requirements,
		private array $ability_requirements,
		private array $validation_rule_ids,
		private bool $requirements_checked,
		private bool $validation_checked,
		private array $missing_adapter_ids = array(),
		private array $missing_ability_ids = array(),
		private ?string $workflow_id = null,
		private ?int $definition_revision = null,
		private ?string $definition_checksum = null,
		private ?string $normalized_input_hash = null
	) {
		if ( ! self::valid_hash( $plan_hash )
			|| ! self::valid_ids( $missing_adapter_ids, true )
			|| ! self::valid_ids( $missing_ability_ids, false )
			|| ! self::valid_identity( $workflow_id, $definition_revision, $definition_checksum, $normalized_input_hash )
		) {
			throw new WorkflowPlanningException( 'invalid_request', '$.readiness' );
		}
	}

	/**
	 * Build exact plan-bound readiness evidence from a pure requirement check.
	 *
	 * @param WorkflowPlan $plan                Exact immutable plan.
	 * @param array        $missing_adapter_ids Sorted missing adapter IDs.
	 * @param array        $missing_ability_ids Sorted missing ability IDs.
	 * @param bool         $validation_checked  Whether validation has been separately checked.
	 * @throws WorkflowPlanningException When evidence IDs are malformed.
	 * @phpstan-param list<string> $missing_adapter_ids
	 * @phpstan-param list<string> $missing_ability_ids
	 */
	public static function for_plan(
		WorkflowPlan $plan,
		array $missing_adapter_ids,
		array $missing_ability_ids,
		bool $validation_checked
	): self {
		$identity = $plan->identity();

		return new self(
			$plan->hash(),
			$identity['adapter_requirements'],
			$identity['ability_requirements'],
			$identity['validation_rule_ids'],
			array() === $missing_adapter_ids && array() === $missing_ability_ids,
			$validation_checked,
			$missing_adapter_ids,
			$missing_ability_ids,
			(string) $identity['workflow_id'],
			(int) $identity['definition_revision'],
			(string) $identity['definition_checksum'],
			(string) $identity['normalized_input_hash']
		);
	}

	/**
	 * Return a mismatch code or null when evidence is exact and complete.
	 *
	 * @param WorkflowPlan $plan Bound plan.
	 */
	public function binding_error_for( WorkflowPlan $plan ): ?string {
		$identity = $plan->identity();
		if ( ! hash_equals( $plan->hash(), $this->plan_hash )
			|| ( null !== $this->workflow_id
				&& ( $identity['workflow_id'] !== $this->workflow_id
					|| $identity['definition_revision'] !== $this->definition_revision
					|| ! hash_equals( $identity['definition_checksum'], (string) $this->definition_checksum )
					|| ! hash_equals( $identity['normalized_input_hash'], (string) $this->normalized_input_hash )
				)
			)
			|| ! $this->values_equal( $identity['adapter_requirements'], $this->adapter_requirements )
			|| $identity['ability_requirements'] !== $this->ability_requirements
			|| $identity['validation_rule_ids'] !== $this->validation_rule_ids
		) {
			return 'evidence_mismatch';
		}

		return null;
	}

	/**
	 * Return validation readiness error when checks are deferred.
	 *
	 * @param WorkflowPlan $plan Bound plan.
	 */
	public function validation_error_for( WorkflowPlan $plan ): ?string {
		if ( $plan->requires_validation() && ! $this->validation_checked ) {
			return 'validation_unchecked';
		}

		return null;
	}

	/**
	 * Return dependency readiness error when requirements are unchecked.
	 */
	public function requirements_error(): ?string {

		if ( ! $this->requirements_checked ) {
			return 'requirements_unchecked';
		}

		return null;
	}

	/**
	 * Return the exact bound workflow ID when evidence was built from a plan.
	 */
	public function workflow_id(): ?string {
		return $this->workflow_id;
	}

	/**
	 * Return the exact bound definition revision when available.
	 */
	public function definition_revision(): ?int {
		return $this->definition_revision;
	}

	/**
	 * Return the exact bound definition checksum when available.
	 */
	public function definition_checksum(): ?string {
		return $this->definition_checksum;
	}

	/**
	 * Return the exact bound normalized-input hash when available.
	 */
	public function normalized_input_hash(): ?string {
		return $this->normalized_input_hash;
	}

	/**
	 * Return the exact bound plan hash.
	 */
	public function plan_hash(): string {
		return $this->plan_hash;
	}

	/**
	 * Return a detached sorted missing adapter-ID list.
	 *
	 * @return list<string>
	 */
	public function missing_adapter_ids(): array {
		return $this->missing_adapter_ids;
	}

	/**
	 * Return a detached sorted missing ability-ID list.
	 *
	 * @return list<string>
	 */
	public function missing_ability_ids(): array {
		return $this->missing_ability_ids;
	}

	/**
	 * Whether all planned adapter and ability requirements are present.
	 */
	public function requirements_ready(): bool {
		return $this->requirements_checked;
	}

	/**
	 * Whether validation was separately checked or was unnecessary.
	 */
	public function validation_checked(): bool {
		return $this->validation_checked;
	}

	/**
	 * Return a detached public-safe evidence projection.
	 *
	 * @return array{workflow_id:?string,definition_revision:?int,definition_checksum:?string,normalized_input_hash:?string,plan_hash:string,missing_adapter_ids:list<string>,missing_ability_ids:list<string>,requirements_ready:bool,validation_checked:bool}
	 */
	public function to_array(): array {
		return array(
			'workflow_id'           => $this->workflow_id(),
			'definition_revision'   => $this->definition_revision(),
			'definition_checksum'   => $this->definition_checksum(),
			'normalized_input_hash' => $this->normalized_input_hash(),
			'plan_hash'             => $this->plan_hash(),
			'missing_adapter_ids'   => $this->missing_adapter_ids(),
			'missing_ability_ids'   => $this->missing_ability_ids(),
			'requirements_ready'    => $this->requirements_ready(),
			'validation_checked'    => $this->validation_checked(),
		);
	}

	/**
	 * Compare detached JSON-compatible values.
	 *
	 * @param mixed $left  First value.
	 * @param mixed $right Second value.
	 */
	private function values_equal( mixed $left, mixed $right ): bool {
		$canonicalizer = new WorkflowPlanningCanonicalizer();

		return $canonicalizer->normalize_and_encode( $left )['json'] === $canonicalizer->normalize_and_encode( $right )['json'];
	}

	/**
	 * Validate a SHA-256 hash.
	 *
	 * @param string $hash Candidate hash.
	 */
	private static function valid_hash( string $hash ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $hash );
	}

	/**
	 * Validate one sorted unique missing-ID list.
	 *
	 * @param array $ids        Candidate IDs.
	 * @param bool  $is_adapter Whether adapter rather than ability IDs are expected.
	 * @phpstan-param list<string> $ids
	 */
	private static function valid_ids( array $ids, bool $is_adapter ): bool {
		if ( ! array_is_list( $ids ) || count( $ids ) > WorkflowAvailabilitySnapshot::MAX_IDS ) {
			return false;
		}

		$seen   = array();
		$sorted = $ids;
		sort( $sorted, SORT_STRING );
		if ( $sorted !== $ids ) {
			return false;
		}

		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || isset( $seen[ $id ] ) || ! self::valid_id( $id, $is_adapter ) ) {
				return false;
			}
			$seen[ $id ] = true;
		}

		return true;
	}

	/**
	 * Validate optional all-or-nothing exact plan identity fields.
	 *
	 * @param string|null $workflow_id           Candidate workflow ID.
	 * @param int|null    $definition_revision   Candidate definition revision.
	 * @param string|null $definition_checksum   Candidate definition checksum.
	 * @param string|null $normalized_input_hash Candidate normalized input hash.
	 */
	private static function valid_identity(
		?string $workflow_id,
		?int $definition_revision,
		?string $definition_checksum,
		?string $normalized_input_hash
	): bool {
		if ( null === $workflow_id && null === $definition_revision && null === $definition_checksum && null === $normalized_input_hash ) {
			return true;
		}

		return null !== $workflow_id
			&& null !== $definition_revision
			&& null !== $definition_checksum
			&& null !== $normalized_input_hash
			&& strlen( $workflow_id ) >= 3
			&& strlen( $workflow_id ) <= 64
			&& 1 === preg_match( '/^[a-z][a-z0-9_]*$/D', $workflow_id )
			&& $definition_revision >= 1
			&& self::valid_hash( $definition_checksum )
			&& self::valid_hash( $normalized_input_hash );
	}

	/**
	 * Validate one exact definition-compatible adapter or ability ID.
	 *
	 * @param string $id         Candidate ID.
	 * @param bool   $is_adapter Whether adapter rather than ability IDs are expected.
	 */
	private static function valid_id( string $id, bool $is_adapter ): bool {
		if ( $is_adapter ) {
			return strlen( $id ) >= 2
				&& strlen( $id ) <= 64
				&& 1 === preg_match( '/^[a-z][a-z0-9_]*$/D', $id );
		}

		return strlen( $id ) <= 128
			&& 1 === preg_match( '#^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*$#D', $id );
	}
}

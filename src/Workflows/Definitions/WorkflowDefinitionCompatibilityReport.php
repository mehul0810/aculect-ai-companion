<?php
/**
 * Immutable workflow definition compatibility report.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

/**
 * Binds one deterministic compatibility decision to two exact definitions.
 */
final readonly class WorkflowDefinitionCompatibilityReport {

	public const COMPATIBLE         = 'compatible';
	public const MIGRATION_REQUIRED = 'migration_required';
	public const INCOMPATIBLE       = 'incompatible';
	private const MAX_CHANGES       = 16;

	/**
	 * Create a detached compatibility report.
	 *
	 * @param string                                                          $workflow_id           Shared workflow identifier.
	 * @param int                                                             $source_schema_version Source schema version.
	 * @param int                                                             $target_schema_version Target schema version.
	 * @param int                                                             $source_revision       Source workflow revision.
	 * @param int                                                             $target_revision       Target workflow revision.
	 * @param string                                                          $source_checksum       Source definition checksum.
	 * @param string                                                          $target_checksum       Target definition checksum.
	 * @param string                                                          $classification        Overall compatibility class.
	 * @param list<array{code: string, path: string, classification: string}> $changes              Bounded change records.
	 * @throws WorkflowDefinitionValidationException When report data violates the bounded contract.
	 */
	public function __construct(
		private string $workflow_id,
		private int $source_schema_version,
		private int $target_schema_version,
		private int $source_revision,
		private int $target_revision,
		private string $source_checksum,
		private string $target_checksum,
		private string $classification,
		private array $changes
	) {
		if (
			1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/', $workflow_id )
			|| $source_schema_version < 1
			|| $target_schema_version < 1
			|| $target_revision <= $source_revision
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $source_checksum )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $target_checksum )
			|| count( $changes ) > self::MAX_CHANGES
		) {
			throw new WorkflowDefinitionValidationException( 'invalid_compatibility_binding', '$' );
		}

		$expected_classification = self::COMPATIBLE;
		$previous_sort_key       = '';
		foreach ( $changes as $change ) {
			if (
				1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $change['code'] )
				|| 1 !== preg_match( '/^\$\.[a-z0-9_.]{1,62}$/', $change['path'] )
				|| ! in_array( $change['classification'], self::classifications(), true )
			) {
				throw new WorkflowDefinitionValidationException( 'invalid_compatibility_change', '$.changes' );
			}

			$sort_key = $change['path'] . "\0" . $change['code'];
			if ( '' !== $previous_sort_key && $sort_key <= $previous_sort_key ) {
				throw new WorkflowDefinitionValidationException( 'invalid_compatibility_order', '$.changes' );
			}
			$previous_sort_key = $sort_key;

			if ( self::INCOMPATIBLE === $change['classification'] ) {
				$expected_classification = self::INCOMPATIBLE;
			} elseif ( self::MIGRATION_REQUIRED === $change['classification'] && self::INCOMPATIBLE !== $expected_classification ) {
				$expected_classification = self::MIGRATION_REQUIRED;
			}
		}

		if ( $classification !== $expected_classification ) {
			throw new WorkflowDefinitionValidationException( 'invalid_compatibility_class', '$.classification' );
		}
	}

	public function workflow_id(): string {
		return $this->workflow_id;
	}

	public function source_schema_version(): int {
		return $this->source_schema_version;
	}

	public function target_schema_version(): int {
		return $this->target_schema_version;
	}

	public function source_revision(): int {
		return $this->source_revision;
	}

	public function target_revision(): int {
		return $this->target_revision;
	}

	public function source_checksum(): string {
		return $this->source_checksum;
	}

	public function target_checksum(): string {
		return $this->target_checksum;
	}

	public function classification(): string {
		return $this->classification;
	}

	/**
	 * Return detached bounded change records.
	 *
	 * @return list<array{code: string, path: string, classification: string}>
	 */
	public function changes(): array {
		return array_map(
			static fn ( array $change ): array => $change,
			$this->changes
		);
	}

	/**
	 * Return a detached serializable report.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'workflow_id'           => $this->workflow_id,
			'source_schema_version' => $this->source_schema_version,
			'target_schema_version' => $this->target_schema_version,
			'source_revision'       => $this->source_revision,
			'target_revision'       => $this->target_revision,
			'source_checksum'       => $this->source_checksum,
			'target_checksum'       => $this->target_checksum,
			'classification'        => $this->classification,
			'changes'               => $this->changes(),
		);
	}

	/**
	 * Return supported classification values in increasing severity order.
	 *
	 * @return list<string>
	 */
	private static function classifications(): array {
		return array( self::COMPATIBLE, self::MIGRATION_REQUIRED, self::INCOMPATIBLE );
	}
}

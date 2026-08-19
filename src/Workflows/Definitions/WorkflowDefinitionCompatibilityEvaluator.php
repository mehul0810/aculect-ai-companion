<?php
/**
 * Pure workflow definition compatibility evaluator.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use JsonException;
use stdClass;

/**
 * Produces bounded, value-free compatibility evidence for two definitions.
 */
final class WorkflowDefinitionCompatibilityEvaluator {

	/**
	 * Compare an earlier definition with a later revision.
	 *
	 * @param WorkflowDefinition $source Earlier validated definition.
	 * @param WorkflowDefinition $target Later validated definition.
	 * @throws WorkflowDefinitionValidationException When definitions cannot be compared safely.
	 */
	public function evaluate(
		WorkflowDefinition $source,
		WorkflowDefinition $target
	): WorkflowDefinitionCompatibilityReport {
		return WorkflowDefinitionCompatibilityReport::from_definitions( $source, $target );
	}

	/**
	 * Derive the private report data for the report factory.
	 *
	 * @internal The immutable report is the only production consumer.
	 *
	 * @param WorkflowDefinition $source Earlier validated definition.
	 * @param WorkflowDefinition $target Later validated definition.
	 * @return array{
	 *     workflow_id: string,
	 *     source_schema_version: int,
	 *     target_schema_version: int,
	 *     source_revision: int,
	 *     target_revision: int,
	 *     source_checksum: string,
	 *     target_checksum: string,
	 *     classification: string,
	 *     changes: list<array{code: string, path: string, classification: string}>
	 * }
	 * @throws WorkflowDefinitionValidationException When definitions cannot be compared safely.
	 */
	public function derive_report_data( WorkflowDefinition $source, WorkflowDefinition $target ): array {
		$source_value = $source->to_array();
		$target_value = $target->to_array();

		$this->assert_comparable( $source_value, $target_value );

		$changes = array();
		$this->add_change(
			$changes,
			'workflow_revision_advanced',
			'$.workflow_version',
			WorkflowDefinitionCompatibilityReport::COMPATIBLE
		);

		$this->compare_compatible_metadata( $source_value, $target_value, $changes );
		$this->compare_input_schema( $source_value['input_schema'], $target_value['input_schema'], $changes );

		$this->compare_field(
			$source_value,
			$target_value,
			'content_target',
			'content_target_changed',
			WorkflowDefinitionCompatibilityReport::MIGRATION_REQUIRED,
			$changes
		);
		$this->compare_field(
			$source_value,
			$target_value,
			'steps',
			'step_graph_changed',
			WorkflowDefinitionCompatibilityReport::MIGRATION_REQUIRED,
			$changes
		);
		$this->compare_field(
			$source_value,
			$target_value,
			'allowed_abilities',
			'allowed_abilities_changed',
			WorkflowDefinitionCompatibilityReport::MIGRATION_REQUIRED,
			$changes
		);
		$this->compare_field(
			$source_value,
			$target_value,
			'output_contract',
			'output_contract_changed',
			WorkflowDefinitionCompatibilityReport::MIGRATION_REQUIRED,
			$changes
		);
		$this->compare_field(
			$source_value,
			$target_value,
			'validation_rules',
			'validation_rules_changed',
			WorkflowDefinitionCompatibilityReport::MIGRATION_REQUIRED,
			$changes
		);
		$this->compare_field(
			$source_value,
			$target_value,
			'compatibility',
			'contract_versions_changed',
			WorkflowDefinitionCompatibilityReport::MIGRATION_REQUIRED,
			$changes
		);

		$this->compare_field(
			$source_value,
			$target_value,
			'write_policy',
			'write_policy_changed',
			WorkflowDefinitionCompatibilityReport::INCOMPATIBLE,
			$changes
		);
		$this->compare_field(
			$source_value,
			$target_value,
			'approval_gates',
			'approval_gates_changed',
			WorkflowDefinitionCompatibilityReport::INCOMPATIBLE,
			$changes
		);
		$this->compare_field(
			$source_value,
			$target_value,
			'status',
			'publication_status_changed',
			WorkflowDefinitionCompatibilityReport::INCOMPATIBLE,
			$changes
		);
		$this->compare_field(
			$source_value,
			$target_value,
			'created_by',
			'creation_identity_changed',
			WorkflowDefinitionCompatibilityReport::INCOMPATIBLE,
			$changes
		);

		usort(
			$changes,
			static fn ( array $left, array $right ): int => array( $left['path'], $left['code'] ) <=> array( $right['path'], $right['code'] )
		);

		return array(
			'workflow_id'           => $source_value['workflow_id'],
			'source_schema_version' => $source_value['definition_schema_version'],
			'target_schema_version' => $target_value['definition_schema_version'],
			'source_revision'       => $source_value['workflow_version'],
			'target_revision'       => $target_value['workflow_version'],
			'source_checksum'       => $source->checksum(),
			'target_checksum'       => $target->checksum(),
			'classification'        => $this->overall_classification( $changes ),
			'changes'               => $changes,
		);
	}

	/**
	 * Fail closed when two definitions do not form one advancing revision pair.
	 *
	 * @param array<string, mixed> $source Source definition.
	 * @param array<string, mixed> $target Target definition.
	 * @throws WorkflowDefinitionValidationException When identities or revisions cannot be compared.
	 */
	private function assert_comparable( array $source, array $target ): void {
		if ( $source['workflow_id'] !== $target['workflow_id'] ) {
			throw new WorkflowDefinitionValidationException( 'workflow_id_mismatch', '$.workflow_id' );
		}
		if ( $source['definition_schema_version'] !== $target['definition_schema_version'] ) {
			throw new WorkflowDefinitionValidationException( 'schema_version_mismatch', '$.definition_schema_version' );
		}
		if ( $target['workflow_version'] <= $source['workflow_version'] ) {
			throw new WorkflowDefinitionValidationException( 'non_advancing_revision', '$.workflow_version' );
		}
	}

	/**
	 * Compare explicitly safe descriptive metadata.
	 *
	 * @param array<string, mixed>                                            $source  Source definition.
	 * @param array<string, mixed>                                            $target  Target definition.
	 * @param list<array{code: string, path: string, classification: string}> $changes Change records.
	 */
	private function compare_compatible_metadata( array $source, array $target, array &$changes ): void {
		$this->compare_field(
			$source,
			$target,
			'name',
			'name_changed',
			WorkflowDefinitionCompatibilityReport::COMPATIBLE,
			$changes
		);
		$this->compare_field(
			$source,
			$target,
			'description',
			'description_changed',
			WorkflowDefinitionCompatibilityReport::COMPATIBLE,
			$changes
		);
		$this->compare_field(
			$source,
			$target,
			'updated_by',
			'update_identity_changed',
			WorkflowDefinitionCompatibilityReport::COMPATIBLE,
			$changes
		);
	}

	/**
	 * Compare input contracts, recognizing only additive optional properties as safe.
	 *
	 * @param mixed                                                           $source_schema Source input schema.
	 * @param mixed                                                           $target_schema Target input schema.
	 * @param list<array{code: string, path: string, classification: string}> $changes      Change records.
	 */
	private function compare_input_schema( mixed $source_schema, mixed $target_schema, array &$changes ): void {
		if ( $this->same( $source_schema, $target_schema ) ) {
			return;
		}

		if ( $this->only_adds_optional_input_properties( $source_schema, $target_schema ) ) {
			$this->add_change(
				$changes,
				'optional_input_added',
				'$.input_schema.properties',
				WorkflowDefinitionCompatibilityReport::COMPATIBLE
			);

			return;
		}

		$source_map      = $this->map_value( $source_schema );
		$target_map      = $this->map_value( $target_schema );
		$source_required = $this->sorted_strings( $source_map['required'] ?? array() );
		$target_required = $this->sorted_strings( $target_map['required'] ?? array() );
		$code            = $source_required === $target_required ? 'input_schema_changed' : 'required_input_changed';

		$this->add_change(
			$changes,
			$code,
			'$.input_schema',
			WorkflowDefinitionCompatibilityReport::MIGRATION_REQUIRED
		);
	}

	/**
	 * Determine whether the only schema change is one or more optional properties.
	 *
	 * @param mixed $source_schema Source input schema.
	 * @param mixed $target_schema Target input schema.
	 */
	private function only_adds_optional_input_properties( mixed $source_schema, mixed $target_schema ): bool {
		$source = $this->map_value( $source_schema );
		$target = $this->map_value( $target_schema );

		$source_properties = $this->property_entries( $source['properties'] ?? array() );
		$target_properties = $this->property_entries( $target['properties'] ?? array() );
		$source_required   = $this->sorted_strings( $source['required'] ?? array() );
		$target_required   = $this->sorted_strings( $target['required'] ?? array() );

		unset( $source['properties'], $target['properties'] );
		if ( ! $this->same( $source, $target ) || $source_required !== $target_required ) {
			return false;
		}

		$added = 0;
		foreach ( $source_properties as $source_property ) {
			$target_property = $this->find_property( $target_properties, $source_property['name'] );
			if ( null === $target_property || ! $this->same( $source_property['schema'], $target_property['schema'] ) ) {
				return false;
			}
		}

		foreach ( $target_properties as $target_property ) {
			if ( null !== $this->find_property( $source_properties, $target_property['name'] ) ) {
				continue;
			}
			if ( in_array( $target_property['name'], $target_required, true ) ) {
				return false;
			}
			++$added;
		}

		return $added > 0;
	}

	/**
	 * Compare one exact top-level field.
	 *
	 * @param array<string, mixed>                                            $source         Source definition.
	 * @param array<string, mixed>                                            $target         Target definition.
	 * @param string                                                          $field          Field name.
	 * @param string                                                          $code           Stable change code.
	 * @param string                                                          $classification Compatibility class.
	 * @param list<array{code: string, path: string, classification: string}> $changes        Change records.
	 */
	private function compare_field(
		array $source,
		array $target,
		string $field,
		string $code,
		string $classification,
		array &$changes
	): void {
		if ( $this->same( $source[ $field ], $target[ $field ] ) ) {
			return;
		}

		$this->add_change( $changes, $code, '$.' . $field, $classification );
	}

	/**
	 * Add one fixed, value-free change record.
	 *
	 * @param list<array{code: string, path: string, classification: string}> $changes        Change records.
	 * @param string                                                          $code           Stable code.
	 * @param string                                                          $path           Fixed schema path.
	 * @param string                                                          $classification Compatibility class.
	 */
	private function add_change( array &$changes, string $code, string $path, string $classification ): void {
		$changes[] = array(
			'code'           => $code,
			'path'           => $path,
			'classification' => $classification,
		);
	}

	/**
	 * Return the most restrictive classification represented by the changes.
	 *
	 * @param list<array{code: string, path: string, classification: string}> $changes Change records.
	 */
	private function overall_classification( array $changes ): string {
		$classification = WorkflowDefinitionCompatibilityReport::COMPATIBLE;
		foreach ( $changes as $change ) {
			if ( WorkflowDefinitionCompatibilityReport::INCOMPATIBLE === $change['classification'] ) {
				return WorkflowDefinitionCompatibilityReport::INCOMPATIBLE;
			}
			if ( WorkflowDefinitionCompatibilityReport::MIGRATION_REQUIRED === $change['classification'] ) {
				$classification = WorkflowDefinitionCompatibilityReport::MIGRATION_REQUIRED;
			}
		}

		return $classification;
	}

	/**
	 * Compare normalized JSON-compatible values without collapsing object/list semantics.
	 *
	 * @param mixed $left  Left value.
	 * @param mixed $right Right value.
	 * @throws WorkflowDefinitionValidationException When a validated invariant cannot be encoded.
	 */
	private function same( mixed $left, mixed $right ): bool {
		try {
			return json_encode( $left, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact object/list comparison requires native throwing JSON.
				=== json_encode( $right, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact object/list comparison requires native throwing JSON.
		} catch ( JsonException ) {
			throw new WorkflowDefinitionValidationException( 'comparison_failed', '$' );
		}
	}

	/**
	 * Return a validated schema map as an array.
	 *
	 * @param mixed $value Validated map value.
	 * @return array<string, mixed>
	 */
	private function map_value( mixed $value ): array {
		if ( $value instanceof stdClass ) {
			return get_object_vars( $value );
		}

		/* @var array<string, mixed> $value */
		return $value;
	}

	/**
	 * Return property entries without coercing numeric JSON member names.
	 *
	 * @param mixed $properties Validated property map.
	 * @return list<array{name: string, schema: mixed}>
	 */
	private function property_entries( mixed $properties ): array {
		$entries = array();
		foreach ( $properties as $name => $schema ) {
			$entries[] = array(
				'name'   => (string) $name,
				'schema' => $schema,
			);
		}

		return $entries;
	}

	/**
	 * Find one property entry by exact name.
	 *
	 * @param list<array{name: string, schema: mixed}> $properties Property entries.
	 * @param string                                   $name       Exact property name.
	 * @return array{name: string, schema: mixed}|null
	 */
	private function find_property( array $properties, string $name ): ?array {
		foreach ( $properties as $property ) {
			if ( $property['name'] === $name ) {
				return $property;
			}
		}

		return null;
	}

	/**
	 * Return a sorted string list from a validated schema keyword.
	 *
	 * @param mixed $values Validated string list.
	 * @return list<string>
	 */
	private function sorted_strings( mixed $values ): array {
		/**
		 * Validated string list.
		 *
		 * @var list<string> $sorted
		 */
		$sorted = $values;
		sort( $sorted, SORT_STRING );

		return $sorted;
	}
}

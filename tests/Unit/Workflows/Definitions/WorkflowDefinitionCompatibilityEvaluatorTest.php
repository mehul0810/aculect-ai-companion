<?php
/**
 * Workflow definition compatibility evaluator tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Definitions;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionCompatibilityEvaluator;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionCompatibilityReport;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies reports are deterministic, exact-bound, and value-free.
 */
final class WorkflowDefinitionCompatibilityEvaluatorTest extends TestCase {

	public function test_safe_metadata_and_optional_input_addition_are_compatible(): void {
		$source = $this->fixture();
		$target = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['name']        = 'Renamed private workflow';
				$value['description'] = 'Updated private description.';
				$value['updated_by']  = 19;
				$properties           = self::schema_properties( $value['input_schema'] );
				$properties->summary  = (object) array(
					'type'      => 'string',
					'maxLength' => 200,
				);
			}
		);

		$report = ( new WorkflowDefinitionCompatibilityEvaluator() )->evaluate( $source, $target );

		self::assertSame( WorkflowDefinitionCompatibilityReport::COMPATIBLE, $report->classification() );
		self::assertSame(
			array( 'description_changed', 'optional_input_added', 'name_changed', 'update_identity_changed', 'workflow_revision_advanced' ),
			array_column( $report->changes(), 'code' )
		);
		self::assertSame( $source->checksum(), $report->source_checksum() );
		self::assertSame( $target->checksum(), $report->target_checksum() );
		self::assertSame( 3, $report->source_revision() );
		self::assertSame( 4, $report->target_revision() );
	}

	/**
	 * Verify one migration-requiring field category.
	 *
	 * @param callable $mutate        Target mutation.
	 * @param string   $expected_code Expected change code.
	 */
	#[DataProvider( 'migration_changes' )]
	public function test_structural_changes_require_migration( callable $mutate, string $expected_code ): void {
		$source = $this->fixture();
		$target = $this->target( $source, $mutate );
		$report = ( new WorkflowDefinitionCompatibilityEvaluator() )->evaluate( $source, $target );

		self::assertSame( WorkflowDefinitionCompatibilityReport::MIGRATION_REQUIRED, $report->classification() );
		self::assertContains( $expected_code, array_column( $report->changes(), 'code' ) );
	}

	/**
	 * Return migration-requiring definition mutations.
	 *
	 * @return iterable<string, array{callable(array<string, mixed>&): void, string}>
	 */
	public static function migration_changes(): iterable {
		yield 'content target' => array(
			static function ( array &$value ): void {
				$value['content_target']->mode = 'either';
			},
			'content_target_changed',
		);
		yield 'step adapter' => array(
			static function ( array &$value ): void {
				$value['steps'][0]->adapter_version = 3;
			},
			'step_graph_changed',
		);
		yield 'allowed abilities' => array(
			static function ( array &$value ): void {
				$value['allowed_abilities'][] = 'content/list-items';
			},
			'allowed_abilities_changed',
		);
		yield 'required input' => array(
			static function ( array &$value ): void {
				self::schema_properties( $value['input_schema'] )->locale = (object) array( 'type' => 'string' );
				$value['input_schema']->required[]                         = 'locale';
			},
			'required_input_changed',
		);
		yield 'existing input validation' => array(
			static function ( array &$value ): void {
				self::schema_properties( $value['input_schema'] )->brief->maxLength = 2000;
			},
			'input_schema_changed',
		);
		yield 'output contract' => array(
			static function ( array &$value ): void {
				self::schema_properties( $value['output_contract'] )->summary = (object) array( 'type' => 'string' );
			},
			'output_contract_changed',
		);
		yield 'validation rules' => array(
			static function ( array &$value ): void {
				$value['validation_rules'][] = (object) array(
					'rule_id'  => 'require_excerpt',
					'severity' => 'warning',
				);
			},
			'validation_rules_changed',
		);
		yield 'contract versions' => array(
			static function ( array &$value ): void {
				$value['compatibility']->input_contract_version = 3;
			},
			'contract_versions_changed',
		);
	}

	/**
	 * Verify one incompatible field category.
	 *
	 * @param callable $mutate        Target mutation.
	 * @param string   $expected_code Expected change code.
	 */
	#[DataProvider( 'incompatible_changes' )]
	public function test_safety_and_identity_changes_are_incompatible( callable $mutate, string $expected_code ): void {
		$source = $this->fixture();
		$target = $this->target( $source, $mutate );
		$report = ( new WorkflowDefinitionCompatibilityEvaluator() )->evaluate( $source, $target );

		self::assertSame( WorkflowDefinitionCompatibilityReport::INCOMPATIBLE, $report->classification() );
		self::assertContains( $expected_code, array_column( $report->changes(), 'code' ) );
	}

	/**
	 * Return incompatible definition mutations.
	 *
	 * @return iterable<string, array{callable(array<string, mixed>&): void, string}>
	 */
	public static function incompatible_changes(): iterable {
		yield 'write policy' => array(
			static function ( array &$value ): void {
				$value['write_policy']->mode = 'approved_update';
			},
			'write_policy_changed',
		);
		yield 'publication status' => array(
			static function ( array &$value ): void {
				$value['status'] = 'draft';
			},
			'publication_status_changed',
		);
		yield 'creation identity' => array(
			static function ( array &$value ): void {
				$value['created_by'] = 27;
			},
			'creation_identity_changed',
		);
		yield 'approval gates and second write' => array(
			static function ( array &$value ): void {
				$value['steps'][] = (object) array(
					'step_id'         => 'update_draft',
					'adapter_id'      => 'wordpress',
					'adapter_version' => 1,
					'ability_id'      => 'content/update-item',
					'kind'            => 'write',
					'arguments'       => new stdClass(),
					'depends_on'      => array( 'create_draft' ),
				);
				$value['allowed_abilities'][] = 'content/update-item';
				$value['approval_gates'][]    = 'update_draft';
			},
			'approval_gates_changed',
		);
	}

	public function test_change_order_is_deterministic_and_contains_no_definition_values(): void {
		$source = $this->fixture();
		$target = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['description']                = 'SECRET_MARKER_DESCRIPTION';
				$value['allowed_abilities'][]        = 'secret/private-ability';
				$value['write_policy']->mode         = 'approved_update';
				$value['content_target']->post_types = array_reverse( $value['content_target']->post_types );
			}
		);

		$evaluator = new WorkflowDefinitionCompatibilityEvaluator();
		$first     = $evaluator->evaluate( $source, $target );
		$second    = $evaluator->evaluate( $source, $target );
		$encoded   = json_encode( $first->changes(), JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact test serialization.

		self::assertSame( $first->to_array(), $second->to_array() );
		self::assertStringNotContainsString( 'SECRET_MARKER_DESCRIPTION', $encoded );
		self::assertStringNotContainsString( 'secret/private-ability', $encoded );
		self::assertLessThanOrEqual( 16, count( $first->changes() ) );
		self::assertSame( $first->changes(), $this->sorted_changes( $first->changes() ) );
	}

	public function test_report_values_are_detached(): void {
		$source  = $this->fixture();
		$target  = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['name'] = 'New name';
			}
		);
		$report  = ( new WorkflowDefinitionCompatibilityEvaluator() )->evaluate( $source, $target );
		$changes = $report->changes();
		$array   = $report->to_array();

		$changes[0]['code']          = 'mutated';
		$array['changes'][0]['path'] = '$.mutated';

		self::assertNotSame( 'mutated', $report->changes()[0]['code'] );
		self::assertNotSame( '$.mutated', $report->to_array()['changes'][0]['path'] );
	}

	public function test_report_rejects_classification_that_does_not_match_changes(): void {
		$this->expect_compatibility_error( 'invalid_compatibility_class' );

		new WorkflowDefinitionCompatibilityReport(
			'sample_workflow',
			1,
			1,
			1,
			2,
			str_repeat( 'a', 64 ),
			str_repeat( 'b', 64 ),
			WorkflowDefinitionCompatibilityReport::COMPATIBLE,
			array(
				array(
					'code'           => 'write_policy_changed',
					'path'           => '$.write_policy',
					'classification' => WorkflowDefinitionCompatibilityReport::INCOMPATIBLE,
				),
			)
		);
	}

	public function test_workflow_id_mismatch_fails_closed(): void {
		$source = $this->fixture();
		$target = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['workflow_id'] = 'different_workflow';
			}
		);

		$this->expect_compatibility_error( 'workflow_id_mismatch' );
		( new WorkflowDefinitionCompatibilityEvaluator() )->evaluate( $source, $target );
	}

	#[DataProvider( 'non_advancing_revisions' )]
	public function test_non_advancing_revision_fails_closed( int $target_revision ): void {
		$source                    = $this->fixture();
		$value                     = $source->to_array();
		$value['workflow_version'] = $target_revision;
		$target                    = WorkflowDefinition::from_array( $value );

		$this->expect_compatibility_error( 'non_advancing_revision' );
		( new WorkflowDefinitionCompatibilityEvaluator() )->evaluate( $source, $target );
	}

	/**
	 * Return non-advancing target revisions.
	 *
	 * @return iterable<string, array{int}>
	 */
	public static function non_advancing_revisions(): iterable {
		yield 'equal' => array( 3 );
		yield 'older' => array( 2 );
	}

	public function test_definition_layer_has_no_wordpress_or_runtime_dependency(): void {
		$files  = array(
			dirname( __DIR__, 4 ) . '/src/Workflows/Definitions/WorkflowDefinitionCompatibilityEvaluator.php',
			dirname( __DIR__, 4 ) . '/src/Workflows/Definitions/WorkflowDefinitionCompatibilityReport.php',
		);
		$source = '';
		foreach ( $files as $file ) {
			$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Source architecture assertion.
			self::assertNotFalse( $contents );
			$source .= $contents;
		}

		self::assertDoesNotMatchRegularExpression(
			'/\b(?:wpdb|add_action|add_filter|get_option|update_option|McpController|WorkflowRegistry|WorkflowRun)\b/',
			$source
		);
	}

	private function fixture(): WorkflowDefinition {
		$json = file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/ordered-multi-step-v1.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned fixture.
		self::assertNotFalse( $json );

		return WorkflowDefinition::from_json( $json );
	}

	/**
	 * Build a valid later revision from one mutation.
	 *
	 * @param WorkflowDefinition $source Source definition.
	 * @param callable           $mutate Target mutation.
	 */
	private function target( WorkflowDefinition $source, callable $mutate ): WorkflowDefinition {
		$value = $source->to_array();
		++$value['workflow_version'];
		$mutate( $value );

		return WorkflowDefinition::from_array( $value );
	}

	/**
	 * Return the properties object from a validated root schema.
	 *
	 * @param mixed $schema Root schema.
	 */
	private static function schema_properties( mixed $schema ): stdClass {
		self::assertInstanceOf( stdClass::class, $schema );
		self::assertInstanceOf( stdClass::class, $schema->properties );

		return $schema->properties;
	}

	/**
	 * Return changes sorted by the report contract.
	 *
	 * @param list<array{code: string, path: string, classification: string}> $changes Changes.
	 * @return list<array{code: string, path: string, classification: string}>
	 */
	private function sorted_changes( array $changes ): array {
		usort(
			$changes,
			static fn ( array $left, array $right ): int => array( $left['path'], $left['code'] ) <=> array( $right['path'], $right['code'] )
		);

		return $changes;
	}

	/**
	 * Configure one expected compatibility failure.
	 *
	 * @param string $error_code Expected stable code.
	 */
	private function expect_compatibility_error( string $error_code ): void {
		$this->expectException( WorkflowDefinitionValidationException::class );
		$this->expectExceptionMessage( $error_code );
	}
}

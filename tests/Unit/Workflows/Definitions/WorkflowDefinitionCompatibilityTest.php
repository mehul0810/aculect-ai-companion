<?php
/**
 * Workflow definition compatibility metadata and support-policy tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Definitions;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionCompatibilityMetadata;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionSchemaSupport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies metadata remains detached, deterministic, and version truthful.
 */
final class WorkflowDefinitionCompatibilityTest extends TestCase {

	public function test_metadata_is_sorted_grouped_and_detached(): void {
		$definition = $this->fixture( 'ordered-multi-step-v1.json' );
		$metadata   = ( new WorkflowDefinitionCompatibilityMetadata() )->for_definition( $definition );

		self::assertSame(
			array(
				'definition_schema_version' => 1,
				'workflow_id'               => 'ordered_multi_step_fixture',
				'workflow_version'          => 3,
				'checksum'                  => '80cf306c70c9c905d15f719e73235a1d1d85f989eee7142c866532c1cfa53037',
				'input_contract_version'    => 2,
				'output_contract_version'   => 3,
				'allowed_abilities'         => array(
					'content/create-draft',
					'content/get-item',
					'content/prepare-draft',
				),
				'adapter_requirements'      => array(
					array(
						'adapter_id'       => 'content_planner',
						'adapter_versions' => array( 1 ),
					),
					array(
						'adapter_id'       => 'wordpress',
						'adapter_versions' => array( 1, 2 ),
					),
				),
			),
			$metadata
		);

		$metadata['allowed_abilities'][0]                          = 'mutated/value';
		$metadata['adapter_requirements'][0]['adapter_versions'][] = 99;

		self::assertSame(
			'content/create-draft',
			( new WorkflowDefinitionCompatibilityMetadata() )->for_definition( $definition )['allowed_abilities'][0]
		);
		self::assertSame(
			array( 1 ),
			( new WorkflowDefinitionCompatibilityMetadata() )->for_definition( $definition )['adapter_requirements'][0]['adapter_versions']
		);
	}

	public function test_equivalent_definition_order_has_identical_metadata(): void {
		$definition                 = $this->fixture( 'ordered-multi-step-v1.json' );
		$value                      = $definition->to_array();
		$value['allowed_abilities'] = array_reverse( $value['allowed_abilities'] );

		$reordered = WorkflowDefinition::from_array( $value );
		$first     = ( new WorkflowDefinitionCompatibilityMetadata() )->for_definition( $definition );
		$second    = ( new WorkflowDefinitionCompatibilityMetadata() )->for_definition( $reordered );

		self::assertNotSame( $first['checksum'], $second['checksum'], 'Definition list order remains checksum-significant.' );
		unset( $first['checksum'], $second['checksum'] );
		self::assertSame( $first, $second, 'Compatibility requirements remain order-independent.' );
	}

	public function test_current_v1_support_does_not_invent_v0(): void {
		$support = new WorkflowDefinitionSchemaSupport();

		self::assertSame( 1, $support->current() );
		self::assertNull( $support->previous() );
		self::assertSame( array( 1 ), $support->supported_versions() );
		self::assertSame( WorkflowDefinitionSchemaSupport::CURRENT, $support->classify( 1 ) );
		self::assertSame( WorkflowDefinitionSchemaSupport::UNSUPPORTED_NEWER, $support->classify( 2 ) );
		self::assertFalse( $support->supports( 0 ) );
		self::assertTrue( $support->supports( 1 ) );
		self::assertFalse( $support->supports( 0 ) );
	}

	public function test_synthetic_v2_supports_exactly_current_and_previous(): void {
		$support = new WorkflowDefinitionSchemaSupport( 2 );

		self::assertSame( 1, $support->previous() );
		self::assertSame( array( 2, 1 ), $support->supported_versions() );
		self::assertSame( WorkflowDefinitionSchemaSupport::CURRENT, $support->classify( 2 ) );
		self::assertSame( WorkflowDefinitionSchemaSupport::PREVIOUS, $support->classify( 1 ) );
		self::assertSame( WorkflowDefinitionSchemaSupport::UNSUPPORTED_NEWER, $support->classify( 3 ) );
	}

	public function test_support_policy_rejects_non_positive_current_version(): void {
		$this->expectException( InvalidArgumentException::class );

		new WorkflowDefinitionSchemaSupport( 0 );
	}

	public function test_support_policy_rejects_non_positive_candidate_version(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new WorkflowDefinitionSchemaSupport() )->classify( 0 );
	}

	/**
	 * Load one repository-owned fixture through the production value boundary.
	 *
	 * @param string $name Fixture basename.
	 */
	private function fixture( string $name ): WorkflowDefinition {
		$json = file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned local fixture.
		self::assertNotFalse( $json );

		return WorkflowDefinition::from_json( $json );
	}
}

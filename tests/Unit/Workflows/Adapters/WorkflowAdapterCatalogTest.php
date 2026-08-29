<?php
/**
 * Tests for the closed native workflow adapter catalog.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Adapters
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Adapters;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Workflows\Adapters\NativeAbilityWorkflowAdapter;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterCatalog;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterDescriptor;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterRegistry;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Proves adapters declare bounded contracts and reuse native ability policy.
 */
final class WorkflowAdapterCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		AbilitiesRegistry::reset_module_cache();
	}

	public function test_catalog_declares_content_seo_media_and_discovery_contracts(): void {
		$descriptors = WorkflowAdapterCatalog::descriptors();
		$by_key      = array();
		foreach ( $descriptors as $descriptor ) {
			self::assertInstanceOf( WorkflowAdapterDescriptor::class, $descriptor );
			self::assertNotSame( '', $descriptor->adapter_id() );
			self::assertGreaterThanOrEqual( 1, $descriptor->adapter_version() );
			self::assertContains( $descriptor->kind(), array( 'read', 'proposal', 'write' ) );
			self::assertSame( 'object', $descriptor->input_schema()['type'] ?? null );
			self::assertSame( 'object', $descriptor->output_schema()['type'] ?? null );
			$by_key[ $descriptor->adapter_id() . '@' . $descriptor->adapter_version() ] = $descriptor;
		}

		self::assertCount( 18, $descriptors );
		self::assertArrayHasKey( 'wordpress@1', $by_key );
		self::assertArrayHasKey( 'content_planner@1', $by_key );
		self::assertArrayHasKey( 'wordpress_content_create@1', $by_key );
		self::assertArrayHasKey( 'wordpress_content_update@1', $by_key );
		self::assertArrayHasKey( 'wordpress_seo_update@1', $by_key );
		self::assertArrayHasKey( 'wordpress_media_upload@1', $by_key );
		self::assertArrayHasKey( 'wordpress_internal_links@1', $by_key );

		self::assertTrue( $by_key['wordpress_content_list@1']->is_read_only() );
		self::assertFalse( $by_key['wordpress_content_create@1']->is_read_only() );
		self::assertSame( array( 'edit_posts' ), $by_key['wordpress_content_create@1']->required_capabilities() );
		self::assertSame( array( 'edit_post', 'upload_files' ), $by_key['wordpress_content_media@1']->required_capabilities() );
	}

	public function test_registry_can_explicitly_compose_the_catalog_without_changing_compatibility_defaults(): void {
		$catalog = WorkflowAdapterRegistry::from_catalog();
		$default = new WorkflowAdapterRegistry();

		self::assertCount( 18, $catalog->descriptors() );
		self::assertCount( 2, $default->descriptors() );
		self::assertSame( array( 'content_planner@1', 'wordpress@1' ), array_map( static fn ( WorkflowAdapterDescriptor $descriptor ): string => $descriptor->adapter_id() . '@' . $descriptor->adapter_version(), $default->descriptors() ) );
	}

	public function test_missing_native_ability_fails_closed_before_gateway_dispatch(): void {
		$adapter = new NativeAbilityWorkflowAdapter( 'missing_native', 1, 'content/missing', 'content.missing', 'read', true );
		$plan    = $this->plan_for( 'missing_native', 'content/missing', 'read' );
		$result  = $adapter->execute( $plan, 'missing_step', array(), array( 'user_id' => 7 ) );

		self::assertSame( WorkflowAdapterResult::CODE_ABILITY_CONTRACT_MISMATCH, $result->code() );
		self::assertFalse( $result->succeeded() );
	}

	private function plan_for( string $adapter_id, string $ability_id, string $kind ): WorkflowPlan {
		$definition = WorkflowDefinition::from_array(
			array(
				'definition_schema_version' => 1,
				'workflow_id'               => 'catalog_test_workflow',
				'workflow_version'          => 1,
				'name'                      => 'Catalog test workflow',
				'description'               => 'Verifies the native adapter boundary.',
				'content_target'            => array(
					'mode'       => 'either',
					'post_types' => array( 'post' ),
				),
				'input_schema'              => array( 'type' => 'object' ),
				'steps'                     => array(
					array(
						'step_id'         => 'missing_step',
						'adapter_id'      => $adapter_id,
						'adapter_version' => 1,
						'ability_id'      => $ability_id,
						'kind'            => $kind,
						'arguments'       => array(),
						'depends_on'      => array(),
					),
				),
				'allowed_abilities'         => array( $ability_id ),
				'write_policy'              => array( 'mode' => 'proposal_only' ),
				'approval_gates'            => array(),
				'output_contract'           => array( 'type' => 'object' ),
				'validation_rules'          => array(),
				'status'                    => 'draft',
				'created_by'                => 7,
				'updated_by'                => 7,
				'compatibility'             => array(
					'input_contract_version'  => 1,
					'output_contract_version' => 1,
				),
			)
		);

		return ( new WorkflowPlanBuilder() )->build( $definition, WorkflowInputContract::from_value( new \stdClass() ) );
	}
}

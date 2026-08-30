<?php
/**
 * Tests for the closed native workflow adapter catalog.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Adapters
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Adapters;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
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
		$GLOBALS['aculect_ai_companion_test_options']             = array();
		$GLOBALS['aculect_ai_companion_test_transients']          = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_current_user_id']     = 1;
		$GLOBALS['aculect_ai_companion_test_users']               = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
		$GLOBALS['aculect_ai_companion_test_posts']               = array(
			123 => new \WP_Post(
				array(
					'ID'           => 123,
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_title'   => 'Original title',
					'post_content' => '<!-- wp:paragraph --><p>Original content.</p><!-- /wp:paragraph -->',
				)
			),
		);
	}

	protected function tearDown(): void {
		AbilitiesRegistry::reset_module_cache();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;

		parent::tearDown();
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

	public function test_confirmation_required_native_preview_cannot_complete_a_step(): void {
		( new ToolSafety() )->save_confirmation_groups( array( 'Content' ) );
		$result = $this->native_update_adapter()->execute(
			$this->native_update_plan(),
			'update_item',
			array(
				'id'    => 123,
				'title' => 'Preview title',
			),
			$this->native_auth()
		);

		self::assertFalse( $result->succeeded() );
		self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $result->code() );
		self::assertSame( 'Original title', get_post( 123 )?->post_title );
	}

	public function test_authorized_native_write_result_completes_after_gateway_commit(): void {
		$result = $this->native_update_adapter()->execute(
			$this->native_update_plan(),
			'update_item',
			array(
				'id'    => 123,
				'title' => 'Committed title',
			),
			$this->native_auth( true )
		);

		self::assertTrue( $result->succeeded() );
		self::assertSame( WorkflowAdapterResult::CODE_SUCCESS, $result->code() );
		self::assertSame( 'Committed title', get_post( 123 )?->post_title );
		self::assertSame( 123, $result->output()->id ?? null );
	}

	public function test_authorized_native_write_preserves_pending_post_status(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123]->post_status = 'pending';

		$result = $this->native_update_adapter()->execute(
			$this->native_update_plan(),
			'update_item',
			array(
				'id'    => 123,
				'title' => 'Committed pending title',
			),
			$this->native_auth( true )
		);

		self::assertTrue( $result->succeeded() );
		self::assertSame( WorkflowAdapterResult::CODE_SUCCESS, $result->code() );
		self::assertSame( 'Committed pending title', get_post( 123 )?->post_title );
		self::assertSame( 'pending', $result->output()->status ?? null );
	}

	private function native_update_adapter(): NativeAbilityWorkflowAdapter {
		return new NativeAbilityWorkflowAdapter(
			'wordpress_content_update',
			1,
			'content/update-item',
			'content.update_item',
			'write',
			false,
			array( 'edit_post' ),
			null,
			new AbilitiesRegistry()
		);
	}

	/**
	 * Return an exact plan binding for the native update adapter.
	 */
	private function native_update_plan(): WorkflowPlan {
		$definition = WorkflowDefinition::from_array(
			array(
				'definition_schema_version' => 1,
				'workflow_id'               => 'native_adapter_test',
				'workflow_version'          => 1,
				'name'                      => 'Native adapter test',
				'description'               => 'Verifies completion only follows a committed gateway result.',
				'content_target'            => array(
					'mode'       => 'either',
					'post_types' => array( 'post' ),
				),
				'input_schema'              => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'steps'                     => array(
					array(
						'step_id'         => 'update_item',
						'adapter_id'      => 'wordpress_content_update',
						'adapter_version' => 1,
						'ability_id'      => 'content/update-item',
						'kind'            => 'write',
						'arguments'       => array(
							'id'    => 123,
							'title' => 'Planned title',
						),
						'depends_on'      => array(),
					),
				),
				'allowed_abilities'         => array( 'content/update-item' ),
				'write_policy'              => array( 'mode' => 'draft_only' ),
				'approval_gates'            => array( 'update_item' ),
				'output_contract'           => array( 'type' => 'object' ),
				'validation_rules'          => array(),
				'status'                    => 'draft',
				'created_by'                => 1,
				'updated_by'                => 1,
				'compatibility'             => array(
					'input_contract_version'  => 1,
					'output_contract_version' => 1,
				),
			)
		);

		return ( new WorkflowPlanBuilder() )->build( $definition, WorkflowInputContract::from_value( new \stdClass() ) );
	}

	/**
	 * Return a normal or trusted direct-write auth context.
	 *
	 * @param bool $direct_write Whether the trusted direct-write permission is enabled.
	 * @return array<string, mixed>
	 */
	private function native_auth( bool $direct_write = false ): array {
		return array(
			'user_id'                  => 1,
			'client_id'                => 'native-adapter-test-client',
			'provider'                 => 'chatgpt',
			'scopes'                   => array( 'content:read', 'content:draft' ),
			'profile'                  => 'full_access',
			'access_level'             => $direct_write ? ConnectionAccessLevel::WRITE : '',
			'write_permission_enabled' => $direct_write,
		);
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

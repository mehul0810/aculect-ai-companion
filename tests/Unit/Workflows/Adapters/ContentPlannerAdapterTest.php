<?php
/**
 * Tests for the private content planner workflow adapter.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Adapters
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Adapters;

require_once dirname( __DIR__, 3 ) . '/Support/WorkflowDefinitionFixtureException.php';
require_once dirname( __DIR__, 3 ) . '/Support/WorkflowDefinitionFixtureLoader.php';

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\CallbackAbilityModule;
use Aculect\AICompanion\Connectors\MCP\ContentWorkflowAbilities;
use Aculect\AICompanion\Connectors\MCP\McpToolProfiles;
use Aculect\AICompanion\Tests\Support\WorkflowDefinitionFixtureLoader;
use Aculect\AICompanion\Workflows\Adapters\ContentPlannerAdapter;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterRegistry;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Proves exact binding, gateway ownership, privacy, and closed projection.
 */
final class ContentPlannerAdapterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		AbilitiesRegistry::reset_module_cache();
		$GLOBALS['aculect_ai_companion_test_options']             = array();
		$GLOBALS['aculect_ai_companion_test_transients']          = array();
		$GLOBALS['aculect_ai_companion_test_posts']               = array();
		$GLOBALS['aculect_ai_companion_test_post_meta']           = array();
		$GLOBALS['aculect_ai_companion_test_post_terms']          = array();
		$GLOBALS['aculect_ai_companion_test_taxonomies']          = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']    = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']     = 99;
		$GLOBALS['aculect_ai_companion_test_users']               = array(
			1  => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
			99 => (object) array(
				'ID'           => 99,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Previous Actor',
				'user_login'   => 'previous',
			),
		);
	}

	protected function tearDown(): void {
		AbilitiesRegistry::reset_module_cache();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']    = array();

		parent::tearDown();
	}

	public function test_adapter_declares_the_exact_private_proposal_contract(): void {
		$adapter = new ContentPlannerAdapter();
		$input   = $adapter->input_schema();
		$output  = $adapter->output_schema();

		self::assertSame( 'content_planner', $adapter->adapter_id() );
		self::assertSame( 1, $adapter->adapter_version() );
		self::assertSame( 'content/prepare-draft', $adapter->ability_id() );
		self::assertSame( 'proposal', $adapter->kind() );
		self::assertTrue( $adapter->is_read_only() );
		self::assertSame( array(), $adapter->required_capabilities() );
		self::assertFalse( $input['additionalProperties'] );
		self::assertSame( array( 'brief' ), $input['required'] );
		self::assertArrayNotHasKey( 'workflow_session_id', $input['properties'] );
		self::assertSame(
			array(
				'minimum' => 3000,
				'maximum' => 5000,
			),
			array_intersect_key(
				$input['properties']['desired_word_count'],
				array(
					'minimum' => true,
					'maximum' => true,
				)
			)
		);
		self::assertSame( array( 'article', 'page', 'landing_page', 'visual_layout', 'service_page', 'product_page', 'case_study' ), $input['properties']['content_mode']['enum'] );
		self::assertSame( 120, $input['properties']['content_type']['maxLength'] );
		self::assertSame( 12, $input['properties']['section_requirements']['maxItems'] );
		self::assertSame( 8, $input['properties']['preferred_block_families']['maxItems'] );
		self::assertSame( 20, $input['properties']['preferred_blocks']['maxItems'] );
		self::assertSame( 12, $input['properties']['preferred_patterns']['maxItems'] );
		self::assertFalse( $output['additionalProperties'] );
		self::assertSame(
			array( 'status', 'workflow', 'content_mode', 'post_type', 'desired_word_count', 'outline', 'block_plan', 'context', 'available_operations', 'next_actions' ),
			$output['required']
		);
	}

	public function test_default_registry_executes_the_exact_fixture_step_and_projects_only_safe_structure(): void {
		$secret_brief    = 'Private launch brief sentinel';
		$secret_audience = 'Confidential buyer sentinel';
		$secret_seo      = 'Private search goal sentinel';
		$secret_visual   = 'Secret screenshot detail sentinel';
		$secret_section  = str_pad( 'private-section-sentinel-', 120, 'x' );
		self::assertSame( 120, strlen( $secret_section ) );
		$result = ( new WorkflowAdapterRegistry() )->execute(
			$this->ordered_plan(),
			'prepare_content',
			array(
				'brief'                    => $secret_brief,
				'post_type'                => 'page',
				'audience'                 => $secret_audience,
				'seo_intent'               => $secret_seo,
				'content_mode'             => 'visual_layout',
				'layout_intent'            => 'Hero, cards, and CTA.',
				'visual_reference_summary' => $secret_visual,
				'desired_word_count'       => 3200,
				'section_requirements'     => array( $secret_section ),
				'preferred_block_families' => array( 'layout', 'media' ),
				'preferred_blocks'         => array( 'core/group', 'core/columns' ),
			),
			$this->auth()
		);

		self::assertTrue( $result->succeeded(), (string) wp_json_encode( $result->to_array() ) );
		self::assertSame( 'ready', $result->output()->status ?? null );
		self::assertSame( 'content_workflow_prepare_post', $result->output()->workflow ?? null );
		self::assertSame( 'visual_layout', $result->output()->content_mode ?? null );
		self::assertSame( 'serialized_wordpress_blocks', $result->output()->block_plan->format ?? null );
		self::assertSame( array( 'core/html' ), $result->output()->block_plan->forbidden_blocks ?? null );
		$projected_ids = array_map( static fn( object $section ): string => (string) $section->id, $result->output()->outline ?? array() );
		self::assertSame( array_map( static fn( int $ordinal ): string => 'section-' . $ordinal, range( 1, count( $projected_ids ) ) ), $projected_ids );
		self::assertSame( $projected_ids, $result->output()->block_plan->section_ids ?? null );
		self::assertSame( 8, count( $result->output()->available_operations ?? array() ) );
		self::assertSame( array( 'use_private_context', 'compose_serialized_blocks', 'validate_blocks', 'create_or_update_content' ), $result->output()->next_actions ?? null );

		$encoded = (string) wp_json_encode( $result->to_array() );
		foreach ( array( $secret_brief, $secret_audience, $secret_seo, $secret_visual, $secret_section, 'intelligence_context', 'memories', 'related_items', 'relevant_chunks', 'https://' ) as $private_value ) {
			self::assertStringNotContainsString( $private_value, $encoded );
		}
		self::assertStringNotContainsString( 'layout_intent', $encoded );
		self::assertStringNotContainsString( 'pattern_search_terms', $encoded );
	}

	public function test_workflow_session_is_rejected_before_gateway_and_never_persisted(): void {
		$executions = 0;
		$this->replace_planner_module(
			true,
			static function ( array $arguments ) use ( &$executions ): array {
				unset( $arguments );
				++$executions;
				return array();
			}
		);

		$before = $GLOBALS['aculect_ai_companion_test_transients'];
		$result = ( new WorkflowAdapterRegistry() )->execute(
			$this->ordered_plan(),
			'prepare_content',
			array(
				'brief'               => 'Session-free planning.',
				'workflow_session_id' => 'private-session-sentinel',
			),
			$this->auth()
		);

		self::assertSame( WorkflowAdapterResult::CODE_INVALID_ARGUMENTS, $result->code() );
		self::assertSame( 0, $executions );
		self::assertSame( $before, $GLOBALS['aculect_ai_companion_test_transients'] );
		self::assertStringNotContainsString( 'private-session-sentinel', (string) wp_json_encode( $result->to_array() ) );
	}

	public function test_percent_encoded_and_non_latin_section_ids_are_internal_only(): void {
		$private_section = str_repeat( '日', 22 );
		$encoded_id      = str_repeat( '%e6%97%a5', 22 );
		self::assertSame( 66, strlen( $private_section ) );
		self::assertSame( 198, strlen( $encoded_id ) );

		$raw                                 = $this->raw_output(
			array(
				'brief'                => 'Encoded section.',
				'content_mode'         => 'visual_layout',
				'section_requirements' => array( $private_section ),
			)
		);
		$raw['outline'][0]['id']             = $encoded_id;
		$raw['block_plan']['section_ids'][0] = $encoded_id;
		$raw['block_plan']['layout_plan']    = $raw['outline'];
		$this->replace_planner_module( true, static fn(): array => $raw );

		$result = ( new WorkflowAdapterRegistry() )->execute(
			$this->ordered_plan(),
			'prepare_content',
			array(
				'brief'                => 'Encoded section.',
				'content_mode'         => 'visual_layout',
				'section_requirements' => array( $private_section ),
			),
			$this->auth()
		);
		self::assertTrue( $result->succeeded(), (string) wp_json_encode( $result->to_array() ) );
		self::assertSame( 'section-1', $result->output()->outline[0]->id ?? null );
		self::assertSame( $result->output()->outline[0]->id ?? null, $result->output()->block_plan->section_ids[0] ?? null );
		self::assertStringNotContainsString( $private_section, (string) wp_json_encode( $result->to_array() ) );
		self::assertStringNotContainsString( $encoded_id, (string) wp_json_encode( $result->to_array() ) );
	}

	public function test_closed_input_bounds_reject_hostile_values_before_dispatch(): void {
		$executions = 0;
		$this->replace_planner_module(
			true,
			static function ( array $arguments ) use ( &$executions ): array {
				unset( $arguments );
				++$executions;
				return array();
			}
		);

		$invalid = array(
			array(),
			array( 'brief' => '' ),
			array( 'brief' => array( 'not', 'text' ) ),
			array( 'brief' => (object) array( 'private' => 'value' ) ),
			array( 'brief' => str_repeat( 'b', 1001 ) ),
			array(
				'brief'   => 'x',
				'private' => 'secret',
			),
			array(
				'brief'              => 'x',
				'desired_word_count' => 2999,
			),
			array(
				'brief'              => 'x',
				'desired_word_count' => 5001,
			),
			array(
				'brief'        => 'x',
				'content_mode' => 'private_mode',
			),
			array(
				'brief'        => 'x',
				'content_type' => str_repeat( 't', 121 ),
			),
			array(
				'brief'                    => 'x',
				'preferred_block_families' => array( 'layout', 'private' ),
			),
			array(
				'brief'                    => 'x',
				'preferred_block_families' => array( 'family' => 'layout' ),
			),
			array(
				'brief'                => 'x',
				'section_requirements' => array_fill( 0, 13, 'section' ),
			),
			array(
				'brief'            => 'x',
				'preferred_blocks' => array( 'not a block' ),
			),
			array(
				'brief'            => 'x',
				'existing_post_id' => 0,
			),
		);

		foreach ( $invalid as $arguments ) {
			$result = ( new WorkflowAdapterRegistry() )->execute( $this->ordered_plan(), 'prepare_content', $arguments, $this->auth() );
			self::assertSame( WorkflowAdapterResult::CODE_INVALID_ARGUMENTS, $result->code(), (string) wp_json_encode( $arguments ) );
			self::assertArrayNotHasKey( 'output', $result->to_array() );
		}
		self::assertSame( 0, $executions );
	}

	public function test_gateway_owns_scope_pause_profile_and_principal_boundaries(): void {
		$executions = 0;
		$actor      = 0;
		$this->replace_planner_module(
			true,
			function ( array $arguments ) use ( &$executions, &$actor ): array {
				++$executions;
				$actor = get_current_user_id();
				return $this->raw_output( $arguments );
			}
		);

		$registry      = new WorkflowAdapterRegistry();
		$missing_scope = $registry->execute( $this->ordered_plan(), 'prepare_content', array( 'brief' => 'Scope test.' ), array_merge( $this->auth(), array( 'scopes' => array() ) ) );
		self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $missing_scope->code() );
		self::assertSame( 0, $executions );

		update_option( 'aculect_ai_companion_access_paused', '1', false );
		$paused = $registry->execute( $this->ordered_plan(), 'prepare_content', array( 'brief' => 'Pause test.' ), $this->auth() );
		delete_option( 'aculect_ai_companion_access_paused' );
		self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $paused->code() );
		self::assertSame( 0, $executions );

		$hidden = $registry->execute(
			$this->ordered_plan(),
			'prepare_content',
			array( 'brief' => 'Profile test.' ),
			array_merge( $this->auth(), array( 'profile' => McpToolProfiles::PROFILE_SITE_MANAGEMENT ) )
		);
		self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $hidden->code() );
		self::assertSame( 0, $executions );

		$success = $registry->execute( $this->ordered_plan(), 'prepare_content', array( 'brief' => 'Principal test.' ), $this->auth() );
		self::assertTrue( $success->succeeded() );
		self::assertSame( 1, $executions );
		self::assertSame( 1, $actor );
		self::assertSame( 99, get_current_user_id() );
	}

	public function test_exact_plan_tuple_is_required(): void {
		$registry = new WorkflowAdapterRegistry();

		self::assertSame( WorkflowAdapterResult::CODE_STEP_NOT_FOUND, $registry->execute( $this->ordered_plan(), 'missing', array( 'brief' => 'x' ), $this->auth() )->code() );
		self::assertSame( WorkflowAdapterResult::CODE_ADAPTER_NOT_REGISTERED, $registry->execute( $this->mutated_plan( 'adapter_version', 2 ), 'prepare_content', array( 'brief' => 'x' ), $this->auth() )->code() );
		self::assertSame( WorkflowAdapterResult::CODE_ADAPTER_NOT_REGISTERED, $registry->execute( $this->mutated_plan( 'adapter_id', 'external' ), 'prepare_content', array( 'brief' => 'x' ), $this->auth() )->code() );
		self::assertSame( WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH, $registry->execute( $this->mutated_plan( 'ability_id', 'content/list-items' ), 'prepare_content', array( 'brief' => 'x' ), $this->auth() )->code() );
		self::assertSame( WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH, $registry->execute( $this->mutated_plan( 'kind', 'read' ), 'prepare_content', array( 'brief' => 'x' ), $this->auth() )->code() );
	}

	public function test_read_only_scope_or_input_schema_drift_fails_before_dispatch(): void {
		$executions = 0;
		$this->replace_planner_module(
			false,
			static function () use ( &$executions ): array {
				++$executions;
				return array();
			}
		);
		$write_drift = ( new WorkflowAdapterRegistry() )->execute( $this->ordered_plan(), 'prepare_content', array( 'brief' => 'x' ), $this->auth() );
		self::assertSame( WorkflowAdapterResult::CODE_ABILITY_CONTRACT_MISMATCH, $write_drift->code() );

		$this->replace_planner_module(
			true,
			static function () use ( &$executions ): array {
				++$executions;
				return array();
			},
			array(
				'type'                 => 'object',
				'properties'           => array( 'brief' => array( 'type' => 'string' ) ),
				'additionalProperties' => false,
				'required'             => array( 'brief' ),
			),
			array( 'content:draft' )
		);
		$scope_drift = ( new WorkflowAdapterRegistry() )->execute( $this->ordered_plan(), 'prepare_content', array( 'brief' => 'x' ), $this->auth() );
		self::assertSame( WorkflowAdapterResult::CODE_ABILITY_CONTRACT_MISMATCH, $scope_drift->code() );

		$this->replace_planner_module(
			true,
			static function () use ( &$executions ): array {
				++$executions;
				return array();
			},
			array(
				'type'                 => 'object',
				'properties'           => array(
					'brief'   => array( 'type' => 'string' ),
					'private' => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
				'required'             => array( 'brief' ),
			)
		);
		$schema_drift = ( new WorkflowAdapterRegistry() )->execute( $this->ordered_plan(), 'prepare_content', array( 'brief' => 'x' ), $this->auth() );
		self::assertSame( WorkflowAdapterResult::CODE_ABILITY_CONTRACT_MISMATCH, $schema_drift->code() );
		self::assertSame( 0, $executions );
	}

	public function test_error_list_oversize_and_top_level_schema_drift_fail_closed_without_echo(): void {
		$base                    = $this->raw_output( array( 'brief' => 'Safe base.' ) );
		$extra                   = $base;
		$extra['private_output'] = 'top-level-output-secret';
		$oversize                = $base;
		$oversize['brief']       = str_repeat( 'oversized-output-secret', 70000 );
		$wrong_status            = $base;
		$wrong_status['status']  = 'private-output-secret';
		$recursive               = $base;
		$recursive['recursive']  = &$recursive;

		$cases = array(
			array(
				'output' => array(
					'status'  => 'error',
					'error'   => 'private-output-secret',
					'message' => 'private-output-secret',
				),
				'code'   => WorkflowAdapterResult::CODE_ABILITY_FAILED,
			),
			array(
				'output' => array( array( 'private-output-secret' ) ),
				'code'   => WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE,
			),
			array(
				'output' => $extra,
				'code'   => WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE,
			),
			array(
				'output' => $oversize,
				'code'   => WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE,
			),
			array(
				'output' => $wrong_status,
				'code'   => WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE,
			),
			array(
				'output' => $recursive,
				'code'   => WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE,
			),
		);

		foreach ( $cases as $case ) {
			$this->replace_planner_module( true, static fn(): array => $case['output'] );
			$result  = ( new WorkflowAdapterRegistry() )->execute( $this->ordered_plan(), 'prepare_content', array( 'brief' => 'x' ), $this->auth() );
			$encoded = (string) wp_json_encode( $result->to_array() );
			self::assertSame( $case['code'], $result->code() );
			self::assertArrayNotHasKey( 'output', $result->to_array() );
			self::assertStringNotContainsString( 'output-secret', $encoded );
		}
	}

	public function test_nested_outline_plan_context_operation_and_action_drift_fail_closed(): void {
		$base = $this->raw_output( array( 'brief' => 'Safe base.' ) );

		$outline                                    = $base;
		$outline['outline'][0]['private']           = 'nested-output-secret';
		$plan                                       = $base;
		$plan['block_plan']['section_ids']          = array( 'different' );
		$context                                    = $base;
		$context['intelligence_context']['private'] = 'context-output-secret';
		$operation                                  = $base;
		$operation['required_operations']['validate_blocks']['tool'] = 'private-output-secret';
		$operation_shape = $base;
		$operation_shape['required_operations']['validate_blocks']['private'] = 'nested-output-secret';
		$actions                    = $base;
		$actions['next_actions'][2] = 'private-output-secret';

		foreach ( array( $outline, $plan, $context, $operation, $operation_shape, $actions ) as $hostile ) {
			$this->replace_planner_module( true, static fn(): array => $hostile );
			$result = ( new WorkflowAdapterRegistry() )->execute( $this->ordered_plan(), 'prepare_content', array( 'brief' => 'x' ), $this->auth() );
			self::assertSame( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE, $result->code() );
			self::assertStringNotContainsString( 'output-secret', (string) wp_json_encode( $result->to_array() ) );
		}
	}

	public function test_result_is_detached_and_no_session_or_content_state_is_mutated(): void {
		$before_posts      = $GLOBALS['aculect_ai_companion_test_posts'];
		$before_meta       = $GLOBALS['aculect_ai_companion_test_post_meta'];
		$before_terms      = $GLOBALS['aculect_ai_companion_test_post_terms'];
		$before_taxonomies = $GLOBALS['aculect_ai_companion_test_taxonomies'];
		$before_transients = $GLOBALS['aculect_ai_companion_test_transients'];

		$result = ( new WorkflowAdapterRegistry() )->execute( $this->ordered_plan(), 'prepare_content', array( 'brief' => 'Detached planning.' ), $this->auth() );
		self::assertTrue( $result->succeeded() );
		$output                        = $result->output();
		$output->status                = 'mutated';
		$output->outline[0]->blocks[0] = 'private/mutated';

		self::assertSame( 'ready', $result->output()->status ?? null );
		self::assertNotSame( 'private/mutated', $result->output()->outline[0]->blocks[0] ?? null );
		self::assertSame( $before_posts, $GLOBALS['aculect_ai_companion_test_posts'] );
		self::assertSame( $before_meta, $GLOBALS['aculect_ai_companion_test_post_meta'] );
		self::assertSame( $before_terms, $GLOBALS['aculect_ai_companion_test_post_terms'] );
		self::assertSame( $before_taxonomies, $GLOBALS['aculect_ai_companion_test_taxonomies'] );
		self::assertSame( $before_transients, $GLOBALS['aculect_ai_companion_test_transients'] );
	}

	public function test_source_has_no_direct_public_service_or_persistence_boundary(): void {
		$root   = dirname( __DIR__, 4 );
		$source = file_get_contents( $root . '/src/Workflows/Adapters/ContentPlannerAdapter.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned source guard.
		self::assertIsString( $source );
		self::assertStringContainsString( '$this->gateway->execute(', $source );
		self::assertDoesNotMatchRegularExpression( '/(?:ContentWorkflowAbilities|AbilitiesService|McpController|->abilities->execute\s*\(|->registry->execute\s*\()/i', $source );
		self::assertDoesNotMatchRegularExpression( '/(?:register_rest_route|wp_register_ability|add_action|add_filter|\$wpdb|get_option|update_option|add_option|delete_option|set_transient|wp_insert_post|wp_update_post|update_post_meta|wp_set_object_terms)/i', $source );

		$plugin_source = file_get_contents( $root . '/src/Plugin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned source guard.
		self::assertIsString( $plugin_source );
		self::assertStringNotContainsString( 'ContentPlannerAdapter', $plugin_source );
		self::assertStringNotContainsString( 'WorkflowAdapterRegistry', $plugin_source );
	}

	/** Build the existing ordered multi-step fixture plan. */
	private function ordered_plan(): WorkflowPlan {
		return ( new WorkflowPlanBuilder() )->build(
			( new WorkflowDefinitionFixtureLoader() )->load( 'ordered-multi-step-v1.json' ),
			WorkflowInputContract::from_json( '{"brief":"Exact content planner adapter"}' )
		);
	}

	/**
	 * Build a valid ordered plan with one prepare-step identity field changed.
	 *
	 * @param string     $field Step field.
	 * @param int|string $value Replacement value.
	 */
	private function mutated_plan( string $field, int|string $value ): WorkflowPlan {
		$path = dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/ordered-multi-step-v1.json';
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned fixture.
		self::assertIsString( $json );
		$definition = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		self::assertIsArray( $definition );
		$definition['steps'][1][ $field ] = $value;
		if ( 'ability_id' === $field ) {
			$definition['allowed_abilities'][0] = $value;
		}

		return ( new WorkflowPlanBuilder() )->build(
			WorkflowDefinition::from_json( (string) wp_json_encode( $definition ) ),
			WorkflowInputContract::from_json( '{"brief":"Mutated content planner adapter"}' )
		);
	}

	/**
	 * Return valid private gateway authorization metadata.
	 *
	 * @return array<string, mixed>
	 */
	private function auth(): array {
		return array(
			'user_id'   => 1,
			'client_id' => 'content-planner-adapter-test-client',
			'provider'  => 'chatgpt',
			'scopes'    => array( 'content:read' ),
			'profile'   => McpToolProfiles::PROFILE_FULL_ACCESS,
		);
	}

	/**
	 * Return a real planner output without using the adapter.
	 *
	 * @param array<string, mixed> $arguments Planner arguments.
	 * @return array<string, mixed>
	 */
	private function raw_output( array $arguments ): array {
		return ( new ContentWorkflowAbilities() )->prepare_post( $arguments );
	}

	/**
	 * Replace only the process-local planner module.
	 *
	 * @param bool                      $read_only Whether replacement is read-only.
	 * @param callable                  $handler Replacement callback.
	 * @param array<string, mixed>|null $schema Optional replacement input schema.
	 * @param array                     $scopes Optional replacement scopes.
	 * @phpstan-param list<string> $scopes
	 */
	private function replace_planner_module( bool $read_only, callable $handler, ?array $schema = null, array $scopes = array( 'content:read' ) ): void {
		AbilitiesRegistry::reset_module_cache();
		$registry = new AbilitiesRegistry();
		$module   = $registry->module( 'content_workflow.prepare_post' );
		self::assertNotNull( $module );
		$modules                                  = $registry->modules();
		$modules['content_workflow.prepare_post'] = new CallbackAbilityModule(
			'content_workflow.prepare_post',
			'Replaced content planner',
			'Test replacement.',
			'Content Workflows',
			$scopes,
			$read_only,
			$schema ?? $module->input_schema(),
			static function ( array $arguments ) use ( $handler ): array {
				$result = $handler( $arguments );
				if ( ! is_array( $result ) ) {
					throw new RuntimeException( 'Test handler must return an array.' );
				}
				return $result;
			}
		);

		$property = new ReflectionProperty( AbilitiesRegistry::class, 'shared_modules' );
		$property->setValue( null, $modules );
	}
}

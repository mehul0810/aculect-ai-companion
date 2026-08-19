<?php
/**
 * Tests for the private workflow adapter composition boundary.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Adapters
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Adapters;

require_once dirname( __DIR__, 3 ) . '/Support/WorkflowDefinitionFixtureException.php';
require_once dirname( __DIR__, 3 ) . '/Support/WorkflowDefinitionFixtureLoader.php';

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\CallbackAbilityModule;
use Aculect\AICompanion\Connectors\MCP\McpToolProfiles;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use Aculect\AICompanion\Tests\Support\WorkflowDefinitionFixtureLoader;
use Aculect\AICompanion\Workflows\Adapters\WordPressReadAdapter;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterRegistry;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use stdClass;

/**
 * Proves exact binding, gateway ownership, bounded output, and registry safety.
 */
final class WorkflowAdapterRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		AbilitiesRegistry::reset_module_cache();
		$GLOBALS['aculect_ai_companion_test_options']             = array();
		$GLOBALS['aculect_ai_companion_test_transients']          = array();
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
			2  => (object) array(
				'ID'           => 2,
				'roles'        => array( 'author' ),
				'display_name' => 'Ari Author',
				'user_login'   => 'ari',
			),
			99 => (object) array(
				'ID'           => 99,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Previous Actor',
				'user_login'   => 'previous',
			),
		);
		$GLOBALS['aculect_ai_companion_test_posts']               = array(
			123 => $this->post( '<!-- wp:paragraph --><p>Adapter content.</p><!-- /wp:paragraph -->' ),
		);
		$GLOBALS['aculect_ai_companion_test_post_meta']           = array();
		$GLOBALS['aculect_ai_companion_test_post_types']          = array();
		$GLOBALS['aculect_ai_companion_test_taxonomies']          = array();
	}

	protected function tearDown(): void {
		AbilitiesRegistry::reset_module_cache();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']    = array();

		parent::tearDown();
	}

	public function test_adapter_declares_one_exact_read_only_contract(): void {
		$adapter = new WordPressReadAdapter();

		self::assertSame( 'wordpress', $adapter->adapter_id() ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Exact machine ID.
		self::assertSame( 1, $adapter->adapter_version() );
		self::assertSame( 'content/get-item', $adapter->ability_id() );
		self::assertSame( 'read', $adapter->kind() );
		self::assertTrue( $adapter->is_read_only() );
		self::assertSame( array( 'read_post' ), $adapter->required_capabilities() );
		self::assertSame(
			array(
				'type'                 => 'object',
				'properties'           => array( 'id' => array( 'type' => 'integer' ) ),
				'additionalProperties' => false,
				'required'             => array( 'id' ),
			),
			$adapter->input_schema()
		);
		self::assertSame( 'object', $adapter->output_schema()['type'] ?? null );
		self::assertFalse( $adapter->output_schema()['additionalProperties'] ?? true );
		self::assertSame( array( 'type' => 'string' ), $adapter->output_schema()['properties']['mime_type'] ?? null );
		self::assertSame( array( 'type' => 'string' ), $adapter->output_schema()['properties']['source_url'] ?? null );
		self::assertSame( array( 'type' => 'string' ), $adapter->output_schema()['properties']['alt_text'] ?? null );
		self::assertSame(
			array( 'id', 'type', 'title', 'slug', 'status', 'content', 'excerpt', 'author', 'author_display_name', 'featured_media', 'date', 'date_gmt', 'modified_gmt', 'link', 'terms', 'block_locators' ),
			$adapter->output_schema()['required'] ?? null
		);
		self::assertFalse( $adapter->output_schema()['properties']['block_locators']['items']['additionalProperties'] ?? true );
		self::assertFalse( $adapter->output_schema()['properties']['terms']['additionalProperties']['items']['additionalProperties'] ?? true );
	}

	public function test_registry_rejects_duplicate_and_invalid_adapter_identities(): void {
		$adapter = new WordPressReadAdapter();

		try {
			new WorkflowAdapterRegistry( array( $adapter, $adapter ) );
			self::fail( 'Duplicate adapter identity must fail closed.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Duplicate workflow adapter identity.', $exception->getMessage() );
		}

		try {
			// @phpstan-ignore-next-line Deliberate hostile runtime value.
			new WorkflowAdapterRegistry( array( new stdClass() ) );
			self::fail( 'A non-adapter value must fail closed.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Invalid workflow adapter contract.', $exception->getMessage() );
		}
	}

	public function test_exact_plan_step_executes_only_through_gateway_and_restores_actor(): void {
		$capability_actor = 0;
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability, array $arguments, int $user_id ) use ( &$capability_actor ): bool {
			unset( $arguments );
			if ( 'read_post' === $capability ) {
				$capability_actor = $user_id;
			}

			return true;
		};

		$result = ( new WorkflowAdapterRegistry() )->execute(
			$this->proposal_plan(),
			'read_content',
			array( 'id' => 123 ),
			$this->auth()
		);

		self::assertTrue( $result->succeeded() );
		self::assertSame( 123, $result->output()->id ?? null );
		self::assertSame( 'Adapter content.', wp_strip_all_tags( (string) ( $result->output()->content ?? '' ) ) );
		self::assertSame( 1, $capability_actor, 'The module capability check must run as the authenticated gateway actor.' );
		self::assertSame( 99, get_current_user_id(), 'The gateway must restore the previous WordPress actor.' );
	}

	public function test_result_output_is_detached_from_callers(): void {
		$result                          = ( new WorkflowAdapterRegistry() )->execute( $this->proposal_plan(), 'read_content', array( 'id' => 123 ), $this->auth() );
		$output                          = $result->output();
		$output->title                   = 'Mutated outside result';
		$output->block_locators[0]->text = 'Mutated locator';

		self::assertNotSame( 'Mutated outside result', $result->output()->title ?? null );
		self::assertNotSame( 'Mutated locator', $result->output()->block_locators[0]->text ?? null );
		self::assertStringContainsString( '"output":{', (string) wp_json_encode( $result->to_array() ) );
	}

	public function test_plan_binding_rejects_missing_version_adapter_ability_and_kind_mismatches(): void {
		$registry = new WorkflowAdapterRegistry();

		self::assertSame(
			WorkflowAdapterResult::CODE_STEP_NOT_FOUND,
			$registry->execute( $this->proposal_plan(), 'private_step_name', array( 'id' => 123 ), $this->auth() )->code()
		);
		self::assertSame(
			WorkflowAdapterResult::CODE_ADAPTER_NOT_REGISTERED,
			$registry->execute( $this->ordered_plan(), 'read_context', array( 'id' => 123 ), $this->auth() )->code()
		);
		self::assertSame(
			WorkflowAdapterResult::CODE_ADAPTER_NOT_REGISTERED,
			$registry->execute( $this->mutated_plan( 'adapter_id', 'external' ), 'read_content', array( 'id' => 123 ), $this->auth() )->code()
		);
		self::assertSame(
			WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH,
			$registry->execute( $this->mutated_plan( 'ability_id', 'content/list-items' ), 'read_content', array( 'id' => 123 ), $this->auth() )->code()
		);
		self::assertSame(
			WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH,
			$registry->execute( $this->mutated_plan( 'kind', 'proposal' ), 'read_content', array( 'id' => 123 ), $this->auth() )->code()
		);
	}

	public function test_gateway_policy_rejections_are_closed_and_do_not_disclose_context(): void {
		$plan = $this->proposal_plan();
		$auth = array_merge( $this->auth(), array( 'client_secret' => 'never-return-this-secret' ) );

		$missing_scope = ( new WorkflowAdapterRegistry() )->execute(
			$plan,
			'read_content',
			array( 'id' => 123 ),
			array_merge( $auth, array( 'scopes' => array() ) )
		);
		update_option( 'aculect_ai_companion_access_paused', '1', false );
		$paused = ( new WorkflowAdapterRegistry() )->execute( $plan, 'read_content', array( 'id' => 123 ), $auth );
		delete_option( 'aculect_ai_companion_access_paused' );
		$hidden = ( new WorkflowAdapterRegistry() )->execute(
			$plan,
			'read_content',
			array( 'id' => 123 ),
			array_merge( $auth, array( 'profile' => McpToolProfiles::PROFILE_SITE_MANAGEMENT ) )
		);

		foreach ( array( $missing_scope, $paused, $hidden ) as $result ) {
			self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $result->code() );
			self::assertStringNotContainsString( 'never-return-this-secret', (string) wp_json_encode( $result->to_array() ) );
			self::assertArrayNotHasKey( 'message', $result->to_array() );
		}
	}

	public function test_gateway_enforces_global_role_and_object_capability_policy(): void {
		$plan     = $this->proposal_plan();
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.list_items' ) );
		$adapter  = new WordPressReadAdapter( $registry );
		$disabled = ( new WorkflowAdapterRegistry( array( $adapter ) ) )->execute( $plan, 'read_content', array( 'id' => 123 ), $this->auth() );
		self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $disabled->code() );

		$GLOBALS['aculect_ai_companion_test_options'] = array();
		RoleAbilitiesPolicy::set_editing_enabled( true );
		( new RoleAbilitiesPolicy() )->save_role_policy( 'author', array(), new AbilitiesRegistry() );
		$role = ( new WorkflowAdapterRegistry() )->execute( $plan, 'read_content', array( 'id' => 123 ), $this->auth( 2 ) );
		self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $role->code() );

		$GLOBALS['aculect_ai_companion_test_options']             = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn ( string $capability ): bool => 'read_post' !== $capability;
		$object_denied = ( new WorkflowAdapterRegistry() )->execute( $plan, 'read_content', array( 'id' => 123 ), $this->auth() );
		self::assertTrue( $object_denied->succeeded() );
		self::assertSame( array(), get_object_vars( $object_denied->output() ), 'Object-level denial must disclose no post fields.' );
		self::assertSame(
			'{"status":"succeeded","code":"success","output":{}}',
			(string) wp_json_encode( $object_denied->to_array() ),
			'Empty successful output must retain the declared JSON object shape.'
		);
	}

	public function test_invalid_and_oversized_arguments_are_rejected_without_echo(): void {
		$registry = new WorkflowAdapterRegistry();
		$plan     = $this->proposal_plan();
		$secret   = str_repeat( 'private-argument-', 20000 );

		$missing = $registry->execute( $plan, 'read_content', array(), $this->auth() );
		$extra   = $registry->execute(
			$plan,
			'read_content',
			array(
				'id'     => 123,
				'secret' => 'raw-secret',
			),
			$this->auth()
		);
		$large   = $registry->execute(
			$plan,
			'read_content',
			array(
				'id'     => 123,
				'secret' => $secret,
			),
			$this->auth()
		);

		self::assertSame( WorkflowAdapterResult::CODE_INVALID_ARGUMENTS, $missing->code() );
		self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $extra->code() );
		self::assertSame( WorkflowAdapterResult::CODE_INVALID_ARGUMENTS, $large->code() );
		self::assertStringNotContainsString( 'raw-secret', (string) wp_json_encode( $extra->to_array() ) );
		self::assertStringNotContainsString( 'private-argument', (string) wp_json_encode( $large->to_array() ) );
	}

	public function test_oversized_ability_output_is_not_returned(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123] = $this->post( str_repeat( 'private-output-', 30000 ) );

		$result = ( new WorkflowAdapterRegistry() )->execute( $this->proposal_plan(), 'read_content', array( 'id' => 123 ), $this->auth() );

		self::assertSame( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE, $result->code() );
		self::assertStringNotContainsString( 'private-output', (string) wp_json_encode( $result->to_array() ) );
	}

	public function test_attachment_output_matches_the_closed_declared_schema(): void {
		$attachment                                      = $this->post( 'Attachment description.' );
		$attachment->post_type                           = 'attachment';
		$attachment->post_mime_type                      = 'image/png';
		$GLOBALS['aculect_ai_companion_test_posts'][123] = $attachment;
		$GLOBALS['aculect_ai_companion_test_post_meta'][123] = array(
			'_source_url'              => 'https://example.com/uploads/adapter.png',
			'_wp_attachment_image_alt' => 'Adapter image alt text',
		);

		$result = ( new WorkflowAdapterRegistry() )->execute( $this->proposal_plan(), 'read_content', array( 'id' => 123 ), $this->auth() );

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'image/png', $result->output()->mime_type ?? null );
		self::assertSame( 'https://example.com/uploads/adapter.png', $result->output()->source_url ?? null );
		self::assertSame( 'Adapter image alt text', $result->output()->alt_text ?? null );
	}

	public function test_output_schema_drift_is_rejected_without_raw_output_leaks(): void {
		$hostile_outputs = array(
			array( 'id' => 123 ),
			array(
				'id'              => 123,
				'unknown_private' => 'unknown-output-secret',
			),
			array( 'id' => 'wrong-type-secret' ),
			array( array( 'private' => 'list-output-secret' ) ),
		);

		foreach ( $hostile_outputs as $hostile_output ) {
			$this->replace_content_module(
				true,
				$this->expected_input_schema(),
				static fn (): array => $hostile_output
			);

			$result  = ( new WorkflowAdapterRegistry() )->execute( $this->proposal_plan(), 'read_content', array( 'id' => 123 ), $this->auth() );
			$encoded = (string) wp_json_encode( $result->to_array() );

			self::assertSame( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE, $result->code() );
			self::assertStringNotContainsString( 'output-secret', $encoded );
			self::assertArrayNotHasKey( 'output', $result->to_array() );
		}
	}

	public function test_complete_nested_collections_pass_the_closed_output_contract(): void {
		$complete = $this->complete_nested_output();
		$this->replace_content_module( true, $this->expected_input_schema(), static fn (): array => $complete );

		$result = ( new WorkflowAdapterRegistry() )->execute( $this->proposal_plan(), 'read_content', array( 'id' => 123 ), $this->auth() );

		self::assertTrue( $result->succeeded() );
		self::assertSame( 'category', $result->output()->terms->category[0]->taxonomy ?? null );
		self::assertSame( array( 0, 1 ), $result->output()->block_locators[0]->path ?? null );
		self::assertSame( array(), get_object_vars( $result->output()->terms->category[0]->image ?? new stdClass() ) );
	}

	public function test_nested_collection_drift_is_rejected_without_secret_leaks(): void {
		$unknown_term                                    = $this->complete_nested_output();
		$unknown_term['terms']['category'][0]['private'] = 'term-unknown-output-secret';
		$wrong_term                                      = $this->complete_nested_output();
		$wrong_term['terms']['category'][0]['count']     = 'term-type-output-secret';
		$unknown_image                                   = $this->complete_nested_output();
		$unknown_image['terms']['category'][0]['image']  = array(
			'attachment_id' => 456,
			'meta_key'      => 'thumbnail_id',
			'source_url'    => 'https://example.com/image.jpg',
			'private'       => 'image-output-secret',
		);
		$unknown_locator                                 = $this->complete_nested_output();
		$unknown_locator['block_locators'][0]['private'] = 'locator-unknown-output-secret';
		$wrong_path                                      = $this->complete_nested_output();
		$wrong_path['block_locators'][0]['path']         = array( 'path-type-output-secret' );

		foreach ( array( $unknown_term, $wrong_term, $unknown_image, $unknown_locator, $wrong_path ) as $hostile_output ) {
			$this->replace_content_module( true, $this->expected_input_schema(), static fn (): array => $hostile_output );

			$result  = ( new WorkflowAdapterRegistry() )->execute( $this->proposal_plan(), 'read_content', array( 'id' => 123 ), $this->auth() );
			$encoded = (string) wp_json_encode( $result->to_array() );

			self::assertSame( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE, $result->code() );
			self::assertStringNotContainsString( 'output-secret', $encoded );
			self::assertArrayNotHasKey( 'output', $result->to_array() );
		}
	}

	public function test_read_only_or_schema_drift_fails_before_dispatch(): void {
		$executions = 0;
		$this->replace_content_module(
			false,
			$this->expected_input_schema(),
			static function () use ( &$executions ): array {
				++$executions;
				return array( 'id' => 123 );
			}
		);
		$write_drift = ( new WorkflowAdapterRegistry() )->execute( $this->proposal_plan(), 'read_content', array( 'id' => 123 ), $this->auth() );
		self::assertSame( WorkflowAdapterResult::CODE_ABILITY_CONTRACT_MISMATCH, $write_drift->code() );
		self::assertSame( 0, $executions );

		$this->replace_content_module(
			true,
			array(
				'type'                 => 'object',
				'properties'           => array( 'post_id' => array( 'type' => 'integer' ) ),
				'additionalProperties' => false,
				'required'             => array( 'post_id' ),
			),
			static function () use ( &$executions ): array {
				++$executions;
				return array( 'id' => 123 );
			}
		);
		$schema_drift = ( new WorkflowAdapterRegistry() )->execute( $this->proposal_plan(), 'read_content', array( 'id' => 123 ), $this->auth() );
		self::assertSame( WorkflowAdapterResult::CODE_ABILITY_CONTRACT_MISMATCH, $schema_drift->code() );
		self::assertSame( 0, $executions );
	}

	public function test_execution_exception_does_not_leak_arguments_auth_or_message(): void {
		$this->replace_content_module(
			true,
			$this->expected_input_schema(),
			static function (): array {
				throw new RuntimeException( 'private exception detail' );
			}
		);

		$result  = ( new WorkflowAdapterRegistry() )->execute(
			$this->proposal_plan(),
			'read_content',
			array( 'id' => 123 ),
			array_merge( $this->auth(), array( 'client_secret' => 'private-client-secret' ) )
		);
		$encoded = (string) wp_json_encode( $result->to_array() );

		self::assertSame( WorkflowAdapterResult::CODE_EXECUTION_NOT_AVAILABLE, $result->code() );
		self::assertStringNotContainsString( 'private exception detail', $encoded );
		self::assertStringNotContainsString( 'private-client-secret', $encoded );
		self::assertStringNotContainsString( '123', $encoded );
	}

	public function test_adapter_source_has_no_direct_service_public_or_persistence_boundary(): void {
		$root   = dirname( __DIR__, 4 );
		$source = file_get_contents( $root . '/src/Workflows/Adapters/WordPressReadAdapter.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned source guard.
		self::assertIsString( $source );
		self::assertStringContainsString( '$this->gateway->execute(', $source );
		self::assertDoesNotMatchRegularExpression( '/(?:ContentAbilities|AbilitiesService|McpController|->abilities->execute\s*\(|->registry->execute\s*\()/i', $source );

		$files = glob( $root . '/src/Workflows/Adapters/*.php' );
		self::assertIsArray( $files );
		foreach ( $files as $file ) {
			$file_source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned source guard.
			self::assertIsString( $file_source );
			self::assertDoesNotMatchRegularExpression( '/(?:register_rest_route|wp_register_ability|add_action|add_filter|\$wpdb|get_option|update_option|add_option|delete_option|set_transient)/i', $file_source, basename( $file ) );
		}

		$plugin_source = file_get_contents( $root . '/src/Plugin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned source guard.
		self::assertIsString( $plugin_source );
		self::assertStringNotContainsString( 'WorkflowAdapterRegistry', $plugin_source );
	}

	/**
	 * Build a validated plan from the proposal-only fixture.
	 */
	private function proposal_plan(): WorkflowPlan {
		return ( new WorkflowPlanBuilder() )->build(
			( new WorkflowDefinitionFixtureLoader() )->load( 'proposal-only-v1.json' ),
			WorkflowInputContract::from_json( '{"post_id":123}' )
		);
	}

	/**
	 * Build the fixture whose read step requires unsupported wordpress@2.
	 */
	private function ordered_plan(): WorkflowPlan {
		return ( new WorkflowPlanBuilder() )->build(
			( new WorkflowDefinitionFixtureLoader() )->load( 'ordered-multi-step-v1.json' ),
			WorkflowInputContract::from_json( '{"brief":"Exact adapter version"}' )
		);
	}

	/**
	 * Build a valid one-step plan with one identity field changed.
	 *
	 * @param string     $field Step field.
	 * @param int|string $value Replacement value.
	 */
	private function mutated_plan( string $field, int|string $value ): WorkflowPlan {
		$path = dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/proposal-only-v1.json';
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned fixture.
		self::assertIsString( $json );
		$definition = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		self::assertIsArray( $definition );
		$definition['steps'][0][ $field ] = $value;
		if ( 'ability_id' === $field ) {
			$definition['allowed_abilities'] = array( $value );
		}

		return ( new WorkflowPlanBuilder() )->build(
			WorkflowDefinition::from_json( (string) wp_json_encode( $definition ) ),
			WorkflowInputContract::from_json( '{"post_id":123}' )
		);
	}

	/**
	 * Return a gateway auth context.
	 *
	 * @param int $user_id WordPress actor ID.
	 * @return array<string, mixed>
	 */
	private function auth( int $user_id = 1 ): array {
		return array(
			'user_id'   => $user_id,
			'client_id' => 'workflow-adapter-test-client',
			'provider'  => 'chatgpt',
			'scopes'    => array( 'content:read' ),
			'profile'   => McpToolProfiles::PROFILE_FULL_ACCESS,
		);
	}

	/**
	 * Create a readable test post.
	 *
	 * @param string $content Post content.
	 */
	private function post( string $content ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Adapter title',
				'post_name'         => 'adapter-title',
				'post_content'      => $content,
				'post_excerpt'      => 'Adapter excerpt',
				'post_author'       => 1,
				'post_date'         => '2026-08-19 10:00:00',
				'post_date_gmt'     => '2026-08-19 10:00:00',
				'post_modified_gmt' => '2026-08-19 10:00:00',
			)
		);
	}

	/**
	 * Replace only the process-local content.get_item test module.
	 *
	 * @param bool                 $read_only Whether replacement is read-only.
	 * @param array<string, mixed> $schema    Replacement input schema.
	 * @param callable             $handler   Replacement handler.
	 */
	private function replace_content_module( bool $read_only, array $schema, callable $handler ): void {
		$modules                     = ( new AbilitiesRegistry() )->modules();
		$modules['content.get_item'] = new CallbackAbilityModule(
			'content.get_item',
			'Replaced content item',
			'Test replacement.',
			'Content',
			array( 'content:read' ),
			$read_only,
			$schema,
			$handler( ... )
		);

		$property = new ReflectionProperty( AbilitiesRegistry::class, 'shared_modules' );
		$property->setValue( null, $modules );
	}

	/**
	 * Return the expected content.get_item input schema.
	 *
	 * @return array<string, mixed>
	 */
	private function expected_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'id' => array( 'type' => 'integer' ) ),
			'additionalProperties' => false,
			'required'             => array( 'id' ),
		);
	}

	/**
	 * Return one complete non-empty content.get_item result with both collections.
	 *
	 * @return array<string, mixed>
	 */
	private function complete_nested_output(): array {
		return array(
			'id'                  => 123,
			'type'                => 'post',
			'title'               => 'Adapter title',
			'slug'                => 'adapter-title',
			'status'              => 'draft',
			'content'             => 'Adapter content.',
			'excerpt'             => 'Adapter excerpt',
			'author'              => 1,
			'author_display_name' => 'Ada Admin',
			'featured_media'      => 0,
			'date'                => '2026-08-19 10:00:00',
			'date_gmt'            => '2026-08-19 10:00:00',
			'modified_gmt'        => '2026-08-19 10:00:00',
			'link'                => 'https://example.com/?p=123',
			'terms'               => array(
				'category' => array(
					array(
						'id'          => 7,
						'taxonomy'    => 'category',
						'name'        => 'News',
						'slug'        => 'news',
						'description' => 'News posts.',
						'parent'      => 0,
						'count'       => 3,
						'image'       => array(),
					),
				),
			),
			'block_locators'      => array(
				array(
					'path'       => array( 0, 1 ),
					'path_label' => '0/1',
					'block_name' => 'core/paragraph',
					'text'       => 'Adapter content.',
				),
			),
		);
	}
}

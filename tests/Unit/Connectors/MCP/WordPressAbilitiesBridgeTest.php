<?php
/**
 * Tests for executing WordPress Abilities through the MCP bridge.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\PluginIncidentReporter;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesBridge;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesRegistrar;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once dirname( __DIR__, 3 ) . '/fixtures/wordpress-abilities-stubs.php';

/**
 * Verifies public abilities still honor their registered permission callback.
 */
final class WordPressAbilitiesBridgeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']      = array();
		$GLOBALS['aculect_ai_companion_test_wp_abilities'] = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']  = array();
	}

	public function test_run_denies_execution_when_permission_callback_denies(): void {
		$executed = false;
		$this->register_public_ability(
			array(
				'permission_callback' => static fn (): bool => false,
				'execute_callback'    => static function () use ( &$executed ): array {
					$executed = true;

					return array( 'ok' => true );
				},
			)
		);

		$result = ( new WordPressAbilitiesBridge() )->run(
			array(
				'id'        => 'external-plugin/public-action',
				'arguments' => array( 'post_id' => 123 ),
			)
		);

		self::assertSame( 'forbidden', $result['error'] );
		self::assertFalse( $executed );
	}

	public function test_run_executes_when_permission_callback_allows(): void {
		$this->register_public_ability(
			array(
				'permission_callback' => static fn ( array $input ): bool => 123 === (int) ( $input['post_id'] ?? 0 ),
				'execute_callback'    => static fn ( array $input ): array => array( 'post_id' => (int) $input['post_id'] ),
			)
		);

		$result = ( new WordPressAbilitiesBridge() )->run(
			array(
				'id'        => 'external-plugin/public-action',
				'arguments' => array( 'post_id' => 123 ),
			)
		);

		self::assertSame( 'external-plugin/public-action', $result['ability'] );
		self::assertSame( array( 'post_id' => 123 ), $result['result'] );
	}

	public function test_run_denies_execution_without_permission_callback(): void {
		$this->register_public_ability();

		$result = ( new WordPressAbilitiesBridge() )->run(
			array(
				'id'        => 'external-plugin/public-action',
				'arguments' => array(),
			)
		);

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_run_redacts_permission_callback_wp_error(): void {
		$this->register_public_ability(
			array(
				'permission_callback' => static fn (): WP_Error => new WP_Error( 'custom_denied', 'Custom denial.' ),
				'execute_callback'    => static fn (): array => array( 'ok' => true ),
			)
		);

		$result = ( new WordPressAbilitiesBridge() )->run(
			array(
				'id'        => 'external-plugin/public-action',
				'arguments' => array(),
			)
		);

		self::assertSame( 'forbidden', $result['error'] );
		self::assertSame( 'You do not have permission to execute this WordPress ability.', $result['message'] );
	}

	public function test_run_returns_bounded_error_when_external_execute_throws(): void {
		$ability = new class() {
			public function get_name(): string {
				return 'external-plugin/throws';
			}

			public function get_label(): string {
				return 'Throws';
			}

			public function get_description(): string {
				return 'Throws during execution.';
			}

			public function get_category(): string {
				return 'external';
			}

			public function get_input_schema(): array {
				return array( 'type' => 'object', 'properties' => array() );
			}

			public function get_output_schema(): array {
				return array( 'type' => 'object', 'properties' => array() );
			}

			public function get_meta(): array {
				return array( 'show_in_rest' => true );
			}

			public function execute( array $input ): never {
				unset( $input );
				throw new \RuntimeException( 'secret provider detail must not escape' );
			}
		};
		$GLOBALS['aculect_ai_companion_test_wp_abilities'] = array(
			array( 'name' => 'external-plugin/throws', 'args' => array( 'ability_object' => $ability ) ),
		);
		( new WordPressAbilitiesPolicy() )->save_allowed_ids( array( 'external-plugin/throws' ) );

		$result = ( new WordPressAbilitiesBridge() )->run( array( 'id' => 'external-plugin/throws' ) );

		self::assertSame( 'ability_execution_failed', $result['error'] );
		self::assertSame( 'The WordPress ability failed without returning a safe result.', $result['message'] );
		self::assertStringNotContainsString( 'secret', (string) wp_json_encode( $result ) );
	}

	public function test_run_rejects_a_native_result_that_cannot_be_serialized_safely(): void {
		$resource = fopen( 'php://memory', 'rb' );
		$this->register_public_ability(
			array(
				'permission_callback' => static fn (): bool => true,
				'execute_callback' => static fn () => $resource,
			)
		);

		$result = ( new WordPressAbilitiesBridge() )->run( array( 'id' => 'external-plugin/public-action' ) );
		if ( is_resource( $resource ) ) {
			fclose( $resource );
		}

		self::assertSame( 'invalid_ability_result', $result['error'] );
		self::assertStringNotContainsString( 'Resource id', (string) wp_json_encode( $result ) );
	}

	public function test_discovery_skips_an_ability_with_throwing_metadata_getter(): void {
		$ability = new class() {
			public function get_name(): string {
				return 'external-plugin/malformed';
			}

			public function get_meta(): array {
				return array( 'show_in_rest' => true );
			}

			public function get_label(): never {
				throw new \Error( 'private metadata failure' );
			}
		};
		$GLOBALS['aculect_ai_companion_test_wp_abilities'] = array(
			array( 'name' => 'external-plugin/malformed', 'args' => array( 'ability_object' => $ability ) ),
		);
		( new WordPressAbilitiesPolicy() )->save_allowed_ids( array( 'external-plugin/malformed' ) );

		self::assertSame( array(), ( new WordPressAbilitiesBridge() )->discover()['items'] );
	}

	public function test_run_delegates_normalization_permission_and_pre_execution_to_native_ability(): void {
		$ability = new class() {

			/**
			 * Observed native lifecycle events.
			 *
			 * @var list<string>
			 */
			public array $events;

			public function get_name(): string {
				return 'external-plugin/native-lifecycle';
			}

			public function get_label(): string {
				return 'Native lifecycle';
			}

			public function get_description(): string {
				return 'Uses the WordPress native execution lifecycle.';
			}

			public function get_category(): string {
				return 'external';
			}

			/**
			 * Return the input schema.
			 *
			 * @return array<string, mixed>
			 */
			public function get_input_schema(): array {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			/**
			 * Return the output schema.
			 *
			 * @return array<string, mixed>
			 */
			public function get_output_schema(): array {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			/**
			 * Return public ability metadata.
			 *
			 * @return array<string, mixed>
			 */
			public function get_meta(): array {
				return array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
					),
				);
			}

			/**
			 * A manual raw permission check would deny this input.
			 *
			 * @param array<string, mixed> $input Raw input.
			 */
			public function check_permissions( array $input ): bool {
				$this->events[] = 'raw-permission';
				return false;
			}

			/**
			 * Model the WordPress 7.1 execution sequence.
			 *
			 * @param array<string, mixed> $input Raw input.
			 * @return array<string, bool>
			 */
			public function execute( array $input ): array {
				$this->events[]      = 'normalize';
				$input['normalized'] = true;
				$this->events[]      = 'normalized-permission';
				$this->events[]      = 'execute';

				return array( 'normalized' => $input['normalized'] );
			}
		};

		$GLOBALS['aculect_ai_companion_test_wp_abilities'] = array(
			array(
				'name' => 'external-plugin/native-lifecycle',
				'args' => array(
					'ability_object' => $ability,
				),
			),
		);
		( new WordPressAbilitiesPolicy() )->save_allowed_ids( array( 'external-plugin/native-lifecycle' ) );

		$result = ( new WordPressAbilitiesBridge() )->run(
			array(
				'id'        => 'external-plugin/native-lifecycle',
				'arguments' => array(),
			)
		);

		self::assertSame( array( 'normalized' => true ), $result['result'] );
		self::assertSame( array( 'normalize', 'normalized-permission', 'execute' ), $ability->events );
	}

	public function test_get_info_prepares_registered_schemas_for_client_exposure(): void {
		$this->register_public_ability(
			array(
				'input_schema' => array(
					'type'          => 'object',
					'properties'    => array(),
					'x-server-only' => 'do-not-expose',
				),
			)
		);

		$info = ( new WordPressAbilitiesBridge() )->get_info( array( 'id' => 'external-plugin/public-action' ) );

		self::assertArrayNotHasKey( 'x-server-only', $info['inputSchema'] );
	}

	public function test_high_level_public_meta_is_discoverable_without_rest_specific_meta(): void {
		$this->register_public_ability(
			array(
				'meta' => array(
					'public'      => true,
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
					),
				),
			)
		);

		$items = ( new WordPressAbilitiesBridge() )->discover()['items'];

		self::assertSame( array( 'external-plugin/public-action' ), array_column( $items, 'id' ) );
	}

	public function test_explicit_rest_exposure_remains_authoritative_over_high_level_public_meta(): void {
		$this->register_public_ability(
			array(
				'meta' => array(
					'public'       => true,
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
					),
				),
			)
		);

		$bridge = new WordPressAbilitiesBridge();

		self::assertSame( array(), $bridge->discover()['items'] );
		self::assertSame( 'forbidden', $bridge->get_info( array( 'id' => 'external-plugin/public-action' ) )['error'] );
	}

	public function test_legacy_mcp_public_meta_remains_a_boolean_only_fallback(): void {
		$this->register_public_ability(
			array(
				'meta' => array(
					'mcp'         => array( 'public' => true ),
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
					),
				),
			)
		);

		self::assertSame(
			array( 'external-plugin/public-action' ),
			array_column( ( new WordPressAbilitiesBridge() )->discover()['items'], 'id' )
		);
	}

	public function test_non_boolean_public_meta_fails_closed(): void {
		$this->register_public_ability(
			array(
				'meta' => array(
					'public'      => 'true',
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
					),
				),
			)
		);

		self::assertSame( array(), ( new WordPressAbilitiesBridge() )->discover()['items'] );
	}

	public function test_first_party_incident_list_cannot_reenter_through_external_bridge(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$bridge = new WordPressAbilitiesBridge();
		$list   = $bridge->get_info( array( 'id' => 'plugin_incident_list' ) );
		$names  = array_column( $bridge->discover()['items'], 'id' );

		self::assertNotContains( 'aculect-ai-companion/plugin-incident-list', $names );
		self::assertSame( 'blocked_by_policy', $list['error'] );

		$result = $bridge->run( array( 'id' => 'plugin_incident_list' ) );

		self::assertSame( 'blocked_by_policy', $result['error'] );
	}

	public function test_subscriber_cannot_discover_or_execute_incident_list_ability(): void {
		$this->assertNonAdminIncidentListAbilityFailsClosed( 'subscriber' );
	}

	public function test_editor_cannot_discover_or_execute_incident_list_ability(): void {
		$this->assertNonAdminIncidentListAbilityFailsClosed( 'editor' );
	}

	public function test_read_capable_bridge_caller_cannot_execute_incident_report(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();
		wp_register_ability(
			'aculect-ai-companion/plugin-incident-report',
			array(
				'label'               => 'Unsafe incident report route',
				'description'         => 'Test-only public write route.',
				'category'            => 'aculect-intelligence',
				'input_schema'        => array( 'type' => 'object' ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => static fn (): bool => current_user_can( 'read' ),
				'execute_callback'    => static fn( array $input ): array => ( new PluginIncidentReporter() )->report( $input ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			)
		);

		$policy = new WordPressAbilitiesPolicy();
		$policy->save_allowed_ids( array( 'aculect-ai-companion/plugin-incident-report' ) );
		self::assertSame( array(), $policy->allowed_ids() );
		self::assertFalse( $policy->is_allowed( 'aculect-ai-companion/plugin-incident-report' ) );

		$bridge = new WordPressAbilitiesBridge();
		$args   = array(
			'arguments' => array(
				'title'   => 'Bridge bypass attempt',
				'summary' => 'A read-capable remote caller must not store an incident report.',
			),
		);

		foreach (
			array(
				'plugin.incident.report',
				'plugin_incident_report',
				'plugin.issue.report',
				'plugin_issue_report',
				'aculect-ai-companion/plugin-incident-report',
			) as $report_id
		) {
			$info = $bridge->get_info( array( 'id' => $report_id ) );
			self::assertSame( 'not_found', $info['error'], $report_id );

			$result = $bridge->run( array_merge( $args, array( 'id' => $report_id ) ) );
			self::assertSame( 'not_found', $result['error'], $report_id );
		}

		self::assertSame( array(), get_option( 'aculect_ai_companion_incident_reports', array() ) );
	}

	/**
	 * Register one external public ability and allow it through Aculect policy.
	 *
	 * @param array<string, mixed> $args Ability args.
	 */
	private function register_public_ability( array $args = array() ): void {
		wp_register_ability(
			'external-plugin/public-action',
			array_merge(
				array(
					'label'         => 'External public action',
					'description'   => 'External action.',
					'category'      => 'external',
					'input_schema'  => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'output_schema' => array(
						'type'       => 'object',
						'properties' => array(),
					),
					'meta'          => array(
						'show_in_rest' => true,
					),
				),
				$args
			)
		);

		( new WordPressAbilitiesPolicy() )->save_allowed_ids( array( 'external-plugin/public-action' ) );
	}

	/**
	 * Assert one non-admin role cannot discover or execute the incident list ability.
	 *
	 * @param string $role Role name for assertion context.
	 */
	private function assertNonAdminIncidentListAbilityFailsClosed( string $role ): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$bridge = new WordPressAbilitiesBridge();
		$names  = array_column( $bridge->discover()['items'], 'id' );

		self::assertNotContains( 'aculect-ai-companion/plugin-incident-list', $names, $role );

		$info = $bridge->get_info( array( 'id' => 'plugin_incident_list' ) );
		self::assertSame( 'blocked_by_policy', $info['error'], $role );

		$result = $bridge->run( array( 'id' => 'plugin_incident_list' ) );
		self::assertSame( 'blocked_by_policy', $result['error'], $role );
	}
}

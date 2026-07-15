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

	public function test_run_returns_permission_error_without_permission_callback(): void {
		$this->register_public_ability();

		$result = ( new WordPressAbilitiesBridge() )->run(
			array(
				'id'        => 'external-plugin/public-action',
				'arguments' => array(),
			)
		);

		self::assertSame( 'permission_callback_unavailable', $result['error'] );
	}

	public function test_run_returns_permission_callback_wp_error(): void {
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

		self::assertSame( 'custom_denied', $result['error'] );
		self::assertSame( 'Custom denial.', $result['message'] );
	}

	public function test_administrator_can_discover_and_execute_incident_list_alias(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$bridge = new WordPressAbilitiesBridge();
		$list   = $bridge->get_info( array( 'id' => 'plugin_incident_list' ) );
		$names  = array_column( $bridge->discover()['items'], 'id' );

		self::assertContains( 'aculect-ai-companion/plugin-incident-list', $names );
		self::assertSame( 'aculect-ai-companion/plugin-incident-list', $list['id'] );
		self::assertTrue( $list['readOnly'] );
		self::assertTrue( $list['allowed'] );
		self::assertSame( 'plugin_incident_list', $list['meta']['mcp']['tool'] );

		$result = $bridge->run( array( 'id' => 'plugin_incident_list' ) );

		self::assertSame( 'aculect-ai-companion/plugin-incident-list', $result['ability'] );
		self::assertSame( 0, $result['result']['total'] );
		self::assertArrayNotHasKey( 'confirmation_required', $result['result'] );
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
		self::assertSame( 'forbidden', $info['error'], $role );

		$result = $bridge->run( array( 'id' => 'plugin_incident_list' ) );
		self::assertSame( 'forbidden', $result['error'], $role );
	}
}

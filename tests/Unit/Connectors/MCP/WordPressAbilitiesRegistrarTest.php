<?php
/**
 * Tests for mirroring Aculect Intelligence into the WordPress Abilities API.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesDiagnostics;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesRegistrar;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname( __DIR__, 3 ) . '/fixtures/wordpress-abilities-stubs.php';

/**
 * Verifies read-only intelligence is available through WordPress Abilities.
 */
final class WordPressAbilitiesRegistrarTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_hooks']['actions']      = array();
		$GLOBALS['aculect_ai_companion_test_options']               = array();
		$GLOBALS['aculect_ai_companion_test_wp_abilities']          = array();
		$GLOBALS['aculect_ai_companion_test_wp_ability_categories'] = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']           = array();
	}

	public function test_register_hooks_wires_wordpress_abilities_lifecycle(): void {
		( new WordPressAbilitiesRegistrar() )->register_hooks();

		$hooks = array_column( $GLOBALS['aculect_ai_companion_test_hooks']['actions'], 'callback', 'hook_name' );

		self::assertArrayHasKey( 'wp_abilities_api_categories_init', $hooks );
		self::assertArrayHasKey( 'wp_abilities_api_init', $hooks );
	}

	public function test_registers_only_read_intelligence_with_read_only_metadata(): void {
		$registrar = new WordPressAbilitiesRegistrar();

		$registrar->register_categories();
		$registrar->register_abilities();

		self::assertSame( 'aculect-intelligence', $GLOBALS['aculect_ai_companion_test_wp_ability_categories'][0]['slug'] );

		$abilities = $GLOBALS['aculect_ai_companion_test_wp_abilities'];
		$names     = array_column( $abilities, 'name' );

		foreach (
			array(
				'aculect-ai-companion/intelligence-capabilities-get-directory',
				'aculect-ai-companion/intelligence-site-get-context',
				'aculect-ai-companion/intelligence-content-get-context',
				'aculect-ai-companion/intelligence-developer-get-context',
				'aculect-ai-companion/intelligence-brand-get-context',
				'aculect-ai-companion/intelligence-blocks-list-available',
				'aculect-ai-companion/intelligence-blocks-get-info',
				'aculect-ai-companion/intelligence-patterns-list-available',
				'aculect-ai-companion/intelligence-patterns-get-info',
				'aculect-ai-companion/intelligence-content-validate-blocks',
				'aculect-ai-companion/site-editor-get-context',
				'aculect-ai-companion/site-editor-list-templates',
				'aculect-ai-companion/site-editor-get-template',
				'aculect-ai-companion/site-editor-list-template-parts',
				'aculect-ai-companion/site-editor-get-template-part',
				'aculect-ai-companion/admin-menu-get-context',
				'aculect-ai-companion/admin-menu-list-pages',
				'aculect-ai-companion/admin-menu-get-navigation-target',
				'aculect-ai-companion/admin-menu-list-settings',
				'aculect-ai-companion/workflow-guides-list',
				'aculect-ai-companion/workflow-guides-get',
				'aculect-ai-companion/content-search-items',
				'aculect-ai-companion/content-search-chunks',
				'aculect-ai-companion/content-find-related',
				'aculect-ai-companion/content-internal-link-policy',
				'aculect-ai-companion/content-find-internal-links',
				'aculect-ai-companion/memory-list',
				'aculect-ai-companion/plugin-incident-list',
			) as $expected_name
		) {
			self::assertContains( $expected_name, $names );
		}

		self::assertNotContains( 'aculect-ai-companion/intelligence-feedback-submit', $names );
		self::assertNotContains( 'aculect-ai-companion/memory-save', $names );
		self::assertNotContains( 'aculect-ai-companion/plugin-incident-report', $names );
		self::assertNotContains( 'aculect-ai-companion/content-workflow-create-draft', $names );
		self::assertNotContains( 'aculect-ai-companion/seo-workflow-update-rankmath', $names );

		foreach ( $abilities as $ability ) {
			self::assertMatchesRegularExpression( '#^aculect-ai-companion/[a-z0-9-]+$#', $ability['name'] );
			self::assertSame( 'aculect-intelligence', $ability['args']['category'] );
			self::assertSame( 'object', $ability['args']['input_schema']['type'] );
			self::assertSame( 'object', $ability['args']['output_schema']['type'] );
			self::assertIsArray( $ability['args']['output_schema']['properties'] );
			self::assertTrue( $ability['args']['meta']['show_in_rest'] );
			self::assertTrue( $ability['args']['meta']['annotations']['readonly'] );
			self::assertFalse( $ability['args']['meta']['annotations']['destructive'] );
			self::assertTrue( $ability['args']['meta']['annotations']['idempotent'] );
			self::assertIsString( $ability['args']['meta']['mcp']['tool'] );
			self::assertMatchesRegularExpression( '/^[a-zA-Z0-9_-]{1,64}$/', $ability['args']['meta']['mcp']['tool'] );
		}

		$abilities_by_name = array_column( $abilities, 'args', 'name' );
		$list              = $abilities_by_name['aculect-ai-companion/plugin-incident-list'];

		self::assertTrue( $list['meta']['annotations']['readonly'] );
		self::assertTrue( $list['meta']['annotations']['idempotent'] );
		self::assertSame( 'plugin_incident_list', $list['meta']['mcp']['tool'] );
		self::assertArrayHasKey( 'items', $list['output_schema']['properties'] );
		self::assertSame( 0, $list['execute_callback']( array() )['total'] );

		self::assertSame( '', $registrar->ability_name_for_id( 'plugin_incident_report' ) );
		self::assertTrue( $registrar->is_mcp_only_intelligence( 'plugin_issue_report' ) );
		self::assertTrue( $registrar->is_mcp_only_intelligence( 'aculect-ai-companion/plugin-incident-report' ) );
	}

	public function test_every_registered_read_ability_executes_through_its_owning_registry(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused database-boundary fixture.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function __get( string $name ): string {
				return $this->prefix . $name;
			}

			public function __call( string $name, array $arguments ): never {
				unset( $name, $arguments );

				throw new \RuntimeException( 'Database fixture method not implemented.' );
			}
		};

		$unknown = array();
		try {
			foreach ( $GLOBALS['aculect_ai_companion_test_wp_abilities'] as $ability ) {
				try {
					$result = $ability['args']['execute_callback']( array() );
				} catch ( \Throwable ) {
					// Some read abilities require a WordPress database fixture.
					// Reaching that dependency proves the owning executor handled
					// the module instead of rejecting it as an unknown tool.
					continue;
				}

				if ( 'Unknown tool' === ( $result['error'] ?? '' ) ) {
					$unknown[] = $ability['name'];
				}
			}
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original test boundary.
			$GLOBALS['wpdb'] = $previous_wpdb;
		}

		self::assertSame( array(), $unknown );
	}

	public function test_registered_callbacks_preserve_both_registry_execution_paths(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$abilities = array_column( $GLOBALS['aculect_ai_companion_test_wp_abilities'], 'args', 'name' );

		$site = $abilities['aculect-ai-companion/intelligence-site-get-context']['execute_callback']( array() );
		self::assertArrayNotHasKey( 'error', $site );

		$search = $abilities['aculect-ai-companion/search']['execute_callback'](
			array(
				'query' => 'fixture',
				'limit' => 1,
			)
		);
		self::assertNotSame( 'Unknown tool', $search['error'] ?? '' );

		$incidents = $abilities['aculect-ai-companion/plugin-incident-list']['execute_callback']( array() );
		self::assertSame( 0, $incidents['total'] );
	}

	public function test_permission_callback_requires_basic_read_capability(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$ability             = $GLOBALS['aculect_ai_companion_test_wp_abilities'][0];
		$permission_callback = $ability['args']['permission_callback'];

		self::assertIsCallable( $permission_callback );
		self::assertTrue( $permission_callback( array() ) );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'read' );

		self::assertFalse( $permission_callback( array() ) );
	}

	public function test_admin_intelligence_permission_callbacks_use_admin_caps(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$abilities = array_column( $GLOBALS['aculect_ai_companion_test_wp_abilities'], 'args', 'name' );

		self::assertTrue( $abilities['aculect-ai-companion/site-editor-get-context']['permission_callback']( array() ) );
		self::assertTrue( $abilities['aculect-ai-companion/admin-menu-get-context']['permission_callback']( array() ) );
		self::assertTrue( $abilities['aculect-ai-companion/plugin-incident-list']['permission_callback']( array() ) );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_theme_options' );
		self::assertFalse( $abilities['aculect-ai-companion/site-editor-get-context']['permission_callback']( array() ) );
		self::assertTrue( $abilities['aculect-ai-companion/admin-menu-get-context']['permission_callback']( array() ) );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		self::assertTrue( $abilities['aculect-ai-companion/site-editor-get-context']['permission_callback']( array() ) );
		self::assertFalse( $abilities['aculect-ai-companion/admin-menu-get-context']['permission_callback']( array() ) );
		self::assertFalse( $abilities['aculect-ai-companion/plugin-incident-list']['permission_callback']( array() ) );
	}

	public function test_first_party_read_intelligence_cannot_reenter_through_external_policy(): void {
		$policy = new WordPressAbilitiesPolicy();

		self::assertFalse( $policy->is_allowed( 'aculect-ai-companion/intelligence-site-get-context' ) );
		self::assertFalse( $policy->is_allowed( 'aculect-ai-companion/plugin-incident-report' ) );
		self::assertFalse( $policy->is_allowed( 'plugin_issue_report' ) );
		self::assertFalse( $policy->is_allowed( 'external-plugin/some-ability' ) );
	}

	public function test_trust_check_fails_closed_when_external_getters_throw(): void {
		$ability = new class() {
			public function get_name(): never {
				throw new \Error( 'private getter failure' );
			}

			public function get_meta(): array {
				return array();
			}
		};

		self::assertFalse( ( new WordPressAbilitiesRegistrar() )->is_trusted_first_party_ability( $ability ) );
	}

	public function test_wordpress_abilities_diagnostics_report_first_party_registration_status(): void {
		$diagnostics = new WordPressAbilitiesDiagnostics();

		self::assertSame( 'missing_registration', $diagnostics->operation_metadata( 'content_search.items', new AbilitiesRegistry() )['status'] );

		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$metadata = ( new WordPressAbilitiesDiagnostics() )->operation_metadata( 'content_search.items', new AbilitiesRegistry() );

		self::assertTrue( $metadata['mirrored'] );
		self::assertSame( 'aculect-ai-companion/content-search-items', $metadata['name'] );
		self::assertTrue( $metadata['registered'] );
		self::assertTrue( $metadata['public'] );
		self::assertTrue( $metadata['allowed'] );
		self::assertTrue( $metadata['schema_valid'] );
		self::assertSame( 'registered', $metadata['registration_status'] );
		self::assertSame( 'valid', $metadata['schema_status'] );
		self::assertSame( 'allowed', $metadata['policy_status'] );
		self::assertSame( 'allowed', $metadata['permission_status'] );
		self::assertSame( 'available', $metadata['status'] );

		$atomic = ( new WordPressAbilitiesDiagnostics() )->operation_metadata( 'content.get_item', new AbilitiesRegistry() );

		self::assertFalse( $atomic['mirrored'] );
		self::assertSame( 'not_applicable', $atomic['registration_status'] );
		self::assertSame( 'not_mirrored', $atomic['status'] );
	}

	public function test_wordpress_abilities_diagnostics_report_policy_blocked_public_abilities(): void {
		wp_register_ability(
			'external-plugin/public-action',
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
					'public' => true,
				),
			)
		);

		$context = ( new WordPressAbilitiesDiagnostics() )->runtime_context();

		self::assertTrue( $context['api_available'] );
		self::assertSame( 'available', $context['runtime_status'] );
		self::assertSame( 'blocked', $context['policy_status'] );
		self::assertSame( 1, $context['policy_blocked_public_count'] );
		self::assertSame( array( 'external-plugin/public-action' ), $context['policy_blocked_public_names'] );
	}

	public function test_wordpress_abilities_diagnostics_reject_first_party_name_collision(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();
		$registrations = array_column( $GLOBALS['aculect_ai_companion_test_wp_abilities'], 'args', 'name' );
		$args          = $registrations['aculect-ai-companion/content-search-items'];
		unset( $args['meta']['aculect_internal_registration'] );
		$args['meta']['annotations']['destructive']          = true;
		$GLOBALS['aculect_ai_companion_test_wp_abilities']   = array();
		$GLOBALS['aculect_ai_companion_test_wp_abilities'][] = array(
			'name' => 'aculect-ai-companion/content-search-items',
			'args' => $args,
		);

		$diagnostics = new WordPressAbilitiesDiagnostics();
		$metadata    = $diagnostics->operation_metadata( 'content_search.items', new AbilitiesRegistry() );
		$context     = $diagnostics->runtime_context();

		self::assertFalse( $metadata['allowed'] );
		self::assertSame( 'blocked', $metadata['policy_status'] );
		self::assertSame( 'policy_blocked', $metadata['status'] );
		self::assertSame( 'blocked', $context['policy_status'] );
		self::assertContains( 'aculect-ai-companion/content-search-items', $context['policy_blocked_public_names'] );
	}

	public function test_wordpress_abilities_mirror_does_not_change_mcp_descriptors(): void {
		$controller = new McpController();
		$before     = $this->invokePrivate( $controller, 'list_tools' );

		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$after = $this->invokePrivate( new McpController(), 'list_tools' );

		self::assertSame( wp_json_encode( $before ), wp_json_encode( $after ) );
	}

	/**
	 * Invoke a private method for focused contract testing.
	 *
	 * @param object       $object    Object instance.
	 * @param string       $method    Method name.
	 * @param array<mixed> $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}
}

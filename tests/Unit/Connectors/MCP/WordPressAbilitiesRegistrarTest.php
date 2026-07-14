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

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_theme_options' );
		self::assertFalse( $abilities['aculect-ai-companion/site-editor-get-context']['permission_callback']( array() ) );
		self::assertTrue( $abilities['aculect-ai-companion/admin-menu-get-context']['permission_callback']( array() ) );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		self::assertTrue( $abilities['aculect-ai-companion/site-editor-get-context']['permission_callback']( array() ) );
		self::assertFalse( $abilities['aculect-ai-companion/admin-menu-get-context']['permission_callback']( array() ) );
	}

	public function test_first_party_read_intelligence_is_allowed_without_external_policy_toggle(): void {
		$policy = new WordPressAbilitiesPolicy();

		self::assertTrue( $policy->is_allowed( 'aculect-ai-companion/intelligence-site-get-context' ) );
		self::assertFalse( $policy->is_allowed( 'aculect-ai-companion/plugin-incident-report' ) );
		self::assertFalse( $policy->is_allowed( 'plugin_issue_report' ) );
		self::assertFalse( $policy->is_allowed( 'external-plugin/some-ability' ) );
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
					'show_in_rest' => true,
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

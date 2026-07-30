<?php
/**
 * Settings page payload tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\SettingsPage;
use Aculect\AICompanion\Brand\BrandProfile;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use Aculect\AICompanion\Intelligence\LearningSuggestionRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Verifies tab-specific admin payload hydration stays bounded.
 */
final class SettingsPageTest extends TestCase {

	private FakeSettingsPageWpdb $wpdb;

	private mixed $original_wpdb = null;

	/**
	 * Original GET data.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_get = array();

	protected function setUp(): void {
		parent::setUp();

		$this->original_get  = $_GET;
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb          = new FakeSettingsPageWpdb();

		$GLOBALS['wpdb']                                       = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_environment_type'] = 'production';
		$GLOBALS['aculect_ai_companion_test_admin_pages']      = array(
			'menu'    => array(),
			'options' => array(),
			'submenu' => array(),
		);
		$GLOBALS['aculect_ai_companion_test_hooks']            = array(
			'actions' => array(),
			'filters' => array(),
		);
		$GLOBALS['aculect_ai_companion_test_users']            = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']      = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']  = 5;
		$_GET = array(
			'page' => 'aculect-ai-companion',
		);
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
	}

	public function test_register_adds_settings_page_without_top_level_menu(): void {
		( new SettingsPage() )->register();

		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_admin_pages']['menu'] );
		self::assertCount( 1, $GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'] );
		self::assertSame( '', $GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'][0]['parent_slug'] );
		self::assertSame( 'read', $GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'][0]['capability'] );
		self::assertSame( 'aculect-ai-companion-oauth-consent', $GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'][0]['menu_slug'] );
		self::assertCount( 1, $GLOBALS['aculect_ai_companion_test_admin_pages']['options'] );
		self::assertSame( 'AI Companion', $GLOBALS['aculect_ai_companion_test_admin_pages']['options'][0]['menu_title'] );
		self::assertSame( 'manage_options', $GLOBALS['aculect_ai_companion_test_admin_pages']['options'][0]['capability'] );
		self::assertSame( 'aculect-ai-companion', $GLOBALS['aculect_ai_companion_test_admin_pages']['options'][0]['menu_slug'] );
		self::assertContains( 'admin_enqueue_scripts', array_column( $GLOBALS['aculect_ai_companion_test_hooks']['actions'], 'hook_name' ) );
		self::assertContains( 'parent_file', array_column( $GLOBALS['aculect_ai_companion_test_hooks']['filters'], 'hook_name' ) );
		self::assertContains( 'submenu_file', array_column( $GLOBALS['aculect_ai_companion_test_hooks']['filters'], 'hook_name' ) );
	}

	public function test_settings_urls_and_menu_highlighting_use_wordpress_settings_parent(): void {
		$page = new SettingsPage();
		$url  = $this->invokePrivate(
			$page,
			'settings_url',
			array(
				array(
					'tab' => 'connections',
				),
			)
		);

		self::assertSame( 'https://example.com/wp-admin/options-general.php?page=aculect-ai-companion&tab=connections', $url );
		self::assertSame( 'options-general.php', $page->highlight_parent_menu( 'plugins.php' ) );
		self::assertSame( 'aculect-ai-companion', $page->highlight_submenu( 'options-general.php' ) );

		$_GET = array();

		self::assertSame( 'plugins.php', $page->highlight_parent_menu( 'plugins.php' ) );
		self::assertSame( 'options-general.php', $page->highlight_submenu( 'options-general.php' ) );
	}

	public function test_overview_payload_defers_tab_specific_data(): void {
		$payload = $this->settings_payload();

		self::assertSame( 'overview', $payload['payloadTab'] );
		self::assertSame( array( 'overview', 'connect', 'diagnostics', 'advanced' ), $payload['hydratedTabs'] );
		self::assertSame(
			'https://example.com/wp-content/plugins/aculect-ai-companion/assets/images/aculect-icon-light.svg',
			$payload['brandIconUrl']
		);
		self::assertSame(
			'https://example.com/wp-content/plugins/aculect-ai-companion/assets/images/aculect-mark.svg',
			$payload['brandMarkUrl']
		);
		self::assertSame(
			'https://example.com/wp-content/plugins/aculect-ai-companion/assets/images/connectors/cursor.svg',
			$payload['connectorLogoUrls']['cursor']
		);
		self::assertSame(
			'https://example.com/wp-content/plugins/aculect-ai-companion/assets/images/connectors/gemini.svg',
			$payload['connectorLogoUrls']['gemini']
		);
		self::assertSame(
			'https://example.com/wp-content/plugins/aculect-ai-companion/assets/images/connectors/mcp-client.svg',
			$payload['connectorLogoUrls']['mcp']
		);
		self::assertSame(
			'https://wordpress.org/plugins/aculect-ai-companion/',
			$payload['pluginMetadata']['documentationUrl']
		);
		self::assertSame( 'https://example.com/wp-json/aculect-ai-companion/v1/settings-payload', $payload['settingsPayloadUrl'] );
		self::assertSame( 'nonce-wp_rest', $payload['settingsRestNonce'] );
		self::assertTrue( $payload['isConnected'] );
		self::assertSame( 2, $payload['activeSessionCount'] );
		self::assertSame( 'interactive_oauth', $payload['connectionRequests']['approvalMode'] );
		self::assertFalse( $payload['connectionRequests']['approvalModeEnabled'] );
		self::assertFalse( $payload['connectionRequests']['queueAvailable'] );
		self::assertSame( 'disabled', $payload['connectionRequests']['status'] );
		self::assertSame( 0, $payload['connectionRequests']['pendingCount'] );
		self::assertSame( array(), $payload['connectionRequests']['items'] );
		self::assertSame( 'https://example.com/wp-json/aculect-ai-companion/v1/mcp', $payload['mcpUrl'] );
		self::assertSame( array(), $payload['sessions'] );
		self::assertSame( array(), $payload['revokedSessions'] );
		self::assertFalse( $payload['roleAbilities']['enabled'] );
		self::assertSame( array(), $payload['roleAbilityPolicy'] );
		self::assertSame( array(), $payload['brandProfile'] );
		self::assertSame( 0, $payload['learningSuggestions']['summary']['total'] );
		self::assertSame( 0, $payload['memoryRecords']['summary']['total'] );
		self::assertArrayNotHasKey( 'internalLinksMap', $payload );
		self::assertSame( array(), $payload['changelog'] );
		self::assertIsArray( $payload['providers'] );
		$providers = array_column( $payload['providers'], null, 'id' );
		self::assertArrayHasKey( 'chatgpt', $providers );
		self::assertSame( 'https://chatgpt.com/#settings/Apps', $providers['chatgpt']['primaryActionUrl'] );
		self::assertSame( 'Open ChatGPT Apps', $providers['chatgpt']['primaryActionLabel'] );
		self::assertStringContainsString( 'Settings > Apps', implode( ' ', $providers['chatgpt']['setupSections'][0]['steps'] ) );
		self::assertStringNotContainsString( 'Settings > Connectors', implode( ' ', $providers['chatgpt']['setupSections'][0]['steps'] ) );
		self::assertArrayHasKey( 'claude', $providers );
		self::assertIsArray( $providers['claude'] );
		self::assertSame( 'https://claude.ai/customize/connectors', $providers['claude']['primaryActionUrl'] );
		self::assertArrayHasKey( 'wizard', $providers['claude'] );
		self::assertSame( 'Open Claude', $providers['claude']['wizard']['steps'][0]['title'] );
		self::assertArrayHasKey( 'codex', $providers );
		self::assertSame( 'https://developers.openai.com/codex/mcp', $providers['codex']['primaryActionUrl'] );
		self::assertStringContainsString( 'Streamable HTTP', $providers['codex']['setupSections'][0]['description'] );
		self::assertSame( 'aculect_ai_companion', $providers['codex']['wizard']['steps'][1]['copyFields'][0]['value'] );
		self::assertSame( 'MCP Server Name', $providers['codex']['setupSections'][0]['copyFields'][0]['label'] );
		self::assertSame( 'aculect_ai_companion', $providers['codex']['setupSections'][0]['copyFields'][0]['value'] );
		self::assertSame( 'MCP URL', $providers['codex']['setupSections'][0]['copyFields'][1]['label'] );
		self::assertSame( 'https://example.com/wp-json/aculect-ai-companion/v1/mcp', $providers['codex']['setupSections'][0]['copyFields'][1]['value'] );
		self::assertStringContainsString( 'codex mcp login aculect_ai_companion', $providers['codex']['setupSections'][0]['copyFields'][2]['value'] );
		self::assertStringContainsString( '[mcp_servers.aculect_ai_companion]', $providers['codex']['setupSections'][1]['copyFields'][0]['value'] );
		self::assertStringNotContainsString( 'scopes =', $providers['codex']['setupSections'][1]['copyFields'][0]['value'] );
		self::assertArrayHasKey( 'cursor', $providers );
		self::assertSame( 'https://cursor.com/docs/mcp', $providers['cursor']['primaryActionUrl'] );
		self::assertSame( 'Add MCP server', $providers['cursor']['wizard']['steps'][1]['title'] );
		self::assertStringContainsString( '"url": "https://example.com/wp-json/aculect-ai-companion/v1/mcp"', $providers['cursor']['setupSections'][0]['copyFields'][0]['value'] );
		self::assertStringContainsString( '.cursor/mcp.json', implode( ' ', $providers['cursor']['setupSections'][0]['steps'] ) );
		self::assertArrayHasKey( 'gemini', $providers );
		self::assertStringContainsString( 'CLI add command', $providers['gemini']['wizard']['steps'][1]['copyFields'][0]['label'] );
		self::assertStringContainsString( 'settings.json', $providers['gemini']['wizard']['steps'][1]['copyFields'][1]['label'] );
		self::assertStringContainsString( '"httpUrl": "https://example.com/wp-json/aculect-ai-companion/v1/mcp"', $providers['gemini']['setupSections'][0]['copyFields'][1]['value'] );
		self::assertStringContainsString( 'Gemini web does not currently provide', $providers['gemini']['wizard']['steps'][0]['description'] );
		self::assertStringContainsString( 'gemini.google.com', implode( ' ', $providers['gemini']['setupSections'][2]['steps'] ) );
		self::assertSame( 0, $payload['activity']['total'] );
		self::assertSame( 0, $payload['diagnostics']['logs']['total'] );
		self::assertSame(
			'aculect_ai_companion_set_session_access_level',
			$payload['actions']['setSessionAccessLevelAction']
		);
		self::assertSame(
			'nonce-aculect_ai_companion_set_session_access_level',
			$payload['actions']['setSessionAccessLevelNonce']
		);
		self::assertSame(
			'aculect_ai_companion_set_session_write_permission',
			$payload['actions']['setSessionWritePermissionAction']
		);
		self::assertSame(
			'nonce-aculect_ai_companion_set_session_write_permission',
			$payload['actions']['setSessionWritePermissionNonce']
		);
		self::assertSame(
			'aculect_ai_companion_export_mcp_tool_manifest',
			$payload['actions']['exportMcpToolManifestAction']
		);
		self::assertSame(
			'nonce-aculect_ai_companion_export_mcp_tool_manifest',
			$payload['actions']['exportMcpToolManifestNonce']
		);
		self::assertSame(
			'aculect_ai_companion_run_content_index_sweep',
			$payload['actions']['runContentIndexSweepAction']
		);
		self::assertSame(
			'nonce-aculect_ai_companion_run_content_index_sweep',
			$payload['actions']['runContentIndexSweepNonce']
		);
		self::assertSame(
			'aculect_ai_companion_review_learning_suggestion',
			$payload['actions']['reviewLearningSuggestionAction']
		);
		self::assertSame(
			'nonce-aculect_ai_companion_review_learning_suggestion',
			$payload['actions']['reviewLearningSuggestionNonce']
		);
		self::assertSame(
			'aculect_ai_companion_review_memory_item',
			$payload['actions']['reviewMemoryAction']
		);
		self::assertSame(
			'nonce-aculect_ai_companion_review_memory_item',
			$payload['actions']['reviewMemoryNonce']
		);
		self::assertFalse( $this->wpdb->has_query_fragment( 'ORDER BY access_tokens.created_at DESC' ) );
		self::assertFalse( $this->wpdb->has_query_fragment( 'wp_aculect_ai_companion_activity' ) );
		self::assertFalse( $this->wpdb->has_query_fragment( 'wp_aculect_ai_companion_logs' ) );
		self::assertFalse( $this->wpdb->has_query_fragment( 'wp_aculect_ai_content_index' ) );
	}

	public function test_connections_payload_loads_session_lists_only_for_connections_tab(): void {
		$_GET['tab'] = 'connections';

		$payload = $this->settings_payload();

		self::assertSame( 'connections', $payload['payloadTab'] );
		self::assertContains( 'connections', $payload['hydratedTabs'] );
		self::assertTrue( $this->wpdb->has_query_fragment( 'refresh_tokens.revoked = 0' ) );
		self::assertTrue( $this->wpdb->has_query_fragment( 'refresh_tokens.expires_at >= %s' ) );
		self::assertTrue( $this->wpdb->has_query_fragment( 'WHERE access_tokens.revoked = 1' ) );
	}

	public function test_connections_payload_includes_effective_ability_details(): void {
		$_GET['tab'] = 'connections';
		$GLOBALS['aculect_ai_companion_test_users'][7] = (object) array(
			'ID'           => 7,
			'roles'        => array( 'administrator' ),
			'display_name' => 'Admin User',
			'user_login'   => 'admin',
		);
		$this->wpdb->active_session_rows                = array(
			array(
				'id'                       => '5',
				'client_id'                => 'client-1',
				'user_id'                  => '7',
				'scopes'                   => '["content:read"]',
				'resource'                 => 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
				'access_token_expires_at'  => '2026-06-01 01:00:00',
				'connection_expires_at'    => '2026-07-01 00:00:00',
				'created_at'               => '2026-06-01 00:00:00',
				'last_used_at'             => '',
				'write_permission_enabled' => '0',
				'access_level'             => 'read',
				'client_name'              => 'ChatGPT',
				'provider'                 => 'chatgpt',
			),
		);

		$payload = $this->settings_payload();
		$session = $payload['sessions'][0];
		$ids     = array_column( $session['effective_abilities'], 'id' );

		self::assertContains( 'content.get_item', $ids );
		self::assertNotContains( 'content.update_item', $ids );
		self::assertTrue( $session['effective_ability_summary']['scope_aware'] );
		self::assertSame( count( $session['effective_abilities'] ), $session['effective_ability_summary']['available_count'] );
		self::assertArrayHasKey( 'effective_write_ability_count', $session );
	}

	public function test_activity_payload_loads_activity_rows_only_for_activity_tab(): void {
		$_GET['tab']             = 'activity';
		$_GET['activity_status'] = 'success';
		$_GET['activity_range']  = '30d';

		$payload = $this->settings_payload();

		self::assertSame( 'activity', $payload['payloadTab'] );
		self::assertContains( 'activity', $payload['hydratedTabs'] );
		self::assertSame( 7, $payload['activity']['total'] );
		self::assertSame( 7, $payload['activity']['summary']['total'] );
		self::assertSame( 'content.update_item', $payload['activity']['items'][0]['action'] );
		self::assertSame( 'success', $payload['activity']['filters']['status'] );
		self::assertSame( '30d', $payload['activity']['filters']['range'] );
		self::assertTrue( $this->wpdb->has_query_fragment( 'wp_aculect_ai_companion_activity' ) );
		self::assertFalse( $this->wpdb->has_query_fragment( 'ORDER BY access_tokens.created_at DESC' ) );
	}

	public function test_logs_payload_loads_log_rows_only_for_logs_tab_when_enabled(): void {
		update_option( 'aculect_ai_companion_logging_enabled', '1', false );
		$_GET['tab'] = 'logs';

		$payload = $this->settings_payload();

		self::assertSame( 'logs', $payload['payloadTab'] );
		self::assertContains( 'logs', $payload['hydratedTabs'] );
		self::assertTrue( $payload['diagnostics']['loggingEnabled'] );
		self::assertSame( 3, $payload['diagnostics']['logs']['total'] );
		self::assertSame( 'oauth.registered', $payload['diagnostics']['logs']['items'][0]['event'] );
		self::assertTrue( $this->wpdb->has_query_fragment( 'wp_aculect_ai_companion_logs' ) );
	}

	public function test_brand_and_changelog_payloads_load_only_for_matching_hidden_tabs(): void {
		( new BrandProfile() )->save(
			array(
				'site_name' => 'Payload Brand',
			)
		);

		$_GET['tab'] = 'brand';
		$brand       = $this->settings_payload();

		self::assertSame( 'brand', $brand['payloadTab'] );
		self::assertContains( 'brand', $brand['hydratedTabs'] );
		self::assertSame( 'Payload Brand', $brand['brandProfile']['fields']['site_name'] );
		self::assertSame( array(), $brand['changelog'] );

		$_GET['tab'] = 'changelog';
		$changelog   = $this->settings_payload();

		self::assertSame( 'changelog', $changelog['payloadTab'] );
		self::assertContains( 'changelog', $changelog['hydratedTabs'] );
		self::assertSame( array(), $changelog['brandProfile'] );
		self::assertArrayHasKey( '0.6.0', $changelog['changelog'] );
		self::assertSame( '2026-06-16', $changelog['changelog']['0.6.0']['date'] );
	}

	public function test_learning_payload_loads_suggestions_only_for_learning_tab(): void {
		( new LearningSuggestionRepository() )->submit(
			array(
				'domain'           => 'site',
				'issue'            => 'Missing site guidance.',
				'suggested_update' => 'Include more durable site context.',
			)
		);

		$_GET['tab'] = 'learning';
		$learning    = $this->settings_payload();

		self::assertSame( 'learning', $learning['payloadTab'] );
		self::assertContains( 'learning', $learning['hydratedTabs'] );
		self::assertSame( 1, $learning['learningSuggestions']['summary']['total'] );
		self::assertSame( 'Missing site guidance.', $learning['learningSuggestions']['items'][0]['issue'] );
		self::assertSame( 0, $learning['memoryRecords']['summary']['total'] );

		$_GET['tab'] = 'overview';
		$overview    = $this->settings_payload();

		self::assertSame( 'overview', $overview['payloadTab'] );
		self::assertSame( 0, $overview['learningSuggestions']['summary']['total'] );
		self::assertSame( array(), $overview['learningSuggestions']['items'] );
		self::assertSame( 0, $overview['memoryRecords']['summary']['total'] );
		self::assertSame( array(), $overview['memoryRecords']['items'] );
	}

	public function test_retired_internal_links_tab_falls_back_to_overview_payload(): void {
		$_GET['tab']               = 'links-map';
		$_GET['links_state']       = 'orphan';
		$_GET['links_post_type']   = 'page';
		$_GET['links_status']      = 'publish';
		$_GET['links_per_page']    = '200';
		$_GET['links_min_inbound'] = '3';
		$_GET['links_thin_words']  = '250';

		$payload = $this->settings_payload();

		self::assertSame( 'overview', $payload['payloadTab'] );
		self::assertSame( array( 'overview', 'connect', 'diagnostics', 'advanced' ), $payload['hydratedTabs'] );
		self::assertArrayNotHasKey( 'internalLinksMap', $payload );
		self::assertFalse( $this->wpdb->has_query_fragment( 'wp_aculect_ai_content_index' ) );
	}

	public function test_rest_settings_payload_loads_requested_tab_without_global_get_tab(): void {
		$response = ( new SettingsPage() )->rest_settings_payload(
			new WP_REST_Request(
				// @phpstan-ignore-next-line Test bootstrap WP_REST_Request accepts parameter arrays.
				array(
					'tab' => 'connections',
				)
			)
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );

		$payload = $response->get_data();
		self::assertIsArray( $payload );
		self::assertSame( 'connections', $payload['payloadTab'] );
		self::assertContains( 'connections', $payload['hydratedTabs'] );
		self::assertTrue( $this->wpdb->has_query_fragment( 'refresh_tokens.revoked = 0' ) );
		self::assertTrue( $this->wpdb->has_query_fragment( 'refresh_tokens.expires_at >= %s' ) );
		self::assertTrue( $this->wpdb->has_query_fragment( 'WHERE access_tokens.revoked = 1' ) );
	}

	public function test_settings_payload_rest_route_uses_manage_settings_permission(): void {
		$GLOBALS['aculect_ai_companion_test_rest_routes'] = array();

		$page = new SettingsPage();
		$page->register_rest_routes();
		/** @var list<array{namespace:string, route:string, args:array<string, mixed>}> $routes */
		$routes = $GLOBALS['aculect_ai_companion_test_rest_routes'];

		self::assertNotEmpty( $routes );
		self::assertSame( 'aculect-ai-companion/v1', $routes[0]['namespace'] );
		self::assertSame( '/settings-payload', $routes[0]['route'] );
		self::assertSame( array( $page, 'can_manage_settings' ), $routes[0]['args']['permission_callback'] );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		self::assertFalse( $page->can_manage_settings() );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
	}

	public function test_production_payload_does_not_apply_local_samples(): void {
		$this->wpdb->return_empty_results = true;
		$_GET['tab']                      = 'connections';

		$payload = $this->settings_payload();

		self::assertArrayNotHasKey( 'sampleData', $payload );
		self::assertSame( 0, $payload['activeSessionCount'] );
		self::assertFalse( $payload['isConnected'] );
		self::assertSame( array(), $payload['sessions'] );
		self::assertSame( array(), $payload['revokedSessions'] );
	}

	public function test_local_connections_payload_applies_sample_rows_when_empty(): void {
		$GLOBALS['aculect_ai_companion_test_environment_type'] = 'local';
		$this->wpdb->return_empty_results                      = true;
		update_option( 'aculect_ai_companion_first_installed_at', 1704067200, false );
		$_GET['tab'] = 'connections';

		$payload = $this->settings_payload();

		self::assertSame( 0, $payload['activeSessionCount'] );
		self::assertFalse( $payload['isConnected'] );
		self::assertCount( 3, $payload['sessions'] );
		self::assertCount( 1, $payload['revokedSessions'] );
		self::assertSame( 'ChatGPT Local QA', $payload['sessions'][0]['client_name'] );
		self::assertSame( 'revoked', $payload['revokedSessions'][0]['status'] );
		self::assertTrue( $payload['sessions'][0]['is_sample'] );
		self::assertTrue( $payload['revokedSessions'][0]['is_sample'] );
		self::assertGreaterThanOrEqual( 1704067200, strtotime( (string) $payload['sessions'][0]['created_at'] . ' UTC' ) );
		self::assertSame( 'Preview data - these are examples, not real connections or activity.', $payload['sampleData']['message'] );
		self::assertSame( array( 'connections' ), $payload['sampleData']['appliedTabs'] );
	}

	public function test_local_abilities_payload_reports_sample_connection_count_when_empty(): void {
		$GLOBALS['aculect_ai_companion_test_environment_type'] = 'local';
		$this->wpdb->return_empty_results                      = true;
		$_GET['tab'] = 'abilities';

		$payload = $this->settings_payload();

		self::assertSame( 'abilities', $payload['payloadTab'] );
		self::assertSame( 0, $payload['activeSessionCount'] );
		self::assertFalse( $payload['isConnected'] );
		self::assertContains( 'abilities', $payload['sampleData']['appliedTabs'] );
		self::assertNotEmpty( $payload['abilities'] );
	}

	public function test_abilities_payload_hides_role_policy_editor_until_enabled(): void {
		$_GET['tab'] = 'abilities';

		$default_payload = $this->settings_payload();

		self::assertFalse( $default_payload['roleAbilities']['enabled'] );
		self::assertFalse( $default_payload['roleAbilityPolicy']['enabled'] );
		self::assertSame( array(), $default_payload['roleAbilityPolicy']['roles'] );

		RoleAbilitiesPolicy::set_editing_enabled( true );

		$enabled_payload = $this->settings_payload();
		$roles           = array_column( $enabled_payload['roleAbilityPolicy']['roles'], null, 'id' );

		self::assertTrue( $enabled_payload['roleAbilities']['enabled'] );
		self::assertTrue( $enabled_payload['roleAbilityPolicy']['enabled'] );
		self::assertArrayNotHasKey( 'administrator', $roles );
		self::assertArrayHasKey( 'editor', $roles );
	}

	public function test_local_activity_payload_applies_sample_rows_when_empty(): void {
		$GLOBALS['aculect_ai_companion_test_environment_type'] = 'local';
		$this->wpdb->return_empty_results                      = true;
		$_GET['tab'] = 'activity';

		$payload = $this->settings_payload();

		self::assertSame( 5, $payload['activity']['total'] );
		self::assertCount( 5, $payload['activity']['items'] );
		self::assertSame( 4, $payload['activity']['summary']['successes'] );
		self::assertSame( 1, $payload['activity']['summary']['failures'] );
		self::assertSame( 'content.update_item', $payload['activity']['items'][0]['action'] );
		self::assertTrue( $payload['activity']['items'][0]['is_sample'] );
		self::assertSame( array( 'activity' ), $payload['sampleData']['appliedTabs'] );
	}

	public function test_local_logs_payload_applies_sample_rows_when_empty(): void {
		$GLOBALS['aculect_ai_companion_test_environment_type'] = 'local';
		$this->wpdb->return_empty_results                      = true;
		$_GET['tab'] = 'logs';

		$payload = $this->settings_payload();

		self::assertTrue( $payload['diagnostics']['loggingEnabled'] );
		self::assertSame( 4, $payload['diagnostics']['logs']['total'] );
		self::assertCount( 4, $payload['diagnostics']['logs']['items'] );
		self::assertSame( 'oauth.registered', $payload['diagnostics']['logs']['items'][0]['event'] );
		self::assertTrue( $payload['diagnostics']['logs']['items'][0]['is_sample'] );
		self::assertSame( array( 'logs' ), $payload['sampleData']['appliedTabs'] );
	}

	public function test_local_learning_payload_applies_sample_rows_when_empty(): void {
		$GLOBALS['aculect_ai_companion_test_environment_type'] = 'local';
		$_GET['tab'] = 'learning';

		$payload = $this->settings_payload();

		self::assertSame( 3, $payload['learningSuggestions']['summary']['total'] );
		self::assertCount( 3, $payload['learningSuggestions']['items'] );
		self::assertSame( 'learn_local_brand', $payload['learningSuggestions']['items'][0]['id'] );
		self::assertTrue( $payload['learningSuggestions']['items'][0]['is_sample'] );
		self::assertSame( array( 'learning' ), $payload['sampleData']['appliedTabs'] );
	}

	public function test_local_diagnostics_payload_applies_sample_checks_when_empty(): void {
		$GLOBALS['aculect_ai_companion_test_environment_type'] = 'local';
		$this->wpdb->return_empty_results                      = true;
		$_GET['tab'] = 'diagnostics';

		$payload = $this->settings_payload();

		self::assertSame( 'warn', $payload['connectionHealth']['summary'] );
		self::assertCount( 5, $payload['connectionHealth']['items'] );
		self::assertSame( 'local', $payload['connectionHealth']['system']['environment_type'] );
		self::assertTrue( $payload['connectionHealth']['items'][0]['is_sample'] );
		self::assertSame( array( 'diagnostics' ), $payload['sampleData']['appliedTabs'] );
	}

	public function test_payload_uses_server_derived_sanitized_mcp_url(): void {
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect-ai-companion/connectors/external_url'] =
			static fn (): string => 'https://edge.example.test/site///';

		$payload   = $this->settings_payload();
		$providers = array_column( $payload['providers'], null, 'id' );

		self::assertSame( 'https://edge.example.test/site/wp-json/aculect-ai-companion/v1/mcp', $payload['mcpUrl'] );
		self::assertSame(
			$payload['mcpUrl'],
			$providers['codex']['setupSections'][0]['copyFields'][1]['value']
		);
		self::assertStringContainsString(
			$payload['mcpUrl'],
			$providers['cursor']['setupSections'][0]['copyFields'][0]['value']
		);

		unset( $GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect-ai-companion/connectors/external_url'] );
	}

	/**
	 * Invoke the private settings payload builder.
	 *
	 * @return array<string, mixed>
	 */
	private function settings_payload(): array {
		$payload = $this->invokePrivate( new SettingsPage(), 'settings_payload' );

		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Invoke a private method for focused unit coverage.
	 *
	 * @param object      $object    Object instance.
	 * @param string      $method    Method name.
	 * @param list<mixed> $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}
}

/**
 * Focused wpdb test double for settings-page payload queries.
 */
final class FakeSettingsPageWpdb {

	public string $prefix = 'wp_';

	public bool $return_empty_results = false;

	/**
	 * Active OAuth session rows returned by get_results().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $active_session_rows = array();

	/**
	 * Revoked OAuth session rows returned by get_results().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $revoked_session_rows = array();

	/**
	 * @var string[]
	 */
	public array $queries = array();

	/**
	 * Record a prepared SQL template.
	 *
	 * @param string $query SQL query.
	 * @param mixed  ...$args Placeholder values.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$prepared        = trim( $query . ' ' . implode( ' ', array_map( 'strval', $args ) ) );
		$this->queries[] = $prepared;

		return $prepared;
	}

	/**
	 * Return count-style values.
	 *
	 * @param string $query SQL query.
	 */
	public function get_var( string $query ): int {
		$this->queries[] = $query;

		if ( str_contains( $query, 'wp_aculect_ai_companion_activity' ) ) {
			if ( $this->return_empty_results ) {
				return 0;
			}

			return 7;
		}

		if ( str_contains( $query, 'wp_aculect_ai_companion_logs' ) ) {
			if ( $this->return_empty_results ) {
				return 0;
			}

			return 3;
		}

		if ( str_contains( $query, 'wp_aculect_ai_content_index' ) ) {
			if ( $this->return_empty_results ) {
				return 0;
			}

			return str_contains( $query, 'audit_rows' ) ? 80 : 12;
		}

		if ( str_contains( $query, 'wp_aculect_ai_companion_oauth_access_tokens' ) ) {
			if ( $this->return_empty_results ) {
				return 0;
			}

			return 2;
		}

		return 0;
	}

	/**
	 * Record a write query.
	 *
	 * @param string $query SQL query.
	 */
	public function query( string $query ): int|false {
		$this->queries[] = $query;

		return 1;
	}

	/**
	 * Return one aggregate row.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output format.
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, string $output ): ?array {
		unset( $output );

		$this->queries[] = $query;

		if ( str_contains( $query, 'wp_aculect_ai_companion_activity' ) ) {
			if ( $this->return_empty_results ) {
				return null;
			}

			return array(
				'total'           => '7',
				'successes'       => '6',
				'failures'        => '1',
				'assistants'      => '2',
				'high_risk'       => '1',
				'content_actions' => '4',
				'comment_actions' => '2',
				'media_actions'   => '1',
			);
		}

		if ( str_contains( $query, 'wp_aculect_ai_content_index' ) && str_contains( $query, 'latest_indexed_at' ) ) {
			if ( $this->return_empty_results ) {
				return array(
					'total'             => '0',
					'stale'             => '0',
					'latest_indexed_at' => '',
				);
			}

			return array(
				'total'             => '12',
				'stale'             => '4',
				'latest_indexed_at' => '2026-07-01 10:00:00',
			);
		}

		return null;
	}

	/**
	 * Return list rows for activity and diagnostic logs.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output format.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );

		$this->queries[] = $query;

		if ( str_contains( $query, 'wp_aculect_ai_companion_activity' ) ) {
			if ( $this->return_empty_results ) {
				return array();
			}

			return array(
				array(
					'id'          => '11',
					'created_at'  => '2026-05-28 00:00:00',
					'provider'    => 'chatgpt',
					'client_id'   => 'client-1',
					'client_name' => 'ChatGPT',
					'user_id'     => null,
					'action'      => 'content.update_item',
					'target_type' => 'post',
					'target_id'   => '42',
					'status'      => 'success',
					'error_code'  => null,
					'message'     => '',
					'context'     => '{"risk_level":"publish"}',
				),
			);
		}

		if ( str_contains( $query, 'wp_aculect_ai_companion_logs' ) ) {
			if ( $this->return_empty_results ) {
				return array();
			}

			return array(
				array(
					'id'             => '4',
					'created_at'     => '2026-05-28 00:00:00',
					'level'          => 'info',
					'event'          => 'oauth.registered',
					'provider'       => 'chatgpt',
					'request_method' => 'POST',
					'request_route'  => '/wp-json/aculect-ai-companion/v1/oauth/register',
					'http_status'    => '201',
					'error_code'     => null,
					'message'        => 'Registered.',
					'context'        => '{}',
				),
			);
		}

		if ( str_contains( $query, 'wp_aculect_ai_content_index' ) ) {
			if ( $this->return_empty_results ) {
				return array();
			}

			return array(
				array(
					'object_id'               => '42',
					'object_type'             => 'post',
					'post_type'               => 'page',
					'post_status'             => 'publish',
					'title'                   => 'Internal Link Strategy',
					'slug'                    => 'internal-link-strategy',
					'permalink'               => 'https://example.com/internal-link-strategy/',
					'excerpt'                 => 'Compact excerpt.',
					'summary'                 => 'Compact summary.',
					'word_count'              => '480',
					'content_hash'            => 'abc',
					'indexed_at'              => '2026-07-01 10:00:00',
					'modified_gmt'            => '2026-07-01 09:00:00',
					'stale'                   => '0',
					'search_text'             => 'full hidden index text',
					'metadata'                => '{}',
					'inbound_internal_links'  => '0',
					'outbound_internal_links' => '4',
				),
				array(
					'object_id'               => '43',
					'object_type'             => 'post',
					'post_type'               => 'page',
					'post_status'             => 'publish',
					'title'                   => 'Topic Hub',
					'slug'                    => 'topic-hub',
					'permalink'               => 'https://example.com/topic-hub/',
					'excerpt'                 => '',
					'summary'                 => '',
					'word_count'              => '180',
					'content_hash'            => 'def',
					'indexed_at'              => '2026-07-01 10:00:00',
					'modified_gmt'            => '2026-07-01 09:30:00',
					'stale'                   => '1',
					'search_text'             => 'more hidden index text',
					'metadata'                => '{}',
					'inbound_internal_links'  => '1',
					'outbound_internal_links' => '30',
				),
			);
		}

		if ( str_contains( $query, 'wp_aculect_ai_companion_oauth_access_tokens' ) ) {
			if ( $this->return_empty_results ) {
				return array();
			}

			return str_contains( $query, 'WHERE access_tokens.revoked = 1' )
				? $this->revoked_session_rows
				: $this->active_session_rows;
		}

		return array();
	}

	/**
	 * Check whether any recorded query contains a fragment.
	 *
	 * @param string $fragment Query fragment.
	 */
	public function has_query_fragment( string $fragment ): bool {
		foreach ( $this->queries as $query ) {
			if ( str_contains( $query, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}

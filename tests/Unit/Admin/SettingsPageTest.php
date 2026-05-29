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
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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

		$GLOBALS['wpdb']                                      = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_admin_pages']     = array(
			'menu'    => array(),
			'options' => array(),
			'submenu' => array(),
		);
		$GLOBALS['aculect_ai_companion_test_hooks']           = array(
			'actions' => array(),
			'filters' => array(),
		);
		$GLOBALS['aculect_ai_companion_test_users']           = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 5;
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
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'] );
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
			'https://wordpress.org/plugins/aculect-ai-companion/',
			$payload['pluginMetadata']['documentationUrl']
		);
		self::assertTrue( $payload['isConnected'] );
		self::assertSame( 2, $payload['activeSessionCount'] );
		self::assertSame( array(), $payload['sessions'] );
		self::assertSame( array(), $payload['revokedSessions'] );
		self::assertSame( array(), $payload['roleAbilityPolicy'] );
		self::assertSame( array(), $payload['brandProfile'] );
		self::assertSame( array(), $payload['changelog'] );
		self::assertSame( 0, $payload['activity']['total'] );
		self::assertSame( 0, $payload['diagnostics']['logs']['total'] );
		self::assertFalse( $this->wpdb->has_query_fragment( 'ORDER BY access_tokens.created_at DESC' ) );
		self::assertFalse( $this->wpdb->has_query_fragment( 'wp_aculect_ai_companion_activity' ) );
		self::assertFalse( $this->wpdb->has_query_fragment( 'wp_aculect_ai_companion_logs' ) );
	}

	public function test_connections_payload_loads_session_lists_only_for_connections_tab(): void {
		$_GET['tab'] = 'connections';

		$payload = $this->settings_payload();

		self::assertSame( 'connections', $payload['payloadTab'] );
		self::assertContains( 'connections', $payload['hydratedTabs'] );
		self::assertTrue( $this->wpdb->has_query_fragment( 'WHERE access_tokens.revoked = 0 AND access_tokens.expires_at >= %s' ) );
		self::assertTrue( $this->wpdb->has_query_fragment( 'WHERE access_tokens.revoked = 1' ) );
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
		self::assertArrayHasKey( '0.5.0', $changelog['changelog'] );
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
			return 7;
		}

		if ( str_contains( $query, 'wp_aculect_ai_companion_logs' ) ) {
			return 3;
		}

		if ( str_contains( $query, 'wp_aculect_ai_companion_oauth_access_tokens' ) ) {
			return 2;
		}

		return 0;
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

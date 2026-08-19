<?php
/**
 * Tests for connection health result handling.
 *
 * @package Aculect\AICompanion\Tests\Unit\Diagnostics
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Diagnostics;

use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesRegistrar;
use Aculect\AICompanion\Connectors\OAuth\Server\SecretsVault;
use Aculect\AICompanion\Diagnostics\ConnectionHealth;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once dirname( __DIR__, 2 ) . '/fixtures/wordpress-abilities-stubs.php';

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused diagnostics tests replace wpdb with a local test double.

/**
 * Verifies connection diagnostics summarize and sanitize stored results.
 */
final class ConnectionHealthTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_wp_abilities'] = array();
		$this->original_wpdb                               = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['aculect_ai_companion_test_db_delta_callback'] );
	}

	protected function tearDown(): void {
		delete_option( ConnectionHealth::OPTION_LAST_RESULT );
		delete_option( 'aculect_ai_companion_connection_health_transient_probe' );
		delete_option( 'aculect_ai_companion_secret_storage_key' );
		$GLOBALS['aculect_ai_companion_test_transients']   = array();
		$GLOBALS['aculect_ai_companion_test_wp_abilities'] = array();
		unset(
			$_SERVER['HTTP_CF_RAY'],
			$_SERVER['HTTP_CF_VISITOR'],
			$_SERVER['HTTP_CF_CONNECTING_IP'],
			$_SERVER['HTTP_CF_IPCOUNTRY'],
			$_SERVER['HTTP_CF_MITIGATED']
		);

		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		unset( $GLOBALS['aculect_ai_companion_test_db_delta_callback'] );

		parent::tearDown();
	}

	public function test_summary_status_prefers_failures_over_warnings(): void {
		$health = new ConnectionHealth();

		self::assertSame(
			'fail',
			$this->invokePrivate(
				$health,
				'summary_status',
				array(
					array(
						array( 'status' => 'pass' ),
						array( 'status' => 'warn' ),
						array( 'status' => 'fail' ),
					),
				)
			)
		);
	}

	public function test_required_storage_check_reports_a_bounded_actionable_repair_failure(): void {
		$GLOBALS['wpdb'] = new ConnectionHealthStorageWpdb();

		$result = $this->invokePrivate( new ConnectionHealth(), 'check_required_storage' );

		self::assertSame( 'required_storage', $result['id'] );
		self::assertSame( 'fail', $result['status'] );
		self::assertSame( array( 'oauth', 'activity' ), $result['details']['checked_stores'] );
		self::assertSame( array( 'oauth', 'activity' ), $result['details']['unavailable_stores'] );
		self::assertArrayNotHasKey( 'repaired_stores', $result['details'] );
		self::assertStringContainsString( 'database user', $result['remediation'] );
	}

	public function test_required_storage_check_reports_only_the_store_that_was_actually_repaired(): void {
		$wpdb            = new ConnectionHealthStorageWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			if ( str_contains( $sql, 'aculect_ai_companion_oauth_clients' ) ) {
				$wpdb->available_tables = array(
					'wp_42_aculect_ai_companion_oauth_clients',
					'wp_42_aculect_ai_companion_oauth_access_tokens',
					'wp_42_aculect_ai_companion_oauth_refresh_tokens',
					'wp_42_aculect_ai_companion_oauth_auth_codes',
				);
			}

			return array();
		};

		$result = $this->invokePrivate( new ConnectionHealth(), 'check_required_storage' );

		self::assertSame( 'fail', $result['status'] );
		self::assertSame( array( 'oauth' ), $result['details']['repaired_stores'] );
		self::assertSame( array( 'activity' ), $result['details']['unavailable_stores'] );
		self::assertNotContains( 'activity', $result['details']['repaired_stores'] );
	}

	public function test_required_storage_check_records_a_successful_repair_without_physical_table_names(): void {
		$wpdb            = new ConnectionHealthStorageWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			unset( $sql );
			$wpdb->all_tables_available = true;

			return array( 'created missing plugin table' );
		};

		$result = $this->invokePrivate( new ConnectionHealth(), 'check_required_storage' );

		self::assertSame( 'required_storage', $result['id'] );
		self::assertSame( 'warn', $result['status'] );
		self::assertSame( array( 'oauth', 'activity' ), $result['details']['repaired_stores'] );
		self::assertArrayNotHasKey( 'table_names', $result['details'] );
	}

	public function test_summary_status_reports_warnings_without_failures(): void {
		self::assertSame(
			'warn',
			$this->invokePrivate(
				new ConnectionHealth(),
				'summary_status',
				array(
					array(
						array( 'status' => 'pass' ),
						array( 'status' => 'warn' ),
					),
				)
			)
		);
	}

	public function test_stored_results_are_sanitized_before_admin_output(): void {
		$result = $this->invokePrivate(
			new ConnectionHealth(),
			'sanitize_result',
			array(
				array(
					'ranAt'   => "2026-05-20\n<script>",
					'summary' => 'pass',
					'items'   => array(
						array(
							'id'          => 'mcp_auth_challenge',
							'status'      => 'pass',
							'message'     => '<strong>ok</strong>',
							'remediation' => 'No action needed.',
							'details'     => array(
								'url'           => 'https://example.test/wp-json/aculect-ai-companion/v1/mcp',
								'client_secret' => 'do-not-store',
							),
						),
					),
					'details' => array(
						'connectionUrl' => 'https://example.test/wp-json/aculect-ai-companion/v1/mcp',
						'access_token'  => 'secret',
					),
					'system'  => array(
						'site_url'        => 'https://example.test',
						'php_version'     => '8.2.0',
						'auth_header'     => 'Bearer no',
						'private_payload' => '{"token":"no"}',
						'wp_salt'         => 'secret',
					),
				),
			)
		);

		self::assertSame( '2026-05-20', $result['ranAt'] );
		self::assertSame( 'ok', $result['items'][0]['message'] );
		self::assertSame( '8.2.0', $result['system']['php_version'] );
		self::assertArrayNotHasKey( 'client_secret', $result['items'][0]['details'] );
		self::assertArrayNotHasKey( 'access_token', $result['details'] );
		self::assertArrayNotHasKey( 'auth_header', $result['system'] );
		self::assertArrayNotHasKey( 'private_payload', $result['system'] );
		self::assertArrayNotHasKey( 'wp_salt', $result['system'] );
	}

	public function test_empty_admin_result_exposes_only_support_safe_ui_context(): void {
		$result = $this->invokePrivate( new ConnectionHealth(), 'empty_result' );

		self::assertSame( '', $result['ranAt'] );
		self::assertSame( '', $result['summary'] );
		self::assertSame( array(), $result['items'] );
		self::assertSame(
			array(
				'site_url',
				'rest_url',
				'connection_url',
				'wordpress_version',
				'php_version',
				'environment_type',
				'debug_mode',
			),
			array_keys( $result['system'] )
		);
		self::assertSame(
			array(
				'connectionUrl',
				'protectedResourceMetadataUrl',
				'authorizationServerMetadataUrl',
			),
			array_keys( $result['details'] )
		);
		self::assertArrayNotHasKey( 'access_token', $result['system'] );
		self::assertArrayNotHasKey( 'client_secret', $result['details'] );
	}

	public function test_mcp_tool_manifest_check_reports_local_tool_summary(): void {
		$result = $this->invokePrivate( new ConnectionHealth(), 'check_mcp_tool_manifest' );

		self::assertSame( 'mcp_tool_manifest', $result['id'] );
		self::assertSame( 'pass', $result['status'] );
		self::assertGreaterThan( 0, $result['details']['tool_count'] );
		self::assertSame( array(), $result['details']['duplicate_tool_names'] );
		self::assertSame( array(), $result['details']['invalid_tool_names'] );
		self::assertArrayHasKey( 'ability_policy', $result['details'] );
		self::assertArrayHasKey( 'wordpress_abilities', $result['details'] );
		self::assertArrayHasKey( 'api_available', $result['details']['wordpress_abilities'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['details']['metadata_fingerprint'] );
		self::assertIsString( $result['details']['metadata_generated_at'] );
		self::assertArrayHasKey( 'chatgpt_app', $result['details']['metadata_refresh_guidance'] );
		self::assertArrayHasKey( 'gemini_cli', $result['details']['metadata_refresh_guidance'] );
		self::assertStringContainsString( 'fingerprint', $result['remediation'] );
	}

	public function test_wordpress_abilities_runtime_check_reports_api_context(): void {
		$result = $this->invokePrivate( new ConnectionHealth(), 'check_wordpress_abilities_runtime' );

		self::assertSame( 'wordpress_abilities_runtime', $result['id'] );
		self::assertArrayHasKey( 'api_available', $result['details'] );
		self::assertArrayHasKey( 'registration_functions_present', $result['details'] );
		self::assertSame( 'available', $result['details']['runtime_status'] );
	}

	public function test_wordpress_abilities_checks_report_registration_schema_and_policy_separately(): void {
		$health = new ConnectionHealth();

		$registration = $this->invokePrivate( $health, 'check_wordpress_abilities_registration' );
		self::assertSame( 'wordpress_abilities_registration', $registration['id'] );
		self::assertSame( 'warn', $registration['status'] );
		self::assertSame( 'incomplete', $registration['details']['registration_status'] );

		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$registration = $this->invokePrivate( $health, 'check_wordpress_abilities_registration' );
		self::assertSame( 'pass', $registration['status'] );
		self::assertSame( 'complete', $registration['details']['registration_status'] );

		$GLOBALS['aculect_ai_companion_test_wp_abilities'][0]['args']['input_schema'] = array( 'type' => 'string' );

		$schema = $this->invokePrivate( $health, 'check_wordpress_abilities_schema' );
		self::assertSame( 'wordpress_abilities_schema', $schema['id'] );
		self::assertSame( 'warn', $schema['status'] );
		self::assertSame( 'invalid', $schema['details']['schema_status'] );

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

		$policy = $this->invokePrivate( $health, 'check_wordpress_abilities_policy' );
		self::assertSame( 'wordpress_abilities_policy', $policy['id'] );
		self::assertSame( 'warn', $policy['status'] );
		self::assertSame( 'blocked', $policy['details']['policy_status'] );
	}

	public function test_cloudflare_compatibility_check_reports_best_effort_when_not_detected(): void {
		$result = $this->invokePrivate( new ConnectionHealth(), 'check_cloudflare_compatibility' );

		self::assertSame( 'cloudflare_compatibility', $result['id'] );
		self::assertSame( 'pass', $result['status'] );
		self::assertFalse( $result['details']['detected'] );
		self::assertStringContainsString( '/wp-json/aculect-ai-companion/v1/', $result['details']['rule_expression'] );
	}

	public function test_cloudflare_compatibility_check_warns_when_cloudflare_headers_are_visible(): void {
		$_SERVER['HTTP_CF_RAY']       = 'test-ray';
		$_SERVER['HTTP_CF_MITIGATED'] = 'challenge';

		$result = $this->invokePrivate( new ConnectionHealth(), 'check_cloudflare_compatibility' );

		self::assertSame( 'cloudflare_compatibility', $result['id'] );
		self::assertSame( 'warn', $result['status'] );
		self::assertTrue( $result['details']['detected'] );
		self::assertSame( 'test-ray', $result['details']['detected_by']['cf-ray'] );
		self::assertSame( 'challenge', $result['details']['detected_by']['cf-mitigated'] );
		self::assertStringContainsString( 'Flexible SSL', $result['remediation'] );
	}

	public function test_transient_persistence_check_requires_second_run(): void {
		$health = new ConnectionHealth();

		$first = $this->invokePrivate( $health, 'check_transient_persistence' );
		self::assertSame( 'transient_persistence', $first['id'] );
		self::assertSame( 'warn', $first['status'] );
		self::assertIsArray( get_option( 'aculect_ai_companion_connection_health_transient_probe', array() ) );

		$second = $this->invokePrivate( $health, 'check_transient_persistence' );
		self::assertSame( 'transient_persistence', $second['id'] );
		self::assertSame( 'pass', $second['status'] );
		self::assertSame( array(), get_option( 'aculect_ai_companion_connection_health_transient_probe', array() ) );
	}

	public function test_secret_storage_reports_database_managed_fallback_key(): void {
		if ( ! SecretsVault::sodium_available() ) {
			self::markTestSkipped( 'The sodium extension is required for secret storage diagnostics.' );
		}

		$result = $this->invokePrivate( new ConnectionHealth(), 'check_secret_storage' );

		self::assertSame( 'secret_storage', $result['id'] );
		self::assertSame( 'warn', $result['status'] );
		self::assertStringContainsString( 'database-managed key', $result['message'] );
		self::assertIsString( get_option( 'aculect_ai_companion_secret_storage_key', '' ) );
	}

	public function test_delete_removes_transient_probe_state(): void {
		$key = 'aculect_ai_companion_health_probe_delete';

		set_transient( $key, 'probe-value', 60 );
		update_option(
			'aculect_ai_companion_connection_health_transient_probe',
			array(
				'key'        => $key,
				'value'      => 'probe-value',
				'created_at' => time(),
			),
			false
		);

		ConnectionHealth::delete();

		self::assertFalse( get_transient( $key ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_connection_health_transient_probe', 'missing' ) );
	}

	/**
	 * Invoke a private method for focused unit coverage.
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

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Commenting.DocComment.MissingShort, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.IncorrectTypeHint, Squiz.Commenting.FunctionComment.ParamNameNoMatch -- Focused wpdb double remains local to this test file.

/**
 * Minimal wpdb double for required storage diagnostics.
 */
final class ConnectionHealthStorageWpdb {

	public string $prefix             = 'wp_42_';
	public string $last_error         = '';
	public bool $all_tables_available = false;

	/** @var list<string> */
	public array $available_tables = array();

	/** @var array<int, mixed> */
	private array $last_args = array();

	public function prepare( string $query, mixed ...$args ): string {
		$this->last_args = $args;

		return $query;
	}

	public function esc_like( string $text ): string {
		return $text;
	}

	public function get_var( string $query ): string {
		unset( $query );

		$table = (string) ( $this->last_args[0] ?? '' );

		return $this->all_tables_available || in_array( $table, $this->available_tables, true ) ? $table : '';
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $query, $output );

		return array();
	}

	/**
	 * @param array<string, mixed> $data Data to update.
	 * @param array<string, mixed> $where Update criteria.
	 * @param string[]             $format Data formats.
	 * @param string[]             $where_format Update formats.
	 */
	public function update( string $table, array $data, array $where, array $format, array $where_format ): int {
		unset( $table, $data, $where, $format, $where_format );

		return 0;
	}
}

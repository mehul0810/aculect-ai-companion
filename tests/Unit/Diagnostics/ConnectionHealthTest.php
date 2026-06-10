<?php
/**
 * Tests for connection health result handling.
 *
 * @package Aculect\AICompanion\Tests\Unit\Diagnostics
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Diagnostics;

use Aculect\AICompanion\Connectors\OAuth\Server\SecretsVault;
use Aculect\AICompanion\Diagnostics\ConnectionHealth;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies connection diagnostics summarize and sanitize stored results.
 */
final class ConnectionHealthTest extends TestCase {

	protected function tearDown(): void {
		delete_option( ConnectionHealth::OPTION_LAST_RESULT );
		delete_option( 'aculect_ai_companion_connection_health_transient_probe' );
		delete_option( 'aculect_ai_companion_secret_storage_key' );
		$GLOBALS['aculect_ai_companion_test_transients'] = array();

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

		self::assertSame('2026-05-20', $result['ranAt']);
		self::assertSame('ok', $result['items'][0]['message']);
		self::assertSame('8.2.0', $result['system']['php_version']);
		self::assertArrayNotHasKey('client_secret', $result['items'][0]['details']);
		self::assertArrayNotHasKey('access_token', $result['details']);
		self::assertArrayNotHasKey('auth_header', $result['system']);
		self::assertArrayNotHasKey('private_payload', $result['system']);
		self::assertArrayNotHasKey('wp_salt', $result['system']);
	}

	public function test_mcp_tool_manifest_check_reports_local_tool_summary(): void {
		$result = $this->invokePrivate( new ConnectionHealth(), 'check_mcp_tool_manifest' );

		self::assertSame( 'mcp_tool_manifest', $result['id'] );
		self::assertSame( 'pass', $result['status'] );
		self::assertGreaterThan( 0, $result['details']['tool_count'] );
		self::assertSame( array(), $result['details']['duplicate_tool_names'] );
		self::assertSame( array(), $result['details']['invalid_tool_names'] );
		self::assertArrayHasKey( 'ability_policy', $result['details'] );
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

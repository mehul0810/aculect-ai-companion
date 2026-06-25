<?php
/**
 * Tests for executing WordPress Abilities through the MCP bridge.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

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

	public function test_first_party_incident_report_can_be_discovered_and_run_by_aliases(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$bridge = new WordPressAbilitiesBridge();

		foreach (
			array(
				'plugin.incident.report',
				'plugin_issue_report',
				'plugin.issue.report',
				'aculect-ai-companion/plugin-incident-report',
			) as $id
		) {
			$info = $bridge->get_info( array( 'id' => $id ) );

			self::assertSame( 'aculect-ai-companion/plugin-incident-report', $info['id'] );
			self::assertFalse( $info['readOnly'] );
			self::assertTrue( $info['public'] );
			self::assertTrue( $info['allowed'] );
			self::assertSame( 'plugin_incident_report', $info['meta']['mcp']['tool'] );
		}

		$result = $bridge->run(
			array(
				'id'        => 'plugin.incident.report',
				'arguments' => array(
					'title'          => 'MCP incident report alias missing',
					'summary'        => 'The WordPress Abilities bridge did not resolve the canonical incident reporter.',
					'correlation_id' => 'wp-abilities-incident-1',
				),
			)
		);

		self::assertSame( 'aculect-ai-companion/plugin-incident-report', $result['ability'] );
		self::assertSame( 'stored_ready_for_client_submission', $result['result']['status'] );
		self::assertNotEmpty( $result['result']['report_id'] );
		self::assertSame( 'wp-abilities-incident-1', $result['result']['correlation_id'] );
		self::assertArrayHasKey( 'incident', $result['result'] );
		self::assertArrayHasKey( 'issue_url', $result['result'] );
		self::assertStringNotContainsString( 'client_secret', (string) wp_json_encode( $result ) );
	}

	public function test_first_party_incident_report_run_surfaces_validation_errors(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$result = ( new WordPressAbilitiesBridge() )->run(
			array(
				'id'        => 'aculect-ai-companion/plugin-incident-report',
				'arguments' => array(
					'title' => 'Missing summary',
				),
			)
		);

		self::assertSame( 'aculect-ai-companion/plugin-incident-report', $result['ability'] );
		self::assertSame( 'rejected', $result['result']['status'] );
		self::assertSame( 'title_and_summary_required', $result['result']['error'] );
		self::assertStringContainsString( 'title and summary', $result['result']['message'] );
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
}

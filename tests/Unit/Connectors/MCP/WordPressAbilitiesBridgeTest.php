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

	public function test_incident_abilities_resolve_aliases_with_distinct_read_write_metadata(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$bridge = new WordPressAbilitiesBridge();
		$list   = $bridge->get_info( array( 'id' => 'plugin_incident_list' ) );
		$report = $bridge->get_info( array( 'id' => 'plugin_issue_report' ) );

		self::assertSame( 'aculect-ai-companion/plugin-incident-list', $list['id'] );
		self::assertTrue( $list['readOnly'] );
		self::assertTrue( $list['allowed'] );
		self::assertSame( 'plugin_incident_list', $list['meta']['mcp']['tool'] );

		self::assertSame( 'aculect-ai-companion/plugin-incident-report', $report['id'] );
		self::assertFalse( $report['readOnly'] );
		self::assertTrue( $report['allowed'] );
		self::assertSame( 'plugin_incident_report', $report['meta']['mcp']['tool'] );

		$result = $bridge->run( array( 'id' => 'plugin_incident_list' ) );

		self::assertSame( 'aculect-ai-companion/plugin-incident-list', $result['ability'] );
		self::assertSame( 0, $result['result']['total'] );
		self::assertArrayNotHasKey( 'confirmation_required', $result['result'] );
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

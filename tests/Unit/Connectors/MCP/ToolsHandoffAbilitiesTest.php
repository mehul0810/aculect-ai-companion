<?php
/**
 * Native Tools handoff regression coverage.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ToolsHandoffAbilities;
use PHPUnit\Framework\TestCase;

/**
 * Handoffs must preserve capability and private input boundaries.
 */
final class ToolsHandoffAbilitiesTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		parent::tearDown();
	}

	public function test_each_target_requires_its_native_capability(): void {
		$targets = array(
			'import'         => 'import',
			'export'         => 'export',
			'privacy_export' => 'export_others_personal_data',
			'privacy_erase'  => 'erase_others_personal_data',
			'site_health'    => 'view_site_health_checks',
		);
		foreach ( $targets as $target => $capability ) {
			$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
			$result = ( new ToolsHandoffAbilities() )->prepare( array( 'target' => $target ) );
			self::assertSame( 'manual_action_required', $result['status'] );
			self::assertFalse( $result['operation_executed'] );
			self::assertFalse( $result['completion_verified'] );
			self::assertStringNotContainsString( '?', $result['url'] );
			$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( $capability );
			self::assertSame( 'forbidden', ( new ToolsHandoffAbilities() )->prepare( array( 'target' => $target ) )['error'] );
		}
	}

	public function test_invalid_targets_and_private_inputs_are_rejected(): void {
		foreach ( array(
			array(),
			array( 'target' => array() ),
			array( 'target' => 'delete_site' ),
			array(
				'target' => 'export',
				'email'  => 'private@example.test',
			),
		) as $args ) {
			self::assertSame( array( 'error' => 'invalid_target' ), ( new ToolsHandoffAbilities() )->prepare( $args ) );
		}
	}
}

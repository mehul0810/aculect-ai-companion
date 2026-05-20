<?php
/**
 * Tests for global AI access pause state.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AccessLockdown;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the pause option used by MCP lockdown checks.
 */
final class AccessLockdownTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	public function test_pause_state_defaults_to_inactive(): void {
		self::assertFalse( AccessLockdown::is_paused() );
	}

	public function test_pause_state_can_be_enabled_and_disabled(): void {
		AccessLockdown::set_paused( true );
		self::assertTrue( AccessLockdown::is_paused() );

		AccessLockdown::set_paused( false );
		self::assertFalse( AccessLockdown::is_paused() );
	}

	public function test_delete_removes_pause_state(): void {
		AccessLockdown::set_paused( true );

		AccessLockdown::delete();

		self::assertFalse( AccessLockdown::is_paused() );
	}
}

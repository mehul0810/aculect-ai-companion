<?php
/**
 * Tests for per-user AI access pause state.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\UserAccessControl;
use PHPUnit\Framework\TestCase;

/**
 * Verifies user-level pause state is independent and sanitized.
 */
final class UserAccessControlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	public function test_user_pause_state_defaults_to_inactive(): void {
		self::assertFalse( UserAccessControl::is_paused( 7 ) );
		self::assertFalse( UserAccessControl::is_paused( 0 ) );
	}

	public function test_user_access_can_be_paused_and_resumed_independently(): void {
		UserAccessControl::set_paused( 7, true );
		UserAccessControl::set_paused( 12, true );

		self::assertTrue( UserAccessControl::is_paused( 7 ) );
		self::assertTrue( UserAccessControl::is_paused( 12 ) );

		UserAccessControl::set_paused( 7, false );

		self::assertFalse( UserAccessControl::is_paused( 7 ) );
		self::assertTrue( UserAccessControl::is_paused( 12 ) );
		self::assertSame( array( 12 ), UserAccessControl::paused_user_ids() );
	}

	public function test_stored_user_ids_are_sanitized(): void {
		update_option( 'aculect_ai_companion_paused_user_access', array( '7', -2, 'abc', 7 ), false );

		self::assertSame( array( 7 ), UserAccessControl::paused_user_ids() );
	}

	public function test_delete_removes_user_pause_state(): void {
		UserAccessControl::set_paused( 7, true );

		UserAccessControl::delete();

		self::assertFalse( UserAccessControl::is_paused( 7 ) );
	}
}

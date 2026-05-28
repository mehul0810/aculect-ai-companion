<?php
/**
 * Tests for role-based connection entry point settings.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\RoleConnectionEntryPoint;
use PHPUnit\Framework\TestCase;

/**
 * Verifies role connection settings preserve safe server-side enforcement.
 */
final class RoleConnectionEntryPointTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	public function test_save_sanitizes_roles_against_registered_roles(): void {
		RoleConnectionEntryPoint::save(
			true,
			array( 'administrator', 'editor', 'bad-role', 'editor<script>' )
		);

		self::assertTrue( RoleConnectionEntryPoint::is_enabled() );
		self::assertSame(
			array( 'administrator', 'editor' ),
			RoleConnectionEntryPoint::allowed_roles()
		);
	}

	public function test_save_defaults_to_administrator_when_no_registered_roles_match(): void {
		RoleConnectionEntryPoint::save( false, array( 'bad-role' ) );

		self::assertFalse( RoleConnectionEntryPoint::is_enabled() );
		self::assertSame(
			array( 'administrator' ),
			RoleConnectionEntryPoint::allowed_roles()
		);
	}
}

<?php
/**
 * Tests for connector access-level normalization.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use PHPUnit\Framework\TestCase;

/**
 * Verifies admin-managed access levels keep a narrow string contract.
 */
final class ConnectionAccessLevelTest extends TestCase {

	public function test_normalize_accepts_only_supported_access_levels(): void {
		self::assertSame( ConnectionAccessLevel::READ, ConnectionAccessLevel::normalize( 'read' ) );
		self::assertSame( ConnectionAccessLevel::SELECTIVE_READ, ConnectionAccessLevel::normalize( 'selective_read' ) );
		self::assertSame( ConnectionAccessLevel::SELECTIVE_WRITE, ConnectionAccessLevel::normalize( 'selective_write' ) );
		self::assertSame( ConnectionAccessLevel::FULL_WRITE, ConnectionAccessLevel::normalize( 'full_write' ) );
		self::assertSame( ConnectionAccessLevel::EXECUTE, ConnectionAccessLevel::normalize( 'execute' ) );
		self::assertSame( ConnectionAccessLevel::DEFAULT, ConnectionAccessLevel::normalize( 'unknown' ) );
	}

	public function test_write_capable_levels_skip_write_confirmation(): void {
		self::assertFalse( ConnectionAccessLevel::allows_direct_write( ConnectionAccessLevel::READ ) );
		self::assertFalse( ConnectionAccessLevel::allows_direct_write( ConnectionAccessLevel::SELECTIVE_READ ) );
		self::assertTrue( ConnectionAccessLevel::allows_direct_write( ConnectionAccessLevel::SELECTIVE_WRITE ) );
		self::assertTrue( ConnectionAccessLevel::allows_direct_write( ConnectionAccessLevel::FULL_WRITE ) );
		self::assertTrue( ConnectionAccessLevel::allows_direct_write( ConnectionAccessLevel::EXECUTE ) );
	}

	public function test_legacy_write_permission_maps_to_selective_write(): void {
		self::assertSame( ConnectionAccessLevel::SELECTIVE_WRITE, ConnectionAccessLevel::from_write_permission( true ) );
		self::assertSame( ConnectionAccessLevel::DEFAULT, ConnectionAccessLevel::from_write_permission( false ) );
	}
}

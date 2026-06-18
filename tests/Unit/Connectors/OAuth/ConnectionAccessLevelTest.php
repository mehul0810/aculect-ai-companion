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
		self::assertSame( ConnectionAccessLevel::WRITE, ConnectionAccessLevel::normalize( 'write' ) );
		self::assertSame( ConnectionAccessLevel::READ, ConnectionAccessLevel::normalize( 'selective_read' ) );
		self::assertSame( ConnectionAccessLevel::WRITE, ConnectionAccessLevel::normalize( 'selective_write' ) );
		self::assertSame( ConnectionAccessLevel::WRITE, ConnectionAccessLevel::normalize( 'full_write' ) );
		self::assertSame( ConnectionAccessLevel::WRITE, ConnectionAccessLevel::normalize( 'execute' ) );
		self::assertSame( ConnectionAccessLevel::DEFAULT, ConnectionAccessLevel::normalize( 'unknown' ) );
	}

	public function test_write_capable_levels_skip_write_confirmation(): void {
		self::assertFalse( ConnectionAccessLevel::allows_direct_write( ConnectionAccessLevel::READ ) );
		self::assertFalse( ConnectionAccessLevel::allows_direct_write( ConnectionAccessLevel::SELECTIVE_READ ) );
		self::assertTrue( ConnectionAccessLevel::allows_direct_write( ConnectionAccessLevel::WRITE ) );
		self::assertTrue( ConnectionAccessLevel::allows_direct_write( ConnectionAccessLevel::SELECTIVE_WRITE ) );
		self::assertTrue( ConnectionAccessLevel::allows_direct_write( ConnectionAccessLevel::FULL_WRITE ) );
		self::assertTrue( ConnectionAccessLevel::allows_direct_write( ConnectionAccessLevel::EXECUTE ) );
	}

	public function test_legacy_write_permission_maps_to_canonical_write(): void {
		self::assertSame( ConnectionAccessLevel::WRITE, ConnectionAccessLevel::from_write_permission( true ) );
		self::assertSame( ConnectionAccessLevel::READ, ConnectionAccessLevel::from_write_permission( false ) );
	}
}

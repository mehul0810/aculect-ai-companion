<?php
/**
 * Durable memory administration payload tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\Memory\MemoryAdminQuery;
use PHPUnit\Framework\TestCase;

/** Ensures review cards retain the storage identity and cache can be invalidated. */
final class MemoryAdminQueryTest extends TestCase {
	public function test_payload_retains_namespace_and_observed_version(): void {
		$method = new \ReflectionMethod( MemoryAdminQuery::class, 'record' );
		$record = $method->invoke(
			new MemoryAdminQuery(),
			array(
				'memory_key' => 'tone',
				'namespace'  => 'client:claude',
				'version'    => 12,
			)
		);
		self::assertSame( 'tone', $record['key'] );
		self::assertSame( 'client:claude', $record['namespace'] );
		self::assertSame( 12, $record['version'] );
	}

	public function test_committed_mutations_can_invalidate_cached_totals(): void {
		set_transient(
			'aculect_memory_admin_totals',
			array(
				array(
					'status' => 'approved',
					'total'  => 42,
				),
			),
			60
		);
		MemoryAdminQuery::invalidate_summary();
		self::assertFalse( get_transient( 'aculect_memory_admin_totals' ) );
	}
}

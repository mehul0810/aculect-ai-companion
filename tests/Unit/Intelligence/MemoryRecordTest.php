<?php
/**
 * Tests for the versioned Aculect Memory record contract.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\Memory\MemoryRecord;
use PHPUnit\Framework\TestCase;

final class MemoryRecordTest extends TestCase {

	public function test_new_external_memory_defaults_to_private_pending_record(): void {
		$record = ( new MemoryRecord() )->normalize(
			array(
				'key'        => 'brand.voice.primary',
				'value'      => 'Use concise guidance.',
				'visibility' => 'invalid',
			)
		);

		self::assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', (string) $record['memory_uuid'] );
		self::assertSame( 1, $record['version'] );
		self::assertSame( 'pending', $record['status'] );
		self::assertSame( 'private', $record['visibility'] );
		self::assertSame( 'normal', $record['sensitivity'] );
		self::assertFalse( ( new MemoryRecord() )->can_sync( $record ) );
	}

	public function test_update_preserves_identity_and_increments_version(): void {
		$factory = new MemoryRecord();
		$first   = $factory->normalize(
			array(
				'key'           => 'site.audience',
				'value'         => 'Editors',
				'owner_user_id' => 42,
			)
		);
		$second  = $factory->normalize( array( 'value' => 'Publishers' ), $first );

		self::assertSame( $first['memory_uuid'], $second['memory_uuid'] );
		self::assertSame( $first['memory_key'], $second['memory_key'] );
		self::assertSame( 2, $second['version'] );
		self::assertSame( 42, $second['owner_user_id'] );
		self::assertNotSame( $first['content_hash'], $second['content_hash'] );
	}

	public function test_only_approved_public_normal_memory_can_sync(): void {
		$factory = new MemoryRecord();
		$record  = $factory->normalize(
			array(
				'key'         => 'brand.voice',
				'value'       => 'Clear and direct.',
				'status'      => 'approved',
				'visibility'  => 'site',
				'sensitivity' => 'normal',
			)
		);

		self::assertTrue( $factory->can_sync( $record ) );
		$record['sensitivity'] = 'sensitive';
		self::assertFalse( $factory->can_sync( $record ) );
	}
}

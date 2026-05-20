<?php
/**
 * Tests for connected AI activity metadata extraction.
 *
 * @package Aculect\AICompanion\Tests\Unit\Activity
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Activity;

use Aculect\AICompanion\Activity\ActivityLogger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies activity logging stores metadata instead of raw action payloads.
 */
final class ActivityLoggerTest extends TestCase {

	public function test_safe_argument_metadata_drops_content_payloads(): void {
		$metadata = $this->invokePrivate(
			new ActivityLogger(),
			'safe_argument_metadata',
			array(
				'content.create_item',
				array(
					'post_type' => 'post',
					'title'     => 'Do not store this title',
					'content'   => '<p>Do not store this body.</p>',
					'url'       => 'https://images.example.test/path/photo.jpg?token=secret',
					'status'    => 'draft',
				),
			)
		);

		self::assertSame('content.create_item', $metadata['action']);
		self::assertSame('post', $metadata['post_type']);
		self::assertSame('draft', $metadata['status']);
		self::assertSame('images.example.test', $metadata['source_host']);
		self::assertArrayNotHasKey('title', $metadata);
		self::assertArrayNotHasKey('content', $metadata);
		self::assertArrayNotHasKey('url', $metadata);
	}

	public function test_target_prefers_result_identifier_for_content_updates(): void {
		$target = $this->invokePrivate(
			new ActivityLogger(),
			'target',
			array(
				'content.update_item',
				array( 'id' => 12 ),
				array(
					'id'   => 34,
					'type' => 'page',
				),
			)
		);

		self::assertSame(
			array(
				'type' => 'page',
				'id'   => 34,
			),
			$target
		);
	}

	/**
	 * Invoke a private method for focused unit coverage.
	 *
	 * @param object      $object    Object instance.
	 * @param string      $method    Method name.
	 * @param list<mixed> $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}
}

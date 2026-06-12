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

		self::assertSame( 'content.create_item', $metadata['action'] );
		self::assertSame( 'post', $metadata['post_type'] );
		self::assertSame( 'draft', $metadata['status'] );
		self::assertSame( 'images.example.test', $metadata['source_host'] );
		self::assertArrayNotHasKey( 'title', $metadata );
		self::assertArrayNotHasKey( 'content', $metadata );
		self::assertArrayNotHasKey( 'url', $metadata );
	}

	public function test_recorded_context_includes_risk_level_without_payload_values(): void {
		$context = $this->invokePrivate(
			new ActivityLogger(),
			'context',
			array(
				'content.create_item',
				array(
					'title'   => 'Private draft title',
					'content' => '<p>Private body.</p>',
					'status'  => 'publish',
				),
				array(
					'id'     => 12,
					'type'   => 'post',
					'status' => 'publish',
				),
				'publish',
			)
		);

		self::assertSame( 'publish', $context['risk_level'] );
		self::assertContains( 'title', $context['argument_keys'] );
		self::assertArrayNotHasKey( 'title', $context['metadata'] );
		self::assertArrayNotHasKey( 'content', $context['metadata'] );
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

	public function test_target_handles_workflow_and_index_events_without_payloads(): void {
		$workflow_target = $this->invokePrivate(
			new ActivityLogger(),
			'target',
			array(
				'content_workflow.update_post',
				array(
					'id'          => 12,
					'section_map' => array(
						'introduction' => '<!-- wp:paragraph --><p>Private body.</p><!-- /wp:paragraph -->',
					),
				),
				array(
					'post_id'  => 12,
					'workflow' => 'content_workflow_update_post',
				),
			)
		);

		$metadata = $this->invokePrivate(
			new ActivityLogger(),
			'safe_argument_metadata',
			array(
				'content_workflow.update_post',
				array(
					'id'          => 12,
					'update_mode' => 'sections',
					'section_map' => array(
						'introduction' => '<!-- wp:paragraph --><p>Private body.</p><!-- /wp:paragraph -->',
					),
				),
			)
		);

		$index_target = $this->invokePrivate(
			new ActivityLogger(),
			'target',
			array(
				'content_index.refresh_batch',
				array( 'post_type' => 'post' ),
				array( 'status' => 'queued' ),
			)
		);

		self::assertSame(
			array(
				'type' => 'content',
				'id'   => 12,
			),
			$workflow_target
		);
		self::assertSame( 'sections', $metadata['update_mode'] );
		self::assertArrayNotHasKey( 'section_map', $metadata );
		self::assertSame(
			array(
				'type' => 'intelligence_job',
				'id'   => null,
			),
			$index_target
		);
	}

	public function test_memory_save_activity_context_excludes_memory_values(): void {
		$logger = new ActivityLogger();
		$args   = array(
			'key'      => 'brand.voice.primary',
			'value'    => 'Private durable guidance that should not be logged.',
			'evidence' => 'Private evidence that should not be logged.',
			'status'   => 'approved',
		);

		$target  = $this->invokePrivate( $logger, 'target', array( 'memory.save', $args, array( 'status' => 'confirmation_required' ) ) );
		$context = $this->invokePrivate( $logger, 'context', array( 'memory.save', $args, array( 'status' => 'confirmation_required' ), 'update' ) );

		self::assertSame(
			array(
				'type' => 'memory',
				'id'   => null,
			),
			$target
		);
		self::assertSame( 'update', $context['risk_level'] );
		self::assertContains( 'value', $context['argument_keys'] );
		self::assertSame( 'approved', $context['metadata']['status'] );
		self::assertArrayNotHasKey( 'key', $context['metadata'] );
		self::assertArrayNotHasKey( 'value', $context['metadata'] );
		self::assertArrayNotHasKey( 'evidence', $context['metadata'] );
	}

	/**
	 * Invoke a private method for focused unit coverage.
	 *
	 * @param object $object    Object instance.
	 * @param string $method    Method name.
	 * @param array  $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}
}

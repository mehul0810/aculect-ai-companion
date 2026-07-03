<?php
/**
 * Tests for internal-link target state classification.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\InternalLinkTargetInspector;
use PHPUnit\Framework\TestCase;

/**
 * Verifies stale and broken internal-link target classification.
 */
final class InternalLinkTargetInspectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_posts']         = array();
		$GLOBALS['aculect_ai_companion_test_url_to_postid'] = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']   = array();
	}

	public function test_missing_target_is_reported_when_url_cannot_resolve(): void {
		$result = ( new InternalLinkTargetInspector() )->inspect(
			array(
				'target_id'  => 0,
				'target_url' => 'https://example.com/missing-page/',
			)
		);

		self::assertSame( 'missing_target', $result['state'] );
		self::assertTrue( ( new InternalLinkTargetInspector() )->is_reportable_state( $result['state'] ) );
		self::assertSame( 0, $result['evidence']['resolved_post_id'] );
	}

	public function test_unpublished_target_is_reported_for_draft_posts(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][22] = new \WP_Post(
			array(
				'ID'          => 22,
				'post_status' => 'draft',
				'post_title'  => 'Draft Target',
			)
		);

		$result = ( new InternalLinkTargetInspector() )->inspect(
			array(
				'target_id'  => 22,
				'target_url' => 'https://example.com/?p=22',
			)
		);

		self::assertSame( 'unpublished_target', $result['state'] );
		self::assertSame( 'draft', $result['evidence']['target_status'] );
	}

	public function test_unreadable_target_is_reported_before_status_details(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][23] = new \WP_Post(
			array(
				'ID'          => 23,
				'post_status' => 'private',
				'post_title'  => 'Private Target',
			)
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'read_post' );

		$result = ( new InternalLinkTargetInspector() )->inspect(
			array(
				'target_id'  => 23,
				'target_url' => 'https://example.com/?p=23',
			)
		);

		self::assertSame( 'unreadable_target', $result['state'] );
		self::assertSame( 'private', $result['evidence']['target_status'] );
	}

	public function test_stale_permalink_is_reported_when_current_path_changed(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][24] = new \WP_Post(
			array(
				'ID'          => 24,
				'post_status' => 'publish',
				'post_title'  => 'Current Target',
			)
		);

		$result = ( new InternalLinkTargetInspector() )->inspect(
			array(
				'target_id'  => 24,
				'target_url' => 'https://example.com/old-target/',
			)
		);

		self::assertSame( 'stale_permalink', $result['state'] );
		self::assertSame( 'https://example.com/?p=24', $result['evidence']['current_permalink'] );
	}

	public function test_current_published_permalink_is_not_reportable(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][25] = new \WP_Post(
			array(
				'ID'          => 25,
				'post_status' => 'publish',
				'post_title'  => 'Current Target',
			)
		);

		$result = ( new InternalLinkTargetInspector() )->inspect(
			array(
				'target_id'  => 25,
				'target_url' => 'https://example.com/?p=25',
			)
		);

		self::assertSame( 'ok', $result['state'] );
		self::assertFalse( ( new InternalLinkTargetInspector() )->is_reportable_state( $result['state'] ) );
	}
}

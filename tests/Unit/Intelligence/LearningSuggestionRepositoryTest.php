<?php
/**
 * Tests for the MCP intelligence learning suggestion queue.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\LearningSuggestionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Verifies learning suggestions remain bounded, sanitized, and review-first.
 */
final class LearningSuggestionRepositoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	public function test_submit_queues_sanitized_pending_suggestion(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'domain'           => 'brand',
				'issue'            => '<strong>Tone drifted</strong>',
				'evidence'         => "Used casual copy\nwith no saved evidence.",
				'suggested_update' => 'Prefer concise enterprise copy.',
				'confidence'       => 'high',
			),
			array(
				'provider'    => 'claude',
				'client_id'   => 'client-123',
				'client_name' => 'Claude Connector',
				'user_id'     => 7,
			)
		);

		self::assertSame( 'queued', $result['status'] );
		self::assertFalse( $result['review_status']['updates_memory'] );
		self::assertTrue( $result['review_status']['admin_review_required'] );
		self::assertSame( 'brand', $result['suggestion']['domain'] );
		self::assertSame( 'Tone drifted', $result['suggestion']['issue'] );
		self::assertSame( 'claude', $result['suggestion']['source']['provider'] );

		$payload = $repository->admin_payload();
		self::assertSame( 1, $payload['summary']['total'] );
		self::assertSame( 1, $payload['summary']['pending'] );
		self::assertSame( 'Prefer concise enterprise copy.', $payload['items'][0]['suggested_update'] );
	}

	public function test_submit_rejects_incomplete_suggestions_without_writing(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'domain' => 'site',
				'issue'  => 'Missing context.',
			)
		);

		self::assertSame( 'rejected', $result['status'] );
		self::assertSame( array(), get_option( 'aculect_ai_companion_learning_suggestions', array() ) );
	}

	public function test_review_updates_status_without_mutating_suggestion_text(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'domain'           => 'content',
				'issue'            => 'Pattern recommendation was incomplete.',
				'suggested_update' => 'Prefer registered patterns with clear usage descriptions.',
			)
		);
		$id         = (string) $result['suggestion']['id'];

		self::assertTrue( $repository->review( $id, 'approve', 'Reviewed for beta.' ) );

		$payload = $repository->admin_payload();
		self::assertSame( 1, $payload['summary']['approved'] );
		self::assertSame( 0, $payload['summary']['pending'] );
		self::assertSame( 'approved', $payload['items'][0]['status'] );
		self::assertSame( 'Reviewed for beta.', $payload['items'][0]['review_note'] );
		self::assertSame( 'Prefer registered patterns with clear usage descriptions.', $payload['items'][0]['suggested_update'] );
	}

	public function test_update_edits_pending_suggestion_without_changing_status(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'domain'           => 'site',
				'issue'            => 'Original issue',
				'suggested_update' => 'Original update',
			)
		);
		$id         = (string) $result['suggestion']['id'];

		self::assertTrue(
			$repository->update(
				$id,
				array(
					'domain'           => 'developer',
					'issue'            => 'Updated issue',
					'evidence'         => 'Updated evidence',
					'suggested_update' => 'Updated guidance',
					'confidence'       => 'high',
				),
				'Edited before approval.'
			)
		);

		$payload = $repository->admin_payload();
		self::assertSame( 'pending', $payload['items'][0]['status'] );
		self::assertSame( 'developer', $payload['items'][0]['domain'] );
		self::assertSame( 'Updated issue', $payload['items'][0]['issue'] );
		self::assertSame( 'Updated guidance', $payload['items'][0]['suggested_update'] );
		self::assertSame( 'Edited before approval.', $payload['items'][0]['review_note'] );
	}

	public function test_queue_is_bounded_to_latest_suggestions(): void {
		$repository = new LearningSuggestionRepository();

		for ( $i = 0; $i < 105; ++$i ) {
			$repository->submit(
				array(
					'domain'           => 'developer',
					'issue'            => 'Issue ' . $i,
					'suggested_update' => 'Update ' . $i,
				)
			);
		}

		$stored = get_option( 'aculect_ai_companion_learning_suggestions', array() );
		self::assertCount( 100, $stored );
		self::assertSame( 'Issue 5', $stored[0]['issue'] );
	}
}

<?php
/**
 * Tests for reviewable internal-link suggestion records.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\InternalLinkSuggestionRepository;
use PHPUnit\Framework\TestCase;

/**
 * Verifies internal-link suggestions remain bounded, review-first, and non-mutating.
 */
final class InternalLinkSuggestionRepositoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$GLOBALS['aculect_ai_companion_test_posts']   = array(
			11 => new \WP_Post(
				array(
					'ID'           => 11,
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Source Page',
					'post_content' => '<!-- wp:paragraph --><p>Read the Target Post for deeper context.</p><!-- /wp:paragraph -->',
				)
			),
			22 => new \WP_Post(
				array(
					'ID'          => 22,
					'post_type'   => 'post',
					'post_status' => 'publish',
					'post_title'  => 'Target Post',
				)
			),
		);

		$this->registerTestBlocks();
	}

	public function test_create_stores_bounded_reviewable_suggestion_without_full_content(): void {
		$result = ( new InternalLinkSuggestionRepository() )->create(
			array(
				'source_id'   => 11,
				'target_id'   => 22,
				'anchor_text' => 'Target Post',
				'reason'      => 'Target explains the source topic.',
				'score'       => 82,
				'confidence'  => 'high',
				'warnings'    => array( 'duplicate_anchor' ),
				'signals'     => array( 'topic_overlap_terms' => 3 ),
			)
		);

		self::assertSame( 'created', $result['status'] );
		self::assertSame( 1, $result['total_created'] );
		self::assertSame( 'suggested', $result['items'][0]['status'] );
		self::assertSame( 11, $result['items'][0]['source_post']['id'] );
		self::assertSame( 22, $result['items'][0]['target_post']['id'] );
		self::assertSame( 'Target Post', $result['items'][0]['anchor_text'] );
		self::assertArrayNotHasKey( 'content', $result['items'][0]['source_post'] );
		self::assertArrayNotHasKey( 'content', $result['items'][0]['target_post'] );
	}

	public function test_create_skips_active_duplicate_suggestion(): void {
		$repository = new InternalLinkSuggestionRepository();
		$args       = array(
			'source_id'   => 11,
			'target_id'   => 22,
			'anchor_text' => 'Target Post',
			'reason'      => 'Target explains the source topic.',
		);

		$repository->create( $args );
		$result = $repository->create( $args );

		self::assertSame( 'duplicate', $result['status'] );
		self::assertSame( 0, $result['total_created'] );
		self::assertCount( 1, $result['duplicates'] );
		self::assertSame( 1, $repository->list( array() )['total'] );
	}

	public function test_review_approves_suggestion_without_mutating_content(): void {
		$repository = new InternalLinkSuggestionRepository();
		$result     = $repository->create(
			array(
				'source_id'   => 11,
				'target_id'   => 22,
				'anchor_text' => 'Target Post',
				'reason'      => 'Target explains the source topic.',
			)
		);
		$id         = (string) $result['items'][0]['id'];

		$review = $repository->review( $id, 'approve', 'Looks relevant.' );

		self::assertSame( 'updated', $review['status'] );
		self::assertSame( 'approved', $review['suggestion']['status'] );
		self::assertTrue( $review['review_state']['approved_for_apply_plan'] );
		self::assertSame( 'Looks relevant.', $review['suggestion']['review_note'] );
	}

	public function test_apply_plan_is_dry_run_only_for_approved_suggestions(): void {
		$repository = new InternalLinkSuggestionRepository();
		$result     = $repository->create(
			array(
				'source_id'   => 11,
				'target_id'   => 22,
				'anchor_text' => 'Target Post',
				'reason'      => 'Target explains the source topic.',
			)
		);
		$id         = (string) $result['items'][0]['id'];

		self::assertSame( 'suggestion_not_approved', $repository->apply_plan( $id, true )['error'] );

		$repository->review( $id, 'approve' );
		$preview = $repository->apply_plan( $id, true );

		self::assertSame( 'preview', $preview['status'] );
		self::assertTrue( $preview['dry_run'] );
		self::assertSame( 'block.internal_link', $preview['diff']['fields'][0]['field'] );

		$execute = $repository->apply_plan( $id, false );
		self::assertSame( 'applied', $execute['status'] );
		self::assertSame( 'applied', $execute['suggestion']['status'] );
		self::assertStringContainsString( '<a href="https://example.com/?p=22">Target Post</a>', $GLOBALS['aculect_ai_companion_test_posts'][11]->post_content );
	}

	public function test_apply_skips_duplicate_target_links_without_reapplying(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][11]->post_content = '<!-- wp:paragraph --><p>Read the <a href="https://example.com/?p=22">Target Post</a> for deeper context.</p><!-- /wp:paragraph -->';

		$repository = new InternalLinkSuggestionRepository();
		$result     = $repository->create(
			array(
				'source_id'   => 11,
				'target_id'   => 22,
				'anchor_text' => 'Target Post',
				'reason'      => 'Target explains the source topic.',
			)
		);
		$id         = (string) $result['items'][0]['id'];

		$repository->review( $id, 'approve' );
		$execute = $repository->apply_plan( $id, false );

		self::assertSame( 'duplicate_internal_link', $execute['error'] );
		self::assertSame( 'skipped', $repository->find( $id )['status'] );
	}

	public function test_apply_marks_missing_anchor_stale_without_mutating_content(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][11]->post_content = '<!-- wp:paragraph --><p>This source changed after approval.</p><!-- /wp:paragraph -->';

		$repository = new InternalLinkSuggestionRepository();
		$result     = $repository->create(
			array(
				'source_id'   => 11,
				'target_id'   => 22,
				'anchor_text' => 'Target Post',
				'reason'      => 'Target explains the source topic.',
			)
		);
		$id         = (string) $result['items'][0]['id'];

		$repository->review( $id, 'approve' );
		$execute = $repository->apply_plan( $id, false );

		self::assertSame( 'stale_suggestion', $execute['error'] );
		self::assertSame( 'stale', $repository->find( $id )['status'] );
		self::assertStringNotContainsString( '<a ', $GLOBALS['aculect_ai_companion_test_posts'][11]->post_content );
	}

	private function registerTestBlocks(): void {
		\WP_Block_Type_Registry::get_instance()->unregister_all();
		foreach ( array( 'core/paragraph', 'core/heading', 'core/group', 'core/html' ) as $name ) {
			\WP_Block_Type_Registry::get_instance()->register(
				$name,
				array(
					'title'    => $name,
					'category' => 'text',
					'supports' => array(
						'inserter' => true,
					),
				)
			);
		}
	}
}

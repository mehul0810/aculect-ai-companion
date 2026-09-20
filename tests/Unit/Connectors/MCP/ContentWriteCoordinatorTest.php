<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ContentWriteCoordinator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies compensation and terminal partial-write behavior for composite
 * post, taxonomy, and media operations.
 */
final class ContentWriteCoordinatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_posts'] = array(
			10 => new \WP_Post(
				array(
					'ID'                => 10,
					'post_type'         => 'post',
					'post_status'       => 'draft',
					'post_title'        => 'Original title',
					'post_content'      => 'Original content',
					'post_excerpt'      => 'Original excerpt',
					'post_name'         => 'original-title',
					'post_modified_gmt' => '2026-08-26 10:00:00',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_taxonomies'] = array(
			'category' => new \WP_Taxonomy( 'category', array( 'hierarchical' => true ) ),
		);
		$GLOBALS['aculect_ai_companion_test_terms'] = array(
			'category' => array(
				1 => new \WP_Term(
					array(
						'term_id'  => 1,
						'name'     => 'News',
						'slug'     => 'news',
						'taxonomy' => 'category',
					)
				),
				2 => new \WP_Term(
					array(
						'term_id'  => 2,
						'name'     => 'Guides',
						'slug'     => 'guides',
						'taxonomy' => 'category',
					)
				),
			),
		);
		$GLOBALS['aculect_ai_companion_test_object_terms'] = array(
			10 => array( $GLOBALS['aculect_ai_companion_test_terms']['category'][1] ),
		);
		$GLOBALS['aculect_ai_companion_test_post_meta'] = array( 10 => array( '_thumbnail_id' => 99 ) );
		$GLOBALS['aculect_ai_companion_test_set_object_terms_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_wp_trash_post_callback'] = null;
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_set_object_terms_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_wp_trash_post_callback'] = null;

		parent::tearDown();
	}

	public function test_create_taxonomy_failure_is_compensated_by_trashing_created_post(): void {
		$GLOBALS['aculect_ai_companion_test_set_object_terms_callback'] = static fn(): \WP_Error => new \WP_Error( 'term_failure', 'Terms failed.' );

		$result = ( new ContentWriteCoordinator() )->create(
			array( 'post_type' => 'post', 'post_title' => 'New post' ),
			null,
			array( 'category' => array( 1 ) )
		);

		self::assertSame( 'term_failure', $result['error'] );
		self::assertSame( 'verified', $result['rollback_status'] );
		self::assertSame( 'trash', get_post( (int) $result['post_id'] )->post_status );
	}

	public function test_create_rollback_failure_is_terminal_partial_write(): void {
		$GLOBALS['aculect_ai_companion_test_set_object_terms_callback'] = static fn(): \WP_Error => new \WP_Error( 'term_failure', 'Terms failed.' );
		$GLOBALS['aculect_ai_companion_test_wp_trash_post_callback']   = static fn(): false => false;

		$result = ( new ContentWriteCoordinator() )->create(
			array( 'post_type' => 'post', 'post_title' => 'Needs review' ),
			null,
			array( 'category' => array( 1 ) )
		);

		self::assertSame( 'partial_write', $result['error'] );
		self::assertTrue( $result['terminal'] );
		self::assertSame( 'failed', $result['rollback_status'] );
		self::assertSame( 'draft', get_post( (int) $result['post_id'] )->post_status );
	}

	public function test_update_failure_restores_post_and_taxonomy_snapshot(): void {
		$attempts = 0;
		$GLOBALS['aculect_ai_companion_test_set_object_terms_callback'] = static function () use ( &$attempts ): ?\WP_Error {
			++$attempts;

			return 1 === $attempts ? new \WP_Error( 'term_failure', 'Terms failed.' ) : null;
		};

		$result = ( new ContentWriteCoordinator() )->update(
			get_post( 10 ),
			array( 'ID' => 10, 'post_title' => 'Changed title' ),
			array( 'category' => array( 2 ) ),
			array()
		);

		self::assertSame( 'term_failure', $result['error'] );
		self::assertSame( 'verified', $result['rollback_status'] );
		self::assertSame( 'Original title', get_post( 10 )->post_title );
		self::assertSame( array( 1 ), array_map( static fn( \WP_Term $term ): int => $term->term_id, wp_get_object_terms( 10, 'category' ) ) );
	}

	public function test_update_clear_without_existing_featured_media_is_idempotent(): void {
		unset( $GLOBALS['aculect_ai_companion_test_post_meta'][10]['_thumbnail_id'] );

		$result = ( new ContentWriteCoordinator() )->update(
			get_post( 10 ),
			array( 'ID' => 10, 'post_title' => 'Still safe' ),
			array(),
			array( 'value' => 0 )
		);

		self::assertTrue( $result['success'] );
		self::assertSame( 0, get_post_thumbnail_id( 10 ) );
	}
}

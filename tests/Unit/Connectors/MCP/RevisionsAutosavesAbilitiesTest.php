<?php
/**
 * Tests for read-only revision and autosave MCP discovery.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\RevisionsAutosavesAbilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies revision/autosave discovery stays bounded and capability-gated.
 */
final class RevisionsAutosavesAbilitiesTest extends TestCase {

	private RevisionsAutosavesAbilities $abilities;

	protected function setUp(): void {
		parent::setUp();

		$this->abilities = new RevisionsAutosavesAbilities();

		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = array();
		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_post_types']      = array(
			'post'    => new \WP_Post_Type( 'post', array( 'label' => 'Posts' ) ),
			'product' => new \WP_Post_Type(
				'product',
				array(
					'label'        => 'Products',
					'show_in_rest' => true,
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			7 => (object) array(
				'ID'           => 7,
				'display_name' => 'Ada Editor',
				'roles'        => array( 'editor' ),
			),
		);
		$GLOBALS['aculect_ai_companion_test_posts']           = array(
			123 => new \WP_Post(
				array(
					'ID'                => 123,
					'post_type'         => 'post',
					'post_status'       => 'draft',
					'post_title'        => 'Parent Draft',
					'post_modified_gmt' => '2026-07-02 10:00:00',
				)
			),
			456 => new \WP_Post(
				array(
					'ID'                => 456,
					'post_type'         => 'product',
					'post_status'       => 'publish',
					'post_title'        => 'Custom Product',
					'post_modified_gmt' => '2026-07-02 11:00:00',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_post_revisions']  = array(
			123 => array(
				1001 => $this->revision( 1001, 123, 'First Revision', '2026-07-02 09:30:00', 'Earlier safe body.' ),
				1002 => $this->revision( 1002, 123, 'Second Revision', '2026-07-02 09:45:00', str_repeat( 'Long preview text ', 80 ) ),
				1003 => $this->revision( 1003, 123, 'Autosave Hidden From Revision List', '2026-07-02 09:50:00', 'Autosave body.', '123-autosave-v1' ),
			),
		);
		$GLOBALS['aculect_ai_companion_test_post_autosaves']  = array(
			123 => array(
				7 => $this->revision( 1003, 123, 'Autosave Hidden From Revision List', '2026-07-02 09:50:00', 'Autosave body.', '123-autosave-v1' ),
			),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = array();

		parent::tearDown();
	}

	public function test_list_revisions_returns_bounded_metadata_without_body_by_default(): void {
		$result = $this->abilities->list_revisions(
			array(
				'post_id'  => 123,
				'per_page' => 1,
			)
		);

		self::assertSame( 123, $result['post_id'] );
		self::assertSame( 'Parent Draft', $result['parent']['title'] );
		self::assertSame( 2, $result['total'] );
		self::assertTrue( $result['has_more'] );
		self::assertCount( 1, $result['items'] );
		self::assertSame( 1002, $result['items'][0]['id'] );
		self::assertSame( 'revision', $result['items'][0]['type'] );
		self::assertSame( 'Ada Editor', $result['items'][0]['author']['display_name'] );
		self::assertArrayNotHasKey( 'content_preview', $result['items'][0] );
		self::assertArrayNotHasKey( 'content', $result['items'][0] );
		self::assertFalse( $result['items'][0]['comparison']['body_included'] );
		self::assertFalse( $result['preview']['included'] );
	}

	public function test_list_revisions_allows_explicit_capped_preview(): void {
		$result = $this->abilities->list_revisions(
			array(
				'post_id'         => 123,
				'include_preview' => true,
				'preview_chars'   => 40,
				'per_page'        => 5,
			)
		);

		self::assertTrue( $result['preview']['included'] );
		self::assertSame( 40, $result['preview']['max_chars'] );
		self::assertLessThanOrEqual( 43, strlen( $result['items'][0]['content_preview'] ) );
		self::assertStringEndsWith( '...', $result['items'][0]['content_preview'] );
	}

	public function test_list_revisions_denies_without_edit_post_capability(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_post' );

		$result = $this->abilities->list_revisions( array( 'post_id' => 123 ) );

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_list_revisions_returns_empty_collection_for_supported_post_without_revisions(): void {
		$result = $this->abilities->list_revisions( array( 'post_id' => 456 ) );

		self::assertSame( 456, $result['post_id'] );
		self::assertSame( 0, $result['total'] );
		self::assertSame( array(), $result['items'] );
		self::assertFalse( $result['has_more'] );
	}

	public function test_list_revisions_rejects_unsupported_parent_type(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][999] = new \WP_Post(
			array(
				'ID'          => 999,
				'post_type'   => 'revision',
				'post_status' => 'inherit',
			)
		);

		$result = $this->abilities->list_revisions( array( 'post_id' => 999 ) );

		self::assertSame( 'unsupported_post_type', $result['error'] );
	}

	public function test_inspect_autosaves_reports_presence_for_current_user(): void {
		$result = $this->abilities->inspect_autosaves(
			array(
				'post_id' => 123,
				'context' => 'full',
			)
		);

		self::assertTrue( $result['has_autosave'] );
		self::assertSame( 1003, $result['autosave']['id'] );
		self::assertSame( 'autosave', $result['autosave']['type'] );
		self::assertArrayHasKey( 'content_summary', $result['autosave'] );
		self::assertArrayNotHasKey( 'content_preview', $result['autosave'] );
		self::assertFalse( $result['autosave']['comparison']['restore_supported'] );
	}

	public function test_inspect_autosaves_reports_absence(): void {
		$result = $this->abilities->inspect_autosaves( array( 'post_id' => 456 ) );

		self::assertFalse( $result['has_autosave'] );
		self::assertNull( $result['autosave'] );
	}

	/**
	 * Build a test revision post.
	 *
	 * @param int    $id Revision ID.
	 * @param int    $parent_id Parent post ID.
	 * @param string $title Revision title.
	 * @param string $modified_gmt Modified GMT date.
	 * @param string $content Revision content.
	 * @param string $name Revision slug.
	 */
	private function revision( int $id, int $parent_id, string $title, string $modified_gmt, string $content, string $name = '' ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'                => $id,
				'post_type'         => 'revision',
				'post_status'       => 'inherit',
				'post_parent'       => $parent_id,
				'post_author'       => 7,
				'post_title'        => $title,
				'post_name'         => '' === $name ? $parent_id . '-revision-v1' : $name,
				'post_excerpt'      => 'Revision excerpt for ' . $title,
				'post_content'      => '<!-- wp:paragraph --><p>' . $content . '</p><!-- /wp:paragraph -->',
				'post_date_gmt'     => $modified_gmt,
				'post_modified_gmt' => $modified_gmt,
			)
		);
	}
}

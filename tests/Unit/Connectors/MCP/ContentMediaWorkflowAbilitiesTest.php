<?php
/**
 * Tests for content media workflow abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ContentMediaWorkflowAbilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies image-source workflows stay bounded and block-safe.
 */
final class ContentMediaWorkflowAbilitiesTest extends TestCase {

	private ContentMediaWorkflowAbilities $abilities;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_post_meta']       = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			7 => (object) array(
				'ID'           => 7,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
		$GLOBALS['aculect_ai_companion_test_posts']           = array(
			123 => new \WP_Post(
				array(
					'ID'           => 123,
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_title'   => 'Garden Guide',
					'post_content' => '<!-- wp:heading {"anchor":"intro"} --><h2 id="intro">Intro</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Existing opening paragraph.</p><!-- /wp:paragraph -->',
				)
			),
			456 => new \WP_Post(
				array(
					'ID'             => 456,
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_title'     => 'Raised garden bed',
					'post_mime_type' => 'image/jpeg',
					'post_parent'    => 123,
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_post_meta'][456]  = array(
			'_wp_attachment_image_alt' => 'Raised garden bed with herbs',
			'_source_url'              => 'https://example.com/uploads/garden.jpg',
		);

		$this->abilities = new ContentMediaWorkflowAbilities();
		$this->registerTestBlocks();
	}

	public function test_search_cc0_images_returns_bounded_openverse_candidates(): void {
		$GLOBALS['aculect_ai_companion_test_http_get'] = static function ( string $url ): array {
			self::assertStringContainsString( 'license=cc0', $url );

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'result_count' => 1,
						'results'      => array(
							array(
								'id'                  => 'ov-1',
								'title'               => 'Herb garden',
								'creator'             => 'Public Domain',
								'source'              => 'wikimedia',
								'license'             => 'cc0',
								'url'                 => 'https://images.example.com/herb-garden.jpg',
								'foreign_landing_url' => 'https://example.com/herb-garden',
								'width'               => 1200,
								'height'              => 800,
							),
						),
					)
				),
			);
		};

		$result = $this->abilities->search_cc0_images(
			array(
				'query'    => 'herb garden',
				'per_page' => 5,
			)
		);

		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'openverse', $result['provider'] );
		self::assertSame( 'cc0', $result['license'] );
		self::assertCount( 1, $result['items'] );
		self::assertSame( 'ov-1', $result['items'][0]['id'] );
		self::assertSame( 'https://images.example.com/herb-garden.jpg', $result['items'][0]['download_url'] );
	}

	public function test_apply_existing_attachment_as_featured_image_dry_run(): void {
		$result = $this->abilities->apply_image(
			array(
				'post_id'       => 123,
				'source_type'   => 'attachment_id',
				'attachment_id' => 456,
				'target'        => 'featured_image',
				'dry_run'       => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'content_media_apply_image', $result['workflow'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 456, $result['image']['attachment_id'] );
		self::assertContains( 'featured_media', array_column( $result['changes'], 'field' ) );
	}

	public function test_apply_existing_attachment_inserts_image_block_dry_run(): void {
		$result = $this->abilities->apply_image(
			array(
				'post_id'       => 123,
				'source_type'   => 'attachment_id',
				'attachment_id' => 456,
				'target'        => 'insert_block',
				'block_type'    => 'image',
				'placement'     => 'after_first_paragraph',
				'dry_run'       => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'destructive', $result['risk_level'] );
		$changes_by_field = array_column( $result['changes'], null, 'field' );
		self::assertArrayHasKey( 'content', $changes_by_field );
		self::assertStringContainsString( '<!-- wp:image', $changes_by_field['content']['to'] );
		self::assertStringContainsString( 'wp-image-456', $changes_by_field['content']['to'] );
		self::assertStringContainsString( 'Existing opening paragraph.', $changes_by_field['content']['to'] );
	}

	public function test_image_data_source_dry_run_plans_upload_before_apply(): void {
		$result = $this->abilities->apply_image(
			array(
				'post_id'     => 123,
				'source_type' => 'image_data',
				'data_url'    => 'data:image/png;base64,' . base64_encode( 'png-bytes' ),
				'target'      => 'insert_block',
				'block_type'  => 'gallery',
				'placement'   => 'append',
				'dry_run'     => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'image_data', $result['image']['source_type'] );
		self::assertContains( 'block_type', array_column( $result['changes'], 'field' ) );
		self::assertContains( 'Block validation will run after the image is imported and has a real WordPress attachment ID.', $result['warnings'] );
	}

	private function registerTestBlocks(): void {
		\WP_Block_Type_Registry::get_instance()->unregister_all();
		foreach ( array( 'core/heading', 'core/paragraph', 'core/image', 'core/gallery', 'core/cover', 'core/media-text' ) as $name ) {
			\WP_Block_Type_Registry::get_instance()->register(
				$name,
				array(
					'title'    => $name,
					'category' => in_array( $name, array( 'core/gallery', 'core/cover', 'core/media-text' ), true ) ? 'design' : 'media',
					'supports' => array(
						'inserter' => true,
					),
				)
			);
		}
	}
}

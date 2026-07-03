<?php
/**
 * Tests for reusable block and block area discovery abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\SiteStructureDiscoveryAbilities;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/site-workflow-stubs.php';

/**
 * Verifies site-structure discovery stays bounded and read-only.
 */
final class SiteStructureDiscoveryAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = array();
		$GLOBALS['aculect_ai_companion_test_is_block_theme']  = true;
		$GLOBALS['aculect_ai_companion_test_posts']           = array();
		$GLOBALS['aculect_ai_companion_test_post_types']      = array(
			'wp_block' => new \WP_Post_Type(
				'wp_block',
				array(
					'public'       => false,
					'show_ui'      => true,
					'show_in_rest' => true,
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_block_templates'] = array(
			'wp_template_part' => array(
				(object) array(
					'id'      => 'twentytwentysix//header',
					'slug'    => 'header',
					'type'    => 'wp_template_part',
					'area'    => 'header',
					'source'  => 'theme',
					'status'  => 'publish',
					'title'   => 'Header',
					'content' => '<!-- wp:group --><div></div><!-- /wp:group -->',
				),
				(object) array(
					'id'      => 'twentytwentysix//footer',
					'slug'    => 'footer',
					'type'    => 'wp_template_part',
					'area'    => 'footer',
					'source'  => 'custom',
					'status'  => 'publish',
					'title'   => 'Footer',
					'content' => '<!-- wp:group --><div></div><!-- /wp:group -->',
				),
			),
		);
		$GLOBALS['aculect_ai_companion_test_theme']           = array(
			'Name'       => 'Twenty Twenty-Six',
			'Version'    => '1.0.0',
			'Stylesheet' => 'twentytwentysix',
			'Template'   => 'twentytwentysix',
		);
		$this->set_registered_sidebars();
		$GLOBALS['aculect_ai_companion_test_sidebars_widgets'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = array();
		$GLOBALS['aculect_ai_companion_test_posts']           = array();
		$GLOBALS['aculect_ai_companion_test_post_types']      = array();
		$GLOBALS['aculect_ai_companion_test_block_templates'] = array();
		$this->set_registered_sidebars();
		$GLOBALS['aculect_ai_companion_test_sidebars_widgets'] = array();

		parent::tearDown();
	}

	public function test_lists_reusable_blocks_without_content_by_default(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][101] = new \WP_Post(
			array(
				'ID'                => 101,
				'post_type'         => 'wp_block',
				'post_status'       => 'publish',
				'post_title'        => 'CTA Banner',
				'post_name'         => 'cta-banner',
				'post_modified_gmt' => '2026-07-01 10:00:00',
				'post_content'      => '<!-- wp:group --><div><p>Join today</p></div><!-- /wp:group -->',
			)
		);

		$result = ( new SiteStructureDiscoveryAbilities() )->list_reusable_blocks();

		self::assertTrue( $result['available'] );
		self::assertTrue( $result['read_only'] );
		self::assertSame( 1, $result['total'] );
		self::assertSame( 101, $result['items'][0]['id'] );
		self::assertSame( 'synced_pattern', $result['items'][0]['type'] );
		self::assertSame( 'wp_block', $result['items'][0]['source'] );
		self::assertSame( 'publish', $result['items'][0]['status'] );
		self::assertSame( array( 'core/group' ), $result['items'][0]['usage_hints']['block_names'] );
		self::assertArrayHasKey( 'edit_link', $result['items'][0] );
		self::assertArrayNotHasKey( 'content_preview', $result['items'][0] );
		self::assertStringContainsString( 'never returned', $result['content_body_note'] );
	}

	public function test_reusable_block_preview_is_explicit_and_bounded(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][102] = new \WP_Post(
			array(
				'ID'                => 102,
				'post_type'         => 'wp_block',
				'post_status'       => 'draft',
				'post_title'        => 'Long Synced Pattern',
				'post_name'         => 'long-synced-pattern',
				'post_modified_gmt' => '2026-07-02 10:00:00',
				'post_content'      => '<!-- wp:paragraph --><p>' . str_repeat( 'Preview text ', 80 ) . '</p><!-- /wp:paragraph -->',
			)
		);

		$result = ( new SiteStructureDiscoveryAbilities() )->list_reusable_blocks(
			array(
				'include_preview' => true,
				'per_page'        => 200,
			)
		);

		self::assertSame( 100, $result['per_page'] );
		self::assertArrayHasKey( 'content_preview', $result['items'][0] );
		self::assertLessThanOrEqual( 600, strlen( $result['items'][0]['content_preview'] ) );
		self::assertTrue( $result['items'][0]['preview_truncated'] );
		self::assertStringNotContainsString( '<!-- wp:', $result['items'][0]['content_preview'] );
	}

	public function test_reusable_block_discovery_filters_unreadable_posts(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][103]      = new \WP_Post(
			array(
				'ID'           => 103,
				'post_type'    => 'wp_block',
				'post_status'  => 'private',
				'post_title'   => 'Private Pattern',
				'post_content' => '<!-- wp:paragraph --><p>Hidden</p><!-- /wp:paragraph -->',
			)
		);
		$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = array( 103 );

		$result = ( new SiteStructureDiscoveryAbilities() )->list_reusable_blocks();

		self::assertSame( 0, $result['total'] );
		self::assertSame( array(), $result['items'] );
	}

	public function test_lists_block_theme_template_part_areas(): void {
		$result = ( new SiteStructureDiscoveryAbilities() )->list_block_areas();

		self::assertSame( 'block_theme', $result['site_structure_mode'] );
		self::assertTrue( $result['block_areas']['available'] );
		self::assertSame( 2, $result['block_areas']['total'] );
		self::assertSame( 'header', $result['block_areas']['items'][0]['id'] );
		self::assertSame( 'block_area', $result['block_areas']['items'][0]['type'] );
		self::assertSame( 1, $result['block_areas']['items'][0]['template_part_count'] );
		self::assertFalse( $result['safety']['mutates_templates'] );
		self::assertFalse( $result['safety']['raw_theme_files_read'] );
	}

	public function test_lists_classic_widget_areas_and_counts(): void {
		$GLOBALS['aculect_ai_companion_test_is_block_theme'] = false;
		$this->set_registered_sidebars(
			array(
				'sidebar-1' => array(
					'id'          => 'sidebar-1',
					'name'        => 'Primary Sidebar',
					'description' => 'Appears beside posts.',
				),
				'footer-1'  => array(
					'id'          => 'footer-1',
					'name'        => 'Footer',
					'description' => 'Footer widgets.',
				),
			),
			array(
				'block-2' => array( 'name' => 'Block' ),
			)
		);
		$GLOBALS['aculect_ai_companion_test_sidebars_widgets'] = array(
			'sidebar-1' => array( 'block-2' ),
			'footer-1'  => array(),
		);

		$result = ( new SiteStructureDiscoveryAbilities() )->list_block_areas();

		self::assertSame( 'classic_theme', $result['site_structure_mode'] );
		self::assertTrue( $result['widget_areas']['available'] );
		self::assertSame( 2, $result['widget_areas']['total'] );
		self::assertSame( 'active', $result['widget_areas']['items'][0]['status'] );
		self::assertSame( 1, $result['widget_areas']['items'][0]['widget_count'] );
		self::assertSame( 'inactive', $result['widget_areas']['items'][1]['status'] );
		self::assertFalse( $result['safety']['mutates_widgets'] );
	}

	public function test_no_widget_areas_returns_empty_bounded_inventory(): void {
		$GLOBALS['aculect_ai_companion_test_is_block_theme']  = false;
		$GLOBALS['aculect_ai_companion_test_block_templates'] = array( 'wp_template_part' => array() );

		$result = ( new SiteStructureDiscoveryAbilities() )->list_block_areas();

		self::assertFalse( $result['widget_areas']['available'] );
		self::assertSame( 0, $result['widget_areas']['total'] );
		self::assertSame( array(), $result['widget_areas']['items'] );
		self::assertFalse( $result['block_areas']['available'] );
	}

	public function test_reusable_blocks_require_capability(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_posts', 'edit_theme_options' );

		$result = ( new SiteStructureDiscoveryAbilities() )->list_reusable_blocks();

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_block_areas_require_theme_capability(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_theme_options' );

		$result = ( new SiteStructureDiscoveryAbilities() )->list_block_areas();

		self::assertSame( 'forbidden', $result['error'] );
	}

	/**
	 * Set registered sidebar/widget globals used by the WordPress widget API.
	 *
	 * @param array<string, array<string, mixed>> $sidebars Registered sidebars.
	 * @param array<string, array<string, mixed>> $widgets  Registered widgets.
	 */
	private function set_registered_sidebars( array $sidebars = array(), array $widgets = array() ): void {
		global $wp_registered_sidebars, $wp_registered_widgets;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Unit tests need to seed WordPress sidebar/widget registries.
		$wp_registered_sidebars = $sidebars;
		$wp_registered_widgets  = $widgets;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}

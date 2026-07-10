<?php
/**
 * Tests for read-only navigation and menu discovery MCP abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\NavigationMenuDiscoveryAbilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies classic and block navigation discovery stays read-only and bounded.
 */
final class NavigationMenuDiscoveryAbilitiesTest extends TestCase {

	private NavigationMenuDiscoveryAbilities $abilities;

	protected function setUp(): void {
		parent::setUp();

		$this->abilities = new NavigationMenuDiscoveryAbilities();

		$GLOBALS['aculect_ai_companion_test_denied_caps']          = array();
		$GLOBALS['aculect_ai_companion_test_theme_supports']       = array( 'menus' => true );
		$GLOBALS['aculect_ai_companion_test_is_block_theme']       = false;
		$GLOBALS['aculect_ai_companion_test_theme']                = array(
			'Name'       => 'Twenty Twenty-One',
			'Version'    => '1.0.0',
			'Stylesheet' => 'twentytwentyone',
			'Template'   => 'twentytwentyone',
		);
		$GLOBALS['aculect_ai_companion_test_registered_nav_menus'] = array(
			'primary' => 'Primary Menu',
			'footer'  => 'Footer Menu',
		);
		$GLOBALS['aculect_ai_companion_test_nav_menu_locations']   = array(
			'primary' => 11,
		);
		$GLOBALS['aculect_ai_companion_test_nav_menus']            = array(
			array(
				'term_id'  => 11,
				'name'     => 'Main Menu',
				'slug'     => 'main-menu',
				'taxonomy' => 'nav_menu',
			),
			array(
				'term_id'  => 12,
				'name'     => 'Footer Links',
				'slug'     => 'footer-links',
				'taxonomy' => 'nav_menu',
			),
		);
		$GLOBALS['aculect_ai_companion_test_nav_menu_items']       = array(
			11 => array(
				(object) array(
					'ID'               => 101,
					'title'            => 'Home',
					'url'              => 'https://example.com/',
					'menu_item_parent' => 0,
					'menu_order'       => 1,
					'post_status'      => 'publish',
					'type'             => 'custom',
					'object'           => 'custom',
					'object_id'        => 0,
					'target'           => '',
					'xfn'              => '',
				),
				(object) array(
					'ID'               => 102,
					'title'            => 'Docs',
					'url'              => 'https://example.com/docs',
					'menu_item_parent' => 0,
					'menu_order'       => 2,
					'post_status'      => 'publish',
					'type'             => 'post_type',
					'object'           => 'page',
					'object_id'        => 201,
					'target'           => '',
					'xfn'              => '',
				),
				(object) array(
					'ID'               => 103,
					'title'            => 'API',
					'url'              => 'https://example.com/docs/api',
					'menu_item_parent' => 102,
					'menu_order'       => 3,
					'post_status'      => 'publish',
					'type'             => 'custom',
					'object'           => 'custom',
					'object_id'        => 0,
					'target'           => '_blank',
					'xfn'              => 'noopener',
				),
			),
			12 => array(),
		);
		$GLOBALS['aculect_ai_companion_test_posts']                = array();
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps']          = array();
		$GLOBALS['aculect_ai_companion_test_theme_supports']       = array();
		$GLOBALS['aculect_ai_companion_test_registered_nav_menus'] = array();
		$GLOBALS['aculect_ai_companion_test_nav_menu_locations']   = array();
		$GLOBALS['aculect_ai_companion_test_nav_menus']            = array();
		$GLOBALS['aculect_ai_companion_test_nav_menu_items']       = array();
		$GLOBALS['aculect_ai_companion_test_posts']                = array();

		parent::tearDown();
	}

	public function test_context_detects_classic_navigation_and_write_policy(): void {
		$result = $this->abilities->get_context();

		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'classic_theme', $result['navigation']['theme_mode'] );
		self::assertSame( 'classic_menu', $result['navigation']['primary_surface'] );
		self::assertSame( 2, $result['navigation']['registered_location_count'] );
		self::assertFalse( $result['write_support']['implemented'] );
		self::assertFalse( $result['safety']['raw_string_navigation_edits_allowed'] );
	}

	public function test_list_items_resolves_classic_location_to_assigned_menu(): void {
		$result = $this->abilities->list_items(
			array(
				'location' => 'primary',
			)
		);

		self::assertSame( 3, $result['total'] );
		self::assertSame( 'classic_location', $result['summary']['source_type'] );
		self::assertSame( 'classic_menu', $result['summary']['resolved_source_type'] );
		self::assertSame( 'Home', $result['items'][0]['label'] );
		self::assertSame( 102, $result['items'][2]['parent_id'] );
		self::assertSame( 'explicit_only', $result['summary']['future_reassignment_policy'] );
	}

	public function test_list_items_for_wp_navigation_returns_bounded_block_navigation_inventory(): void {
		$GLOBALS['aculect_ai_companion_test_is_block_theme'] = true;
		$GLOBALS['aculect_ai_companion_test_posts']          = array(
			501 => array(
				'ID'                => 501,
				'post_type'         => 'wp_navigation',
				'post_status'       => 'publish',
				'post_title'        => 'Header Navigation',
				'post_name'         => 'header-navigation',
				'post_modified_gmt' => '2026-07-06 10:00:00',
				'post_content'      => '<!-- wp:navigation --><!-- wp:navigation-link {"label":"Home","url":"https://example.com/"} /--><!-- wp:search /--><!-- wp:navigation-link {"label":"Docs","url":"https://example.com/docs"} --><!-- wp:navigation-link {"label":"API","url":"https://example.com/docs/api"} /--><!-- /wp:navigation-link --><!-- /wp:navigation -->',
			),
		);

		$result = $this->abilities->list_items(
			array(
				'navigation_id' => 501,
				'context'       => 'full',
			)
		);

		self::assertSame( 3, $result['total'] );
		self::assertSame( 'wp_navigation', $result['summary']['source_type'] );
		self::assertSame( 1, $result['summary']['navigation_block_count'] );
		self::assertSame( 3, $result['summary']['link_item_count'] );
		self::assertTrue( $result['summary']['mixed_blocks_detected'] );
		self::assertSame( 1, $result['summary']['unsupported_block_count'] );
		self::assertSame( 'block_navigation', $result['items'][0]['source_type'] );
		self::assertSame( 'block:0.2', $result['items'][2]['parent_id'] );
	}

	public function test_context_requires_edit_theme_options_capability(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_theme_options' );

		$result = $this->abilities->get_context();

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_context_reports_unsupported_dynamic_navigation_when_no_surfaces_are_readable(): void {
		$GLOBALS['aculect_ai_companion_test_theme_supports']       = array();
		$GLOBALS['aculect_ai_companion_test_registered_nav_menus'] = array();
		$GLOBALS['aculect_ai_companion_test_nav_menu_locations']   = array();
		$GLOBALS['aculect_ai_companion_test_nav_menus']            = array();
		$GLOBALS['aculect_ai_companion_test_nav_menu_items']       = array();

		$result = $this->abilities->get_context();

		self::assertSame( 'unsupported', $result['navigation']['theme_mode'] );
		self::assertSame( 'unsupported', $result['navigation']['primary_surface'] );
		self::assertTrue( $result['navigation']['dynamic_navigation_possible'] );
		self::assertNotEmpty( $result['navigation']['unsupported_reasons'] );
	}
}

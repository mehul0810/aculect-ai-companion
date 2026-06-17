<?php
/**
 * Tests for Site Editor intelligence MCP abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\SiteEditorAbilities;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/site-workflow-stubs.php';

/**
 * Verifies Site Editor intelligence stays admin-level and file-safe.
 */
final class SiteEditorAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']             = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_is_block_theme']      = true;
			$GLOBALS['aculect_ai_companion_test_theme']           = array(
				'Name'       => 'Twenty Twenty-Six',
				'Version'    => '1.0.0',
				'Stylesheet' => 'twentytwentysix',
				'Template'   => 'twentytwentysix',
			);
			$GLOBALS['aculect_ai_companion_test_global_settings'] = array(
				'color'      => array(
					'palette' => array(
						'theme' => array(
							array(
								'slug'  => 'primary',
								'name'  => 'Primary',
								'color' => '#111111',
							),
						),
					),
				),
				'typography' => array(
					'fontFamilies' => array(
						'theme' => array(
							array(
								'slug'       => 'system',
								'name'       => 'System',
								'fontFamily' => 'system-ui',
							),
						),
					),
				),
				'layout'     => array(
					'contentSize' => '720px',
					'wideSize'    => '1200px',
				),
			);
			$GLOBALS['aculect_ai_companion_test_global_styles']   = array(
				'color'  => array(
					'background' => '#ffffff',
					'text'       => '#111111',
				),
				'blocks' => array(
					'core/heading' => array(
						'typography' => array(
							'fontWeight' => '700',
						),
					),
				),
			);
			$GLOBALS['aculect_ai_companion_test_block_templates'] = array(
				'wp_template'      => array(
					(object) array(
						'id'             => 'twentytwentysix//front-page',
						'slug'           => 'front-page',
						'theme'          => 'twentytwentysix',
						'type'           => 'wp_template',
						'source'         => 'theme',
						'origin'         => 'theme',
						'status'         => 'publish',
						'title'          => 'Front Page',
						'description'    => 'Front page template.',
						'wp_id'          => 0,
						'has_theme_file' => true,
						'is_custom'      => false,
						'content'        => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
					),
				),
				'wp_template_part' => array(
					(object) array(
						'id'             => 'twentytwentysix//header',
						'slug'           => 'header',
						'theme'          => 'twentytwentysix',
						'type'           => 'wp_template_part',
						'source'         => 'custom',
						'origin'         => 'theme',
						'status'         => 'publish',
						'title'          => 'Header',
						'description'    => 'Site header.',
						'area'           => 'header',
						'wp_id'          => 123,
						'has_theme_file' => true,
						'is_custom'      => false,
						'content'        => '<!-- wp:group --><div class="wp-block-group"><!-- wp:site-title /--></div><!-- /wp:group -->',
					),
				),
			);
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_is_block_theme']  = true;
		$GLOBALS['aculect_ai_companion_test_global_settings'] = array();
		$GLOBALS['aculect_ai_companion_test_global_styles']   = array();
		$GLOBALS['aculect_ai_companion_test_block_templates'] = array();

		parent::tearDown();
	}

	public function test_context_describes_site_editor_without_file_writes(): void {
		$result = ( new SiteEditorAbilities() )->get_context();

		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'site_editor', $result['type'] );
		self::assertTrue( $result['theme']['is_block_theme'] );
		self::assertSame( 'Admin-level WordPress changes only; no filesystem or theme-file writes.', $result['site_editor']['change_model'] );
		self::assertTrue( $result['global_settings']['available'] );
		self::assertSame( 1, $result['templates']['total'] );
		self::assertSame( 1, $result['template_parts']['total'] );
		self::assertFalse( $result['safety']['filesystem_writes_allowed'] );
		self::assertNotEmpty( $result['memory_candidates'] );
	}

	public function test_get_template_part_returns_bounded_block_markup(): void {
		$result = ( new SiteEditorAbilities() )->get_template_part(
			array(
				'slug' => 'header',
			)
		);

		self::assertSame( 'header', $result['slug'] );
		self::assertSame( 'wp_template_part', $result['type'] );
		self::assertStringContainsString( '<!-- wp:site-title /-->', $result['content'] );
		self::assertSame( 'Serialized WordPress block markup.', $result['content_guidance']['format'] );
		self::assertContains( 'core/html', $result['content_guidance']['never_use'] );
	}

	public function test_refresh_context_stores_plugin_owned_snapshot(): void {
		$result = ( new SiteEditorAbilities() )->refresh_context();
		$stored = get_option( SiteEditorAbilities::OPTION_SNAPSHOT, array() );

		self::assertSame( 'success', $result['status'] );
		self::assertIsArray( $stored );
		self::assertNotEmpty( $stored['fingerprint'] );
		self::assertSame( $stored['fingerprint'], $result['snapshot']['fingerprint'] );
	}

	public function test_context_requires_site_editor_capability(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_theme_options' );

		$result = ( new SiteEditorAbilities() )->get_context();

		self::assertSame( 'forbidden', $result['error'] );
	}
}

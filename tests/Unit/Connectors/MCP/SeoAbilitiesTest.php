<?php
/**
 * Tests for SEO metadata ability dry-run diffs.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\SeoAbilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies SEO write previews expose safe reusable field-level diffs.
 */
final class SeoAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		$GLOBALS['aculect_ai_companion_test_options']     = array(
			'active_plugins' => array( 'seo-by-rank-math/rank-math.php' ),
		);
		$GLOBALS['aculect_ai_companion_test_posts']       = array(
			123 => new \WP_Post(
				array(
					'ID'          => 123,
					'post_type'   => 'post',
					'post_status' => 'draft',
					'post_title'  => 'Existing Draft',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_post_meta']   = array(
			123 => array(
				'rank_math_title'         => 'Existing SEO title',
				'rank_math_description'   => 'Existing description',
				'rank_math_focus_keyword' => 'old keyword',
			),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();

		parent::tearDown();
	}

	public function test_update_seo_dry_run_includes_changed_and_unchanged_public_diff_fields(): void {
		$result = ( new SeoAbilities() )->update_seo(
			array(
				'id'               => 123,
				'plugin'           => 'rank_math',
				'meta_title'       => 'Existing SEO title',
				'meta_description' => 'Updated description',
				'focus_keywords'   => array( 'mcp', 'dry run' ),
				'dry_run'          => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertArrayHasKey( 'diff', $result );

		$diff_by_field = array_column( $result['diff']['fields'], null, 'field' );
		self::assertFalse( $diff_by_field['meta_title']['changed'] );
		self::assertSame( 'Existing SEO title', $diff_by_field['meta_title']['before']['value'] );
		self::assertSame( 'Updated description', $diff_by_field['meta_description']['after']['value'] );
		self::assertTrue( $diff_by_field['meta_description']['changed'] );
		self::assertSame( 'mcp, dry run', $diff_by_field['focus_keywords']['after']['value'] );
		self::assertNotContains( 'rank_math_title', array_column( $result['diff']['fields'], 'field' ) );
		self::assertNotContains( 'rank_math_title', array_column( $result['changes'], 'field' ) );
	}

	public function test_update_seo_dry_run_redacts_previous_values_without_read_access(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'read_post' );

		$result = ( new SeoAbilities() )->update_seo(
			array(
				'id'         => 123,
				'plugin'     => 'rank_math',
				'meta_title' => 'Public proposed title',
				'dry_run'    => true,
			)
		);

		$diff_by_field = array_column( $result['diff']['fields'], null, 'field' );
		self::assertFalse( $diff_by_field['meta_title']['before']['available'] );
		self::assertSame( 'not_readable', $diff_by_field['meta_title']['before']['reason'] );
		self::assertArrayNotHasKey( 'value', $diff_by_field['meta_title']['before'] );
		self::assertNull( $diff_by_field['meta_title']['changed'] );

		$changes_by_field = array_column( $result['changes'], null, 'field' );
		self::assertNull( $changes_by_field['rank_math_title']['from'] );
		self::assertSame( 'Public proposed title', $changes_by_field['rank_math_title']['to'] );
	}

	public function test_update_seo_rejects_invalid_input_before_diff_preview(): void {
		$result = ( new SeoAbilities() )->update_seo(
			array(
				'id'      => 123,
				'plugin'  => 'rank_math',
				'dry_run' => true,
			)
		);

		self::assertSame( 'invalid_seo_fields', $result['error'] );
		self::assertArrayNotHasKey( 'diff', $result );
	}
}

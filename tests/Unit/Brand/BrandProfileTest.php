<?php
/**
 * Tests for safe brand profile storage and assistant output.
 *
 * @package Aculect\AICompanion\Tests\Unit\Brand
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Brand;

use Aculect\AICompanion\Brand\BrandProfile;
use PHPUnit\Framework\TestCase;

/**
 * Verifies brand profile data is sanitized before admins or assistants use it.
 */
final class BrandProfileTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array(
			'blogname'        => 'Default Site',
			'blogdescription' => 'Default tagline',
		);
	}

	public function test_saved_profile_is_sanitized_and_limited_to_known_fields(): void {
		$profile = new BrandProfile();

		$profile->save(
			array(
				'site_name'       => ' <strong>Custom Brand</strong> ',
				'primary_color'   => '#ABCDEF',
				'secondary_color' => 'not-a-color',
				'logo_url'        => 'javascript:alert(1)',
				'image_style'     => "Editorial photography\n<script>alert(1)</script>",
				'tone'            => str_repeat( 'Precise ', 200 ),
				'unknown_field'   => 'do not store',
			)
		);

		$saved = $profile->saved();

		self::assertSame( 'Custom Brand', $saved['site_name'] );
		self::assertSame( '#abcdef', $saved['primary_color'] );
		self::assertSame( '', $saved['secondary_color'] );
		self::assertSame( '', $saved['logo_url'] );
		self::assertStringContainsString( 'Editorial photography', $saved['image_style'] );
		self::assertStringNotContainsString( '<script>', $saved['image_style'] );
		self::assertLessThanOrEqual( 1200, strlen( $saved['tone'] ) );
		self::assertArrayNotHasKey( 'unknown_field', $saved );
	}

	public function test_public_profile_marks_saved_defaults_and_empty_sources(): void {
		$profile = new BrandProfile();

		$profile->save(
			array(
				'tone' => 'Clear, pragmatic, and direct.',
			)
		);

		$public = $profile->public_profile();

		self::assertSame( 'Default Site', $public['site']['name']['value'] );
		self::assertSame( 'default', $public['site']['name']['source'] );
		self::assertSame( 'Default tagline', $public['site']['tagline']['value'] );
		self::assertSame( 'default', $public['site']['tagline']['source'] );
		self::assertSame( 'Clear, pragmatic, and direct.', $public['editorial']['tone']['value'] );
		self::assertSame( 'saved', $public['editorial']['tone']['source'] );
		self::assertSame( '', $public['colors']['primary']['value'] );
		self::assertSame( 'empty', $public['colors']['primary']['source'] );
	}
}

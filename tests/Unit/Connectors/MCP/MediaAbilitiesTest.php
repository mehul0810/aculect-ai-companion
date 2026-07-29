<?php
/**
 * Tests for media MCP abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\MediaAbilities;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use PHPUnit\Framework\TestCase;

/**
 * Verifies media usage intelligence stays bounded and read-only.
 */
final class MediaAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_denied_caps']      = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids']  = array();
		$GLOBALS['aculect_ai_companion_test_posts']            = array();
		$GLOBALS['aculect_ai_companion_test_post_meta']        = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();
		$GLOBALS['aculect_ai_companion_test_post_statuses']    = array(
			'inherit' => (object) array( 'name' => 'inherit' ),
			'publish' => (object) array( 'name' => 'publish' ),
			'draft'   => (object) array( 'name' => 'draft' ),
			'private' => (object) array( 'name' => 'private' ),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps']      = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids']  = array();
		$GLOBALS['aculect_ai_companion_test_posts']            = array();
		$GLOBALS['aculect_ai_companion_test_post_meta']        = array();
		$GLOBALS['aculect_ai_companion_test_post_statuses']    = array();
		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();

		parent::tearDown();
	}

	public function test_audit_reports_attached_unattached_embedded_and_missing_alt_media(): void {
		$this->post( 10, 'post', 'publish', 'Parent post', '' );
		$this->attachment( 101, 'Hero image', 10, 'image/jpeg', 'Hero alt', 'Hero caption', '', 1200, 800 );
		$this->attachment( 102, 'Unused image', 0, 'image/png', '', '', '', 640, 480 );
		$this->attachment( 103, 'Embedded image', 0, 'image/webp', 'Embedded alt', '', 'Useful description.', 800, 600 );
		$this->post(
			20,
			'post',
			'publish',
			'Article',
			'<!-- wp:image {"id":103} --><figure class="wp-block-image"><img class="wp-image-103" src="https://example.com/uploads/image-103.jpg" /></figure><!-- /wp:image -->'
		);

		$result = ( new MediaAbilities() )->audit_usage( array( 'per_page' => 10 ) );
		$items  = array_column( $result['items'], null, 'id' );

		self::assertSame( 3, $result['total'] );
		self::assertTrue( $result['safety']['read_only'] );
		self::assertFalse( $result['safety']['deletion_actions_exposed'] );
		self::assertSame( 1, $result['summary']['attached'] );
		self::assertSame( 2, $result['summary']['unattached'] );
		self::assertSame( 2, $result['summary']['used'] );
		self::assertSame( 1, $result['summary']['likely_unused'] );
		self::assertSame( 1, $result['summary']['missing_alt'] );
		self::assertSame(
			array(
				'width'  => 1200,
				'height' => 800,
			),
			$items[101]['dimensions']
		);
		self::assertSame( 10, $items[101]['parent']['id'] );
		self::assertTrue( $items[101]['usage']['attached'] );
		self::assertFalse( $items[102]['has_alt_text'] );
		self::assertTrue( $items[102]['usage']['likely_unused'] );
		self::assertTrue( $items[103]['usage']['likely_used'] );
		self::assertSame( 20, $items[103]['usage']['content_references'][0]['post_id'] );
		self::assertContains( 'wp_image_class', $items[103]['usage']['content_references'][0]['evidence'] );
	}

	public function test_audit_filters_to_missing_alt_or_unused_media(): void {
		$this->attachment( 201, 'Alt missing', 0, 'image/jpeg', '' );
		$this->attachment( 202, 'Alt present', 0, 'image/jpeg', 'Present alt' );

		$missing = ( new MediaAbilities() )->audit_usage(
			array(
				'status_filter'      => 'missing_alt',
				'content_scan_limit' => 0,
			)
		);
		$unused  = ( new MediaAbilities() )->audit_usage(
			array(
				'status_filter'      => 'unused',
				'content_scan_limit' => 0,
			)
		);

		self::assertSame( array( 201 ), array_column( $missing['items'], 'id' ) );
		self::assertSame( array( 201, 202 ), array_column( $unused['items'], 'id' ) );
	}

	public function test_audit_omits_inaccessible_media(): void {
		$this->attachment( 301, 'Readable', 0, 'image/jpeg', 'Readable alt' );
		$this->attachment( 302, 'Denied', 0, 'image/jpeg', 'Denied alt' );
		$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = array( 302 );

		$result = ( new MediaAbilities() )->audit_usage( array() );

		self::assertSame( array( 301 ), array_column( $result['items'], 'id' ) );
		self::assertSame( 1, $result['summary']['total_scanned'] );
	}

	public function test_media_audit_tool_is_registered_in_media_operations(): void {
		$registry = new AbilitiesRegistry();

		self::assertArrayHasKey( 'media.audit_usage', $registry->definitions() );
		self::assertSame( 'media.audit_usage', $registry->internal_id( 'media_audit_usage' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );

		self::assertSame( 'media_audit_usage', $operations['media']['audit_usage']['tool'] );
		self::assertTrue( $operations['media']['audit_usage']['available'] );
	}

	public function test_image_data_rejects_oversized_base64_before_decoding(): void {
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_media_upload_max_bytes'] = static fn (): int => 1024;

		$result = ( new MediaAbilities() )->upload_image_data(
			array(
				'mime_type'   => 'image/png',
				'data_base64' => str_repeat( 'A', 1500 ),
				'dry_run'     => true,
			)
		);

		self::assertSame( 'file_too_large', $result['error'] ?? '' );
	}

	public function test_image_data_accepts_standard_line_wrapped_base64_within_limit(): void {
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_media_upload_max_bytes'] = static fn (): int => 100000;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Unit test fixture for line-wrapped image data.
		$encoded = chunk_split( base64_encode( str_repeat( 'x', 100000 ) ), 76, "\r\n" );

		$result = ( new MediaAbilities() )->upload_image_data(
			array(
				'mime_type'   => 'image/png',
				'data_base64' => $encoded,
				'dry_run'     => true,
			)
		);

		self::assertSame( 'preview', $result['status'] ?? '' );
		$changes = array_column( $result['changes'] ?? array(), null, 'field' );
		self::assertSame( 100000, $changes['bytes']['to'] ?? null );
	}

	private function post( int $id, string $type, string $status, string $title, string $content ): void {
		$GLOBALS['aculect_ai_companion_test_posts'][ $id ] = new \WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => $type,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}

	private function attachment( int $id, string $title, int $parent, string $mime_type, string $alt, string $caption = '', string $description = '', int $width = 0, int $height = 0 ): void {
		$GLOBALS['aculect_ai_companion_test_posts'][ $id ] = new \WP_Post(
			array(
				'ID'             => $id,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => $title,
				'post_excerpt'   => $caption,
				'post_content'   => $description,
				'post_parent'    => $parent,
				'post_mime_type' => $mime_type,
			)
		);

		$GLOBALS['aculect_ai_companion_test_post_meta'][ $id ] = array(
			'_wp_attachment_image_alt' => $alt,
			'_source_url'              => 'https://example.com/uploads/image-' . $id . '.jpg',
		);

		if ( $width > 0 && $height > 0 ) {
			$GLOBALS['aculect_ai_companion_test_post_meta'][ $id ]['_wp_attachment_metadata'] = array(
				'width'  => $width,
				'height' => $height,
			);
		}
	}
}

<?php
/**
 * Tests negotiated media and content picker resources.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\McpAppsPickerOrigin;
use Aculect\AICompanion\Connectors\MCP\MediaAbilities;
use Aculect\AICompanion\Connectors\MCP\MediaPickerMetadata;
use Aculect\AICompanion\Connectors\MCP\McpResourceRegistry;
use PHPUnit\Framework\TestCase;

/** Verifies picker negotiation, packaging, and network bounds. */
final class McpAppsPickerResourceTest extends TestCase {
	public function test_unauthorized_media_is_rejected_before_picker_metadata_is_added(): void {
		$previous_posts                                       = $GLOBALS['aculect_ai_companion_test_posts'] ?? array();
		$previous_denied_ids                                  = $GLOBALS['aculect_ai_companion_test_denied_post_ids'] ?? array();
		$GLOBALS['aculect_ai_companion_test_posts'][71]       = new \WP_Post(
			array(
				'ID'             => 71,
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = array( 71 );

		try {
			$result = ( new MediaAbilities() )->get_media( 71 );
		} finally {
			$GLOBALS['aculect_ai_companion_test_posts']           = $previous_posts;
			$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = $previous_denied_ids;
		}

		self::assertSame( 'forbidden', $result['error'] ?? '' );
		self::assertArrayNotHasKey( 'thumbnail', $result );
	}

	public function test_media_get_keeps_legacy_shape_when_apps_are_not_negotiated(): void {
		$previous_posts                                 = $GLOBALS['aculect_ai_companion_test_posts'] ?? array();
		$GLOBALS['aculect_ai_companion_test_posts'][72] = new \WP_Post(
			array(
				'ID'             => 72,
				'post_type'      => 'attachment',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Test image',
			)
		);
		try {
			$result = McpAppsNegotiation::with_request_enabled( false, static fn (): array => ( new MediaAbilities() )->get_media( 72 ) );
		} finally {
			$GLOBALS['aculect_ai_companion_test_posts'] = $previous_posts;
		}

		self::assertSame( 72, $result['id'] ?? null );
		self::assertSame( 'https://example.com/uploads/image-72.jpg', $result['source_url'] ?? '' );
		self::assertArrayNotHasKey( 'thumbnail', $result );
	}

	public function test_thumbnail_enrichment_leaves_non_images_unchanged(): void {
		$items = array(
			array(
				'id'         => 12,
				'mime_type'  => 'application/pdf',
				'source_url' => 'https://example.com/document.pdf',
			),
		);

		self::assertSame( $items, ( new MediaPickerMetadata() )->add_thumbnails( $items ) );
	}

	public function test_picker_origin_rejects_untrusted_or_malformed_urls(): void {
		$site_origin = McpAppsPickerOrigin::site();
		self::assertSame( 'https://example.com', McpAppsPickerOrigin::from_url( 'https://EXAMPLE.com:443/media/preview.jpg' ) );
		self::assertSame( 'https://localhost', McpAppsPickerOrigin::from_url( 'https://localhost/media.jpg' ) );
		self::assertSame( 'https://[2001:db8::1]', McpAppsPickerOrigin::from_url( 'https://[2001:db8::1]:443/media.jpg' ) );
		self::assertSame( '', McpAppsPickerOrigin::from_url( 'https://user:pass@example.com/media.jpg' ) );
		self::assertSame( '', McpAppsPickerOrigin::from_url( 'javascript:alert(1)' ) );
		self::assertSame( '', McpAppsPickerOrigin::from_url( 'https://bad host.example/media.jpg' ) );
		self::assertSame( '', McpAppsPickerOrigin::from_url( 'https://bad;host.example/media.jpg' ) );
		self::assertSame( '', McpAppsPickerOrigin::from_url( 'https://bad"host.example/media.jpg' ) );
		self::assertNotSame( $site_origin, McpAppsPickerOrigin::from_url( 'https://untrusted.invalid/media.jpg' ) );
	}

	public function test_malformed_site_url_disables_media_resource(): void {
		$previous_home                                 = $GLOBALS['aculect_ai_companion_test_home_url'] ?? null;
		$GLOBALS['aculect_ai_companion_test_home_url'] = 'https://bad;host.example';
		try {
			$result = ( new McpResourceRegistry() )->read_resource( array( 'uri' => McpAppsNegotiation::MEDIA_PICKER_URI ), true );
		} finally {
			if ( null === $previous_home ) {
				unset( $GLOBALS['aculect_ai_companion_test_home_url'] );
			} else {
				$GLOBALS['aculect_ai_companion_test_home_url'] = $previous_home;
			}
		}

		self::assertSame( 'resource_unavailable', $result['error'] ?? '' );
	}

	public function test_resources_are_hidden_without_negotiation(): void {
		$registry = new McpResourceRegistry();
		$uris     = array_column( $registry->list_resources()['resources'], 'uri' );

		self::assertNotContains( McpAppsNegotiation::MEDIA_PICKER_URI, $uris );
		self::assertNotContains( McpAppsNegotiation::CONTENT_PICKER_URI, $uris );
		self::assertSame( 'resource_not_found', $registry->read_resource( array( 'uri' => McpAppsNegotiation::MEDIA_PICKER_URI ) )['error'] );
		self::assertSame( 'resource_not_found', $registry->read_resource( array( 'uri' => McpAppsNegotiation::CONTENT_PICKER_URI ) )['error'] );
	}

	public function test_negotiated_assets_are_fixed_and_csp_is_site_scoped(): void {
		$registry = new McpResourceRegistry();
		$uris     = array_column( $registry->list_resources( true )['resources'], 'uri' );
		self::assertContains( McpAppsNegotiation::MEDIA_PICKER_URI, $uris );
		self::assertContains( McpAppsNegotiation::CONTENT_PICKER_URI, $uris );

		$media   = $registry->read_resource( array( 'uri' => McpAppsNegotiation::MEDIA_PICKER_URI ), true )['contents'][0] ?? array();
		$content = $registry->read_resource( array( 'uri' => McpAppsNegotiation::CONTENT_PICKER_URI ), true )['contents'][0] ?? array();
		$origin  = untrailingslashit( home_url() );

		self::assertSame( McpAppsNegotiation::MIME_TYPE, $media['mimeType'] ?? '' );
		self::assertSame( McpAppsNegotiation::MIME_TYPE, $content['mimeType'] ?? '' );
		self::assertStringNotContainsString( '__ACULECT_MEDIA_ORIGIN_JSON__', (string) ( $media['text'] ?? '' ) );
		self::assertStringNotContainsString( '__ACULECT_MEDIA_ORIGIN_CSP__', (string) ( $media['text'] ?? '' ) );
		self::assertStringContainsString( 'window.ACULECT_MEDIA_ORIGIN=', (string) ( $media['text'] ?? '' ) );
		self::assertStringContainsString( "img-src 'self' " . esc_attr( $origin ), (string) ( $media['text'] ?? '' ) );
		self::assertSame( array( $origin ), $media['_meta']['ui']['csp']['resourceDomains'] ?? null );
		self::assertSame( array(), $content['_meta']['ui']['csp']['resourceDomains'] ?? null );
		foreach ( array( $media, $content ) as $resource ) {
			self::assertSame( array(), $resource['_meta']['ui']['csp']['connectDomains'] ?? null );
			self::assertSame( array(), $resource['_meta']['ui']['csp']['frameDomains'] ?? null );
			self::assertSame( array(), $resource['_meta']['ui']['csp']['baseUriDomains'] ?? null );
			self::assertStringNotContainsString( 'Bearer ', (string) ( $resource['text'] ?? '' ) );
		}
	}
}

<?php
/**
 * Tests for the negotiated MCP pattern picker resource.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\McpResourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the resource is negotiated, packaged, and network-isolated.
 */
final class McpAppsPatternPickerResourceTest extends TestCase {

	public function test_resource_is_hidden_when_apps_are_not_negotiated(): void {
		$registry = new McpResourceRegistry();
		$uris     = array_column( $registry->list_resources()['resources'], 'uri' );

		self::assertNotContains( McpAppsNegotiation::PATTERN_PICKER_URI, $uris );
		self::assertSame( 'resource_not_found', $registry->read_resource( array( 'uri' => McpAppsNegotiation::PATTERN_PICKER_URI ) )['error'] );
	}

	public function test_negotiated_resource_bytes_and_csp_match_generated_local_asset(): void {
		$registry = new McpResourceRegistry();
		$uris     = array_column( $registry->list_resources( true )['resources'], 'uri' );
		$result   = $registry->read_resource( array( 'uri' => McpAppsNegotiation::PATTERN_PICKER_URI ), true );
		$content  = $result['contents'][0] ?? array();
		$html     = (string) ( $content['text'] ?? '' );
		$path     = dirname( __DIR__, 4 ) . '/assets/mcp-apps/pattern-picker/v1/pattern-picker.html';
		$bytes    = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a fixed packaged asset.

		self::assertContains( McpAppsNegotiation::PATTERN_PICKER_URI, $uris );
		self::assertSame( McpAppsNegotiation::MIME_TYPE, $content['mimeType'] ?? '' );
		self::assertSame( $bytes, $html );
		self::assertSame( array(), $content['_meta']['ui']['csp']['connectDomains'] ?? null );
		self::assertSame( array(), $content['_meta']['ui']['csp']['resourceDomains'] ?? null );
		self::assertSame( array(), $content['_meta']['ui']['csp']['frameDomains'] ?? null );
		self::assertSame( array(), $content['_meta']['ui']['csp']['baseUriDomains'] ?? null );
		self::assertDoesNotMatchRegularExpression( '/https?:\\/\\//i', $html );
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\McpResourceRegistry;
use PHPUnit\Framework\TestCase;

final class McpAppsSiteInfoResourceTest extends TestCase {

	public function test_experimental_view_is_hidden_from_default_resource_discovery_and_reads(): void {
		$registry = new McpResourceRegistry();
		$uris     = array_column( $registry->list_resources()['resources'], 'uri' );

		self::assertNotContains( McpAppsNegotiation::SITE_INFO_URI, $uris );
		self::assertSame( 'resource_not_found', $registry->read_resource( array( 'uri' => McpAppsNegotiation::SITE_INFO_URI ) )['error'] );
	}

	public function test_opted_in_resource_is_html_with_restrictive_csp_metadata(): void {
		$registry = new McpResourceRegistry();
		$uris     = array_column( $registry->list_resources( true )['resources'], 'uri' );
		$result   = $registry->read_resource( array( 'uri' => McpAppsNegotiation::SITE_INFO_URI ), true );
		$content  = $result['contents'][0] ?? array();
		$html     = (string) ( $content['text'] ?? '' );

		self::assertContains( McpAppsNegotiation::SITE_INFO_URI, $uris );
		self::assertSame( McpAppsNegotiation::MIME_TYPE, $content['mimeType'] ?? '' );
		self::assertSame( array(), $content['_meta']['ui']['csp']['connectDomains'] ?? null );
		self::assertSame( array(), $content['_meta']['ui']['csp']['resourceDomains'] ?? null );
		self::assertStringContainsString( 'ui/initialize', $html );
		self::assertStringContainsString( 'ui/notifications/tool-result', $html );
		self::assertStringContainsString( 'Connected site', $html );
		self::assertStringNotContainsString( 'src=', $html );
	}
}

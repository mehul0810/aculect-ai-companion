<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpAppsImageUploadResource;
use Aculect\AICompanion\Connectors\MCP\McpResourceRegistry;
use PHPUnit\Framework\TestCase;

final class McpAppsImageUploadResourceTest extends TestCase {
	private bool $had_site_url       = false;
	private mixed $previous_site_url = null;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn ( string $capability ): bool => 'upload_files' === $capability;
		$this->had_site_url                                       = array_key_exists( 'aculect_ai_companion_test_site_url', $GLOBALS );
		$this->previous_site_url                                  = $GLOBALS['aculect_ai_companion_test_site_url'] ?? null;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['aculect_ai_companion_test_capability_callback'] );
		if ( $this->had_site_url ) {
			$GLOBALS['aculect_ai_companion_test_site_url'] = $this->previous_site_url;
		} else {
			unset( $GLOBALS['aculect_ai_companion_test_site_url'] );
		}
		parent::tearDown();
	}

	public function test_descriptor_is_a_wordpress_upload_handoff_not_a_write_tool(): void {
		$descriptor = ( new McpAppsImageUploadResource() )->descriptor();

		self::assertSame( McpAppsImageUploadResource::URI, $descriptor['uri'] );
		self::assertSame( 'text/html;profile=mcp-app', $descriptor['mimeType'] );
		self::assertStringContainsString( 'human', $descriptor['description'] );
	}

	public function test_read_denies_users_without_wordpress_upload_capability(): void {
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn (): bool => false;

		$result = ( new McpAppsImageUploadResource() )->read();

		self::assertSame( 'forbidden', $result['error'] );
		self::assertSame( 'The connected WordPress user cannot upload media.', $result['message'] );
		$registry = new McpResourceRegistry();
		self::assertSame( 'resource_not_found', $registry->read_resource( array( 'uri' => McpAppsImageUploadResource::URI ), true )['error'] );
	}

	public function test_read_rejects_admin_handoff_when_its_host_differs_from_the_wordpress_site(): void {
		$GLOBALS['aculect_ai_companion_test_site_url'] = 'https://other.example';

		$result = ( new McpAppsImageUploadResource() )->read();

		self::assertSame( 'handoff_unavailable', $result['error'] );
	}

	public function test_resource_is_listed_only_with_negotiated_apps_and_upload_permission(): void {
		$registry = new McpResourceRegistry();
		$uris_off = array_column( $registry->list_resources()['resources'], 'uri' );
		$uris_on  = array_column( $registry->list_resources( true )['resources'], 'uri' );

		self::assertNotContains( McpAppsImageUploadResource::URI, $uris_off );
		self::assertContains( McpAppsImageUploadResource::URI, $uris_on );
		self::assertSame( 'resource_not_found', $registry->read_resource( array( 'uri' => McpAppsImageUploadResource::URI ) )['error'] );
		self::assertArrayHasKey( 'contents', $registry->read_resource( array( 'uri' => McpAppsImageUploadResource::URI ), true ) );
	}

	public function test_read_embeds_only_the_same_site_upload_page_url_and_empty_csp_domains(): void {
		$result  = ( new McpAppsImageUploadResource() )->read();
		$content = $result['contents'][0] ?? array();
		$html    = (string) ( $content['text'] ?? '' );

		self::assertSame( 'text/html;profile=mcp-app', $content['mimeType'] ?? '' );
		self::assertStringContainsString( 'https:\/\/example.com\/wp-admin\/admin.php?page=aculect-mcp-image-upload', $html );
		self::assertStringNotContainsString( '__ACULECT_UPLOAD_HANDOFF_URL_JSON__', $html );
		self::assertStringNotContainsString( 'nonce-', $html );
		self::assertStringNotContainsString( 'access_token', $html );
		self::assertStringNotContainsString( 'refresh_token', $html );
		self::assertSame( array(), $content['_meta']['ui']['csp']['connectDomains'] ?? null );
		self::assertSame( array(), $content['_meta']['ui']['csp']['resourceDomains'] ?? null );
		self::assertSame( array(), $content['_meta']['ui']['csp']['frameDomains'] ?? null );
		self::assertSame( array(), $content['_meta']['ui']['csp']['baseUriDomains'] ?? null );
	}
}

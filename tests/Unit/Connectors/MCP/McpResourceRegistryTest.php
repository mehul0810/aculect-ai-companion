<?php
/**
 * Tests for MCP resource registry.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpResourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies compact MCP resources are discoverable and readable.
 */
final class McpResourceRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
	}

	public function test_lists_compact_aculect_resources(): void {
		$result = ( new McpResourceRegistry() )->list_resources();
		$uris   = array_column( $result['resources'], 'uri' );

		self::assertContains( 'aculect://capabilities/directory', $uris );
		self::assertContains( 'aculect://site/summary', $uris );
		self::assertContains( 'aculect://workflow/guides', $uris );
		self::assertContains( 'aculect://memory/approved', $uris );
	}

	public function test_reads_capability_directory_resource_as_json_text(): void {
		$result  = ( new McpResourceRegistry() )->read_resource( array( 'uri' => 'aculect://capabilities/directory' ) );
		$content = $result['contents'][0] ?? array();
		$decoded = json_decode( (string) ( $content['text'] ?? '' ), true );

		self::assertSame( 'aculect://capabilities/directory', $content['uri'] );
		self::assertSame( 'application/json', $content['mimeType'] );
		self::assertIsArray( $decoded );
		self::assertArrayHasKey( 'workflows', $decoded );
		self::assertArrayHasKey( 'intelligence', $decoded );
	}

	public function test_unknown_resource_returns_error_payload(): void {
		$result = ( new McpResourceRegistry() )->read_resource( array( 'uri' => 'aculect://unknown' ) );

		self::assertSame( 'resource_not_found', $result['error'] );
	}
}

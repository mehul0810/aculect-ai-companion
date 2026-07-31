<?php
/**
 * Tests for manifest-derived client MCP tool-filtering guidance.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\Providers
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\Providers;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\Providers\ClientToolFilterGuidance;
use PHPUnit\Framework\TestCase;

/**
 * Verifies client examples use live, canonical public MCP tool names.
 */
final class ClientToolFilterGuidanceTest extends TestCase {

	public function test_recommended_tool_sets_resolve_to_current_manifest_public_names(): void {
		$guidance    = new ClientToolFilterGuidance();
		$registry    = new AbilitiesRegistry();
		$definitions = $registry->definitions();

		foreach ( $guidance->recommended_tool_sets() as $tool_set ) {
			self::assertNotEmpty( $tool_set['toolIds'] );
			self::assertCount( count( $tool_set['toolIds'] ), $tool_set['toolNames'] );

			foreach ( $tool_set['toolIds'] as $index => $id ) {
				self::assertArrayHasKey( $id, $definitions, sprintf( '%s must remain in the ability manifest.', $id ) );
				self::assertSame( $registry->tool_name( $id ), $tool_set['toolNames'][ $index ] );
			}
		}
	}

	public function test_only_documented_providers_receive_client_filtering_examples(): void {
		$guidance = new ClientToolFilterGuidance();

		foreach ( array( 'chatgpt', 'gemini', 'claude', 'cursor', 'grok' ) as $provider ) {
			self::assertTrue( $guidance->supports_provider( $provider ) );
			$section = $guidance->section_for_provider( $provider );
			self::assertSame( 'read_only_content_audit', $section['defaultToolSet'] );
			self::assertStringContainsString( 'not an authorization boundary', $section['description'] );
			self::assertStringContainsString( 'full exposed tool catalog', $section['warning'] );
		}

		self::assertFalse( $guidance->supports_provider( 'codex' ) );
		self::assertFalse( $guidance->supports_provider( 'mcp' ) );
	}

	public function test_xai_remote_mcp_example_stays_credential_and_url_free(): void {
		$section = ( new ClientToolFilterGuidance() )->section_for_provider( 'grok' );
		$values  = array_column( $section['copyFields'], 'value' );
		$example = implode( "\n", $values );

		self::assertStringContainsString( 'allowed_tools', $example );
		self::assertStringContainsString( 'allowed_tool_names', $example );
		self::assertStringNotContainsString( 'https://', $example );
		self::assertStringNotContainsString( 'token', strtolower( $example ) );
	}
}

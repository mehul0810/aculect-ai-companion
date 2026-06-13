<?php
/**
 * Tests for AI client provider registration and DCR attribution.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\Providers
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\Providers;

use Aculect\AICompanion\Connectors\Providers\ProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies setup metadata and provider detection share one registry.
 */
final class ProviderRegistryTest extends TestCase {

	public function test_registry_returns_builtin_provider_setup_definitions_with_generic_fallback(): void {
		$registry    = new ProviderRegistry();
		$definitions = $registry->setup_definitions( 'https://example.com/wp-json/aculect-ai-companion/v1/mcp' );
		$providers   = array_column( $definitions, null, 'id' );

		self::assertArrayHasKey( 'claude', $providers );
		self::assertArrayHasKey( 'chatgpt', $providers );
		self::assertArrayHasKey( 'codex', $providers );
		self::assertArrayHasKey( 'mcp', $providers );
		self::assertSame( 'MCP Client', $providers['mcp']['label'] );
		self::assertSame( 'Open MCP Docs', $providers['mcp']['primaryActionLabel'] );
		self::assertSame(
			'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
			$providers['mcp']['setupSections'][0]['copyFields'][0]['value']
		);
	}

	public function test_registry_detects_known_clients_and_unknown_mcp_fallback(): void {
		$registry = new ProviderRegistry();

		self::assertSame(
			'chatgpt',
			$registry->detect_provider_id( 'OpenAI ChatGPT Connector', array( 'https://chatgpt.com/oauth/callback' ) )
		);
		self::assertSame(
			'claude',
			$registry->detect_provider_id( 'Claude Desktop', array( 'http://localhost/callback' ) )
		);
		self::assertSame(
			'codex',
			$registry->detect_provider_id( 'Codex MCP Client', array( 'http://127.0.0.1:1455/callback' ) )
		);
		self::assertSame(
			'mcp',
			$registry->detect_provider_id( 'Custom MCP Client', array( 'https://example.org/callback' ) )
		);
	}
}

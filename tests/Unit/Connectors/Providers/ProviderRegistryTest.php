<?php
/**
 * Tests for AI client provider registration and DCR attribution.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\Providers
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\Providers;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;
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

		self::assertSame(
			array( 'chatgpt', 'codex', 'claude', 'gemini', 'cursor', 'grok', 'mcp' ),
			array_column( $definitions, 'id' )
		);
		self::assertArrayHasKey( 'claude', $providers );
		self::assertArrayHasKey( 'chatgpt', $providers );
		self::assertArrayHasKey( 'codex', $providers );
		self::assertArrayHasKey( 'cursor', $providers );
		self::assertArrayHasKey( 'gemini', $providers );
		self::assertArrayHasKey( 'grok', $providers );
		self::assertArrayHasKey( 'mcp', $providers );
		self::assertSame( 'OpenAI', $providers['chatgpt']['brandName'] );
		self::assertSame( 'https://openai.com/', $providers['chatgpt']['brandUrl'] );
		self::assertSame( 'OpenAI', $providers['codex']['brandName'] );
		self::assertSame( 'https://developers.openai.com/codex/mcp', $providers['codex']['primaryActionUrl'] );
		self::assertArrayHasKey( 'wizard', $providers['codex'] );
		self::assertCount( 4, $providers['codex']['wizard']['steps'] );
		self::assertSame( 'MCP Server Name', $providers['codex']['setupSections'][0]['copyFields'][0]['label'] );
		self::assertSame( 'aculect_ai_companion', $providers['codex']['setupSections'][0]['copyFields'][0]['value'] );
		self::assertSame( 'MCP URL', $providers['codex']['setupSections'][0]['copyFields'][1]['label'] );
		self::assertSame( 'https://example.com/wp-json/aculect-ai-companion/v1/mcp', $providers['codex']['setupSections'][0]['copyFields'][1]['value'] );
		self::assertSame( 'MCP URL', $providers['codex']['wizard']['steps'][1]['copyFields'][1]['label'] );
		self::assertSame( 'https://example.com/wp-json/aculect-ai-companion/v1/mcp', $providers['codex']['wizard']['steps'][1]['copyFields'][1]['value'] );
		self::assertStringContainsString( 'codex mcp login aculect_ai_companion', $providers['codex']['setupSections'][0]['copyFields'][2]['value'] );
		self::assertStringContainsString( 'The url key tells Codex to use Streamable HTTP.', implode( ' ', $providers['codex']['setupSections'][0]['steps'] ) );
		self::assertStringContainsString( '[mcp_servers.aculect_ai_companion]', $providers['codex']['setupSections'][1]['copyFields'][0]['value'] );
		self::assertStringNotContainsString( 'scopes =', $providers['codex']['setupSections'][1]['copyFields'][0]['value'] );
		self::assertSame( 'Anthropic', $providers['claude']['brandName'] );
		self::assertSame( 'Cursor', $providers['cursor']['label'] );
		self::assertSame( 'Cursor', $providers['cursor']['brandName'] );
		self::assertSame( 'https://cursor.com/', $providers['cursor']['brandUrl'] );
		self::assertSame( 'https://cursor.com/docs/mcp', $providers['cursor']['primaryActionUrl'] );
		self::assertSame( 'Cursor mcp.json', $providers['cursor']['wizard']['steps'][1]['copyFields'][0]['label'] );
		self::assertStringContainsString( '"url": "https://example.com/wp-json/aculect-ai-companion/v1/mcp"', $providers['cursor']['setupSections'][0]['copyFields'][0]['value'] );
		self::assertStringNotContainsString( '"httpUrl":', $providers['cursor']['setupSections'][0]['copyFields'][0]['value'] );
		self::assertStringContainsString( '.cursor/mcp.json', implode( ' ', $providers['cursor']['setupSections'][0]['steps'] ) );
		self::assertStringContainsString( 'cursor://anysphere.cursor-mcp/oauth/callback', implode( ' ', $providers['cursor']['setupSections'][1]['steps'] ) );
		self::assertSame( 'Gemini', $providers['gemini']['label'] );
		self::assertSame( 'Google', $providers['gemini']['brandName'] );
		self::assertSame( 'https://gemini.google.com/', $providers['gemini']['brandUrl'] );
		self::assertSame( 'https://github.com/google-gemini/gemini-cli/blob/main/docs/tools/mcp-server.md', $providers['gemini']['primaryActionUrl'] );
		self::assertStringContainsString( 'Gemini API Deep Research Agent', $providers['gemini']['description'] );
		self::assertStringContainsString( 'Gemini web does not currently provide', $providers['gemini']['wizard']['steps'][0]['description'] );
		self::assertSame( 'Gemini CLI add command', $providers['gemini']['wizard']['steps'][1]['copyFields'][0]['label'] );
		self::assertStringContainsString( 'gemini mcp add --transport http aculect-ai-companion https://example.com/wp-json/aculect-ai-companion/v1/mcp', $providers['gemini']['setupSections'][0]['copyFields'][0]['value'] );
		self::assertSame( 'Gemini MCP settings.json', $providers['gemini']['wizard']['steps'][1]['copyFields'][1]['label'] );
		self::assertStringContainsString( '"httpUrl": "https://example.com/wp-json/aculect-ai-companion/v1/mcp"', $providers['gemini']['setupSections'][0]['copyFields'][1]['value'] );
		self::assertStringContainsString( 'gemini mcp list', implode( ' ', $providers['gemini']['setupSections'][0]['steps'] ) );
		self::assertStringContainsString( 'Keep the JSON key named httpUrl', implode( ' ', $providers['gemini']['setupSections'][0]['steps'] ) );
		self::assertSame( 'https://docs.cloud.google.com/gemini/docs/codeassist/use-agentic-chat-pair-programmer', $providers['gemini']['setupSections'][1]['actionUrl'] );
		self::assertSame( 'Gemini web and API options', $providers['gemini']['setupSections'][2]['title'] );
		self::assertStringContainsString( 'gemini.google.com', implode( ' ', $providers['gemini']['setupSections'][2]['steps'] ) );
		self::assertStringContainsString( 'Deep Research Agent can connect to remote MCP servers', implode( ' ', $providers['gemini']['setupSections'][2]['steps'] ) );
		self::assertSame( 'https://ai.google.dev/gemini-api/docs/interactions/deep-research', $providers['gemini']['setupSections'][2]['actionUrl'] );
		self::assertSame( 'Grok', $providers['grok']['label'] );
		self::assertSame( 'xAI', $providers['grok']['brandName'] );
		self::assertSame( 'https://x.ai/', $providers['grok']['brandUrl'] );
		self::assertSame( 'https://grok.com/connectors', $providers['grok']['primaryActionUrl'] );
		self::assertSame( 'Open Grok Connectors', $providers['grok']['primaryActionLabel'] );
		self::assertArrayHasKey( 'wizard', $providers['grok'] );
		self::assertSame( 'Open Grok Connectors', $providers['grok']['wizard']['steps'][0]['title'] );
		self::assertStringContainsString( 'publicly reachable over HTTPS', $providers['grok']['setupSections'][0]['description'] );
		self::assertStringContainsString( 'New Connector', implode( ' ', $providers['grok']['setupSections'][0]['steps'] ) );
		self::assertStringContainsString( 'allowed_tools', implode( ' ', $providers['grok']['setupSections'][1]['steps'] ) );
		self::assertStringContainsString( 'require_approval', implode( ' ', $providers['grok']['setupSections'][1]['steps'] ) );
		self::assertStringContainsString( 'site_get_info', $providers['grok']['setupSections'][1]['copyFields'][0]['value'] );
		self::assertStringNotContainsString( 'XAI_API_KEY', $providers['grok']['setupSections'][1]['copyFields'][0]['value'] );
		self::assertSame( 'MCP Client', $providers['mcp']['label'] );
		self::assertSame( '', $providers['mcp']['brandName'] );
		self::assertSame( '', $providers['mcp']['brandUrl'] );
		self::assertSame( 'Open MCP Docs', $providers['mcp']['primaryActionLabel'] );
		self::assertSame( 'Open your MCP client', $providers['mcp']['wizard']['steps'][0]['title'] );
		self::assertSame(
			'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
			$providers['mcp']['setupSections'][0]['copyFields'][0]['value']
		);
	}

	public function test_registry_keeps_external_provider_metadata_without_wizard_contract(): void {
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect-ai-companion/connectors/providers'] =
			static fn(): array => array(
				new class() implements ProviderInterface {
					public function id(): string {
						return 'external';
					}

					public function label(): string {
						return 'External Assistant';
					}

					public function description(): string {
						return 'External provider metadata.';
					}

					public function primary_action_url(): string {
						return 'https://example.org/docs';
					}

					public function primary_action_label(): string {
						return 'Open Docs';
					}

					public function setup_sections( string $mcp_url ): array {
						return array(
							array(
								'title'      => 'Manual setup',
								'copyFields' => array(
									array(
										'label' => 'MCP URL',
										'value' => $mcp_url,
									),
								),
							),
						);
					}
				},
			);

		try {
			$definitions = ( new ProviderRegistry() )->setup_definitions( 'https://example.com/mcp' );
		} finally {
			unset( $GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect-ai-companion/connectors/providers'] );
		}

		self::assertCount( 1, $definitions );
		self::assertSame( 'external', $definitions[0]['id'] );
		self::assertArrayHasKey( 'setupSections', $definitions[0] );
		self::assertArrayNotHasKey( 'wizard', $definitions[0] );
		self::assertSame( 'https://example.com/mcp', $definitions[0]['setupSections'][0]['copyFields'][0]['value'] );
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
			'cursor',
			$registry->detect_provider_id( 'Cursor Agent', array( 'cursor://anysphere.cursor-mcp/oauth/callback' ) )
		);
		self::assertSame(
			'gemini',
			$registry->detect_provider_id( 'Gemini CLI MCP Client', array( 'http://localhost:7777/oauth/callback' ) )
		);
		self::assertSame(
			'gemini',
			$registry->detect_provider_id( 'Google Code Assist Agent', array( 'http://localhost:7777/oauth/callback' ) )
		);
		self::assertSame(
			'grok',
			$registry->detect_provider_id( 'Grok Connector', array( 'https://grok.com/oauth/callback' ) )
		);
		self::assertSame(
			'grok',
			$registry->detect_provider_id( 'Remote MCP Client', array( 'https://console.x.ai/oauth/callback' ) )
		);
		self::assertSame(
			'grok',
			$registry->detect_provider_id( 'xAI Remote MCP Client', array( 'https://example.org/callback' ) )
		);
		self::assertSame(
			'mcp',
			$registry->detect_provider_id( 'FluxAI Connector', array( 'https://example.org/callback' ) )
		);
		self::assertSame(
			'mcp',
			$registry->detect_provider_id( 'Remote MCP Client', array( 'https://example.org/oauth/x.ai/callback' ) )
		);
		self::assertSame(
			'mcp',
			$registry->detect_provider_id( 'Custom MCP Client', array( 'https://example.org/callback' ) )
		);
	}
}

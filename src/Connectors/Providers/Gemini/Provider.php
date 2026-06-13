<?php
/**
 * Gemini MCP client provider metadata.
 *
 * @package Aculect\AICompanion\Connectors\Providers\Gemini
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers\Gemini;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderMatcherInterface;

/**
 * Provides Gemini CLI and Gemini Code Assist MCP setup guidance.
 */
final class Provider implements ProviderInterface, ProviderMatcherInterface {

	/**
	 * Return the provider slug.
	 */
	public function id(): string {
		return 'gemini';
	}

	/**
	 * Return the provider label.
	 */
	public function label(): string {
		return 'Gemini';
	}

	/**
	 * Return the provider description.
	 */
	public function description(): string {
		return 'Use Gemini CLI or Gemini Code Assist agent mode to manage WordPress through Aculect AI Companion.';
	}

	/**
	 * Return the Gemini MCP documentation URL.
	 */
	public function primary_action_url(): string {
		return 'https://google-gemini.github.io/gemini-cli/docs/tools/mcp-server.html';
	}

	/**
	 * Return the primary action label.
	 */
	public function primary_action_label(): string {
		return 'Open Gemini MCP Docs';
	}

	/**
	 * Return Gemini setup sections for the admin UI.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 * @return array<int, array<string, mixed>>
	 */
	public function setup_sections( string $mcp_url ): array {
		return array(
			array(
				'title'       => 'Gemini CLI',
				'description' => 'Use this for terminal-based Gemini CLI sessions. Your connection URL must be reachable by Gemini CLI, and remote HTTP connections should use the streamable HTTP endpoint.',
				'steps'       => array(
					'Copy the Gemini settings JSON below.',
					'Add it to ~/.gemini/settings.json for your user, or to .gemini/settings.json for a project-scoped connection.',
					'Run gemini mcp list, or use /mcp list in Gemini CLI, to verify that Aculect AI Companion is connected.',
					'When Gemini asks to authorize the MCP server, approve the WordPress consent screen.',
					'Ask Gemini to list available Aculect tools before running write actions.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
				'copyFields'  => array(
					array(
						'label' => 'Gemini MCP settings.json',
						'value' => $this->settings_json_snippet( $mcp_url ),
					),
				),
			),
			array(
				'title'       => 'Gemini Code Assist agent mode',
				'description' => 'Use this for VS Code or IntelliJ agent-mode workflows where Gemini Code Assist can call configured MCP server tools.',
				'steps'       => array(
					'Open your Gemini settings JSON for the IDE account or workspace.',
					'Add Aculect AI Companion under mcpServers using the same httpUrl value from the Gemini CLI snippet.',
					'Switch Gemini Code Assist chat into Agent mode.',
					'Ask Gemini to inspect safe site context first, then approve any tool calls that can change WordPress.',
				),
				'actionLabel' => 'Open Code Assist Agent Mode Docs',
				'actionUrl'   => 'https://developers.google.com/gemini-code-assist/docs/use-agentic-chat-pair-programmer',
			),
			array(
				'title'       => 'Compatibility notes',
				'description' => 'Gemini discovers MCP tools from the server metadata and can filter tools client-side with includeTools or excludeTools when a site exposes many abilities.',
				'steps'       => array(
					'Prefer the workflow and intelligence tools for content work so Gemini gets the same guided path as other assistants.',
					'Keep trust disabled unless the Gemini client is running in a controlled environment.',
					'After plugin updates or ability policy changes, rerun gemini mcp list or reconnect the MCP server so Gemini refreshes tool metadata.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
			),
		);
	}

	/**
	 * Build the Gemini MCP settings JSON snippet.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 */
	private function settings_json_snippet( string $mcp_url ): string {
		$json = wp_json_encode(
			array(
				'mcpServers' => array(
					'aculect-ai-companion' => array(
						'httpUrl' => $mcp_url,
						'timeout' => 600000,
						'trust'   => false,
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Return whether DCR metadata belongs to Gemini.
	 *
	 * @param string   $client_name   Client display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 */
	public function matches_client( string $client_name, array $redirect_uris ): bool {
		$haystack = strtolower( $client_name . ' ' . implode( ' ', $redirect_uris ) );

		return str_contains( $haystack, 'gemini' )
			|| str_contains( $haystack, 'code assist' )
			|| str_contains( $haystack, 'google ai' );
	}
}

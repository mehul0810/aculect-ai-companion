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
use Aculect\AICompanion\Connectors\Providers\ProviderWizardInterface;

/**
 * Provides Gemini MCP setup guidance for supported Gemini surfaces.
 */
final class Provider implements ProviderInterface, ProviderMatcherInterface, ProviderWizardInterface {

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
		return 'Use Gemini CLI, Gemini Code Assist agent mode, or Gemini API Deep Research Agent to connect to Aculect AI Companion through MCP.';
	}

	/**
	 * Return the Gemini MCP documentation URL.
	 */
	public function primary_action_url(): string {
		return 'https://github.com/google-gemini/gemini-cli/blob/main/docs/tools/mcp-server.md';
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
				'description' => 'Use this for terminal-based Gemini CLI sessions. Gemini reads MCP servers from settings.json, and Streamable HTTP servers use the httpUrl field.',
				'steps'       => array(
					'Run the Gemini CLI add command below, or copy the settings JSON into ~/.gemini/settings.json for your user or .gemini/settings.json for a project.',
					'Keep the JSON key named httpUrl so Gemini treats Aculect AI Companion as a Streamable HTTP MCP server. The url key is for SSE servers.',
					'Keep trust set to false unless you are in a controlled environment and want Gemini to skip MCP tool confirmations.',
					'Run gemini mcp list, or use /mcp list in Gemini CLI, to verify that Aculect AI Companion is connected.',
					'When Gemini asks to authorize the MCP server, run /mcp auth aculect-ai-companion if needed and approve the WordPress consent screen.',
					'Ask Gemini to list available Aculect tools before running write actions.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
				'copyFields'  => array(
					array(
						'label' => 'Gemini CLI add command',
						'value' => $this->mcp_add_command( $mcp_url ),
					),
					array(
						'label' => 'Gemini MCP settings.json',
						'value' => $this->settings_json_snippet( $mcp_url ),
					),
				),
			),
			array(
				'title'       => 'Gemini Code Assist agent mode',
				'description' => 'Use this for Gemini Code Assist agent-mode workflows where Gemini can call configured MCP server tools.',
				'steps'       => array(
					'For VS Code, add Aculect AI Companion under mcpServers in ~/.gemini/settings.json. The command palette cannot install MCP servers for agent mode.',
					'For IntelliJ, add the MCP server to the IDE configuration mcp.json file if you use agent mode there.',
					'Use the same httpUrl value from the Gemini CLI snippet, then reload the IDE window if needed.',
					'Switch Gemini Code Assist chat into Agent mode.',
					'Ask Gemini to inspect safe site context first, then approve any tool calls that can change WordPress.',
				),
				'actionLabel' => 'Open Code Assist Agent Mode Docs',
				'actionUrl'   => 'https://docs.cloud.google.com/gemini/docs/codeassist/use-agentic-chat-pair-programmer',
			),
			array(
				'title'       => 'Gemini web and API options',
				'description' => 'Gemini web does not currently expose a documented custom MCP server setup. Use a supported Gemini MCP surface when you need Aculect AI Companion tools.',
				'steps'       => array(
					'Gemini web at gemini.google.com does not currently provide a documented custom MCP connector or MCP server settings screen.',
					'For interactive assistant use, connect through Gemini CLI or Gemini Code Assist agent mode with the connection URL above.',
					'For API-driven research workflows, the Gemini Deep Research Agent can connect to remote MCP servers through the Interactions API mcp_server tool type.',
					'Do not treat WebMCP or browser automation as native Gemini web MCP support; those paths do not make Gemini web perform Aculect OAuth discovery, Dynamic Client Registration, or MCP tool calls as a Gemini MCP client.',
				),
				'actionLabel' => 'Open Deep Research MCP Docs',
				'actionUrl'   => 'https://ai.google.dev/gemini-api/docs/interactions/deep-research',
			),
			array(
				'title'       => 'Compatibility notes',
				'description' => 'Gemini discovers MCP tools from the server metadata and can filter tools client-side with includeTools or excludeTools when a site exposes many abilities.',
				'steps'       => array(
					'Prefer the workflow and intelligence tools for content work so Gemini gets the same guided path as other assistants.',
					'Let Gemini use OAuth discovery and Dynamic Client Registration from the MCP endpoint instead of hard-coding WordPress credentials or Authorization headers.',
					'Keep trust disabled unless the Gemini client is running in a controlled environment.',
					'If a user asks for Gemini web, explain that Aculect does not have a Gemini web connection path until Google exposes a custom MCP connector surface there.',
					'After plugin updates or ability policy changes, rerun gemini mcp list or reconnect the MCP server so Gemini refreshes tool metadata.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
			),
		);
	}

	/**
	 * Return Gemini setup wizard metadata for the primary Connect flow.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 * @return array<string, mixed>
	 */
	public function setup_wizard( string $mcp_url ): array {
		return array(
			'estimatedTime' => '2 min',
			'steps'         => array(
				array(
					'id'                 => 'open',
					'title'              => 'Open Gemini MCP settings',
					'subtitle'           => 'Choose a supported Gemini MCP surface.',
					'description'        => 'Gemini web does not currently provide a documented custom MCP connector. Use Gemini CLI, Gemini Code Assist agent mode, or the Gemini API Deep Research Agent for MCP.',
					'instructions'       => array(
						array(
							'title'       => 'Choose a Gemini MCP surface',
							'description' => 'Use Gemini CLI for terminal workflows, Gemini Code Assist agent mode for IDE workflows, or the Gemini API Deep Research Agent for API-driven research workflows.',
						),
						array(
							'title'       => 'Avoid Gemini web for MCP',
							'description' => 'The public Gemini web app does not currently expose a documented custom MCP server setup.',
						),
					),
					'primaryActionLabel' => $this->primary_action_label(),
					'primaryActionUrl'   => $this->primary_action_url(),
				),
				array(
					'id'           => 'add',
					'title'        => 'Add MCP server',
					'subtitle'     => 'Add Aculect AI Companion to Gemini settings.',
					'description'  => 'Run the CLI command or copy the settings below. Keep the key named httpUrl for Streamable HTTP.',
					'instructions' => array(
						array(
							'title'       => 'Use the CLI command',
							'description' => 'Run the command below if you want Gemini CLI to write the MCP server entry for you.',
						),
						array(
							'title'       => 'Copy Gemini settings',
							'description' => 'Add the JSON below to ~/.gemini/settings.json or a project .gemini/settings.json file.',
						),
						array(
							'title'       => 'Verify MCP tools',
							'description' => 'Run gemini mcp list, or use /mcp list in Gemini CLI, to confirm the server is available.',
						),
					),
					'copyFields'   => array(
						array(
							'label'       => 'Gemini CLI add command',
							'description' => 'Run this from a terminal where Gemini CLI is installed.',
							'value'       => $this->mcp_add_command( $mcp_url ),
						),
						array(
							'label'       => 'Gemini MCP settings.json',
							'description' => 'Use this httpUrl configuration for Gemini CLI or Code Assist.',
							'value'       => $this->settings_json_snippet( $mcp_url ),
						),
					),
				),
				array(
					'id'                 => 'approve',
					'title'              => 'Authorize in WordPress',
					'subtitle'           => 'Authorize the connection securely in WordPress.',
					'description'        => 'When Gemini detects that the MCP server requires OAuth, approve the WordPress consent screen.',
					'instructions'       => array(
						array(
							'title'       => 'Start authorization',
							'description' => 'Run /mcp auth aculect-ai-companion if Gemini does not prompt automatically.',
						),
						array(
							'title'       => 'Approve connection',
							'description' => 'WordPress issues the connection after you approve the consent screen.',
						),
					),
					'primaryActionLabel' => 'Continue to WordPress authorization',
				),
				array(
					'id'           => 'complete',
					'title'        => 'Complete',
					'subtitle'     => 'Your Gemini MCP server is connected and ready to use.',
					'description'  => 'Ask Gemini to list available Aculect tools before running write actions.',
					'instructions' => array(
						array(
							'title'       => 'Connection active',
							'description' => 'Active sessions appear in the Connections tab where you can review or revoke access.',
						),
					),
				),
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
	 * Build the Gemini CLI command for adding the MCP server.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 */
	private function mcp_add_command( string $mcp_url ): string {
		return sprintf(
			'gemini mcp add --transport http aculect-ai-companion %s',
			$mcp_url
		);
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

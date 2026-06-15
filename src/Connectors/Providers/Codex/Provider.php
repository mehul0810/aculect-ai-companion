<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers\Codex;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderMatcherInterface;

/**
 * Provides Codex-specific MCP setup guidance.
 */
final class Provider implements ProviderInterface, ProviderMatcherInterface {

	/**
	 * Return the provider slug.
	 */
	public function id(): string {
		return 'codex';
	}

	/**
	 * Return the provider label.
	 */
	public function label(): string {
		return 'Codex';
	}

	/**
	 * Return the provider description.
	 */
	public function description(): string {
		return 'Use Codex for developer-assisted WordPress workflows through the same secure MCP and OAuth connection.';
	}

	/**
	 * Return the Codex MCP configuration documentation URL.
	 */
	public function primary_action_url(): string {
		return 'https://developers.openai.com/codex/mcp';
	}

	/**
	 * Return the primary action label.
	 */
	public function primary_action_label(): string {
		return 'Open Codex MCP Docs';
	}

	/**
	 * Return Codex setup sections for the admin UI.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 * @return array<int, array<string, mixed>>
	 */
	public function setup_sections( string $mcp_url ): array {
		return array(
			array(
				'title'       => 'Codex custom MCP',
				'description' => 'Use Streamable HTTP when adding Aculect AI Companion from the Codex custom MCP screen. Your connection URL must be reachable over HTTPS from the Codex client.',
				'steps'       => array(
					'Open Codex MCP settings and choose Connect to a custom MCP.',
					'Enter the MCP server name below.',
					'Select Streamable HTTP, not STDIO.',
					'Paste the MCP URL below into the URL field.',
					'Leave Bearer token env var, headers, and headers from environment variables empty; Aculect uses OAuth discovery from the MCP endpoint.',
					'Save the server, then let Codex start the OAuth login. If it does not start automatically, run the login command below.',
					'Approve the Aculect AI Companion consent screen in WordPress.',
					'Start a new Codex session and ask Codex to list tools or read safe site information before running write actions.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
				'copyFields'  => array(
					array(
						'label' => 'MCP Server Name',
						'value' => 'aculect_ai_companion',
					),
					array(
						'label' => 'MCP URL',
						'value' => $mcp_url,
					),
					array(
						'label' => 'OAuth Login Command',
						'value' => 'codex mcp login aculect_ai_companion',
					),
				),
			),
			array(
				'title'       => 'Codex config.toml',
				'description' => 'Use this manual option for Codex CLI or IDE sessions where you manage MCP servers from config.toml instead of the custom MCP screen.',
				'steps'       => array(
					'Copy the Codex MCP configuration below.',
					'Add it to your user-level ~/.codex/config.toml, or to a trusted project .codex/config.toml when you want the connection scoped to that project.',
					'Run codex mcp login aculect_ai_companion if Codex does not prompt for OAuth automatically.',
					'Approve the Aculect AI Companion consent screen in WordPress.',
					'Use /mcp in Codex or ask Codex to list tools to confirm the connection.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
				'copyFields'  => array(
					array(
						'label' => 'Codex config.toml',
						'value' => $this->config_snippet( $mcp_url ),
					),
				),
			),
			array(
				'title'       => 'Compatibility notes',
				'description' => 'Codex should use the Aculect AI Companion connection URL as a Streamable HTTP MCP server. Do not use WordPress application passwords, manual REST credentials, or broad bearer tokens for this connection.',
				'steps'       => array(
					'Keep the endpoint-only setup flow: Codex discovers OAuth from the MCP server and WordPress handles consent.',
					'Codex can read the server instructions returned during MCP initialization, so the workflow and intelligence guidance is supplied by Aculect after connection.',
					'If Codex cannot open the OAuth callback, check the Codex MCP OAuth callback settings, then retry codex mcp login aculect_ai_companion.',
					'If the available tools look stale after an update, reconnect the MCP server or start a fresh Codex session.',
					'Use Aculect diagnostic logging when troubleshooting so Codex attempts are visible without storing secrets.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
			),
		);
	}

	/**
	 * Build the Codex MCP server TOML snippet.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 */
	private function config_snippet( string $mcp_url ): string {
		return sprintf(
			"[mcp_servers.aculect_ai_companion]\nurl = \"%s\"",
			$mcp_url
		);
	}

	/**
	 * Return whether DCR metadata belongs to Codex.
	 *
	 * @param string   $client_name   Client display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 */
	public function matches_client( string $client_name, array $redirect_uris ): bool {
		$haystack = strtolower( $client_name . ' ' . implode( ' ', $redirect_uris ) );

		return str_contains( $haystack, 'codex' );
	}
}

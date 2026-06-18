<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers\Codex;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderMatcherInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderWizardInterface;

/**
 * Provides Codex-specific MCP setup guidance.
 */
final class Provider implements ProviderInterface, ProviderMatcherInterface, ProviderWizardInterface {

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
	 * Return Codex setup wizard metadata for the primary Connect flow.
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
					'title'              => 'Open Codex MCP settings',
					'subtitle'           => 'Open Codex and choose the custom MCP setup path.',
					'description'        => 'Codex connects to Aculect AI Companion as a Streamable HTTP MCP server.',
					'instructions'       => array(
						array(
							'title'       => 'Open MCP settings',
							'description' => 'Open Codex MCP settings and choose Connect to a custom MCP.',
						),
						array(
							'title'       => 'Choose Streamable HTTP',
							'description' => 'Use Streamable HTTP for Aculect AI Companion, not STDIO.',
						),
					),
					'primaryActionLabel' => $this->primary_action_label(),
					'primaryActionUrl'   => $this->primary_action_url(),
				),
				array(
					'id'           => 'add',
					'title'        => 'Add MCP server',
					'subtitle'     => 'Add Aculect AI Companion as a custom MCP server.',
					'description'  => 'Use the fields below in Codex. Leave manual bearer tokens and headers empty.',
					'instructions' => array(
						array(
							'title'       => 'Enter server details',
							'description' => 'Use the server name and MCP URL below in the Codex MCP server form.',
						),
						array(
							'title'       => 'Start OAuth login',
							'description' => 'Save the server, then let Codex start OAuth. If needed, run the login command below.',
						),
					),
					'copyFields'   => array(
						array(
							'label' => 'MCP Server Name',
							'value' => 'aculect_ai_companion',
						),
						array(
							'label'       => 'MCP URL',
							'description' => 'Paste this URL into the Codex Streamable HTTP URL field.',
							'value'       => $mcp_url,
						),
						array(
							'label' => 'OAuth Login Command',
							'value' => 'codex mcp login aculect_ai_companion',
						),
					),
				),
				array(
					'id'                 => 'approve',
					'title'              => 'Authorize in WordPress',
					'subtitle'           => 'Authorize the connection securely in WordPress.',
					'description'        => 'Codex will open the WordPress consent screen when OAuth starts.',
					'instructions'       => array(
						array(
							'title'       => 'Review connection request',
							'description' => 'Confirm the Codex connection, site, requested actions, and approving WordPress user.',
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
					'subtitle'     => 'Your Codex MCP server is connected and ready to use.',
					'description'  => 'Start a new Codex session and ask Codex to list tools or read safe site information.',
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

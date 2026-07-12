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
				'title'       => 'Codex MCP configuration',
				'description' => 'Codex CLI and the IDE extension share MCP servers from config.toml. Use a Streamable HTTP url entry for Aculect AI Companion.',
				'steps'       => array(
					'Open ~/.codex/config.toml, or a trusted project .codex/config.toml when you want the server scoped to that project.',
					'In the Codex IDE extension, open MCP settings and choose Open config.toml from the gear menu.',
					'Add the MCP server table and URL below. The url key tells Codex to use Streamable HTTP.',
					'Leave bearer_token_env_var, http_headers, and env_http_headers unset; Aculect advertises OAuth from the MCP endpoint.',
					'Run the OAuth login command below if Codex does not prompt for authorization automatically.',
					'Approve the Aculect AI Companion consent screen in WordPress.',
					'Use /mcp in Codex, or ask Codex to list tools, before running write actions.',
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
				'description' => 'Use this snippet when you manage Codex MCP servers by editing config.toml directly.',
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
					'If Codex cannot open the OAuth callback, configure mcp_oauth_callback_port or mcp_oauth_callback_url in config.toml, then retry codex mcp login aculect_ai_companion.',
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
					'title'              => 'Open Codex MCP configuration',
					'subtitle'           => 'Open the Codex config.toml used by the CLI or IDE extension.',
					'description'        => 'Codex connects to Aculect AI Companion as a Streamable HTTP MCP server when the server table contains a url.',
					'instructions'       => array(
						array(
							'title'       => 'Open config.toml',
							'description' => 'Open ~/.codex/config.toml or a trusted project .codex/config.toml. In the IDE extension, use MCP settings > Open config.toml.',
						),
						array(
							'title'       => 'Use the shared config',
							'description' => 'The Codex CLI and IDE extension use the same MCP configuration, so one server entry works in both clients.',
						),
					),
					'primaryActionLabel' => $this->primary_action_label(),
					'primaryActionUrl'   => $this->primary_action_url(),
				),
				array(
					'id'           => 'add',
					'title'        => 'Add MCP server',
					'subtitle'     => 'Add Aculect AI Companion to Codex config.toml.',
					'description'  => 'Use the fields below in Codex. Leave manual bearer tokens and headers unset.',
					'instructions' => array(
						array(
							'title'       => 'Add the server table',
							'description' => 'Paste the Codex config.toml snippet or add the server name and URL below to your MCP configuration.',
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

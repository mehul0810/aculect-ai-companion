<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers\Codex;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;

/**
 * Provides Codex-specific MCP setup guidance.
 */
final class Provider implements ProviderInterface {

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
		return 'https://developers.openai.com/codex/config-reference#configtoml';
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
				'title'       => 'Codex configuration',
				'description' => 'Use this for Codex CLI or Codex desktop sessions that support streamable HTTP MCP servers. Your connection URL must be publicly reachable over HTTPS because Codex connects from outside WordPress.',
				'steps'       => array(
					'Copy the Codex MCP configuration below.',
					'Add it to your user-level ~/.codex/config.toml, or to a trusted project .codex/config.toml when you want the connection scoped to that project.',
					'Start a new Codex session and allow the MCP OAuth login when Codex asks you to connect.',
					'Approve the Aculect AI Companion consent screen in WordPress.',
					'Ask Codex to list tools or read safe site information before running write actions.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
				'copyFields'  => array(
					array(
						'label' => 'Codex MCP Config',
						'value' => $this->config_snippet( $mcp_url ),
					),
				),
			),
			array(
				'title'       => 'Compatibility notes',
				'description' => 'Codex should use the Aculect AI Companion connection URL as a streamable HTTP MCP server. Do not use WordPress application passwords, manual REST credentials, or broad bearer tokens for this connection.',
				'steps'       => array(
					'Keep the endpoint-only setup flow: Codex discovers OAuth from the MCP server and WordPress handles consent.',
					'If Codex cannot open the OAuth callback, check the Codex MCP OAuth callback settings and retry the connection.',
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
			"[mcp_servers.aculect_ai_companion]\nurl = \"%s\"\nscopes = [\"content:read\", \"content:draft\"]\noauth_resource = \"%s\"",
			$mcp_url,
			$mcp_url
		);
	}
}

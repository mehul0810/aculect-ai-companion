<?php

declare(strict_types=1);

namespace Quark\Connectors\Providers\Claude;

use Quark\Connectors\Providers\ProviderInterface;

/**
 * Provides Claude-specific connector setup guidance.
 */
final class Provider implements ProviderInterface {

	/**
	 * Return the provider slug.
	 */
	public function id(): string {
		return 'claude';
	}

	/**
	 * Return the provider label.
	 */
	public function label(): string {
		return 'Claude';
	}

	/**
	 * Return the provider description.
	 */
	public function description(): string {
		return 'Connect Claude, Claude Desktop, Claude Code, or Claude API clients to WordPress through Quark MCP.';
	}

	/**
	 * Return the Claude connector setup URL.
	 */
	public function primary_action_url(): string {
		return 'https://claude.ai/settings/connectors';
	}

	/**
	 * Return the primary action label.
	 */
	public function primary_action_label(): string {
		return 'Open Claude Connectors';
	}

	/**
	 * Return Claude setup sections for app, CLI, and API usage.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 * @return array<int, array<string, mixed>>
	 */
	public function setup_sections( string $mcp_url ): array {
		return array(
			array(
				'title'       => 'Claude app, Claude Desktop, Cowork, and mobile',
				'description' => 'Use Claude custom connectors when you want the normal Claude app experience. Your MCP endpoint must be publicly reachable over HTTPS because Claude connects from Anthropic infrastructure.',
				'steps'       => array(
					'Open Claude connector settings. Team and Enterprise owners can use Organization settings > Connectors.',
					'Choose Add custom connector, or Custom > Web when adding it for an organization.',
					'Paste the MCP endpoint URL shown above.',
					'Finish adding the connector, then click Connect and approve the WordPress consent screen.',
					'Enable the connector for the conversation from the + menu > Connectors.',
				),
				'actionLabel' => 'Open Claude Connectors',
				'actionUrl'   => $this->primary_action_url(),
			),
			array(
				'title'       => 'Claude Code',
				'description' => 'Use this for terminal-based development workflows. Claude Code discovers Quark OAuth from the MCP endpoint and opens the WordPress consent flow from /mcp.',
				'steps'       => array(
					'Copy the Claude Code command below.',
					'Run it in a terminal where Claude Code is available.',
					'In Claude Code, run /mcp and choose Quark to authenticate.',
					'Approve the WordPress consent screen and return to Claude Code.',
				),
				'actionLabel' => 'Open Claude Code MCP Docs',
				'actionUrl'   => 'https://code.claude.com/docs/en/mcp',
				'copyFields'  => array(
					array(
						'label' => 'Claude Code Command',
						'value' => 'claude mcp add --transport http quark ' . $mcp_url,
					),
				),
			),
			array(
				'title'       => 'Claude API',
				'description' => 'Use this only when you are building an application with the Claude Messages API. The API MCP connector expects your application to obtain and refresh an OAuth access token before sending requests.',
				'steps'       => array(
					'Use the MCP endpoint URL shown above as the mcp_servers URL.',
					'Obtain a Quark OAuth access token through your own OAuth client flow or the MCP Inspector.',
					'Send the access token as authorization_token and reference the server from one MCP toolset.',
				),
				'actionLabel' => 'Open Claude API MCP Docs',
				'actionUrl'   => 'https://docs.anthropic.com/en/docs/agents-and-tools/mcp-connector',
			),
		);
	}
}

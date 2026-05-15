<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers\Claude;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;

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
		return 'Use Claude, Claude Desktop, Claude Code, or your own Claude integration to manage WordPress through Aculect AI Companion.';
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
				'description' => 'Use Claude custom connectors when you want the normal Claude app experience. Your connection URL must be publicly reachable over HTTPS because Claude connects from outside your WordPress site.',
				'steps'       => array(
					'Open Claude connector settings. Team and Enterprise owners can use Organization settings > Connectors.',
					'Choose Add custom connector, or Custom > Web when adding it for an organization.',
					'Paste your connection URL from above.',
					'Finish adding the connector, then click Connect and approve the connection on the WordPress screen that appears.',
					'Enable the connector for the conversation from the + menu > Connectors.',
				),
				'actionLabel' => 'Open Claude Connectors',
				'actionUrl'   => $this->primary_action_url(),
			),
			array(
				'title'       => 'Claude Code',
				'description' => 'Use this for terminal-based development workflows.',
				'steps'       => array(
					'Copy the Claude Code command below.',
					'Run it in a terminal where Claude Code is available.',
					'When Claude Code asks you to connect Aculect AI Companion, approve the connection on the WordPress screen that appears.',
					'Return to Claude Code and continue working with your site.',
				),
				'actionLabel' => 'Open Claude Code Docs',
				'actionUrl'   => 'https://code.claude.com/docs/en/mcp',
				'copyFields'  => array(
					array(
						'label' => 'Claude Code Command',
						'value' => 'claude mcp add --transport http aculect-ai-companion ' . $mcp_url,
					),
				),
			),
			array(
				'title'       => 'Claude API developers',
				'description' => 'Use this only when you are building your own Claude integration.',
				'steps'       => array(
					'Use your connection URL from above as the remote server URL in your application.',
					'Follow Claude developer documentation for the authorization details your application must handle.',
					'Keep destructive site actions behind explicit user approval in your application.',
				),
				'actionLabel' => 'Open Claude API Docs',
				'actionUrl'   => 'https://docs.anthropic.com/en/docs/agents-and-tools/mcp-connector',
			),
		);
	}
}

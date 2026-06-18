<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers\Claude;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderMatcherInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderWizardInterface;

/**
 * Provides Claude-specific connector setup guidance.
 */
final class Provider implements ProviderInterface, ProviderMatcherInterface, ProviderWizardInterface {

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
		return 'https://claude.ai/customize/connectors';
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

	/**
	 * Return Claude setup wizard metadata for the primary Connect flow.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 * @return array<string, mixed>
	 */
	public function setup_wizard( string $mcp_url ): array {
		return array(
			'estimatedTime' => '1 min',
			'steps'         => array(
				array(
					'id'                 => 'open',
					'title'              => 'Open Claude',
					'subtitle'           => 'Open Claude connector settings.',
					'description'        => 'Claude can connect to Aculect AI Companion through a custom connector.',
					'instructions'       => array(
						array(
							'title'       => 'Open Claude',
							'description' => 'Go to claude.ai and sign in to your account.',
						),
						array(
							'title'       => 'Open connector settings',
							'description' => 'Open connector settings. Team and Enterprise owners can use Organization settings.',
						),
					),
					'primaryActionLabel' => 'Open Claude',
					'primaryActionUrl'   => $this->primary_action_url(),
				),
				array(
					'id'           => 'add',
					'title'        => 'Add Connector',
					'subtitle'     => 'Add Aculect AI Companion as a custom connector.',
					'description'  => 'Use the connection URL below when Claude asks for the connector URL.',
					'instructions' => array(
						array(
							'title'       => 'Add custom connector',
							'description' => 'Choose Add custom connector, or Custom > Web for organization-managed connectors.',
						),
						array(
							'title'       => 'Paste the connection URL',
							'description' => 'Paste the Aculect connection URL and finish adding the connector.',
						),
					),
					'copyFields'   => array(
						array(
							'label'       => 'Your Aculect connection URL',
							'description' => 'Copy this URL and paste it into Claude when prompted.',
							'value'       => $mcp_url,
						),
					),
				),
				array(
					'id'                 => 'approve',
					'title'              => 'Review and approve',
					'subtitle'           => 'Authorize the connection securely in WordPress.',
					'description'        => 'Claude will redirect you to WordPress to review and approve the connection request.',
					'instructions'       => array(
						array(
							'title'       => 'Review connection request',
							'description' => 'Confirm the assistant, site, requested actions, and approving WordPress user.',
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
					'subtitle'     => 'Your AI assistant is connected and ready to use.',
					'description'  => 'Return to Claude and enable the connector for the conversation.',
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
	 * Return whether DCR metadata belongs to Claude or Anthropic.
	 *
	 * @param string   $client_name   Client display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 */
	public function matches_client( string $client_name, array $redirect_uris ): bool {
		$haystack = strtolower( $client_name . ' ' . implode( ' ', $redirect_uris ) );

		return str_contains( $haystack, 'claude' )
			|| str_contains( $haystack, 'anthropic' );
	}
}

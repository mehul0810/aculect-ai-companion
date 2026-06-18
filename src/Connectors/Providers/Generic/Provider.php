<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers\Generic;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderWizardInterface;

/**
 * Provides generic setup guidance for standards-compliant MCP clients.
 */
final class Provider implements ProviderInterface, ProviderWizardInterface {

	/**
	 * Return the provider slug.
	 */
	public function id(): string {
		return 'mcp';
	}

	/**
	 * Return the provider label.
	 */
	public function label(): string {
		return 'MCP Client';
	}

	/**
	 * Return the provider description.
	 */
	public function description(): string {
		return 'Use any MCP client that supports streamable HTTP servers, OAuth discovery, and Dynamic Client Registration.';
	}

	/**
	 * Return the generic MCP documentation URL.
	 */
	public function primary_action_url(): string {
		return 'https://modelcontextprotocol.io/';
	}

	/**
	 * Return the primary action label.
	 */
	public function primary_action_label(): string {
		return 'Open MCP Docs';
	}

	/**
	 * Return generic MCP setup sections for the admin UI.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 * @return array<int, array<string, mixed>>
	 */
	public function setup_sections( string $mcp_url ): array {
		return array(
			array(
				'title'       => 'Generic MCP client',
				'description' => 'Use this when your AI client supports remote MCP servers and OAuth discovery but does not have provider-specific setup guidance yet.',
				'steps'       => array(
					'Add a remote or streamable HTTP MCP server in your AI client.',
					'Paste your Aculect AI Companion connection URL as the server URL.',
					'Let the client discover OAuth metadata and Dynamic Client Registration from the MCP endpoint.',
					'Approve the WordPress consent screen when the client asks to connect.',
					'Start with read-only site or capability discovery before running write actions.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
				'copyFields'  => array(
					array(
						'label' => 'MCP Endpoint',
						'value' => $mcp_url,
					),
				),
			),
		);
	}

	/**
	 * Return generic MCP setup wizard metadata for the primary Connect flow.
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
					'title'              => 'Open your MCP client',
					'subtitle'           => 'Open the MCP settings in your AI assistant.',
					'description'        => 'Use this path for clients that support remote MCP servers and OAuth discovery.',
					'instructions'       => array(
						array(
							'title'       => 'Open MCP settings',
							'description' => 'Find the area where your client adds remote or Streamable HTTP MCP servers.',
						),
					),
					'primaryActionLabel' => $this->primary_action_label(),
					'primaryActionUrl'   => $this->primary_action_url(),
				),
				array(
					'id'           => 'add',
					'title'        => 'Add MCP server',
					'subtitle'     => 'Add Aculect AI Companion as a remote MCP server.',
					'description'  => 'Use the connection URL below as the server URL.',
					'instructions' => array(
						array(
							'title'       => 'Paste the connection URL',
							'description' => 'Paste this URL into your client as the remote or Streamable HTTP MCP server URL.',
						),
						array(
							'title'       => 'Use OAuth discovery',
							'description' => 'Let the client discover OAuth metadata and Dynamic Client Registration from the MCP endpoint.',
						),
					),
					'copyFields'   => array(
						array(
							'label'       => 'Your Aculect connection URL',
							'description' => 'Copy this URL and paste it into your MCP client when prompted.',
							'value'       => $mcp_url,
						),
					),
				),
				array(
					'id'                 => 'approve',
					'title'              => 'Authorize in WordPress',
					'subtitle'           => 'Authorize the connection securely in WordPress.',
					'description'        => 'Your client should redirect you to WordPress to review and approve the connection request.',
					'instructions'       => array(
						array(
							'title'       => 'Review connection request',
							'description' => 'Confirm the client, site, requested actions, and approving WordPress user.',
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
					'subtitle'     => 'Your MCP client is connected and ready to use.',
					'description'  => 'Start with safe site or capability discovery before running write actions.',
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
}

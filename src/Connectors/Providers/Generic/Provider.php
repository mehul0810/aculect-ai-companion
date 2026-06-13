<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers\Generic;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;

/**
 * Provides generic setup guidance for standards-compliant MCP clients.
 */
final class Provider implements ProviderInterface {

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
}

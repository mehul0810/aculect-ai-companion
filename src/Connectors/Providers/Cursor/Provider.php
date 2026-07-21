<?php
/**
 * Cursor MCP client provider metadata.
 *
 * @package Aculect\AICompanion\Connectors\Providers\Cursor
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers\Cursor;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderMatcherInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderWizardInterface;

/**
 * Provides Cursor-specific MCP setup guidance.
 */
final class Provider implements ProviderInterface, ProviderMatcherInterface, ProviderWizardInterface {

	/**
	 * Return the provider slug.
	 */
	public function id(): string {
		return 'cursor';
	}

	/**
	 * Return the provider label.
	 */
	public function label(): string {
		return 'Cursor';
	}

	/**
	 * Return the provider description.
	 */
	public function description(): string {
		return 'Use Cursor Agent to manage WordPress through Aculect AI Companion as a remote MCP server.';
	}

	/**
	 * Return the Cursor MCP documentation URL.
	 */
	public function primary_action_url(): string {
		return 'https://docs.cursor.com/context/model-context-protocol';
	}

	/**
	 * Return the primary action label.
	 */
	public function primary_action_label(): string {
		return 'Open Cursor MCP Docs';
	}

	/**
	 * Return Cursor setup sections for the admin UI.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 * @return array<int, array<string, mixed>>
	 */
	public function setup_sections( string $mcp_url ): array {
		return array(
			array(
				'title'       => 'Cursor mcp.json',
				'description' => 'Use Cursor Agent with Aculect AI Companion as a remote MCP server. Configure it globally in ~/.cursor/mcp.json or per project in .cursor/mcp.json.',
				'steps'       => array(
					'Open Cursor Settings > Tools & MCP, or edit your global ~/.cursor/mcp.json or project .cursor/mcp.json file.',
					'Copy the Cursor MCP configuration below. Cursor remote servers use the url key for HTTP or SSE endpoints.',
					'Save the file or add the server in Cursor, then enable the Aculect AI Companion server from MCP settings if it is not already enabled.',
					'Let Cursor start the OAuth approval flow and approve the Aculect AI Companion consent screen in WordPress.',
					'Open Available Tools or MCP Logs to confirm the WordPress tools are visible before running write actions.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
				'copyFields'  => array(
					array(
						'label' => 'Cursor mcp.json',
						'value' => $this->config_json_snippet( $mcp_url ),
					),
				),
			),
			array(
				'title'       => 'Compatibility notes',
				'description' => 'Cursor supports local STDIO and remote MCP servers. Use the remote URL configuration for Aculect so OAuth discovery, Dynamic Client Registration, and WordPress consent stay in control.',
				'steps'       => array(
					'Use the url key in Cursor mcp.json for the Aculect MCP endpoint; do not change it to httpUrl.',
					'Leave manual headers, bearer tokens, and static OAuth client credentials empty because Aculect supports OAuth discovery and Dynamic Client Registration.',
					'Only use Cursor static OAuth fields when an MCP provider requires a fixed client registration. The fixed Cursor redirect URI is cursor://anysphere.cursor-mcp/oauth/callback.',
					'After plugin updates or ability policy changes, toggle the Cursor MCP server off and on so Cursor refreshes tool metadata.',
					'Do not use WordPress application passwords or raw REST credentials for Cursor MCP access.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
			),
		);
	}

	/**
	 * Return Cursor setup wizard metadata for the primary Connect flow.
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
					'title'              => 'Open Cursor MCP settings',
					'subtitle'           => 'Open Cursor and go to Tools & MCP.',
					'description'        => 'Cursor connects to Aculect AI Companion as a remote MCP server using the url key in mcp.json.',
					'instructions'       => array(
						array(
							'title'       => 'Open Cursor settings',
							'description' => 'Open Cursor Settings > Tools & MCP, or edit your global or project mcp.json file.',
						),
						array(
							'title'       => 'Choose remote MCP',
							'description' => 'Use a remote URL connection so OAuth discovery, Dynamic Client Registration, and WordPress consent stay in control.',
						),
					),
					'primaryActionLabel' => $this->primary_action_label(),
					'primaryActionUrl'   => $this->primary_action_url(),
				),
				array(
					'id'           => 'add',
					'title'        => 'Add MCP server',
					'subtitle'     => 'Add Aculect AI Companion to Cursor mcp.json.',
					'description'  => 'Copy the configuration below into Cursor, then enable the server.',
					'instructions' => array(
						array(
							'title'       => 'Copy Cursor configuration',
							'description' => 'Add the JSON below to ~/.cursor/mcp.json or a project .cursor/mcp.json file. Keep the remote endpoint under url.',
						),
						array(
							'title'       => 'Enable the server',
							'description' => 'Save the file or add the server in Cursor, then enable Aculect AI Companion from MCP settings if needed.',
						),
					),
					'copyFields'   => array(
						array(
							'label'       => 'Cursor mcp.json',
							'description' => 'Use this remote URL configuration for Cursor Agent.',
							'value'       => $this->config_json_snippet( $mcp_url ),
						),
					),
				),
				array(
					'id'                 => 'approve',
					'title'              => 'Authorize in WordPress',
					'subtitle'           => 'Authorize the connection securely in WordPress.',
					'description'        => 'Cursor will start the OAuth approval flow and open the WordPress consent screen.',
					'instructions'       => array(
						array(
							'title'       => 'Review connection request',
							'description' => 'Confirm the Cursor connection, site, requested actions, and approving WordPress user.',
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
					'subtitle'     => 'Your Cursor MCP server is connected and ready to use.',
					'description'  => 'Open Available Tools or MCP Logs to confirm the WordPress tools are visible.',
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
	 * Build the Cursor MCP JSON snippet.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 */
	private function config_json_snippet( string $mcp_url ): string {
		$json = wp_json_encode(
			array(
				'mcpServers' => array(
					'aculect-ai-companion' => array(
						'url' => $mcp_url,
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Return whether DCR metadata belongs to Cursor.
	 *
	 * @param string   $client_name   Client display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 */
	public function matches_client( string $client_name, array $redirect_uris ): bool {
		$haystack = strtolower( $client_name . ' ' . implode( ' ', $redirect_uris ) );

		return str_contains( $haystack, 'cursor' )
			|| str_contains( $haystack, 'anysphere' )
			|| str_contains( $haystack, 'cursor-mcp' );
	}
}

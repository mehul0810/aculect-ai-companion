<?php
/**
 * Grok and xAI MCP client provider metadata.
 *
 * @package Aculect\AICompanion\Connectors\Providers\Grok
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers\Grok;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderMatcherInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderWizardInterface;

/**
 * Provides Grok and xAI remote MCP setup guidance.
 */
final class Provider implements ProviderInterface, ProviderMatcherInterface, ProviderWizardInterface {

	/**
	 * Return the provider slug.
	 */
	public function id(): string {
		return 'grok';
	}

	/**
	 * Return the provider label.
	 */
	public function label(): string {
		return 'Grok';
	}

	/**
	 * Return the provider description.
	 */
	public function description(): string {
		return 'Use Grok custom MCP connectors or the xAI API to work with WordPress through Aculect AI Companion.';
	}

	/**
	 * Return the Grok connector setup URL.
	 */
	public function primary_action_url(): string {
		return 'https://grok.com/connectors';
	}

	/**
	 * Return the primary action label.
	 */
	public function primary_action_label(): string {
		return 'Open Grok Connectors';
	}

	/**
	 * Return Grok and xAI API setup sections for the admin UI.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 * @return array<int, array<string, mixed>>
	 */
	public function setup_sections( string $mcp_url ): array {
		return array(
			array(
				'title'       => 'Grok custom MCP connector',
				'description' => 'Use this for Grok conversations. Your connection URL must be publicly reachable over HTTPS because Grok connects to custom MCP servers from the public internet.',
				'steps'       => array(
					'Open grok.com/connectors, select New Connector, then select Custom.',
					'Paste the Aculect connection URL as the MCP server URL and complete the required authentication.',
					'If Grok starts the Aculect authorization flow, sign in to WordPress and review the consent screen before approving access.',
					'Confirm that Grok has discovered the available tools, then begin with a read-only request before allowing WordPress changes.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
				'copyFields'  => array(
					array(
						'label'       => 'Your Aculect connection URL',
						'description' => 'Copy this URL and paste it into Grok as the custom MCP server URL.',
						'value'       => $mcp_url,
					),
				),
			),
			array(
				'title'       => 'xAI API remote MCP tools',
				'description' => 'Use this only when you are building your own application with the xAI API. Keep the xAI API key in that application, never in WordPress or this setup screen.',
				'steps'       => array(
					'Configure an xAI Remote MCP Tool with server_url, server_label, and the Aculect connection URL below.',
					'Start with an explicit allowed_tools list containing only read-only tools your WordPress user can access. Without allowed_tools, xAI makes every exposed tool available to the model.',
					'xAI does not support the OpenAI Responses API require_approval parameter. Keep Aculect server-side scopes, roles, capabilities, risk checks, and write confirmations enabled.',
					'Complete Aculect OAuth in your application before passing a protected access token as authorization. Do not store API keys, bearer tokens, or static OAuth client secrets in WordPress.',
				),
				'actionLabel' => 'Open xAI Remote MCP Docs',
				'actionUrl'   => 'https://docs.x.ai/developers/tools/remote-mcp',
				'copyFields'  => array(
					array(
						'label'       => 'xAI Remote MCP tool shape',
						'description' => 'Use this no-secret example in your xAI application. Replace the initial allowlist only after reviewing the tools available to the approving WordPress user.',
						'value'       => $this->api_tool_json_snippet( $mcp_url ),
					),
				),
			),
			array(
				'title'       => 'Troubleshooting and safety',
				'description' => 'Keep the connection path simple: public HTTPS endpoint, WordPress OAuth consent, and a small tool allowlist for API use.',
				'steps'       => array(
					'If Grok cannot reach your server, verify that the WordPress MCP endpoint is publicly reachable over HTTPS. Local development sites need a separately managed public tunnel; Aculect does not provide one.',
					'If authorization does not start, confirm the MCP URL is correct and retry from the Grok connector. Do not replace OAuth with a WordPress application password or raw REST credential.',
					'Revoke the connection from Aculect AI Companion > Connections when access should end, then remove the connector or API configuration from xAI.',
				),
				'actionLabel' => 'Open Grok Connector Docs',
				'actionUrl'   => 'https://docs.x.ai/grok/connectors',
			),
		);
	}

	/**
	 * Return Grok setup wizard metadata for the primary Connect flow.
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
					'title'              => 'Open Grok Connectors',
					'subtitle'           => 'Start a custom MCP connector in Grok.',
					'description'        => 'Grok custom connectors use a public MCP server URL. Keep your Aculect endpoint publicly reachable over HTTPS.',
					'instructions'       => array(
						array(
							'title'       => 'Open Connectors',
							'description' => 'Go to grok.com/connectors and select New Connector > Custom.',
						),
						array(
							'title'       => 'Use a public endpoint',
							'description' => 'Hosted Grok connections cannot reach a local-only WordPress site.',
						),
					),
					'primaryActionLabel' => $this->primary_action_label(),
					'primaryActionUrl'   => $this->primary_action_url(),
				),
				array(
					'id'           => 'add',
					'title'        => 'Add MCP server',
					'subtitle'     => 'Add Aculect AI Companion as a custom connector.',
					'description'  => 'Paste the connection URL below as Grok\'s custom MCP server URL.',
					'instructions' => array(
						array(
							'title'       => 'Paste the connection URL',
							'description' => 'Enter the Aculect URL in the custom MCP connector form and complete the connector setup.',
						),
						array(
							'title'       => 'Avoid static credentials',
							'description' => 'Do not add WordPress application passwords, raw REST credentials, or xAI API keys to WordPress.',
						),
					),
					'copyFields'   => array(
						array(
							'label'       => 'Your Aculect connection URL',
							'description' => 'Copy this URL and paste it into Grok when prompted.',
							'value'       => $mcp_url,
						),
					),
				),
				array(
					'id'                 => 'approve',
					'title'              => 'Authorize in WordPress',
					'subtitle'           => 'Review and approve the connection securely.',
					'description'        => 'If Grok starts authorization, sign in to WordPress and approve only the requested Aculect access.',
					'instructions'       => array(
						array(
							'title'       => 'Review the request',
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
					'subtitle'     => 'Your Grok connector is ready to use.',
					'description'  => 'Begin with a read-only request. For xAI API applications, retain a narrow allowed_tools list and Aculect server-side access controls.',
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
	 * Build a no-secret xAI Remote MCP Tool configuration snippet.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 */
	private function api_tool_json_snippet( string $mcp_url ): string {
		$json = wp_json_encode(
			array(
				'type'          => 'mcp',
				'server_url'    => $mcp_url,
				'server_label'  => 'aculect-ai-companion',
				'allowed_tools' => array( 'site_get_info' ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Return whether DCR metadata belongs to Grok or xAI.
	 *
	 * @param string   $client_name   Client display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 */
	public function matches_client( string $client_name, array $redirect_uris ): bool {
		$normalized_name = strtolower( $client_name );

		if (
			str_contains( $normalized_name, 'grok' )
			|| 1 === preg_match( '/(?:^|[^a-z0-9])xai(?:$|[^a-z0-9])/', $normalized_name )
		) {
			return true;
		}

		foreach ( $redirect_uris as $redirect_uri ) {
			$host = wp_parse_url( $redirect_uri, PHP_URL_HOST );
			if ( ! is_string( $host ) ) {
				continue;
			}

			$host = strtolower( rtrim( $host, '.' ) );
			if ( 'x.ai' === $host || str_ends_with( $host, '.x.ai' ) ) {
				return true;
			}
		}

		return false;
	}
}

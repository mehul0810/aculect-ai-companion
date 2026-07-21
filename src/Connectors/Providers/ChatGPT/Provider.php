<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers\ChatGPT;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderMatcherInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderWizardInterface;

/**
 * Provides ChatGPT-specific app setup guidance.
 */
final class Provider implements ProviderInterface, ProviderMatcherInterface, ProviderWizardInterface {

	/**
	 * Return the provider slug.
	 */
	public function id(): string {
		return 'chatgpt';
	}

	/**
	 * Return the provider label.
	 */
	public function label(): string {
		return 'ChatGPT';
	}

	/**
	 * Return the provider description.
	 */
	public function description(): string {
		return 'Use ChatGPT to create, update, and manage WordPress content through Aculect AI Companion.';
	}

	/**
	 * Return the ChatGPT app setup URL.
	 */
	public function primary_action_url(): string {
		return 'https://chatgpt.com/#settings/Apps';
	}

	/**
	 * Return the primary action label.
	 */
	public function primary_action_label(): string {
		return 'Open ChatGPT Apps';
	}

	/**
	 * Return ChatGPT setup sections for the admin UI.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 * @return array<int, array<string, mixed>>
	 */
	public function setup_sections( string $mcp_url ): array {
		unset( $mcp_url );

		return array(
			array(
				'title'       => 'ChatGPT custom app',
				'description' => 'Use this for a private ChatGPT app. Your connection URL must be publicly reachable over HTTPS because ChatGPT connects from outside your WordPress site.',
				'steps'       => array(
					'In ChatGPT, open Settings > Apps. If your workspace supports custom MCP apps, enable Developer Mode under Apps > Advanced settings.',
					'Create a custom app and paste your connection URL when ChatGPT asks for the MCP server URL.',
					'ChatGPT should read the MCP tool metadata and ask you to authenticate when authorization is required.',
					'After WordPress approval, enable Aculect AI Companion for the conversation where you want to use it.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
			),
			array(
				'title'       => 'OpenAI API developers',
				'description' => 'Use this only when you are building your own application with the OpenAI API.',
				'steps'       => array(
					'Use your connection URL from above as the remote MCP server_url in your Responses API mcp tool configuration.',
					'If your application calls protected Aculect tools directly, complete OAuth first and pass the resulting access token as the MCP authorization value.',
					'Keep destructive WordPress actions behind explicit user approval in your application.',
				),
				'actionLabel' => 'Open OpenAI API Docs',
				'actionUrl'   => 'https://developers.openai.com/api/docs/guides/tools-connectors-mcp',
			),
		);
	}

	/**
	 * Return ChatGPT setup wizard metadata for the primary Connect flow.
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
					'title'              => 'Open ChatGPT',
					'subtitle'           => 'Open ChatGPT and enable Developer Mode if needed.',
					'description'        => 'Developer Mode shows the Create button for private apps in ChatGPT.',
					'instructions'       => array(
						array(
							'title'       => 'Open ChatGPT',
							'description' => 'Go to chatgpt.com in a new tab and sign in to your account.',
						),
						array(
							'title'       => 'Enable Developer Mode',
							'description' => 'Go to Settings > Apps > Advanced settings and toggle Developer Mode if your organization allows it.',
						),
					),
					'helpTitle'          => 'Where is Developer Mode?',
					'helpText'           => 'It is under Settings > Apps > Advanced settings.',
					'primaryActionLabel' => 'Open ChatGPT',
					'primaryActionUrl'   => 'https://chatgpt.com/',
					'secondaryLabel'     => 'View documentation',
					'secondaryUrl'       => $this->primary_action_url(),
				),
				array(
					'id'           => 'add',
					'title'        => 'Add app',
					'subtitle'     => 'Add Aculect AI Companion as a custom app.',
					'description'  => 'Use the connection URL below to add Aculect AI Companion.',
					'instructions' => array(
						array(
							'title'       => 'Open Apps',
							'description' => 'In ChatGPT, open Settings > Apps and create a custom app.',
						),
						array(
							'title'       => 'Paste the connection URL',
							'description' => 'Enter an app name and description, then paste the Aculect connection URL below as the MCP server URL.',
						),
						array(
							'title'       => 'Continue to authorization',
							'description' => 'Click Create. ChatGPT should read the tool list and send you to WordPress when authentication is needed.',
						),
					),
					'copyFields'   => array(
						array(
							'label'       => 'Your Aculect connection URL',
							'description' => 'Copy this URL and paste it into ChatGPT when prompted.',
							'value'       => $mcp_url,
						),
					),
				),
				array(
					'id'                 => 'approve',
					'title'              => 'Review and approve',
					'subtitle'           => 'Authorize the connection securely in WordPress.',
					'description'        => 'ChatGPT will redirect you to WordPress to review and approve the connection request.',
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
					'description'  => 'Return to ChatGPT, add Aculect AI Companion to a conversation from the + menu, and ask it to work with your WordPress site.',
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
	 * Return whether DCR metadata belongs to ChatGPT or OpenAI.
	 *
	 * @param string   $client_name   Client display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 */
	public function matches_client( string $client_name, array $redirect_uris ): bool {
		$haystack = strtolower( $client_name . ' ' . implode( ' ', $redirect_uris ) );

		return str_contains( $haystack, 'chatgpt.com' )
			|| str_contains( $haystack, 'chatgpt' )
			|| str_contains( $haystack, 'openai' );
	}
}

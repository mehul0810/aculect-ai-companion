<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers\ChatGPT;

use Aculect\AICompanion\Connectors\Providers\ProviderInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderMatcherInterface;
use Aculect\AICompanion\Connectors\Providers\ProviderWizardInterface;

/**
 * Provides ChatGPT-specific connector setup guidance.
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
	 * Return the ChatGPT connector setup URL.
	 */
	public function primary_action_url(): string {
		return 'https://chatgpt.com/#settings/Connectors';
	}

	/**
	 * Return the primary action label.
	 */
	public function primary_action_label(): string {
		return 'Open ChatGPT Connectors';
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
				'title'       => 'ChatGPT app / Developer Mode',
				'description' => 'Use this for the ChatGPT web app connector flow. Your connection URL must be publicly reachable over HTTPS because ChatGPT connects from outside your WordPress site.',
				'steps'       => array(
					'In ChatGPT, enable Developer mode under Settings > Apps & Connectors > Advanced settings.',
					'Open Settings > Connectors and click Create to add a connector.',
					'Paste your connection URL from above, then name the connector Aculect AI Companion.',
					'Create the connector and approve the connection on the WordPress screen that appears.',
					'Open a new chat, choose Developer mode from the + menu, and enable the Aculect AI Companion connector for the conversation.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
			),
			array(
				'title'       => 'OpenAI API developers',
				'description' => 'Use this only when you are building your own application with the OpenAI API.',
				'steps'       => array(
					'Use your connection URL from above as the remote server URL in your application.',
					'Follow OpenAI developer documentation for the authorization details your application must handle.',
					'Keep destructive site actions behind explicit user approval in your application.',
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
			'badge'         => 'Most popular',
			'steps'         => array(
				array(
					'id'                 => 'open',
					'title'              => 'Open ChatGPT',
					'subtitle'           => 'Open ChatGPT and enable Developer Mode if required.',
					'description'        => 'You will need Developer Mode to add custom connectors.',
					'instructions'       => array(
						array(
							'title'       => 'Open ChatGPT',
							'description' => 'Go to chatgpt.com in a new tab and sign in to your account.',
						),
						array(
							'title'       => 'Enable Developer Mode',
							'description' => 'Open your profile menu and turn on Developer Mode when your plan requires it.',
						),
					),
					'helpTitle'          => 'Where is Developer Mode?',
					'helpText'           => 'It is in the profile menu at the bottom of the left sidebar.',
					'primaryActionLabel' => 'Open ChatGPT',
					'primaryActionUrl'   => 'https://chatgpt.com/',
					'secondaryLabel'     => 'View documentation',
					'secondaryUrl'       => $this->primary_action_url(),
				),
				array(
					'id'           => 'add',
					'title'        => 'Add Connector',
					'subtitle'     => 'Add Aculect AI Companion as a new connector.',
					'description'  => 'Use the connection URL below to add Aculect AI Companion.',
					'instructions' => array(
						array(
							'title'       => 'Open Connectors',
							'description' => 'In ChatGPT, open Settings, go to Connectors, and click Add custom connector.',
						),
						array(
							'title'       => 'Paste the connection URL',
							'description' => 'Paste the Aculect connection URL below when ChatGPT asks for the connector URL.',
						),
						array(
							'title'       => 'Continue to authorization',
							'description' => 'Keep this WordPress window open. You will return here after adding the connector.',
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
					'description'  => 'Return to ChatGPT and ask it to work with your WordPress site.',
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

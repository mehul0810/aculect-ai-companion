<?php

declare(strict_types=1);

namespace Quark\Connectors\Providers\ChatGPT;

use Quark\Connectors\Providers\ProviderInterface;

/**
 * Provides ChatGPT-specific connector setup guidance.
 */
final class Provider implements ProviderInterface {

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
		return 'Use ChatGPT to create, update, and manage WordPress content through Quark.';
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
					'Paste your connection URL from above, then name the connector Quark.',
					'Create the connector and approve the connection on the WordPress screen that appears.',
					'Open a new chat, choose Developer mode from the + menu, and enable the Quark connector for the conversation.',
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
}

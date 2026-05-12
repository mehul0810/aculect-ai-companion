<?php

declare(strict_types=1);

namespace Quark\Connectors\Providers\ChatGPT;

use Quark\Connectors\Providers\ProviderInterface;

final class Provider implements ProviderInterface {

	public function id(): string {
		return 'chatgpt';
	}

	public function label(): string {
		return 'ChatGPT';
	}

	public function description(): string {
		return 'Connect ChatGPT to WordPress through Quark MCP with OAuth discovery and Dynamic Client Registration.';
	}

	public function primary_action_url(): string {
		return 'https://chatgpt.com/#settings/Connectors';
	}

	public function primary_action_label(): string {
		return 'Open ChatGPT Connectors';
	}

	public function setup_sections( string $mcp_url ): array {
		unset( $mcp_url );

		return array(
			array(
				'title'       => 'ChatGPT app / Developer Mode',
				'description' => 'Use this for the ChatGPT web app connector flow. Quark supports the HTTPS MCP endpoint with OAuth discovery and Dynamic Client Registration.',
				'steps'       => array(
					'In ChatGPT, enable Developer mode under Settings > Apps & Connectors > Advanced settings.',
					'Open Settings > Connectors and click Create to add a connector.',
					'Paste the MCP endpoint URL shown above, then name the connector Quark.',
					'Create the connector and approve the WordPress OAuth consent screen when ChatGPT starts authentication.',
					'Open a new chat, choose Developer mode from the + menu, and enable the Quark connector for the conversation.',
				),
				'actionLabel' => $this->primary_action_label(),
				'actionUrl'   => $this->primary_action_url(),
			),
			array(
				'title'       => 'OpenAI API / Responses API',
				'description' => 'Use this when your own application calls the OpenAI API with a remote MCP server. Your application is responsible for obtaining and passing the OAuth access token.',
				'steps'       => array(
					'Use the MCP endpoint URL shown above as the remote MCP server_url.',
					'If the request is authenticated, include a Quark OAuth access token in the authorization field.',
					'Set a stable server_label such as quark and configure tool approval according to your application risk model.',
				),
				'actionLabel' => 'Open OpenAI MCP API Docs',
				'actionUrl'   => 'https://developers.openai.com/api/docs/guides/tools-connectors-mcp',
			),
		);
	}
}

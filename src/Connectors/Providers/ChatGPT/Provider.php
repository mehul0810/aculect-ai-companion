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
		return 'https://chatgpt.com/apps#settings/Connectors';
	}

	public function setup_steps( string $mcp_url ): array {
		unset( $mcp_url );

		return array(
			'Open ChatGPT connector settings.',
			'Create a new custom MCP connector.',
			'Paste the Quark MCP endpoint URL only.',
			'When ChatGPT opens WordPress, log in and approve the consent screen.',
		);
	}

	public function copy_fields( string $mcp_url ): array {
		return array(
			array(
				'label' => 'MCP Endpoint URL',
				'value' => $mcp_url,
			),
		);
	}
}

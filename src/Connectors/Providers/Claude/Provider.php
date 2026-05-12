<?php

declare(strict_types=1);

namespace Quark\Connectors\Providers\Claude;

use Quark\Connectors\Providers\ProviderInterface;

final class Provider implements ProviderInterface {

	public function id(): string {
		return 'claude';
	}

	public function label(): string {
		return 'Claude';
	}

	public function description(): string {
		return 'Connect Claude Code to WordPress through Quark MCP. Claude Code will open WordPress for OAuth consent when authentication starts.';
	}

	public function primary_action_url(): string {
		return 'https://code.claude.com/docs/en/mcp';
	}

	public function setup_steps( string $mcp_url ): array {
		unset( $mcp_url );

		return array(
			'Copy the Claude Code command from this card.',
			'Run it in your terminal where Claude Code is available.',
			'In Claude Code, run /mcp and choose Quark to authenticate.',
			'Approve the WordPress consent screen and return to Claude Code.',
		);
	}

	public function copy_fields( string $mcp_url ): array {
		return array(
			array(
				'label' => 'Claude Code Command',
				'value' => 'claude mcp add --transport http quark ' . $mcp_url,
			),
			array(
				'label' => 'MCP Endpoint URL',
				'value' => $mcp_url,
			),
		);
	}
}

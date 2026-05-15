<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers;

/**
 * Defines setup metadata shown for an MCP client provider.
 */
interface ProviderInterface {

	/**
	 * Return the provider slug.
	 */
	public function id(): string;

	/**
	 * Return the provider label.
	 */
	public function label(): string;

	/**
	 * Return a short setup description.
	 */
	public function description(): string;

	/**
	 * Return the primary setup URL.
	 */
	public function primary_action_url(): string;

	/**
	 * Return the primary setup button label.
	 */
	public function primary_action_label(): string;

	/**
	 * Return setup instructions and optional copy fields.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 * @return array<int, array<string, mixed>>
	 */
	public function setup_sections( string $mcp_url ): array;
}

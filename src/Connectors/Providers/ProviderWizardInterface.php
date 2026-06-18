<?php
/**
 * Optional setup wizard metadata for known MCP client providers.
 *
 * @package Aculect\AICompanion\Connectors\Providers
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers;

/**
 * Allows built-in providers to expose a guided setup wizard without changing
 * the public provider registration contract.
 */
interface ProviderWizardInterface {

	/**
	 * Return assistant-specific wizard metadata for the Connect tab.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 * @return array<string, mixed>
	 */
	public function setup_wizard( string $mcp_url ): array;
}

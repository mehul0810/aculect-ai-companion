<?php
/**
 * Isolated HMAC fixture; no real keys or WordPress filesystem writes.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Supply a deterministic synthetic salt.
 *
 * @param string $scheme Native salt scheme.
 */
function wp_salt( string $scheme ): string {
	return 'synthetic-media-state-' . $scheme;
}

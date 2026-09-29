<?php
/**
 * Stable site-salt test stub for stateless MCP Apps session IDs.
 *
 * @package Aculect\AICompanion\Tests\Support
 */

declare(strict_types=1);
if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Return the deterministic test-only WordPress authentication salt.
	 *
	 * @param string $scheme Salt scheme.
	 */
	function wp_salt( string $scheme = 'auth' ): string {
		return (string) ( $GLOBALS['aculect_ai_companion_test_salt'] ?? 'mcp-app-session-test-salt-' . $scheme );
	}
}

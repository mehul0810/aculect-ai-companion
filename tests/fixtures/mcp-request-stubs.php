<?php
/**
 * WordPress request-path stubs for MCP controller tests.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

if ( ! function_exists( __NAMESPACE__ . '\\wp_set_current_user' ) ) {
	/**
	 * Set the deterministic current user for request-path tests.
	 *
	 * @param int $user_id User ID.
	 */
	function wp_set_current_user( int $user_id ): object {
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = $user_id;

		return $GLOBALS['aculect_ai_companion_test_users'][ $user_id ] ?? (object) array( 'ID' => $user_id );
	}
}

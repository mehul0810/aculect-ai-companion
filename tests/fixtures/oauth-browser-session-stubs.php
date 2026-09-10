<?php
/**
 * Observable cookie-validation stand-in for browser redirect unit tests.
 *
 * @package Aculect_AI_Companion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

/**
 * Record the cookie scheme without changing the current test user.
 *
 * @param string $cookie Cookie value, or empty to use the browser cookie.
 * @param string $scheme WordPress cookie scheme.
 * @return mixed
 */
function wp_validate_auth_cookie( string $cookie = '', string $scheme = '' ): mixed {
	$GLOBALS['aculect_browser_cookie_calls'][] = array( $cookie, $scheme );

	return $GLOBALS['aculect_browser_cookie_result'] ?? false;
}

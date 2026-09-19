<?php
/**
 * Browser-session hints for the OAuth consent redirect only.
 *
 * @package Aculect_AI_Companion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

/**
 * Selects the login or consent destination without authenticating a REST request.
 */
final class AuthorizationBrowserSession {

	/**
	 * Determine whether the browser can proceed to the admin consent screen.
	 *
	 * REST cookie authentication clears the current user without a REST nonce.
	 * OAuth browser navigation cannot supply that nonce. Validate the logged-in
	 * cookie only as a redirect hint; never restore the REST user or bypass the
	 * admin screen's authentication, permissions, or consent nonce checks.
	 *
	 * @param bool $rest_entry Whether this is the public REST authorization entry.
	 * @return bool
	 */
	public static function is_logged_in( bool $rest_entry ): bool {
		if ( is_user_logged_in() ) {
			return true;
		}

		if ( ! $rest_entry ) {
			return false;
		}

		$user_id = wp_validate_auth_cookie( '', 'logged_in' );

		return is_int( $user_id ) && $user_id > 0;
	}
}

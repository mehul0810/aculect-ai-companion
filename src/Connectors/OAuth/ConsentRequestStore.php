<?php
/**
 * Bounded transient storage for OAuth consent requests.
 *
 * @package Aculect\AICompanion\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use WP_Error;

/**
 * Admits public authorization attempts before allocating consent state.
 */
final class ConsentRequestStore {

	public const TTL = 600;

	/**
	 * Store already allowlisted OAuth parameters under an opaque request token.
	 *
	 * @param array<string, string> $params Allowlisted authorization parameters.
	 * @return string|WP_Error
	 */
	public function store( array $params ): string|WP_Error {
		$admission = ( new RateLimiter() )->check( 'oauth_authorize', 30, self::TTL );
		if ( $admission instanceof WP_Error ) {
			return $admission;
		}

		$token = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::key( $token ),
			array(
				'params'     => $params,
				'user_id'    => get_current_user_id(),
				'expires_at' => time() + self::TTL,
			),
			self::TTL
		);

		return $token;
	}

	/**
	 * Return the transient key for one consent request.
	 *
	 * @param string $request_token Consent request token.
	 */
	public static function key( string $request_token ): string {
		return 'aculect_ai_companion_oauth_consent_' . $request_token;
	}
}

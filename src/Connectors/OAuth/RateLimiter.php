<?php
/**
 * Fixed-window rate limiting for unauthenticated OAuth endpoints.
 *
 * @package Aculect\AICompanion\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use WP_Error;

/**
 * Throttles unauthenticated OAuth requests per client fingerprint.
 *
 * Uses transient-backed fixed windows so limits work on default WordPress
 * installs without external infrastructure. Limits are filterable for hosts
 * behind shared NATs or aggressive AI-client retry loops.
 */
final class RateLimiter {

	public const ERROR_CODE = 'aculect_ai_companion_rate_limited';

	/**
	 * Check and consume one request against a fixed-window limit.
	 *
	 * @param string $action Limiter bucket, e.g. 'oauth_register'.
	 * @param int    $limit  Maximum requests per window.
	 * @param int    $window Window length in seconds.
	 * @return true|WP_Error
	 */
	public function check( string $action, int $limit, int $window ): bool|WP_Error {
		$limit  = max( 1, (int) apply_filters( 'aculect_ai_companion_rate_limit', $limit, $action ) );
		$window = max( 10, (int) apply_filters( 'aculect_ai_companion_rate_limit_window', $window, $action ) );

		if ( (bool) apply_filters( 'aculect_ai_companion_rate_limit_disabled', false, $action ) ) {
			return true;
		}

		$key    = $this->bucket_key( $action );
		$bucket = get_transient( $key );
		$now    = time();

		if ( ! is_array( $bucket ) || $now >= (int) ( $bucket['reset'] ?? 0 ) ) {
			$bucket = array(
				'count' => 0,
				'reset' => $now + $window,
			);
		}

		if ( (int) $bucket['count'] >= $limit ) {
			return new WP_Error(
				self::ERROR_CODE,
				'Too many requests. Retry later.',
				array(
					'status'      => 429,
					'retry_after' => max( 1, (int) $bucket['reset'] - $now ),
				)
			);
		}

		++$bucket['count'];
		set_transient( $key, $bucket, max( 1, (int) $bucket['reset'] - $now ) );

		return true;
	}

	/**
	 * Add a Retry-After header to rate-limited REST responses.
	 *
	 * Registered once from route registration; applies only to this plugin's
	 * limiter error code so other 429 responses are untouched.
	 */
	public static function register_retry_after_header(): void {
		if ( false !== has_filter( 'rest_post_dispatch', array( self::class, 'filter_retry_after_header' ) ) ) {
			return;
		}

		add_filter( 'rest_post_dispatch', array( self::class, 'filter_retry_after_header' ), 10, 1 );
	}

	/**
	 * Attach Retry-After to limiter-generated 429 responses.
	 *
	 * @param mixed $response Dispatch result.
	 * @return mixed
	 */
	public static function filter_retry_after_header( mixed $response ): mixed {
		if ( ! $response instanceof \WP_REST_Response || 429 !== $response->get_status() ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || self::ERROR_CODE !== ( $data['code'] ?? '' ) ) {
			return $response;
		}

		$retry_after = absint( $data['data']['retry_after'] ?? 60 );
		$response->header( 'Retry-After', (string) max( 1, $retry_after ) );

		return $response;
	}

	/**
	 * Build a privacy-safe transient key for one action and client.
	 *
	 * @param string $action Limiter bucket.
	 */
	private function bucket_key( string $action ): string {
		return 'aculect_ai_companion_rl_' . sanitize_key( $action ) . '_' . substr( hash( 'sha256', $this->client_fingerprint() ), 0, 32 );
	}

	/**
	 * Return a stable fingerprint for the requesting client.
	 *
	 * Uses REMOTE_ADDR only: proxy headers are spoofable and must not widen
	 * or bypass the limit. Hosts that terminate TLS upstream can use the
	 * rate-limit filters to adjust budgets instead.
	 */
	private function client_fingerprint(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders -- REMOTE_ADDR is set by the server, not the client.
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' === $address ? 'unknown' : $address;
	}
}

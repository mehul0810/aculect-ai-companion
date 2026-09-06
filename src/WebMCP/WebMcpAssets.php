<?php

declare(strict_types=1);

namespace Aculect\AICompanion\WebMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the progressive-enhancement WebMCP bridge on public site pages.
 */
final class WebMcpAssets {

	private const HANDLE = 'aculect-ai-companion-webmcp';

	/**
	 * Register frontend hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue a small standalone script only where public page context is safe.
	 */
	public function enqueue(): void {
		if ( ! $this->should_enqueue() ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/js/webmcp.js',
			array(),
			ACULECT_AI_COMPANION_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Keep the first WebMCP slice public, read-only, and explicitly disableable.
	 */
	private function should_enqueue(): bool {
		$singular_status = null;
		if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'get_post_status' ) ) {
			$singular_status = (string) get_post_status();
		}

		$allowed = ( new WebMcpRequestPolicy() )->allows(
			array(
				'admin'           => function_exists( 'is_admin' ) && is_admin(),
				'json'            => function_exists( 'wp_is_json_request' ) && wp_is_json_request(),
				'feed'            => function_exists( 'is_feed' ) && is_feed(),
				'password'        => function_exists( 'post_password_required' ) && post_password_required(),
				'logged_in'       => function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
				'preview'         => function_exists( 'is_preview' ) && is_preview(),
				'singular_status' => $singular_status,
			)
		);
		if ( ! $allowed ) {
			return false;
		}

		/**
		 * Filter whether public, read-only WebMCP page context is enabled.
		 *
		 * @param bool $enabled Whether to enqueue the progressive enhancement.
		 */
		return (bool) apply_filters( 'aculect_ai_companion_webmcp_enabled', true );
	}
}

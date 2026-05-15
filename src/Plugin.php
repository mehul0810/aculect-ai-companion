<?php

declare(strict_types=1);

namespace Aculect\AICompanion;

use Aculect\AICompanion\Admin\SettingsPage;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\OAuth\AuthorizationController;
use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationController;
use Aculect\AICompanion\Connectors\OAuth\Database\Installer as OAuthInstaller;
use Aculect\AICompanion\Connectors\OAuth\DiscoveryController;
use Aculect\AICompanion\Connectors\OAuth\TokenController;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin loader and hook registry.
 */
final class Plugin {

	private const REWRITE_VERSION        = '2026.05.11.1';
	private const OPTION_REWRITE_VERSION = 'aculect_ai_companion_rewrite_version';

	private static ?self $instance = null;

	/**
	 * Return the singleton plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Run activation tasks that require immediate persistence.
	 */
	public static function activate(): void {
		OAuthInstaller::activate();
		self::add_rewrite_rules();
		flush_rewrite_rules();
		update_option( self::OPTION_REWRITE_VERSION, self::REWRITE_VERSION, false );
	}

	/**
	 * Flush rewrite rules when the plugin is deactivated.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Register runtime hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'redirect_canonical', array( $this, 'filter_canonical_redirect' ), 10, 2 );
		add_action( 'parse_request', array( $this, 'maybe_redirect_root_authorize' ), 0, 0 );
		add_action( 'parse_request', array( $this, 'render_well_known_metadata' ), 0, 0 );
		add_action( 'template_redirect', array( $this, 'render_well_known_metadata' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_admin' ) );
		add_action( 'admin_post_aculect_ai_companion_save_abilities', array( $this, 'handle_save_abilities' ) );
		add_action( 'admin_post_aculect_ai_companion_revoke_session', array( $this, 'handle_revoke_session' ) );
		add_action( 'admin_post_aculect_ai_companion_revoke_all_sessions', array( $this, 'handle_revoke_all_sessions' ) );
		add_action( 'admin_post_aculect_ai_companion_oauth_consent', array( $this, 'handle_oauth_consent' ) );
		add_action( 'admin_post_nopriv_aculect_ai_companion_oauth_consent', array( $this, 'handle_oauth_consent' ) );

		OAuthInstaller::install();
	}

	/**
	 * Register REST routes for discovery, OAuth, and MCP.
	 */
	public function register_routes(): void {
		( new DiscoveryController() )->register_rest_routes();
		( new ClientRegistrationController() )->register_routes();
		( new AuthorizationController() )->register_routes();
		( new TokenController() )->register_routes();
		( new McpController() )->register_routes();
	}

	/**
	 * Register public rewrite rules used by OAuth discovery endpoints.
	 */
	public function register_rewrite_rules(): void {
		self::add_rewrite_rules();
	}

	/**
	 * Flush rewrite rules when the internal rewrite version changes.
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( self::REWRITE_VERSION === (string) get_option( self::OPTION_REWRITE_VERSION, '' ) ) {
			return;
		}

		self::add_rewrite_rules();
		flush_rewrite_rules();
		update_option( self::OPTION_REWRITE_VERSION, self::REWRITE_VERSION, false );
	}

	/**
	 * Add query variables used by root-level OAuth routes.
	 *
	 * @param string[] $vars Existing query variables.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'aculect_ai_companion_well_known';
		$vars[] = 'aculect_ai_companion_well_known_resource_path';
		$vars[] = 'aculect_ai_companion_well_known_issuer_path';
		$vars[] = 'aculect_ai_companion_oauth_authorize';

		return $vars;
	}

	/**
	 * Redirect the root /oauth/authorize route to the REST authorization handler.
	 */
	public function maybe_redirect_root_authorize(): void {
		if ( ! get_query_var( 'aculect_ai_companion_oauth_authorize' ) ) {
			return;
		}

		$params = array();
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_scalar( $value ) ) {
				$params[ sanitize_key( (string) $key ) ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}

		wp_safe_redirect( add_query_arg( $params, Helpers::authorization_endpoint() ), 302, 'Aculect AI Companion OAuth' );
		exit;
	}

	/**
	 * Render well-known metadata when WordPress matched a rewrite rule.
	 */
	public function render_well_known_metadata(): void {
		( new DiscoveryController() )->render_well_known_metadata();
	}

	/**
	 * Disable canonical redirects for OAuth discovery and authorize routes.
	 *
	 * @param mixed $redirect_url  Candidate canonical redirect URL.
	 * @param mixed $requested_url Requested URL.
	 * @return mixed
	 */
	public function filter_canonical_redirect( mixed $redirect_url, mixed $requested_url ): mixed {
		if ( ! is_string( $requested_url ) ) {
			return $redirect_url;
		}

		$path = (string) wp_parse_url( $requested_url, PHP_URL_PATH );
		if ( str_starts_with( $path, '/.well-known/oauth-' ) || '/oauth/authorize' === untrailingslashit( $path ) ) {
			return false;
		}

		return $redirect_url;
	}

	/**
	 * Register the Settings > Aculect AI Companion admin page.
	 */
	public function register_admin(): void {
		( new SettingsPage() )->register();
	}

	/**
	 * Proxy abilities-save form handling to the settings controller.
	 */
	public function handle_save_abilities(): void {
		( new SettingsPage() )->handle_save_abilities();
	}

	/**
	 * Proxy single-session revocation to the settings controller.
	 */
	public function handle_revoke_session(): void {
		( new SettingsPage() )->handle_revoke_session();
	}

	/**
	 * Proxy all-session revocation to the settings controller.
	 */
	public function handle_revoke_all_sessions(): void {
		( new SettingsPage() )->handle_revoke_all_sessions();
	}

	/**
	 * Proxy OAuth consent submission to the authorization controller.
	 */
	public function handle_oauth_consent(): void {
		( new AuthorizationController() )->handle_admin_consent();
	}

	/**
	 * Register root-level rewrite rules that cannot be expressed as REST routes.
	 */
	private static function add_rewrite_rules(): void {
		( new DiscoveryController() )->add_rewrite_rules();
		add_rewrite_rule( '^oauth/authorize/?$', 'index.php?aculect_ai_companion_oauth_authorize=1', 'top' );
	}
}

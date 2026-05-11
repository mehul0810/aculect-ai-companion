<?php

declare(strict_types=1);

namespace Quark;

use Quark\Admin\SettingsPage;
use Quark\Connectors\Helpers;
use Quark\Connectors\MCP\McpController;
use Quark\Connectors\OAuth\AuthorizationController;
use Quark\Connectors\OAuth\ClientRegistrationController;
use Quark\Connectors\OAuth\Database\Installer as OAuthInstaller;
use Quark\Connectors\OAuth\DiscoveryController;
use Quark\Connectors\OAuth\TokenController;

final class Plugin {

	private const REWRITE_VERSION        = '2026.05.11.1';
	private const OPTION_REWRITE_VERSION = 'quark_rewrite_version';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate(): void {
		OAuthInstaller::activate();
		self::add_rewrite_rules();
		flush_rewrite_rules();
		update_option( self::OPTION_REWRITE_VERSION, self::REWRITE_VERSION, false );
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

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
		add_action( 'admin_post_quark_save_advanced', array( $this, 'handle_save_advanced' ) );
		add_action( 'admin_post_quark_save_abilities', array( $this, 'handle_save_abilities' ) );
		add_action( 'admin_post_quark_revoke_session', array( $this, 'handle_revoke_session' ) );
		add_action( 'admin_post_quark_revoke_all_sessions', array( $this, 'handle_revoke_all_sessions' ) );
		add_action( 'admin_post_quark_oauth_consent', array( $this, 'handle_oauth_consent' ) );
		add_action( 'admin_post_nopriv_quark_oauth_consent', array( $this, 'handle_oauth_consent' ) );

		OAuthInstaller::install();
	}

	public function register_routes(): void {
		( new DiscoveryController() )->register_rest_routes();
		( new ClientRegistrationController() )->register_routes();
		( new AuthorizationController() )->register_routes();
		( new TokenController() )->register_routes();
		( new McpController() )->register_routes();
	}

	public function register_rewrite_rules(): void {
		self::add_rewrite_rules();
	}

	public function maybe_flush_rewrite_rules(): void {
		if ( self::REWRITE_VERSION === (string) get_option( self::OPTION_REWRITE_VERSION, '' ) ) {
			return;
		}

		self::add_rewrite_rules();
		flush_rewrite_rules();
		update_option( self::OPTION_REWRITE_VERSION, self::REWRITE_VERSION, false );
	}

	public function register_query_vars( array $vars ): array {
		$vars[] = 'quark_well_known';
		$vars[] = 'quark_well_known_resource_path';
		$vars[] = 'quark_well_known_issuer_path';
		$vars[] = 'quark_oauth_authorize';

		return $vars;
	}

	public function maybe_redirect_root_authorize(): void {
		if ( ! get_query_var( 'quark_oauth_authorize' ) ) {
			return;
		}

		$params = array();
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_scalar( $value ) ) {
				$params[ sanitize_key( (string) $key ) ] = sanitize_text_field( wp_unslash( (string) $value ) );
			}
		}

		wp_safe_redirect( add_query_arg( $params, Helpers::authorization_endpoint() ), 302, 'Quark OAuth' );
		exit;
	}

	public function render_well_known_metadata(): void {
		( new DiscoveryController() )->render_well_known_metadata();
	}

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

	public function register_admin(): void {
		( new SettingsPage() )->register();
	}

	public function handle_save_advanced(): void {
		( new SettingsPage() )->handle_save_advanced();
	}

	public function handle_save_abilities(): void {
		( new SettingsPage() )->handle_save_abilities();
	}

	public function handle_revoke_session(): void {
		( new SettingsPage() )->handle_revoke_session();
	}

	public function handle_revoke_all_sessions(): void {
		( new SettingsPage() )->handle_revoke_all_sessions();
	}

	public function handle_oauth_consent(): void {
		( new AuthorizationController() )->handle_admin_consent();
	}

	private static function add_rewrite_rules(): void {
		( new DiscoveryController() )->add_rewrite_rules();
		add_rewrite_rule( '^oauth/authorize/?$', 'index.php?quark_oauth_authorize=1', 'top' );
	}
}

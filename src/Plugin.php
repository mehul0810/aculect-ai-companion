<?php

declare(strict_types=1);

namespace Aculect\AICompanion;

use Aculect\AICompanion\Activity\Database\Installer as ActivityInstaller;
use Aculect\AICompanion\Admin\SettingsPage;
use Aculect\AICompanion\Admin\UserAccessControls;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\RoleConnectionEntryPoint;
use Aculect\AICompanion\Connectors\OAuth\AuthorizationController;
use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationController;
use Aculect\AICompanion\Connectors\OAuth\Database\Installer as OAuthInstaller;
use Aculect\AICompanion\Connectors\OAuth\DiscoveryController;
use Aculect\AICompanion\Connectors\OAuth\StorageMaintenance as OAuthStorageMaintenance;
use Aculect\AICompanion\Connectors\OAuth\TokenController;
use Aculect\AICompanion\Diagnostics\Database\Installer as DiagnosticsInstaller;
use Aculect\AICompanion\Intelligence\ContentIndexer;
use Aculect\AICompanion\Intelligence\Database\Installer as IntelligenceInstaller;

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
		DiagnosticsInstaller::activate();
		ActivityInstaller::activate();
		IntelligenceInstaller::activate();
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
		add_action( 'init', array( $this, 'register_role_connection_entry_points' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'redirect_canonical', array( $this, 'filter_canonical_redirect' ), 10, 2 );
		add_action( 'parse_request', array( $this, 'maybe_redirect_root_authorize' ), 0, 1 );
		add_action( 'parse_request', array( $this, 'render_well_known_metadata' ), 0, 0 );
		add_action( 'template_redirect', array( $this, 'render_well_known_metadata' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_admin' ) );
		add_action( 'admin_init', array( $this, 'register_user_access_controls' ) );
		add_action( 'admin_post_aculect_ai_companion_save_abilities', array( $this, 'handle_save_abilities' ) );
		add_action( 'admin_post_aculect_ai_companion_save_role_abilities', array( $this, 'handle_save_role_abilities' ) );
		add_action( 'admin_post_aculect_ai_companion_save_advanced', array( $this, 'handle_save_advanced' ) );
		add_action( 'admin_post_aculect_ai_companion_export_settings', array( $this, 'handle_export_settings' ) );
		add_action( 'admin_post_aculect_ai_companion_export_mcp_tool_manifest', array( $this, 'handle_export_mcp_tool_manifest' ) );
		add_action( 'admin_post_aculect_ai_companion_import_settings', array( $this, 'handle_import_settings' ) );
		add_action( 'admin_post_aculect_ai_companion_reset_settings', array( $this, 'handle_reset_settings' ) );
		add_action( 'admin_post_aculect_ai_companion_save_brand', array( $this, 'handle_save_brand' ) );
		add_action( 'admin_post_aculect_ai_companion_review_learning_suggestion', array( $this, 'handle_review_learning_suggestion' ) );
		add_action( 'admin_post_aculect_ai_companion_run_connection_diagnostics', array( $this, 'handle_run_connection_diagnostics' ) );
		add_action( 'admin_post_aculect_ai_companion_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'admin_post_aculect_ai_companion_set_lockdown', array( $this, 'handle_set_lockdown' ) );
		add_action( 'admin_post_aculect_ai_companion_set_session_access_level', array( $this, 'handle_set_session_access_level' ) );
		add_action( 'admin_post_aculect_ai_companion_set_session_write_permission', array( $this, 'handle_set_session_write_permission' ) );
		add_action( 'admin_post_aculect_ai_companion_revoke_session', array( $this, 'handle_revoke_session' ) );
		add_action( 'admin_post_aculect_ai_companion_revoke_all_sessions', array( $this, 'handle_revoke_all_sessions' ) );
		add_action( 'admin_post_aculect_ai_companion_oauth_consent', array( $this, 'handle_oauth_consent' ) );
		add_action( 'admin_post_nopriv_aculect_ai_companion_oauth_consent', array( $this, 'handle_oauth_consent' ) );
		add_action( 'save_post', array( $this, 'handle_content_index_save' ), 50, 3 );
		add_action( 'before_delete_post', array( $this, 'handle_content_index_delete' ), 10, 1 );
		add_action( 'trashed_post', array( $this, 'handle_content_index_delete' ), 10, 1 );
		add_action( 'set_object_terms', array( $this, 'handle_content_index_terms_changed' ), 10, 6 );
		add_action( 'added_post_meta', array( $this, 'handle_content_index_meta_changed' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'handle_content_index_meta_changed' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'handle_content_index_meta_changed' ), 10, 4 );
		add_action( 'aculect_ai_companion_content_index_refresh_job', array( $this, 'handle_content_index_refresh_job' ), 10, 1 );

		OAuthInstaller::install();
		DiagnosticsInstaller::install();
		ActivityInstaller::install();
		IntelligenceInstaller::install();
		OAuthStorageMaintenance::maybe_prune();
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
		( new SettingsPage() )->register_rest_routes();
	}

	/**
	 * Register optional frontend connection entry points.
	 */
	public function register_role_connection_entry_points(): void {
		( new RoleConnectionEntryPoint() )->register();
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
	 * Handle the root /oauth/authorize route while preserving logged-in cookies.
	 *
	 * @param object $wp Current WordPress request object.
	 */
	public function maybe_redirect_root_authorize( object $wp ): void {
		if ( ! $this->is_root_authorize_request( $wp ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public OAuth query parameters are allowlisted and sanitized by the authorization controller.
		( new AuthorizationController() )->authorize_from_query_params( $_GET );
	}

	/**
	 * Determine whether WordPress matched the root /oauth/authorize rewrite.
	 *
	 * @param object $wp Current WordPress request object.
	 */
	private function is_root_authorize_request( object $wp ): bool {
		$properties = get_object_vars( $wp );
		$query_vars = isset( $properties['query_vars'] ) && is_array( $properties['query_vars'] ) ? $properties['query_vars'] : array();

		return ! empty( $query_vars['aculect_ai_companion_oauth_authorize'] );
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
	 * Register the Aculect AI Companion admin page.
	 */
	public function register_admin(): void {
		( new SettingsPage() )->register();
	}

	/**
	 * Register Users-screen AI access controls.
	 */
	public function register_user_access_controls(): void {
		( new UserAccessControls() )->register();
	}

	/**
	 * Proxy abilities-save form handling to the settings controller.
	 */
	public function handle_save_abilities(): void {
		( new SettingsPage() )->handle_save_abilities();
	}

	/**
	 * Proxy role abilities-save form handling to the settings controller.
	 */
	public function handle_save_role_abilities(): void {
		( new SettingsPage() )->handle_save_role_abilities();
	}

	/**
	 * Proxy advanced settings form handling to the settings controller.
	 */
	public function handle_save_advanced(): void {
		( new SettingsPage() )->handle_save_advanced();
	}

	/**
	 * Proxy settings export handling to the settings controller.
	 */
	public function handle_export_settings(): void {
		( new SettingsPage() )->handle_export_settings();
	}

	/**
	 * Proxy MCP tool manifest export handling to the settings controller.
	 */
	public function handle_export_mcp_tool_manifest(): void {
		( new SettingsPage() )->handle_export_mcp_tool_manifest();
	}

	/**
	 * Proxy settings import handling to the settings controller.
	 */
	public function handle_import_settings(): void {
		( new SettingsPage() )->handle_import_settings();
	}

	/**
	 * Proxy settings reset handling to the settings controller.
	 */
	public function handle_reset_settings(): void {
		( new SettingsPage() )->handle_reset_settings();
	}

	/**
	 * Proxy brand profile form handling to the settings controller.
	 */
	public function handle_save_brand(): void {
		( new SettingsPage() )->handle_save_brand();
	}

	/**
	 * Proxy learning suggestion review handling to the settings controller.
	 */
	public function handle_review_learning_suggestion(): void {
		( new SettingsPage() )->handle_review_learning_suggestion();
	}

	/**
	 * Proxy diagnostic log clearing to the settings controller.
	 */
	public function handle_clear_logs(): void {
		( new SettingsPage() )->handle_clear_logs();
	}

	/**
	 * Handle global AI access pause/resume actions.
	 */
	public function handle_set_lockdown(): void {
		( new SettingsPage() )->handle_set_lockdown();
	}

	/**
	 * Set the admin-managed access level for one connector session.
	 */
	public function handle_set_session_access_level(): void {
		( new SettingsPage() )->handle_set_session_access_level();
	}

	/**
	 * Toggle direct write permission for one connector session.
	 */
	public function handle_set_session_write_permission(): void {
		( new SettingsPage() )->handle_set_session_write_permission();
	}

	/**
	 * Run connection diagnostics from the settings screen.
	 */
	public function handle_run_connection_diagnostics(): void {
		( new SettingsPage() )->handle_run_connection_diagnostics();
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
	 * Refresh the local content intelligence index after a post save.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $post    WordPress post object.
	 * @param bool  $update  Whether this was an update.
	 */
	public function handle_content_index_save( int $post_id, mixed $post = null, bool $update = false ): void {
		unset( $post, $update );

		if ( $this->is_skipped_content_index_post( $post_id ) ) {
			return;
		}

		( new ContentIndexer() )->index_post( $post_id );
	}

	/**
	 * Delete local content intelligence rows for a removed or trashed post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function handle_content_index_delete( int $post_id ): void {
		( new ContentIndexer() )->delete_post( $post_id );
	}

	/**
	 * Mark indexed content stale when terms change after post save.
	 *
	 * @param int   $object_id Object ID.
	 * @param mixed ...$args   Remaining WordPress hook args.
	 */
	public function handle_content_index_terms_changed( int $object_id, mixed ...$args ): void {
		unset( $args );

		( new ContentIndexer() )->mark_post_stale( $object_id );
	}

	/**
	 * Mark indexed content stale when post metadata changes.
	 *
	 * @param mixed $meta_id   Metadata row ID.
	 * @param int   $object_id Object ID.
	 * @param mixed ...$args   Remaining WordPress hook args.
	 */
	public function handle_content_index_meta_changed( mixed $meta_id, int $object_id, mixed ...$args ): void {
		unset( $meta_id, $args );

		( new ContentIndexer() )->mark_post_stale( $object_id );
	}

	/**
	 * Execute a queued content intelligence refresh job.
	 *
	 * @param string $job_key Job key.
	 */
	public function handle_content_index_refresh_job( string $job_key ): void {
		( new ContentIndexer() )->run_queued_refresh_job( $job_key );
	}

	/**
	 * Check whether a post save should not affect the content index.
	 *
	 * @param int $post_id Post ID.
	 */
	private function is_skipped_content_index_post( int $post_id ): bool {
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return true;
		}

		return function_exists( 'wp_is_post_autosave' ) && (bool) wp_is_post_autosave( $post_id );
	}

	/**
	 * Register root-level rewrite rules that cannot be expressed as REST routes.
	 */
	private static function add_rewrite_rules(): void {
		( new DiscoveryController() )->add_rewrite_rules();
		add_rewrite_rule( '^oauth/authorize/?$', 'index.php?aculect_ai_companion_oauth_authorize=1', 'top' );
	}
}

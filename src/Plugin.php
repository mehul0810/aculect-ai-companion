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
		$this->register_settings_actions();
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
		add_action( ContentIndexer::STALE_SWEEP_HOOK, array( $this, 'handle_content_index_stale_sweep' ) );

		OAuthInstaller::install();
		DiagnosticsInstaller::install();
		ActivityInstaller::install();
		IntelligenceInstaller::install();
		OAuthStorageMaintenance::maybe_prune();
	}

	/**
	 * Admin-post action suffixes mapped to their SettingsPage handlers.
	 *
	 * One map instead of sixteen one-line proxy methods; the proxies added
	 * no behavior and doubled the surface to keep in sync.
	 */
	private const SETTINGS_ACTIONS = array(
		'save_abilities'               => 'handle_save_abilities',
		'save_role_abilities'          => 'handle_save_role_abilities',
		'save_advanced'                => 'handle_save_advanced',
		'export_settings'              => 'handle_export_settings',
		'export_mcp_tool_manifest'     => 'handle_export_mcp_tool_manifest',
		'import_settings'              => 'handle_import_settings',
		'reset_settings'               => 'handle_reset_settings',
		'save_brand'                   => 'handle_save_brand',
		'review_learning_suggestion'   => 'handle_review_learning_suggestion',
		'run_connection_diagnostics'   => 'handle_run_connection_diagnostics',
		'clear_logs'                   => 'handle_clear_logs',
		'set_lockdown'                 => 'handle_set_lockdown',
		'set_session_access_level'     => 'handle_set_session_access_level',
		'set_session_write_permission' => 'handle_set_session_write_permission',
		'revoke_session'               => 'handle_revoke_session',
		'revoke_all_sessions'          => 'handle_revoke_all_sessions',
	);

	/**
	 * Register all SettingsPage admin-post handlers from the action map.
	 */
	private function register_settings_actions(): void {
		foreach ( self::SETTINGS_ACTIONS as $action => $method ) {
			add_action(
				'admin_post_aculect_ai_companion_' . $action,
				static fn () => ( new SettingsPage() )->{$method}()
			);
		}
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

		if ( $this->is_bulk_content_context() ) {
			( new ContentIndexer() )->defer_index_post( $post_id );
			return;
		}

		( new ContentIndexer() )->index_post( $post_id );
	}

	/**
	 * Detect bulk write contexts where inline indexing would multiply runtime.
	 *
	 * WP-CLI imports, WordPress importers, and cron-driven writes run the
	 * save_post hook once per post; deferring to the queued stale sweep keeps
	 * those flows fast while the index catches up within a minute.
	 */
	private function is_bulk_content_context(): bool {
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return true;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() && ! doing_action( ContentIndexer::STALE_SWEEP_HOOK ) ) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return (bool) apply_filters( 'aculect_ai_companion_defer_content_indexing', false );
	}

	/**
	 * Run the queued content index stale sweep.
	 */
	public function handle_content_index_stale_sweep(): void {
		( new ContentIndexer() )->run_stale_sweep();
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

		$this->mark_post_stale_once( $object_id );
	}

	/**
	 * Mark indexed content stale when post metadata changes.
	 *
	 * Meta hooks fire dozens of times per editor save (edit locks, SEO and
	 * page-builder fields) and for every post type, so this path must stay
	 * close to free: skip internal meta keys, skip non-indexable post types,
	 * and write the stale flag at most once per post per request.
	 *
	 * @param mixed $meta_id   Metadata row ID.
	 * @param int   $object_id Object ID.
	 * @param mixed ...$args   Remaining WordPress hook args.
	 */
	public function handle_content_index_meta_changed( mixed $meta_id, int $object_id, mixed ...$args ): void {
		unset( $meta_id );

		$meta_key = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';
		if ( str_starts_with( $meta_key, '_' ) && ! $this->is_indexed_internal_meta_key( $meta_key ) ) {
			return;
		}

		$this->mark_post_stale_once( $object_id );
	}

	/**
	 * Internal (underscore-prefixed) meta keys that still affect indexed output.
	 *
	 * @param string $meta_key Meta key.
	 */
	private function is_indexed_internal_meta_key( string $meta_key ): bool {
		$keys = apply_filters( 'aculect_ai_companion_indexed_internal_meta_keys', array( '_thumbnail_id' ) );

		return is_array( $keys ) && in_array( $meta_key, $keys, true );
	}

	/**
	 * Mark a post stale at most once per request, for indexable types only.
	 *
	 * @param int $post_id Post ID.
	 */
	private function mark_post_stale_once( int $post_id ): void {
		static $marked = array();

		$post_id = absint( $post_id );
		if ( 0 >= $post_id || isset( $marked[ $post_id ] ) ) {
			return;
		}

		$post_type = function_exists( 'get_post_type' ) ? (string) get_post_type( $post_id ) : '';
		if ( in_array( $post_type, array( '', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request' ), true ) ) {
			return;
		}

		$marked[ $post_id ] = true;
		( new ContentIndexer() )->mark_post_stale( $post_id );
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

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Activity\ActivityRepository;
use Aculect\AICompanion\Brand\BrandProfile;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\MCP\AccessLockdown;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\RoleConnectionEntryPoint;
use Aculect\AICompanion\Connectors\OAuth\AuthorizationController;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;
use Aculect\AICompanion\Connectors\Providers\ChatGPT\Provider as ChatGPTProvider;
use Aculect\AICompanion\Connectors\Providers\Claude\Provider as ClaudeProvider;
use Aculect\AICompanion\Connectors\Providers\Codex\Provider as CodexProvider;
use Aculect\AICompanion\Connectors\Providers\ProviderInterface;
use Aculect\AICompanion\Diagnostics\ConnectionHealth;
use Aculect\AICompanion\Diagnostics\LogRepository;
use Aculect\AICompanion\Diagnostics\LogSettings;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page controller for connector setup and session management.
 */
final class SettingsPage {

	private const PAGE_SLUG            = 'aculect-ai-companion';
	private const SETTINGS_PARENT_FILE = 'options-general.php';
	private const ASSET_HANDLE         = 'aculect-ai-companion-settings-app';
	private const STYLE_HANDLE         = 'aculect-ai-companion-settings-style';

	/**
	 * Register the settings page and page-specific assets.
	 */
	public function register(): void {
		add_options_page(
			__( 'Aculect AI Companion', 'aculect-ai-companion' ),
			__( 'AI Companion', 'aculect-ai-companion' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'parent_file', array( $this, 'highlight_parent_menu' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_submenu' ) );
	}

	/**
	 * Register admin-only REST routes used by the settings React app.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'aculect-ai-companion/v1',
			'/settings-payload',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_settings_payload' ),
				'permission_callback' => array( $this, 'can_manage_settings' ),
				'args'                => array(
					'tab' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Check whether the current user can load admin settings payloads.
	 */
	public function can_manage_settings(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return a tab-specific settings payload for client-side tab hydration.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function rest_settings_payload( WP_REST_Request $request ): WP_REST_Response {
		$tab = sanitize_key( (string) $request->get_param( 'tab' ) );

		return new WP_REST_Response( $this->settings_payload( $tab ) );
	}

	/**
	 * Render the settings page shell or the OAuth consent screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'aculect-ai-companion' ) );
		}

		if ( $this->is_oauth_consent_view() ) {
			( new AuthorizationController() )->render_admin_consent();
			return;
		}

		echo '<div class="wrap aculect-ai-companion-settings-wrap"><div id="aculect-ai-companion-settings-app-root" class="aculect-ai-companion-settings-app-root"></div></div>';
	}

	/**
	 * Enqueue settings-page assets and hydrate the React application.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->is_settings_admin_screen( $hook_suffix ) ) {
			return;
		}

		if ( $this->is_oauth_consent_view() ) {
			wp_enqueue_style( 'aculect-ai-companion-oauth-consent', ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/css/oauth-consent.css', array(), ACULECT_AI_COMPANION_VERSION );
			return;
		}

		$asset_path = ACULECT_AI_COMPANION_PLUGIN_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-primitives' ),
				'version'      => ACULECT_AI_COMPANION_VERSION,
			);

		wp_register_script(
			self::ASSET_HANDLE,
			ACULECT_AI_COMPANION_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			(string) $asset['version'],
			true
		);
		wp_enqueue_script( self::ASSET_HANDLE );
		wp_enqueue_style( 'wp-components' );

		$style_path = ACULECT_AI_COMPANION_PLUGIN_DIR . 'build/style-index.css';
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style( self::STYLE_HANDLE, ACULECT_AI_COMPANION_PLUGIN_URL . 'build/style-index.css', array( 'wp-components' ), (string) $asset['version'] );
		}

		wp_localize_script(
			self::ASSET_HANDLE,
			'aculectAICompanionSettingsData',
			$this->settings_payload()
		);
	}

	/**
	 * Return settings data for the React application.
	 *
	 * @param string|null $requested_tab Optional requested tab override.
	 * @return array<string, mixed>
	 */
	private function settings_payload( ?string $requested_tab = null ): array {
		$payload_tab          = null === $requested_tab
			? $this->current_payload_tab()
			: $this->normalize_payload_tab( $requested_tab );
		$access_tokens        = new AccessTokenRepository();
		$ability_registry     = new AbilitiesRegistry();
		$sample_data          = new LocalSampleData();
		$real_session_count   = $access_tokens->active_token_count();
		$active_session_count = $sample_data->active_session_count( $real_session_count, $payload_tab );

		$payload = array_merge(
			$this->base_payload( $payload_tab, $active_session_count ),
			$this->connection_payload( $payload_tab, $access_tokens ),
			$this->ability_payload( $payload_tab, $ability_registry ),
			$this->tab_payload( $payload_tab ),
			array(
				'actions' => $this->actions_payload(),
			)
		);

		return $sample_data->apply( $payload, $payload_tab, $real_session_count );
	}

	/**
	 * Return shared settings data that is cheap enough for every tab.
	 *
	 * @param string $payload_tab          Normalized payload tab.
	 * @param int    $active_session_count Active OAuth session count.
	 * @return array<string, mixed>
	 */
	private function base_payload( string $payload_tab, int $active_session_count ): array {
		return array(
			'version'            => ACULECT_AI_COMPANION_VERSION,
			'pluginMetadata'     => $this->plugin_metadata(),
			'payloadTab'         => $payload_tab,
			'hydratedTabs'       => $this->hydrated_tabs( $payload_tab ),
			'adminPageUrl'       => esc_url_raw( $this->settings_url() ),
			'settingsPayloadUrl' => esc_url_raw( rest_url( 'aculect-ai-companion/v1/settings-payload' ) ),
			'settingsRestNonce'  => wp_create_nonce( 'wp_rest' ),
			'brandIconUrl'       => esc_url_raw(
				ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/images/aculect-icon-light.svg'
			),
			'brandMarkUrl'       => esc_url_raw(
				ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/images/aculect-mark.svg'
			),
			'isConnected'        => $active_session_count > 0,
			'activeSessionCount' => $active_session_count,
			'accessPaused'       => AccessLockdown::is_paused(),
			'currentUserId'      => get_current_user_id(),
			'mcpUrl'             => Helpers::mcp_resource(),
			'providers'          => $this->providers(),
			'status'             => $this->status(),
			'diagnostics'        => $this->diagnostics( 'logs' === $payload_tab ),
			'roleConnections'    => $this->role_connections_payload(),
			'connectionHealth'   => ( new ConnectionHealth() )->last_result(),
		);
	}

	/**
	 * Return session lists only for tabs that render connection tables.
	 *
	 * @param string                $payload_tab   Normalized payload tab.
	 * @param AccessTokenRepository $access_tokens Access token repository.
	 * @return array<string, mixed>
	 */
	private function connection_payload( string $payload_tab, AccessTokenRepository $access_tokens ): array {
		if ( 'connections' !== $payload_tab ) {
			return array(
				'sessions'        => array(),
				'revokedSessions' => array(),
			);
		}

		return array(
			'sessions'        => $access_tokens->list_active_sessions(),
			'revokedSessions' => $access_tokens->list_revoked_sessions(),
		);
	}

	/**
	 * Return ability controls while deferring role samples to the Abilities tab.
	 *
	 * @param string            $payload_tab      Normalized payload tab.
	 * @param AbilitiesRegistry $ability_registry Ability registry.
	 * @return array<string, mixed>
	 */
	private function ability_payload( string $payload_tab, AbilitiesRegistry $ability_registry ): array {
		$wp_abilities        = new WordPressAbilitiesPolicy();
		$tool_safety         = new ToolSafety();
		$role_ability_policy = 'abilities' === $payload_tab
			? ( new RoleAbilitiesPolicy() )->admin_payload( $ability_registry )
			: array();

		return array(
			'abilities'                => $ability_registry->public_definitions(),
			'enabledAbilities'         => $ability_registry->enabled_ids(),
			'roleAbilityPolicy'        => $role_ability_policy,
			'wpAbilities'              => $wp_abilities->public_definitions(),
			'enabledWpAbilities'       => $wp_abilities->allowed_ids(),
			'confirmationGroups'       => $tool_safety->confirmation_groups(),
			'confirmationGroupOptions' => $tool_safety->available_confirmation_groups(),
		);
	}

	/**
	 * Return data that belongs to one expensive or hidden tab.
	 *
	 * @param string $payload_tab Normalized payload tab.
	 * @return array<string, mixed>
	 */
	private function tab_payload( string $payload_tab ): array {
		$activity_payload = 'activity' === $payload_tab
			? $this->activity_payload()
			: $this->empty_activity_payload();
		$brand_profile    = 'brand' === $payload_tab
			? ( new BrandProfile() )->admin_payload()
			: array();
		$changelog        = 'changelog' === $payload_tab
			? $this->load_changelog()
			: array();

		return array(
			'activity'     => $activity_payload,
			'brandProfile' => $brand_profile,
			'changelog'    => $changelog,
		);
	}

	/**
	 * Return role-connection settings for the Advanced tab.
	 *
	 * @return array<string, mixed>
	 */
	private function role_connections_payload(): array {
		return array(
			'enabled'      => RoleConnectionEntryPoint::is_enabled(),
			'allowedRoles' => RoleConnectionEntryPoint::allowed_roles(),
			'roleOptions'  => RoleConnectionEntryPoint::role_options(),
			'shortcode'    => '[aculect_ai_companion_connect]',
			'blockName'    => 'aculect/ai-companion-connect',
			'functionName' => 'aculect_ai_companion_connection_entry',
		);
	}

	/**
	 * Return admin-post action names and nonces for forms.
	 *
	 * @return array<string, string>
	 */
	private function actions_payload(): array {
		return array(
			'adminPostUrl'                    => admin_url( 'admin-post.php' ),
			'saveAbilitiesAction'             => 'aculect_ai_companion_save_abilities',
			'saveRoleAbilitiesAction'         => 'aculect_ai_companion_save_role_abilities',
			'saveAdvancedAction'              => 'aculect_ai_companion_save_advanced',
			'exportSettingsAction'            => 'aculect_ai_companion_export_settings',
			'importSettingsAction'            => 'aculect_ai_companion_import_settings',
			'resetSettingsAction'             => 'aculect_ai_companion_reset_settings',
			'saveBrandAction'                 => 'aculect_ai_companion_save_brand',
			'runDiagnosticsAction'            => 'aculect_ai_companion_run_connection_diagnostics',
			'clearLogsAction'                 => 'aculect_ai_companion_clear_logs',
			'setLockdownAction'               => 'aculect_ai_companion_set_lockdown',
			'setSessionAccessLevelAction'     => 'aculect_ai_companion_set_session_access_level',
			'setSessionWritePermissionAction' => 'aculect_ai_companion_set_session_write_permission',
			'revokeSessionAction'             => 'aculect_ai_companion_revoke_session',
			'revokeAllAction'                 => 'aculect_ai_companion_revoke_all_sessions',
			'saveAbilitiesNonce'              => wp_create_nonce( 'aculect_ai_companion_save_abilities' ),
			'saveRoleAbilitiesNonce'          => wp_create_nonce( 'aculect_ai_companion_save_role_abilities' ),
			'saveAdvancedNonce'               => wp_create_nonce( 'aculect_ai_companion_save_advanced' ),
			'exportSettingsNonce'             => wp_create_nonce( 'aculect_ai_companion_export_settings' ),
			'importSettingsNonce'             => wp_create_nonce( 'aculect_ai_companion_import_settings' ),
			'resetSettingsNonce'              => wp_create_nonce( 'aculect_ai_companion_reset_settings' ),
			'saveBrandNonce'                  => wp_create_nonce( 'aculect_ai_companion_save_brand' ),
			'runDiagnosticsNonce'             => wp_create_nonce( 'aculect_ai_companion_run_connection_diagnostics' ),
			'clearLogsNonce'                  => wp_create_nonce( 'aculect_ai_companion_clear_logs' ),
			'setLockdownNonce'                => wp_create_nonce( 'aculect_ai_companion_set_lockdown' ),
			'setSessionAccessLevelNonce'      => wp_create_nonce( 'aculect_ai_companion_set_session_access_level' ),
			'setSessionWritePermissionNonce'  => wp_create_nonce( 'aculect_ai_companion_set_session_write_permission' ),
			'revokeSessionNonce'              => wp_create_nonce( 'aculect_ai_companion_revoke_session' ),
			'revokeAllNonce'                  => wp_create_nonce( 'aculect_ai_companion_revoke_all_sessions' ),
		);
	}

	/**
	 * Persist role-specific MCP ability policy from the admin form.
	 */
	public function handle_save_role_abilities(): void {
		$this->guard_action( 'aculect_ai_companion_save_role_abilities' );
		$registry = new AbilitiesRegistry();
		$policy   = new RoleAbilitiesPolicy();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$role      = isset( $_POST['role_ability_role'] ) ? sanitize_key( wp_unslash( (string) $_POST['role_ability_role'] ) ) : '';
		$action    = isset( $_POST['role_ability_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['role_ability_action'] ) ) : 'save';
		$ids       = isset( $_POST['enabled_role_abilities'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['enabled_role_abilities'] ) )
			: array();
		$copy_from = isset( $_POST['copy_from_role'] ) ? sanitize_key( wp_unslash( (string) $_POST['copy_from_role'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'reset' === $action ) {
			$policy->reset_role_policy( $role, $registry );
		} elseif ( 'copy' === $action ) {
			$policy->copy_role_policy( $copy_from, $role, $registry );
		} else {
			$policy->save_role_policy( $role, $ids, $registry );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'aculect-ai-companion',
					'tab'                  => 'abilities',
					'role_abilities_saved' => '1',
					'role'                 => $role,
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Persist enabled MCP abilities from the admin form.
	 */
	public function handle_save_abilities(): void {
		$this->guard_action( 'aculect_ai_companion_save_abilities' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$enabled = isset( $_POST['enabled_abilities'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['enabled_abilities'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		( new AbilitiesRegistry() )->save_enabled_ids( $enabled );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$confirmation_groups = isset( $_POST['confirmation_required_groups'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['confirmation_required_groups'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		( new ToolSafety() )->save_confirmation_groups( $confirmation_groups );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$enabled_wp_abilities = isset( $_POST['enabled_wp_abilities'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['enabled_wp_abilities'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		( new WordPressAbilitiesPolicy() )->save_allowed_ids( $enabled_wp_abilities );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'aculect-ai-companion',
					'abilities_saved' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Persist advanced diagnostic settings from the admin form.
	 */
	public function handle_save_advanced(): void {
		$this->guard_action( 'aculect_ai_companion_save_advanced' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$enabled = isset( $_POST['diagnostic_logging_enabled'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['diagnostic_logging_enabled'] ) );

		LogSettings::set_enabled( $enabled );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$role_connections_enabled = isset( $_POST['role_connections_enabled'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['role_connections_enabled'] ) );
		$role_connection_roles    = isset( $_POST['role_connection_roles'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['role_connection_roles'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		RoleConnectionEntryPoint::save( $role_connections_enabled, $role_connection_roles );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'aculect-ai-companion',
					'tab'            => 'advanced',
					'advanced_saved' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Stream sanitized plugin settings as a JSON download.
	 */
	public function handle_export_settings(): void {
		$this->guard_action( 'aculect_ai_companion_export_settings' );

		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header(
				'Content-Disposition: attachment; filename="' .
				sanitize_file_name( 'aculect-ai-companion-settings-' . gmdate( 'Y-m-d' ) . '.json' ) .
				'"'
			);
		}

		echo ( new SettingsTransfer() )->export_json(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is encoded by SettingsTransfer::export_json().
		exit;
	}

	/**
	 * Import sanitized plugin settings from a JSON upload.
	 */
	public function handle_import_settings(): void {
		$this->guard_action( 'aculect_ai_companion_import_settings' );

		$transfer = new SettingsTransfer();
		$imported = false;
		$json     = $this->uploaded_settings_json();

		if ( '' !== $json ) {
			$imported = $transfer->import_payload( $transfer->decode_json( $json ) );
		}

		$this->redirect_to_advanced(
			array(
				'settings_imported' => $imported ? '1' : '0',
			)
		);
	}

	/**
	 * Restore plugin settings to defaults.
	 */
	public function handle_reset_settings(): void {
		$this->guard_action( 'aculect_ai_companion_reset_settings' );

		( new SettingsTransfer() )->reset();

		$this->redirect_to_advanced(
			array(
				'settings_reset' => '1',
			)
		);
	}

	/**
	 * Return uploaded settings JSON when the file passes basic safety checks.
	 */
	private function uploaded_settings_json(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this upload read.
		if ( empty( $_FILES['settings_file'] ) || ! is_array( $_FILES['settings_file'] ) ) {
			return '';
		}

		$file  = $_FILES['settings_file'];
		$error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
		if ( ! is_scalar( $error ) || UPLOAD_ERR_OK !== (int) $error ) {
			return '';
		}

		$size = $file['size'] ?? 0;
		if ( ! is_scalar( $size ) ) {
			return '';
		}

		$size = absint( $size );
		if ( $size <= 0 || $size > SettingsTransfer::MAX_IMPORT_BYTES ) {
			return '';
		}

		$tmp_name = $file['tmp_name'] ?? '';
		if ( ! is_scalar( $tmp_name ) ) {
			return '';
		}

		$tmp_name = (string) $tmp_name;
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a bounded PHP-uploaded temporary file.
		$json = file_get_contents( $tmp_name, false, null, 0, SettingsTransfer::MAX_IMPORT_BYTES + 1 );

		return is_string( $json ) && strlen( $json ) <= SettingsTransfer::MAX_IMPORT_BYTES ? $json : '';
	}

	/**
	 * Redirect back to Advanced with a status flag.
	 *
	 * @param array<string, string> $args Additional query args.
	 */
	private function redirect_to_advanced( array $args ): void {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page' => 'aculect-ai-companion',
						'tab'  => 'advanced',
					),
					$args
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Persist brand profile settings from the admin form.
	 */
	public function handle_save_brand(): void {
		$this->guard_action( 'aculect_ai_companion_save_brand' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$profile = isset( $_POST['brand_profile'] ) && is_array( $_POST['brand_profile'] ) ? (array) wp_unslash( $_POST['brand_profile'] ) : array();

		( new BrandProfile() )->save( $profile );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'aculect-ai-companion',
					'tab'         => 'brand',
					'brand_saved' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Clear diagnostic logs from the admin form.
	 */
	public function handle_clear_logs(): void {
		$this->guard_action( 'aculect_ai_companion_clear_logs' );

		( new LogRepository() )->clear();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'aculect-ai-companion',
					'tab'          => 'logs',
					'logs_cleared' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Temporarily pause or resume all connected AI access.
	 */
	public function handle_set_lockdown(): void {
		$this->guard_action( 'aculect_ai_companion_set_lockdown' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$paused = isset( $_POST['access_paused'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['access_paused'] ) );

		AccessLockdown::set_paused( $paused );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'aculect-ai-companion',
					'tab'             => 'connections',
					'access_lockdown' => $paused ? 'paused' : 'resumed',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Set the admin-managed access level for one active connector session.
	 */
	public function handle_set_session_access_level(): void {
		$this->guard_action( 'aculect_ai_companion_set_session_access_level' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$session_id   = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		$access_level = isset( $_POST['session_access_level'] )
			? ConnectionAccessLevel::normalize( wp_unslash( (string) $_POST['session_access_level'] ) )
			: ConnectionAccessLevel::DEFAULT;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$updated = $session_id > 0 && ( new AccessTokenRepository() )->set_access_level( $session_id, $access_level );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'aculect-ai-companion',
					'tab'                  => 'connections',
					'session_access_level' => $updated ? 'updated' : 'not_updated',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Toggle direct write permission for one active connector session.
	 */
	public function handle_set_session_write_permission(): void {
		$this->guard_action( 'aculect_ai_companion_set_session_write_permission' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		$enabled    = isset( $_POST['write_permission_enabled'] )
			&& '1' === sanitize_text_field( wp_unslash( (string) $_POST['write_permission_enabled'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$updated = $session_id > 0 && ( new AccessTokenRepository() )->set_write_permission( $session_id, $enabled );
		$status  = $updated ? ( $enabled ? 'enabled' : 'disabled' ) : 'not_updated';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                     => 'aculect-ai-companion',
					'tab'                      => 'connections',
					'session_write_permission' => $status,
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Run connection health diagnostics from the admin screen.
	 */
	public function handle_run_connection_diagnostics(): void {
		$this->guard_action( 'aculect_ai_companion_run_connection_diagnostics' );

		( new ConnectionHealth() )->run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'aculect-ai-companion',
					'tab'             => 'diagnostics',
					'diagnostics_run' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Revoke a single active connector session.
	 */
	public function handle_revoke_session(): void {
		$this->guard_action( 'aculect_ai_companion_revoke_session' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		if ( $session_id > 0 ) {
			( new AccessTokenRepository() )->revoke_session( $session_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'aculect-ai-companion',
					'revoked' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Revoke every active connector session.
	 */
	public function handle_revoke_all_sessions(): void {
		$this->guard_action( 'aculect_ai_companion_revoke_all_sessions' );
		( new AccessTokenRepository() )->revoke_all();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'aculect-ai-companion',
					'revoked_all' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Return provider setup definitions for the React settings app.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function providers(): array {
		$mcp_url = Helpers::mcp_resource();
		return array_map(
			static function ( ProviderInterface $provider ) use ( $mcp_url ): array {
				return array(
					'id'                 => $provider->id(),
					'label'              => $provider->label(),
					'description'        => $provider->description(),
					'primaryActionUrl'   => $provider->primary_action_url(),
					'primaryActionLabel' => $provider->primary_action_label(),
					'setupSections'      => $provider->setup_sections( $mcp_url ),
				);
			},
			array(
				new ClaudeProvider(),
				new ChatGPTProvider(),
				new CodexProvider(),
			)
		);
	}

	/**
	 * Return diagnostic settings and the current log page for the React app.
	 *
	 * @param bool $include_logs Whether to load paginated log rows.
	 * @return array<string, mixed>
	 */
	private function diagnostics( bool $include_logs = false ): array {
		$enabled = LogSettings::is_enabled();

		return array(
			'loggingEnabled' => $enabled,
			'retentionDays'  => LogSettings::retention_days(),
			'logs'           => $enabled && $include_logs
				? $this->logs_payload()
				: $this->empty_logs_payload(),
		);
	}

	/**
	 * Return a paginated AI activity payload.
	 *
	 * @return array<string, mixed>
	 */
	private function activity_payload(): array {
		$repository  = new ActivityRepository();
		$per_page    = 50;
		$filters     = $this->activity_filters();
		$total       = $repository->count( $filters );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( max( 1, (int) $filters['page'] ), $total_pages );
		$filters     = array_merge(
			$filters,
			array(
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		return array(
			'summary'    => $repository->summary( $filters ),
			'items'      => $repository->list( $filters ),
			'total'      => $total,
			'page'       => $page,
			'perPage'    => $per_page,
			'totalPages' => $total_pages,
			'filters'    => $filters,
			'prevUrl'    => $page > 1 ? $this->activity_page_url( $filters, $page - 1 ) : '',
			'nextUrl'    => $page < $total_pages
				? $this->activity_page_url( $filters, $page + 1 )
				: '',
		);
	}

	/**
	 * Return the default empty AI activity payload.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_activity_payload(): array {
		return array(
			'summary'    => array(),
			'items'      => array(),
			'total'      => 0,
			'page'       => 1,
			'perPage'    => 50,
			'totalPages' => 1,
			'filters'    => array(
				'page'      => 1,
				'action'    => '',
				'status'    => '',
				'user_id'   => 0,
				'assistant' => '',
				'search'    => '',
				'range'     => '7d',
			),
			'prevUrl'    => '',
			'nextUrl'    => '',
		);
	}

	/**
	 * Return sanitized activity filters from the current admin URL.
	 *
	 * @return array<string, mixed>
	 */
	private function activity_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin filters.
		$range = isset( $_GET['activity_range'] ) ? sanitize_key( wp_unslash( (string) $_GET['activity_range'] ) ) : '7d';
		if ( ! in_array( $range, array( '24h', '7d', '30d', '90d', 'all' ), true ) ) {
			$range = '7d';
		}

		return array(
			'page'      => isset( $_GET['activity_page'] ) ? max( 1, absint( $_GET['activity_page'] ) ) : 1,
			'action'    => isset( $_GET['activity_action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['activity_action'] ) ) : '',
			'status'    => isset( $_GET['activity_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['activity_status'] ) ) : '',
			'user_id'   => isset( $_GET['activity_user'] ) ? absint( $_GET['activity_user'] ) : 0,
			'assistant' => isset( $_GET['activity_assistant'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['activity_assistant'] ) ) : '',
			'search'    => isset( $_GET['activity_search'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['activity_search'] ) ) : '',
			'range'     => $range,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Build an Activity tab pagination URL.
	 *
	 * @param array<string, mixed> $filters Activity filters.
	 * @param int                  $page    Page number.
	 */
	private function activity_page_url( array $filters, int $page ): string {
		return add_query_arg(
			array_filter(
				array(
					'page'               => 'aculect-ai-companion',
					'tab'                => 'activity',
					'activity_page'      => max( 1, $page ),
					'activity_action'    => (string) ( $filters['action'] ?? '' ),
					'activity_status'    => (string) ( $filters['status'] ?? '' ),
					'activity_user'      => (int) ( $filters['user_id'] ?? 0 ),
					'activity_assistant' => (string) ( $filters['assistant'] ?? '' ),
					'activity_search'    => (string) ( $filters['search'] ?? '' ),
					'activity_range'     => (string) ( $filters['range'] ?? '7d' ),
				),
				static fn( mixed $value ): bool => '' !== $value && 0 !== $value
			),
			$this->settings_url()
		);
	}

	/**
	 * Return a paginated diagnostic log payload.
	 *
	 * @return array<string, mixed>
	 */
	private function logs_payload(): array {
		$repository = new LogRepository();
		$per_page   = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter.
		$page        = isset( $_GET['logs_page'] ) ? max( 1, absint( $_GET['logs_page'] ) ) : 1;
		$total       = $repository->count();
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( $page, $total_pages );

		return array(
			'items'      => $repository->list( $page, $per_page ),
			'total'      => $total,
			'page'       => $page,
			'perPage'    => $per_page,
			'totalPages' => $total_pages,
			'prevUrl'    => $page > 1 ? $this->logs_page_url( $page - 1 ) : '',
			'nextUrl'    => $page < $total_pages ? $this->logs_page_url( $page + 1 ) : '',
		);
	}

	/**
	 * Return an empty log payload when logging is disabled.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_logs_payload(): array {
		return array(
			'items'      => array(),
			'total'      => 0,
			'page'       => 1,
			'perPage'    => 50,
			'totalPages' => 1,
			'prevUrl'    => '',
			'nextUrl'    => '',
		);
	}

	/**
	 * Build a Logs tab pagination URL.
	 *
	 * @param int $page Log page.
	 */
	private function logs_page_url( int $page ): string {
		return add_query_arg(
			array(
				'page'      => 'aculect-ai-companion',
				'tab'       => 'logs',
				'logs_page' => max( 1, $page ),
			),
			$this->settings_url()
		);
	}

	/**
	 * Return the admin URL for this settings app.
	 *
	 * @param array<string, mixed> $args Additional query args.
	 */
	private function settings_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE_SLUG,
				),
				$args
			),
			admin_url( self::SETTINGS_PARENT_FILE )
		);
	}

	/**
	 * Return the visible settings tabs rendered inside the settings app.
	 *
	 * @return array<int, array{tab:string, title:string, menu_title:string}>
	 */
	private function settings_tabs(): array {
		$tabs = array(
			array(
				'tab'        => 'overview',
				'title'      => __( 'Overview', 'aculect-ai-companion' ),
				'menu_title' => __( 'Overview', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'connect',
				'title'      => __( 'Connect', 'aculect-ai-companion' ),
				'menu_title' => __( 'Connect', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'connections',
				'title'      => __( 'Connections', 'aculect-ai-companion' ),
				'menu_title' => __( 'Connections', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'abilities',
				'title'      => __( 'Abilities', 'aculect-ai-companion' ),
				'menu_title' => __( 'Abilities', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'activity',
				'title'      => __( 'Activity', 'aculect-ai-companion' ),
				'menu_title' => __( 'Activity', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'diagnostics',
				'title'      => __( 'Diagnostics', 'aculect-ai-companion' ),
				'menu_title' => __( 'Diagnostics', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'advanced',
				'title'      => __( 'Advanced', 'aculect-ai-companion' ),
				'menu_title' => __( 'Advanced', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'changelog',
				'title'      => __( 'Changelog', 'aculect-ai-companion' ),
				'menu_title' => __( 'Changelog', 'aculect-ai-companion' ),
			),
		);

		return $tabs;
	}

	/**
	 * Determine whether the current admin screen belongs to this settings app.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	private function is_settings_admin_screen( string $hook_suffix ): bool {
		if ( str_contains( $hook_suffix, 'aculect-ai-companion' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing flag.
		return isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( (string) $_GET['page'] ) );
	}

	/**
	 * Keep the WordPress Settings menu highlighted for tab subpages.
	 *
	 * @param string|null $parent_file Current parent file.
	 */
	public function highlight_parent_menu( ?string $parent_file ): ?string {
		return $this->is_current_settings_page() ? self::SETTINGS_PARENT_FILE : $parent_file;
	}

	/**
	 * Keep the AI Companion settings submenu highlighted for internal tabs.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 */
	public function highlight_submenu( ?string $submenu_file ): ?string {
		if ( ! $this->is_current_settings_page() ) {
			return $submenu_file;
		}

		return self::PAGE_SLUG;
	}

	/**
	 * Determine whether the current request is for the settings app.
	 */
	private function is_current_settings_page(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing flag.
		return isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( (string) $_GET['page'] ) );
	}

	/**
	 * Return the normalized tab used for server-side payload hydration.
	 */
	private function current_payload_tab(): string {
		return $this->normalize_payload_tab( $this->requested_tab() );
	}

	/**
	 * Normalize a requested tab to a tab that can be server-hydrated.
	 *
	 * @param string $tab Requested tab.
	 */
	private function normalize_payload_tab( string $tab ): string {
		$normalized_tab = $this->normalize_requested_tab( $tab );

		return in_array( $normalized_tab, $this->payload_tabs(), true ) ? $normalized_tab : 'overview';
	}

	/**
	 * Return tabs that have complete data in the current localized payload.
	 *
	 * @param string $payload_tab Normalized payload tab.
	 * @return list<string>
	 */
	private function hydrated_tabs( string $payload_tab ): array {
		$tabs = array( 'overview', 'connect', 'diagnostics', 'advanced' );

		$tab_specific_payloads = array(
			'connections',
			'abilities',
			'activity',
			'brand',
			'logs',
			'changelog',
		);

		if ( in_array( $payload_tab, $tab_specific_payloads, true ) ) {
			$tabs[] = $payload_tab;
		}

		return array_values( array_unique( $tabs ) );
	}

	/**
	 * Return every tab name that can be represented by localized data.
	 *
	 * @return list<string>
	 */
	private function payload_tabs(): array {
		return array_merge( array_column( $this->settings_tabs(), 'tab' ), array( 'brand', 'logs' ) );
	}

	/**
	 * Return the raw requested tab normalized for legacy aliases.
	 */
	private function requested_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab routing flag.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview';

		return $this->normalize_requested_tab( $tab );
	}

	/**
	 * Normalize legacy tab aliases.
	 *
	 * @param string $tab Requested tab.
	 */
	private function normalize_requested_tab( string $tab ): string {
		return match ( $tab ) {
			'about' => 'overview',
			'connectors' => 'connect',
			default => $tab,
		};
	}

	/**
	 * Return admin status flags from the current request.
	 */
	private function status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['advanced_saved'] ) ) {
			return 'advanced_saved';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['settings_imported'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
			return '1' === sanitize_key( wp_unslash( (string) $_GET['settings_imported'] ) ) ? 'settings_imported' : 'settings_import_failed';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['settings_reset'] ) ) {
			return 'settings_reset';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['brand_saved'] ) ) {
			return 'brand_saved';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['logs_cleared'] ) ) {
			return 'logs_cleared';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['access_lockdown'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
			return 'paused' === sanitize_key( wp_unslash( (string) $_GET['access_lockdown'] ) ) ? 'access_paused' : 'access_resumed';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['session_access_level'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
			return 'updated' === sanitize_key( wp_unslash( (string) $_GET['session_access_level'] ) ) ? 'session_access_level_updated' : 'session_access_level_not_updated';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['session_write_permission'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
			$status = sanitize_key( wp_unslash( (string) $_GET['session_write_permission'] ) );

			return match ( $status ) {
				'enabled'  => 'session_write_permission_enabled',
				'disabled' => 'session_write_permission_disabled',
				default    => 'session_write_permission_not_updated',
			};
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['diagnostics_run'] ) ) {
			return 'diagnostics_run';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['abilities_saved'] ) ) {
			return 'abilities_saved';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['role_abilities_saved'] ) ) {
			return 'role_abilities_saved';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['revoked_all'] ) ) {
			return 'revoked_all';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['revoked'] ) ) {
			return 'revoked';
		}

		return '';
	}

	/**
	 * Load the bundled changelog data.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function load_changelog(): array {
		$file = ACULECT_AI_COMPANION_PLUGIN_DIR . 'changelog.json';
		if ( ! file_exists( $file ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file read.
		$json = file_get_contents( $file );
		if ( false === $json || '' === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Return plugin metadata used by the changelog screen.
	 *
	 * @return array<string, string>
	 */
	private function plugin_metadata(): array {
		$plugin_data = function_exists( 'get_file_data' )
			? get_file_data(
				ACULECT_AI_COMPANION_PLUGIN_FILE,
				array(
					'version'         => 'Version',
					'requiresAtLeast' => 'Requires at least',
					'requiresPhp'     => 'Requires PHP',
				),
				'plugin'
			)
			: array();
		$readme_data = $this->readme_headers();

		return array(
			'version'          => sanitize_text_field( (string) ( $plugin_data['version'] ?? ACULECT_AI_COMPANION_VERSION ) ),
			'requiresAtLeast'  => sanitize_text_field( (string) ( $plugin_data['requiresAtLeast'] ?? '' ) ),
			'requiresPhp'      => sanitize_text_field( (string) ( $plugin_data['requiresPhp'] ?? '' ) ),
			'testedUpTo'       => sanitize_text_field( (string) ( $readme_data['Tested up to'] ?? '' ) ),
			'stableTag'        => sanitize_text_field( (string) ( $readme_data['Stable tag'] ?? '' ) ),
			'documentationUrl' => esc_url_raw( 'https://wordpress.org/plugins/aculect-ai-companion/' ),
			'wordpressOrgUrl'  => esc_url_raw( 'https://wordpress.org/plugins/aculect-ai-companion/#developers' ),
			'supportUrl'       => esc_url_raw( 'https://wordpress.org/support/plugin/aculect-ai-companion/' ),
			'reviewUrl'        => esc_url_raw( 'https://wordpress.org/support/plugin/aculect-ai-companion/reviews/#new-post' ),
		);
	}

	/**
	 * Parse readme headers without duplicating version requirements in JS.
	 *
	 * @return array<string, string>
	 */
	private function readme_headers(): array {
		$file = ACULECT_AI_COMPANION_PLUGIN_DIR . 'readme.txt';
		if ( ! file_exists( $file ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file read.
		$contents = file_get_contents( $file );
		if ( false === $contents || '' === $contents ) {
			return array();
		}

		$headers = array();
		$lines   = preg_split( '/\R/', $contents );
		$lines   = false === $lines ? array() : $lines;

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line && array() !== $headers ) {
				break;
			}

			if ( str_starts_with( $line, '==' ) ) {
				if ( array() !== $headers ) {
					break;
				}

				continue;
			}

			if ( preg_match( '/^([^:]+):\s*(.+)$/', $line, $matches ) ) {
				$headers[ trim( $matches[1] ) ] = sanitize_text_field( trim( $matches[2] ) );
			}
		}

		return $headers;
	}

	/**
	 * Determine whether the settings page should render OAuth consent.
	 */
	private function is_oauth_consent_view(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing flag for the settings page.
		return isset( $_GET['view'] ) && 'oauth-consent' === sanitize_key( wp_unslash( (string) $_GET['view'] ) );
	}

	/**
	 * Require manage_options and verify the form nonce.
	 *
	 * @param string $nonce_action Expected nonce action.
	 */
	private function guard_action( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'aculect-ai-companion' ) );
		}

		check_admin_referer( $nonce_action );
	}
}

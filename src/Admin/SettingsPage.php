<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Activity\ActivityRepository;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\MCP\AccessLockdown;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\OAuth\AuthorizationController;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;
use Aculect\AICompanion\Connectors\Providers\ChatGPT\Provider as ChatGPTProvider;
use Aculect\AICompanion\Connectors\Providers\Claude\Provider as ClaudeProvider;
use Aculect\AICompanion\Connectors\Providers\ProviderInterface;
use Aculect\AICompanion\Diagnostics\LogRepository;
use Aculect\AICompanion\Diagnostics\LogSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page controller for connector setup and session management.
 */
final class SettingsPage {

	private const ASSET_HANDLE = 'aculect-ai-companion-settings-app';
	private const STYLE_HANDLE = 'aculect-ai-companion-settings-style';

	/**
	 * Register the settings page and page-specific assets.
	 */
	public function register(): void {
		add_options_page( 'Connect your AI assistant', 'Aculect AI Companion', 'manage_options', 'aculect-ai-companion', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
		if ( 'settings_page_aculect-ai-companion' !== $hook_suffix ) {
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
				'dependencies' => array( 'wp-element', 'wp-components' ),
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
			wp_enqueue_style( self::STYLE_HANDLE, ACULECT_AI_COMPANION_PLUGIN_URL . 'build/style-index.css', array(), (string) $asset['version'] );
		}

		wp_localize_script(
			self::ASSET_HANDLE,
			'aculectAICompanionSettingsData',
			array(
				'version'          => ACULECT_AI_COMPANION_VERSION,
				'isConnected'      => ( new AccessTokenRepository() )->has_active_tokens(),
				'accessPaused'     => AccessLockdown::is_paused(),
				'mcpUrl'           => Helpers::mcp_resource(),
				'providers'        => $this->providers(),
				'sessions'         => ( new AccessTokenRepository() )->list_active_sessions(),
				'abilities'        => ( new AbilitiesRegistry() )->public_definitions(),
				'enabledAbilities' => ( new AbilitiesRegistry() )->enabled_ids(),
				'status'           => $this->status(),
				'activity'         => $this->activity_payload(),
				'diagnostics'      => $this->diagnostics(),
				'actions'          => array(
					'adminPostUrl'        => admin_url( 'admin-post.php' ),
					'saveAbilitiesAction' => 'aculect_ai_companion_save_abilities',
					'saveAdvancedAction'  => 'aculect_ai_companion_save_advanced',
					'clearLogsAction'     => 'aculect_ai_companion_clear_logs',
					'setLockdownAction'   => 'aculect_ai_companion_set_lockdown',
					'revokeSessionAction' => 'aculect_ai_companion_revoke_session',
					'revokeAllAction'     => 'aculect_ai_companion_revoke_all_sessions',
					'saveAbilitiesNonce'  => wp_create_nonce( 'aculect_ai_companion_save_abilities' ),
					'saveAdvancedNonce'   => wp_create_nonce( 'aculect_ai_companion_save_advanced' ),
					'clearLogsNonce'      => wp_create_nonce( 'aculect_ai_companion_clear_logs' ),
					'setLockdownNonce'    => wp_create_nonce( 'aculect_ai_companion_set_lockdown' ),
					'revokeSessionNonce'  => wp_create_nonce( 'aculect_ai_companion_revoke_session' ),
					'revokeAllNonce'      => wp_create_nonce( 'aculect_ai_companion_revoke_all_sessions' ),
				),
				'changelog'        => $this->load_changelog(),
			)
		);
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

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'aculect-ai-companion',
					'abilities_saved' => '1',
				),
				admin_url( 'options-general.php' )
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

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'aculect-ai-companion',
					'tab'            => 'advanced',
					'advanced_saved' => '1',
				),
				admin_url( 'options-general.php' )
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
				admin_url( 'options-general.php' )
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
				admin_url( 'options-general.php' )
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
				admin_url( 'options-general.php' )
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
				admin_url( 'options-general.php' )
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
			)
		);
	}

	/**
	 * Return diagnostic settings and the current log page for the React app.
	 *
	 * @return array<string, mixed>
	 */
	private function diagnostics(): array {
		$enabled = LogSettings::is_enabled();

		return array(
			'loggingEnabled' => $enabled,
			'retentionDays'  => LogSettings::retention_days(),
			'logs'           => $enabled ? $this->logs_payload() : $this->empty_logs_payload(),
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
			'items'      => $repository->list( $filters ),
			'total'      => $total,
			'page'       => $page,
			'perPage'    => $per_page,
			'totalPages' => $total_pages,
			'filters'    => $filters,
			'prevUrl'    => $page > 1 ? $this->activity_page_url( $filters, $page - 1 ) : '',
			'nextUrl'    => $page < $total_pages ? $this->activity_page_url( $filters, $page + 1 ) : '',
		);
	}

	/**
	 * Return sanitized activity filters from the current admin URL.
	 *
	 * @return array<string, mixed>
	 */
	private function activity_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin filters.
		return array(
			'page'      => isset( $_GET['activity_page'] ) ? max( 1, absint( $_GET['activity_page'] ) ) : 1,
			'action'    => isset( $_GET['activity_action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['activity_action'] ) ) : '',
			'status'    => isset( $_GET['activity_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['activity_status'] ) ) : '',
			'user_id'   => isset( $_GET['activity_user'] ) ? absint( $_GET['activity_user'] ) : 0,
			'assistant' => isset( $_GET['activity_assistant'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['activity_assistant'] ) ) : '',
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
				),
				static fn( mixed $value ): bool => '' !== $value && 0 !== $value
			),
			admin_url( 'options-general.php' )
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
			admin_url( 'options-general.php' )
		);
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
		if ( isset( $_GET['logs_cleared'] ) ) {
			return 'logs_cleared';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['access_lockdown'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
			return 'paused' === sanitize_key( wp_unslash( (string) $_GET['access_lockdown'] ) ) ? 'access_paused' : 'access_resumed';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['abilities_saved'] ) ) {
			return 'abilities_saved';
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

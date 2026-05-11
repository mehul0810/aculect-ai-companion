<?php

declare(strict_types=1);

namespace Quark\Admin;

use Quark\Connectors\Helpers;
use Quark\Connectors\MCP\AbilitiesRegistry;
use Quark\Connectors\OAuth\AuthorizationController;
use Quark\Connectors\OAuth\DiscoveryController;
use Quark\Connectors\OAuth\Repositories\AccessTokenRepository;
use Quark\Connectors\Providers\ChatGPT\Provider as ChatGPTProvider;
use Quark\Connectors\Providers\Claude\Provider as ClaudeProvider;
use Quark\Connectors\Providers\ProviderInterface;

final class SettingsPage {

	private const OPTION_REMOVE_DATA_ON_UNINSTALL = 'quark_remove_data_on_uninstall';
	private const ASSET_HANDLE                    = 'quark-settings-app';
	private const STYLE_HANDLE                    = 'quark-settings-style';

	public function register(): void {
		add_options_page( 'Quark Settings', 'Quark', 'manage_options', 'quark', array( $this, 'render' ) );
		add_action( 'load-settings_page_quark', array( $this, 'suppress_external_admin_notices' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function suppress_external_admin_notices(): void {
		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook_name ) {
			remove_all_actions( $hook_name );
		}

		add_action( 'admin_notices', array( $this, 'render_quark_admin_notices' ) );
	}

	public function render_quark_admin_notices(): void {
		/**
		 * Fires in the reserved Quark notice slot after third-party admin notices
		 * are suppressed on the Quark settings screen.
		 *
		 * @since 0.1.0
		 */
		do_action( 'quark_admin_notices' );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'quark' ) );
		}

		if ( $this->is_oauth_consent_view() ) {
			( new AuthorizationController() )->render_admin_consent();
			return;
		}

		echo '<div class="wrap quark-settings-wrap"><div id="quark-settings-app-root" class="quark-settings-app-root"></div></div>';
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_quark' !== $hook_suffix ) {
			return;
		}

		if ( $this->is_oauth_consent_view() ) {
			wp_enqueue_style( 'quark-oauth-consent', QUARK_PLUGIN_URL . 'assets/css/oauth-consent.css', array(), QUARK_VERSION );
			return;
		}

		$asset_path = QUARK_PLUGIN_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array( 'wp-element', 'wp-components' ),
				'version'      => QUARK_VERSION,
			);

		wp_register_script(
			self::ASSET_HANDLE,
			QUARK_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			(string) $asset['version'],
			true
		);
		wp_enqueue_script( self::ASSET_HANDLE );
		wp_enqueue_style( 'wp-components' );

		$style_path = QUARK_PLUGIN_DIR . 'build/style-index.css';
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style( self::STYLE_HANDLE, QUARK_PLUGIN_URL . 'build/style-index.css', array(), (string) $asset['version'] );
		}

		wp_localize_script(
			self::ASSET_HANDLE,
			'quarkSettingsData',
			array(
				'version'               => QUARK_VERSION,
				'isConnected'           => ( new AccessTokenRepository() )->has_active_tokens(),
				'mcpUrl'                => Helpers::mcp_resource(),
				'providers'             => $this->providers(),
				'sessions'              => ( new AccessTokenRepository() )->list_active_sessions(),
				'abilities'             => ( new AbilitiesRegistry() )->public_definitions(),
				'enabledAbilities'      => ( new AbilitiesRegistry() )->enabled_ids(),
				'diagnostics'           => ( new DiscoveryController() )->diagnostics(),
				'status'                => $this->status(),
				'removeDataOnUninstall' => $this->remove_data_on_uninstall_enabled(),
				'actions'               => array(
					'adminPostUrl'        => admin_url( 'admin-post.php' ),
					'saveAdvancedAction'  => 'quark_save_advanced',
					'saveAbilitiesAction' => 'quark_save_abilities',
					'revokeSessionAction' => 'quark_revoke_session',
					'revokeAllAction'     => 'quark_revoke_all_sessions',
					'saveAdvancedNonce'   => wp_create_nonce( 'quark_save_advanced' ),
					'saveAbilitiesNonce'  => wp_create_nonce( 'quark_save_abilities' ),
					'revokeSessionNonce'  => wp_create_nonce( 'quark_revoke_session' ),
					'revokeAllNonce'      => wp_create_nonce( 'quark_revoke_all_sessions' ),
				),
				'changelog'             => $this->load_changelog(),
			)
		);
	}

	public function handle_save_advanced(): void {
		$this->guard_action( 'quark_save_advanced' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$enabled = isset( $_POST['remove_data_on_uninstall'] ) && '1' === (string) $_POST['remove_data_on_uninstall'];
		update_option( self::OPTION_REMOVE_DATA_ON_UNINSTALL, $enabled ? '1' : '0', false );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'quark',
					'advanced_saved' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public function handle_save_abilities(): void {
		$this->guard_action( 'quark_save_abilities' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$enabled = isset( $_POST['enabled_abilities'] ) && is_array( $_POST['enabled_abilities'] )
			? array_map( 'wp_unslash', $_POST['enabled_abilities'] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		( new AbilitiesRegistry() )->save_enabled_ids( $enabled );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'quark',
					'abilities_saved' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public function handle_revoke_session(): void {
		$this->guard_action( 'quark_revoke_session' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		if ( $session_id > 0 ) {
			( new AccessTokenRepository() )->revoke_session( $session_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'quark',
					'revoked' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	public function handle_revoke_all_sessions(): void {
		$this->guard_action( 'quark_revoke_all_sessions' );
		( new AccessTokenRepository() )->revoke_all();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'quark',
					'revoked_all' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	private function providers(): array {
		$mcp_url = Helpers::mcp_resource();
		return array_map(
			static function ( ProviderInterface $provider ) use ( $mcp_url ): array {
				return array(
					'id'               => $provider->id(),
					'label'            => $provider->label(),
					'description'      => $provider->description(),
					'primaryActionUrl' => $provider->primary_action_url(),
					'setupSteps'       => $provider->setup_steps( $mcp_url ),
					'copyFields'       => $provider->copy_fields( $mcp_url ),
				);
			},
			array(
				new ClaudeProvider(),
				new ChatGPTProvider(),
			)
		);
	}

	private function status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['advanced_saved'] ) ) {
			return 'advanced_saved';
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

	private function load_changelog(): array {
		$file = QUARK_PLUGIN_DIR . 'changelog.json';
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

	private function remove_data_on_uninstall_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_REMOVE_DATA_ON_UNINSTALL, '0' );
	}

	private function is_oauth_consent_view(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing flag for the settings page.
		return isset( $_GET['view'] ) && 'oauth-consent' === sanitize_key( wp_unslash( (string) $_GET['view'] ) );
	}

	private function guard_action( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'quark' ) );
		}

		check_admin_referer( $nonce_action );
	}
}

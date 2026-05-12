<?php

declare(strict_types=1);

namespace Quark\Admin;

use Quark\Connectors\Helpers;
use Quark\Connectors\MCP\AbilitiesRegistry;
use Quark\Connectors\OAuth\AuthorizationController;
use Quark\Connectors\OAuth\Repositories\AccessTokenRepository;
use Quark\Connectors\Providers\ChatGPT\Provider as ChatGPTProvider;
use Quark\Connectors\Providers\Claude\Provider as ClaudeProvider;
use Quark\Connectors\Providers\ProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page controller for connector setup and session management.
 */
final class SettingsPage {

	private const ASSET_HANDLE = 'quark-settings-app';
	private const STYLE_HANDLE = 'quark-settings-style';

	/**
	 * Register the settings page and page-specific assets.
	 */
	public function register(): void {
		add_options_page( 'Connect your AI assistant', 'Quark', 'manage_options', 'quark', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Render the settings page shell or the OAuth consent screen.
	 */
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

	/**
	 * Enqueue settings-page assets and hydrate the React application.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
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
				'version'          => QUARK_VERSION,
				'isConnected'      => ( new AccessTokenRepository() )->has_active_tokens(),
				'mcpUrl'           => Helpers::mcp_resource(),
				'providers'        => $this->providers(),
				'sessions'         => ( new AccessTokenRepository() )->list_active_sessions(),
				'abilities'        => ( new AbilitiesRegistry() )->public_definitions(),
				'enabledAbilities' => ( new AbilitiesRegistry() )->enabled_ids(),
				'status'           => $this->status(),
				'actions'          => array(
					'adminPostUrl'        => admin_url( 'admin-post.php' ),
					'saveAbilitiesAction' => 'quark_save_abilities',
					'revokeSessionAction' => 'quark_revoke_session',
					'revokeAllAction'     => 'quark_revoke_all_sessions',
					'saveAbilitiesNonce'  => wp_create_nonce( 'quark_save_abilities' ),
					'revokeSessionNonce'  => wp_create_nonce( 'quark_revoke_session' ),
					'revokeAllNonce'      => wp_create_nonce( 'quark_revoke_all_sessions' ),
				),
				'changelog'        => $this->load_changelog(),
			)
		);
	}

	/**
	 * Persist enabled MCP abilities from the admin form.
	 */
	public function handle_save_abilities(): void {
		$this->guard_action( 'quark_save_abilities' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$enabled = isset( $_POST['enabled_abilities'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['enabled_abilities'] ) )
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

	/**
	 * Revoke a single active connector session.
	 */
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

	/**
	 * Revoke every active connector session.
	 */
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
	 * Return admin status flags from the current request.
	 */
	private function status(): string {
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
			wp_die( esc_html__( 'Insufficient permissions.', 'quark' ) );
		}

		check_admin_referer( $nonce_action );
	}
}

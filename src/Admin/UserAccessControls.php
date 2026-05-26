<?php
/**
 * Per-user AI access controls for wp-admin Users screens.
 *
 * @package Aculect\AICompanion\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Activity\ActivityLogger;
use Aculect\AICompanion\Connectors\MCP\UserAccessControl;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Adds administrator controls for pausing and revoking one user's AI access.
 */
final class UserAccessControls {

	private const STATUS_QUERY_VAR = 'aculect_ai_companion_user_access';
	private const USER_QUERY_VAR   = 'aculect_ai_companion_user_id';

	/**
	 * Cached active session counts keyed by user ID.
	 *
	 * @var array<int, int>|null
	 */
	private ?array $active_session_counts = null;

	/**
	 * Register admin hooks.
	 */
	public function register(): void {
		add_filter( 'manage_users_columns', array( $this, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_column' ), 10, 3 );
		add_filter( 'user_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'show_user_profile', array( $this, 'profile_section' ) );
		add_action( 'edit_user_profile', array( $this, 'profile_section' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_post_aculect_ai_companion_set_user_access', array( $this, 'handle_set_user_access' ) );
		add_action( 'admin_post_aculect_ai_companion_revoke_user_access', array( $this, 'handle_revoke_user_access' ) );
	}

	/**
	 * Add the AI access status column.
	 *
	 * @param array<string, string> $columns Users table columns.
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$columns['aculect_ai_companion_access'] = __( 'AI access', 'aculect-ai-companion' );

		return $columns;
	}

	/**
	 * Render the AI access status column.
	 *
	 * @param string $output      Existing column output.
	 * @param string $column_name Column name.
	 * @param int    $user_id     WordPress user ID.
	 */
	public function render_column( string $output, string $column_name, int $user_id ): string {
		if ( 'aculect_ai_companion_access' !== $column_name ) {
			return $output;
		}

		return $this->status_html( $user_id );
	}

	/**
	 * Add row actions to pause/resume or revoke one user's access.
	 *
	 * @param array<string, string> $actions Existing row actions.
	 * @param \WP_User              $user    User object.
	 * @return array<string, string>
	 */
	public function row_actions( array $actions, \WP_User $user ): array {
		$user_id = absint( $user->ID );
		if ( ! $this->can_manage_user_access( $user_id ) ) {
			return $actions;
		}

		$paused = UserAccessControl::is_paused( $user_id );
		$actions['aculect_ai_companion_set_user_access'] = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $this->set_user_access_url( $user_id, ! $paused ) ),
			esc_html( $paused ? __( 'Resume AI access', 'aculect-ai-companion' ) : __( 'Pause AI access', 'aculect-ai-companion' ) )
		);

		if ( $this->active_session_count( $user_id ) > 0 ) {
			$actions['aculect_ai_companion_revoke_user_access'] = sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $this->revoke_user_access_url( $user_id ) ),
				esc_html__( 'Revoke AI sessions', 'aculect-ai-companion' )
			);
		}

		return $actions;
	}

	/**
	 * Render controls on user profile screens.
	 *
	 * @param \WP_User $user User object.
	 */
	public function profile_section( \WP_User $user ): void {
		$user_id = absint( $user->ID );
		if ( ! $this->can_manage_user_access( $user_id ) ) {
			return;
		}

		$paused       = UserAccessControl::is_paused( $user_id );
		$active_count = $this->active_session_count( $user_id );

		echo '<h2>' . esc_html__( 'Aculect AI Companion', 'aculect-ai-companion' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'AI assistant access', 'aculect-ai-companion' ) . '</th>';
		echo '<td>';
		echo wp_kses_post( $this->status_html( $user_id ) );
		echo '<p class="description">' . esc_html__( 'Pause keeps existing connections but blocks MCP actions. Revoke invalidates active AI assistant sessions and requires reconnecting.', 'aculect-ai-companion' ) . '</p>';
		echo '<p>';
		printf(
			'<a class="button" href="%1$s">%2$s</a> ',
			esc_url( $this->set_user_access_url( $user_id, ! $paused ) ),
			esc_html( $paused ? __( 'Resume AI Access', 'aculect-ai-companion' ) : __( 'Pause AI Access', 'aculect-ai-companion' ) )
		);

		if ( $active_count > 0 ) {
			printf(
				'<a class="button button-secondary" href="%1$s">%2$s</a>',
				esc_url( $this->revoke_user_access_url( $user_id ) ),
				esc_html__( 'Revoke AI Sessions', 'aculect-ai-companion' )
			);
		}

		echo '</p>';
		echo '</td></tr></tbody></table>';
	}

	/**
	 * Persist one user's paused access state.
	 */
	public function handle_set_user_access(): void {
		$user_id = $this->request_user_id();
		$this->guard_action( 'aculect_ai_companion_set_user_access_' . $user_id, $user_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- guard_action() verifies the nonce before this read.
		$paused = isset( $_GET['paused'] ) && '1' === sanitize_key( wp_unslash( (string) $_GET['paused'] ) );

		UserAccessControl::set_paused( $user_id, $paused );

		( new ActivityLogger() )->record_user_access_event(
			$paused ? 'user_access.pause' : 'user_access.resume',
			$user_id,
			get_current_user_id(),
			$paused ? __( 'AI access paused for user.', 'aculect-ai-companion' ) : __( 'AI access resumed for user.', 'aculect-ai-companion' )
		);

		$this->redirect_with_status( $paused ? 'paused' : 'resumed', $user_id );
	}

	/**
	 * Revoke all active AI assistant sessions for one user.
	 */
	public function handle_revoke_user_access(): void {
		$user_id = $this->request_user_id();
		$this->guard_action( 'aculect_ai_companion_revoke_user_access_' . $user_id, $user_id );

		$revoked = ( new AccessTokenRepository() )->revoke_user( $user_id );

		( new ActivityLogger() )->record_user_access_event(
			'user_access.revoke',
			$user_id,
			get_current_user_id(),
			__( 'AI assistant sessions revoked for user.', 'aculect-ai-companion' ),
			array( 'revoked_sessions' => $revoked )
		);

		$this->redirect_with_status( 'revoked', $user_id );
	}

	/**
	 * Show admin feedback after a user-level access action.
	 */
	public function admin_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flags.
		$status  = isset( $_GET[ self::STATUS_QUERY_VAR ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::STATUS_QUERY_VAR ] ) ) : '';
		$user_id = isset( $_GET[ self::USER_QUERY_VAR ] ) ? absint( wp_unslash( (string) $_GET[ self::USER_QUERY_VAR ] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $status || $user_id <= 0 ) {
			return;
		}

		$user = get_userdata( $user_id );
		$name = $user && ! empty( $user->display_name ) ? $user->display_name : __( 'the selected user', 'aculect-ai-companion' );

		$message = match ( $status ) {
			'paused' => sprintf(
				/* translators: %s: User display name. */
				__( 'AI access paused for %s.', 'aculect-ai-companion' ),
				$name
			),
			'resumed' => sprintf(
				/* translators: %s: User display name. */
				__( 'AI access resumed for %s.', 'aculect-ai-companion' ),
				$name
			),
			'revoked' => sprintf(
				/* translators: %s: User display name. */
				__( 'AI assistant sessions revoked for %s.', 'aculect-ai-companion' ),
				$name
			),
			default => '',
		};

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Return admin-safe status markup for one user.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private function status_html( int $user_id ): string {
		$active_count = $this->active_session_count( $user_id );
		if ( UserAccessControl::is_paused( $user_id ) ) {
			return sprintf(
				'<strong>%1$s</strong><br><span class="description">%2$s</span>',
				esc_html__( 'Paused', 'aculect-ai-companion' ),
				esc_html__( 'Connections preserved; MCP actions blocked.', 'aculect-ai-companion' )
			);
		}

		if ( $active_count > 0 ) {
			return sprintf(
				'<strong>%1$s</strong><br><span class="description">%2$s</span>',
				esc_html__( 'Active', 'aculect-ai-companion' ),
				esc_html(
					sprintf(
						/* translators: %s: Active AI assistant session count. */
						_n( '%s active AI session.', '%s active AI sessions.', $active_count, 'aculect-ai-companion' ),
						number_format_i18n( $active_count )
					)
				)
			);
		}

		return sprintf(
			'<span class="description">%s</span>',
			esc_html__( 'No active AI sessions.', 'aculect-ai-companion' )
		);
	}

	/**
	 * Build a pause/resume URL.
	 *
	 * @param int  $user_id WordPress user ID.
	 * @param bool $paused  Desired paused state.
	 */
	private function set_user_access_url( int $user_id, bool $paused ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'aculect_ai_companion_set_user_access',
					'user_id' => $user_id,
					'paused'  => $paused ? '1' : '0',
				),
				admin_url( 'admin-post.php' )
			),
			'aculect_ai_companion_set_user_access_' . $user_id
		);
	}

	/**
	 * Build a user-token revocation URL.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private function revoke_user_access_url( int $user_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'aculect_ai_companion_revoke_user_access',
					'user_id' => $user_id,
				),
				admin_url( 'admin-post.php' )
			),
			'aculect_ai_companion_revoke_user_access_' . $user_id
		);
	}

	/**
	 * Return cached active session count for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private function active_session_count( int $user_id ): int {
		if ( null === $this->active_session_counts ) {
			$this->active_session_counts = ( new AccessTokenRepository() )->active_session_counts_by_user();
		}

		return $this->active_session_counts[ $user_id ] ?? 0;
	}

	/**
	 * Validate the current admin action.
	 *
	 * @param string $nonce_action Nonce action.
	 * @param int    $user_id      Target user ID.
	 */
	private function guard_action( string $nonce_action, int $user_id ): void {
		check_admin_referer( $nonce_action );

		if ( ! $this->can_manage_user_access( $user_id ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'aculect-ai-companion' ) );
		}
	}

	/**
	 * Determine whether the current user may manage a target user's AI access.
	 *
	 * @param int $user_id Target user ID.
	 */
	private function can_manage_user_access( int $user_id ): bool {
		return $user_id > 0 && current_user_can( 'manage_options' ) && current_user_can( 'edit_user', $user_id );
	}

	/**
	 * Read the target user ID from the current admin request.
	 */
	private function request_user_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Action-specific nonce is checked after resolving the target user ID.
		return isset( $_GET['user_id'] ) ? absint( wp_unslash( (string) $_GET['user_id'] ) ) : 0;
	}

	/**
	 * Redirect back to the Users screen with a status notice.
	 *
	 * @param string $status  Notice status.
	 * @param int    $user_id Target user ID.
	 */
	private function redirect_with_status( string $status, int $user_id ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					self::STATUS_QUERY_VAR => $status,
					self::USER_QUERY_VAR   => $user_id,
				),
				admin_url( 'users.php' )
			)
		);
		exit;
	}
}

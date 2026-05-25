<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Connectors\Helpers;

/**
 * Optional role-aware connection entry points for non-admin users.
 */
final class RoleConnectionEntryPoint {

	public const OPTION_ENABLED = 'aculect_ai_companion_role_connections_enabled';
	public const OPTION_ROLES   = 'aculect_ai_companion_role_connection_roles';

	/**
	 * Register shortcode and block renderers.
	 */
	public function register(): void {
		add_shortcode( 'aculect_ai_companion_connect', array( $this, 'render_shortcode' ) );

		if ( function_exists( 'register_block_type' ) ) {
			register_block_type(
				'aculect/ai-companion-connect',
				array(
					'api_version'     => '3',
					'title'           => 'AI Companion Connection',
					'category'        => 'widgets',
					'render_callback' => array( $this, 'render' ),
				)
			);
		}
	}

	/**
	 * Render shortcode output.
	 *
	 * @return string
	 */
	public function render_shortcode(): string {
		return $this->render();
	}

	/**
	 * Render a connection entry point for the current user.
	 */
	public function render(): string {
		if ( ! self::is_enabled() ) {
			return '';
		}

		if ( ! is_user_logged_in() ) {
			return '<div class="aculect-ai-companion-connect-entry">' . esc_html__( 'Log in to connect your AI assistant.', 'aculect-ai-companion' ) . '</div>';
		}

		if ( ! self::current_user_allowed() ) {
			return '<div class="aculect-ai-companion-connect-entry">' . esc_html__( 'Your account is not allowed to connect an AI assistant on this site.', 'aculect-ai-companion' ) . '</div>';
		}

		$url = Helpers::mcp_resource();

		return sprintf(
			'<div class="aculect-ai-companion-connect-entry"><label>%1$s</label><input type="text" readonly value="%2$s" aria-label="%1$s" /></div>',
			esc_attr__( 'AI Companion connection URL', 'aculect-ai-companion' ),
			esc_attr( $url )
		);
	}

	/**
	 * Return whether role-aware entry points are enabled.
	 */
	public static function is_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_ENABLED, '0' );
	}

	/**
	 * Persist enabled state and allowed roles.
	 *
	 * @param bool         $enabled Enabled state.
	 * @param array<mixed> $roles   Role slugs.
	 */
	public static function save( bool $enabled, array $roles ): void {
		update_option( self::OPTION_ENABLED, $enabled ? '1' : '0', false );
		update_option( self::OPTION_ROLES, self::sanitize_roles( $roles ), false );
	}

	/**
	 * Delete stored settings.
	 */
	public static function delete(): void {
		delete_option( self::OPTION_ENABLED );
		delete_option( self::OPTION_ROLES );
	}

	/**
	 * Return allowed role slugs.
	 *
	 * @return list<string>
	 */
	public static function allowed_roles(): array {
		$roles = get_option( self::OPTION_ROLES, array( 'administrator' ) );
		return is_array( $roles ) ? self::sanitize_roles( $roles ) : array( 'administrator' );
	}

	/**
	 * Return selectable WordPress roles.
	 *
	 * @return list<array<string, string>>
	 */
	public static function role_options(): array {
		$wp_roles = wp_roles();
		$options  = array();
		foreach ( $wp_roles->roles as $slug => $role ) {
			$options[] = array(
				'id'    => sanitize_key( (string) $slug ),
				'label' => translate_user_role( (string) ( $role['name'] ?? $slug ) ),
			);
		}

		return $options;
	}

	/**
	 * Check whether the current logged-in user's role is allowed.
	 */
	public static function current_user_allowed(): bool {
		$user = wp_get_current_user();
		if ( ! $user instanceof \WP_User || 0 === (int) $user->ID ) {
			return false;
		}

		return array() !== array_intersect( self::allowed_roles(), array_map( 'strval', (array) $user->roles ) );
	}

	/**
	 * Sanitize role slugs against registered roles.
	 *
	 * @param array<mixed> $roles Raw roles.
	 * @return list<string>
	 */
	private static function sanitize_roles( array $roles ): array {
		$registered = array_keys( wp_roles()->roles );
		$roles      = array_filter( array_map( 'sanitize_key', array_map( 'strval', $roles ) ) );
		$roles      = array_values( array_intersect( $roles, $registered ) );

		return array() === $roles ? array( 'administrator' ) : array_values( array_unique( $roles ) );
	}
}

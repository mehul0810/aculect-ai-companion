<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Preserve the assistant and native dependency constraints during activation.
 */
final class ExtensionActivationGuard {

	/**
	 * Return a bounded blocker without exposing plugin exception messages.
	 *
	 * @param string $plugin Installed plugin basename.
	 * @param string $operation Activate or deactivate.
	 * @return array<string,string>|null
	 */
	public static function plugin( string $plugin, string $operation ): ?array {
		if ( 'deactivate' === $operation && defined( 'ACULECT_AI_COMPANION_PLUGIN_FILE' ) && plugin_basename( ACULECT_AI_COMPANION_PLUGIN_FILE ) === $plugin ) {
			return self::error( 'protected_plugin', 'Deactivate Aculect manually in WordPress; this would terminate the assistant connection.' );
		}
		if ( 'activate' === $operation && function_exists( 'validate_plugin_requirements' ) && is_wp_error( validate_plugin_requirements( $plugin ) ) ) {
			return self::error( 'incompatible_plugin', 'The plugin does not meet native WordPress or PHP requirements.' );
		}
		if ( class_exists( '\WP_Plugin_Dependencies' ) ) {
			\WP_Plugin_Dependencies::initialize();
			$blocked = 'activate' === $operation
				? \WP_Plugin_Dependencies::has_unmet_dependencies( $plugin ) || \WP_Plugin_Dependencies::has_circular_dependency( $plugin )
				: \WP_Plugin_Dependencies::has_active_dependents( $plugin );
			if ( $blocked ) {
				return self::error( 'plugin_dependency_blocked', 'Resolve native plugin dependencies before changing activation.' );
			}
		}
		return null;
	}

	/**
	 * Check theme validity and network availability before switching.
	 *
	 * @param string $stylesheet Installed theme slug.
	 * @return array<string,string>|null
	 */
	public static function theme( string $stylesheet ): ?array {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() || $theme->errors() || ( is_multisite() && ! $theme->is_allowed() ) ) {
			return self::error( 'theme_unavailable', 'The theme is missing, invalid, or not allowed on this site.' );
		}
		$php = $theme->get( 'RequiresPHP' );
		$wp  = $theme->get( 'RequiresWP' );
		if ( ( '' !== $php && version_compare( PHP_VERSION, $php, '<' ) ) || ( '' !== $wp && version_compare( get_bloginfo( 'version' ), $wp, '<' ) ) ) {
			return self::error( 'incompatible_theme', 'The theme does not meet native WordPress or PHP requirements.' );
		}
		return null;
	}

	/**
	 * Construct a deterministic safe failure.
	 *
	 * @param string $code Error code.
	 * @param string $message Public message.
	 * @return array<string,string>
	 */
	private static function error( string $code, string $message ): array {
		return array(
			'status'  => 'error',
			'error'   => $code,
			'message' => $message,
		);
	}
}

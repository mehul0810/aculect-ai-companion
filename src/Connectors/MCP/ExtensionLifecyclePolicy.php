<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Shared lifecycle authorization and confirmation classification.
 */
final class ExtensionLifecyclePolicy {

	/**
	 * Return capabilities for lifecycle tools, or null for other domains.
	 *
	 * @param string $tool Internal tool ID.
	 * @return list<string>|null
	 */
	public static function capabilities( string $tool ): ?array {
		return match ( $tool ) {
			'plugin_lifecycle.list_plugins', 'plugin_lifecycle.get_plugin', 'plugin_lifecycle.activate_plugin', 'plugin_lifecycle.deactivate_plugin' => array( 'activate_plugins' ),
			'plugin_lifecycle.search_plugins', 'plugin_lifecycle.install_plugin' => array( 'install_plugins' ),
			'plugin_lifecycle.update_plugin' => array( 'update_plugins' ),
			'plugin_lifecycle.delete_plugin' => array( 'delete_plugins' ),
			'plugin_lifecycle.upload_plugin' => array( 'upload_plugins', 'install_plugins' ),
			'theme_lifecycle.list_themes', 'theme_lifecycle.get_theme', 'theme_lifecycle.switch_theme' => array( 'switch_themes' ),
			'theme_lifecycle.search_themes', 'theme_lifecycle.install_theme' => array( 'install_themes' ),
			'theme_lifecycle.update_theme' => array( 'update_themes' ),
			'theme_lifecycle.delete_theme' => array( 'delete_themes' ),
			'theme_lifecycle.upload_theme' => array( 'upload_themes', 'install_themes' ),
			default => null,
		};
	}

	/**
	 * File mutations must bind the package or deletion state to the preview.
	 *
	 * @param string $tool Internal tool ID.
	 */
	public static function requires_binding( string $tool ): bool {
		return in_array( $tool, array( 'plugin_lifecycle.install_plugin', 'plugin_lifecycle.update_plugin', 'plugin_lifecycle.delete_plugin', 'theme_lifecycle.install_theme', 'theme_lifecycle.update_theme', 'theme_lifecycle.delete_theme' ), true );
	}

	/**
	 * Lifecycle changes never use trusted-connection confirmation bypasses.
	 *
	 * @param string $tool Internal tool ID.
	 */
	public static function requires_confirmation( string $tool ): bool {
		return self::requires_binding( $tool ) || in_array( $tool, array( 'plugin_lifecycle.activate_plugin', 'plugin_lifecycle.deactivate_plugin', 'theme_lifecycle.switch_theme' ), true );
	}

	/**
	 * Classify extension operations independently of configurable groups.
	 *
	 * @param string $tool Internal tool ID.
	 */
	public static function risk( string $tool ): ?string {
		if ( in_array( $tool, array( 'plugin_lifecycle.delete_plugin', 'theme_lifecycle.delete_theme' ), true ) ) {
			return 'destructive';
		}
		return self::requires_confirmation( $tool ) ? 'system' : null;
	}
}

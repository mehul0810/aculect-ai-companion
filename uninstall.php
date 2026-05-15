<?php
/**
 * Cleanup Aculect AI Companion data when explicitly enabled by the site owner.
 *
 * @package Aculect_AI_Companion
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( '1' !== (string) get_option( 'aculect_ai_companion_remove_data_on_uninstall', '0' ) ) {
	return;
}

$aculect_ai_companion_autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $aculect_ai_companion_autoload ) ) {
	require_once $aculect_ai_companion_autoload;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'Aculect\AICompanion\\';

			if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
			}

			$relative_class = substr( $class_name, strlen( $prefix ) );
			$file           = __DIR__ . '/src/' . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

\Aculect\AICompanion\Connectors\OAuth\Database\Installer::uninstall();
\Aculect\AICompanion\Connectors\OAuth\Server\KeyManager::delete_keys();

delete_option( 'aculect_ai_companion_remove_data_on_uninstall' );
delete_option( 'aculect_ai_companion_rewrite_version' );
delete_option( 'aculect_ai_companion_chatgpt_connection_state' );
delete_option( 'aculect_ai_companion_enabled_abilities' );

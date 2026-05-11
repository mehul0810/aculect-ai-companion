<?php
/**
 * Cleanup Quark data when explicitly enabled by the site owner.
 *
 * @package Quark
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( '1' !== (string) get_option( 'quark_remove_data_on_uninstall', '0' ) ) {
	return;
}

$autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $autoload ) ) {
	require_once $autoload;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'Quark\\';

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

\Quark\Connectors\OAuth\Database\Installer::uninstall();
\Quark\Connectors\OAuth\Server\KeyManager::delete_keys();

delete_option( 'quark_remove_data_on_uninstall' );
delete_option( 'quark_rewrite_version' );
delete_option( 'quark_chatgpt_connection_state' );

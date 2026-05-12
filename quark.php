<?php
/**
 * Plugin Name: Quark
 * Plugin URI: https://github.com/mehul0810/quark
 * Description: Your AI assistant for WordPress. Connect WordPress to ChatGPT and manage your site using AI.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author: Mehul Gohil
 * Author URI: https://mehulgohil.com
 * Text Domain: quark
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Quark
 */

declare(strict_types=1);

namespace Quark;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'QUARK_VERSION', '0.1.0' );
define( 'QUARK_PLUGIN_FILE', __FILE__ );
define( 'QUARK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'QUARK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$quark_autoload = QUARK_PLUGIN_DIR . 'vendor/autoload.php';

if ( file_exists( $quark_autoload ) ) {
	require_once $quark_autoload;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = __NAMESPACE__ . '\\';

			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}

			$relative_class = substr( $class_name, strlen( $prefix ) );
			$file           = QUARK_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);

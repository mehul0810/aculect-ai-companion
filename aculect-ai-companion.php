<?php
/**
 * Plugin Name: Aculect AI Companion
 * Plugin URI: https://aculect.com
 * Description: Connect WordPress with AI. Aculect AI Companion helps you manage content, comments, media, and more with your AI assistant.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Author: Mehul Gohil
 * Author URI: https://mehulgohil.com
 * Text Domain: aculect-ai-companion
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Aculect_AI_Companion
 */

declare(strict_types=1);

namespace Aculect\AICompanion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACULECT_AI_COMPANION_VERSION', '0.1.0' );
define( 'ACULECT_AI_COMPANION_PLUGIN_FILE', __FILE__ );
define( 'ACULECT_AI_COMPANION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACULECT_AI_COMPANION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$aculect_ai_companion_autoload = ACULECT_AI_COMPANION_PLUGIN_DIR . 'vendor/autoload.php';

if ( file_exists( $aculect_ai_companion_autoload ) ) {
	require_once $aculect_ai_companion_autoload;
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = __NAMESPACE__ . '\\';

			if ( 0 !== strpos( $class_name, $prefix ) ) {
				return;
			}

			$relative_class = substr( $class_name, strlen( $prefix ) );
			$file           = ACULECT_AI_COMPANION_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

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

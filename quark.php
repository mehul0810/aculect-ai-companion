<?php
/**
 * Plugin Name: Quark
 * Plugin URI: https://github.com/mehul0810/quark
 * Description: Your AI assistant for WordPress. Connect WordPress to ChatGPT and manage your site using AI.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.0
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

if (! defined('ABSPATH')) {
    exit;
}

define('QUARK_VERSION', '0.1.0');
define('QUARK_PLUGIN_FILE', __FILE__);
define('QUARK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('QUARK_PLUGIN_URL', plugin_dir_url(__FILE__));

$autoload = QUARK_PLUGIN_DIR . 'vendor/autoload.php';

if (file_exists($autoload)) {
    require_once $autoload;
}

if (! class_exists(Plugin::class)) {
    return;
}

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});

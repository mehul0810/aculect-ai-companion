<?php
/**
 * PHPStan bootstrap constants for static analysis.
 *
 * @package Quark
 */

declare(strict_types=1);

defined('QUARK_VERSION') || define('QUARK_VERSION', '0.1.0');
defined('QUARK_PLUGIN_FILE') || define('QUARK_PLUGIN_FILE', __DIR__ . '/quark.php');
defined('QUARK_PLUGIN_DIR') || define('QUARK_PLUGIN_DIR', __DIR__ . '/');
defined('QUARK_PLUGIN_URL') || define('QUARK_PLUGIN_URL', 'https://example.com/wp-content/plugins/quark/');
defined('ABSPATH') || define('ABSPATH', dirname(__DIR__, 3) . '/');
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);

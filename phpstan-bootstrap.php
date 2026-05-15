<?php
/**
 * PHPStan bootstrap constants for static analysis.
 *
 * @package Aculect_AI_Companion
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) && 'cli' !== PHP_SAPI ) {
	exit;
}

defined( 'ACULECT_AI_COMPANION_VERSION' ) || define( 'ACULECT_AI_COMPANION_VERSION', '0.1.0' );
defined( 'ACULECT_AI_COMPANION_PLUGIN_FILE' ) || define( 'ACULECT_AI_COMPANION_PLUGIN_FILE', __DIR__ . '/aculect-ai-companion.php' );
defined( 'ACULECT_AI_COMPANION_PLUGIN_DIR' ) || define( 'ACULECT_AI_COMPANION_PLUGIN_DIR', __DIR__ . '/' );
defined( 'ACULECT_AI_COMPANION_PLUGIN_URL' ) || define( 'ACULECT_AI_COMPANION_PLUGIN_URL', 'https://example.com/wp-content/plugins/aculect-ai-companion/' );
defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

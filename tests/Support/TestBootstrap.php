<?php
/**
 * Shared unit-test bootstrap fixtures.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/InMemoryExecutionClaimStore.php';
require_once __DIR__ . '/WordPressWriteTestStubs.php';

if ( ! defined( 'ACULECT_AI_COMPANION_VERSION' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local plugin header in the unit-test bootstrap.
	$plugin_header  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/aculect-ai-companion.php' );
	$plugin_version = '0.0.0';
	if ( 1 === preg_match( '/^\s*\*\s*Version:\s*([^\r\n]+)$/mi', $plugin_header, $matches ) ) {
		$plugin_version = trim( (string) $matches[1] );
	}

	define( 'ACULECT_AI_COMPANION_VERSION', '' !== $plugin_version ? $plugin_version : '0.0.0' );
}

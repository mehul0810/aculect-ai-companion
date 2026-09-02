<?php
/**
 * Shared unit-test bootstrap fixtures.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/InMemoryExecutionClaimStore.php';
require_once __DIR__ . '/WordPressWriteTestStubs.php';

if ( ! function_exists( 'is_main_site' ) ) {
	/**
	 * Return whether the current test site is the network main site.
	 *
	 * @param mixed $site_id Optional site ID, ignored by the lightweight stub.
	 */
	function is_main_site( mixed $site_id = null ): bool {
		unset( $site_id );

		return (bool) ( $GLOBALS['aculect_ai_companion_test_is_main_site'] ?? true );
	}
}

if ( ! function_exists( 'get_site' ) ) {
	/**
	 * Return the current test site's minimal network record.
	 *
	 * @param mixed $site_id Optional site ID, ignored by the lightweight stub.
	 * @param mixed $fields   Requested fields, ignored by the lightweight stub.
	 * @return object|null
	 */
	function get_site( mixed $site_id = null, mixed $fields = null ): ?object {
		unset( $site_id, $fields );

		$site = $GLOBALS['aculect_ai_companion_test_site'] ?? (object) array( 'deleted' => '0' );

		return is_object( $site ) ? $site : null;
	}
}

if ( ! defined( 'ACULECT_AI_COMPANION_VERSION' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the local plugin header in the unit-test bootstrap.
	$plugin_header  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/aculect-ai-companion.php' );
	$plugin_version = '0.0.0';
	if ( 1 === preg_match( '/^\s*\*\s*Version:\s*([^\r\n]+)$/mi', $plugin_header, $matches ) ) {
		$plugin_version = trim( (string) $matches[1] );
	}

	define( 'ACULECT_AI_COMPANION_VERSION', '' !== $plugin_version ? $plugin_version : '0.0.0' );
}

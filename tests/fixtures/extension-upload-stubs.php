<?php
/**
 * HTTPS fixture for native upload handoff contracts.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

if ( ! function_exists( 'is_ssl' ) ) {
	/** Return fixture HTTPS state. */
	function is_ssl(): bool {
		return (bool) ( $GLOBALS['aculect_extension_test_https'] ?? false );
	}
}

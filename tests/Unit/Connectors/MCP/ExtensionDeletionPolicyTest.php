<?php
/**
 * Theme root resolution regressions without invoking deletion.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ExtensionDeletionPolicy;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/site-workflow-stubs.php';
require_once dirname( __DIR__, 3 ) . '/fixtures/theme-package-stubs.php';

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated empty temporary directories; never invokes a production delete API.

/** Prove inventory and native deletion cannot disagree about the theme root. */
final class ExtensionDeletionPolicyTest extends TestCase {

	public function test_secondary_root_is_rejected_and_root_changes_invalidate_confirmation(): void {
		$base      = sys_get_temp_dir() . '/aculect-theme-root-' . bin2hex( random_bytes( 8 ) );
		$default   = $base . '/default';
		$secondary = $base . '/secondary';
		mkdir( $default . '/duplicate', 0700, true );
		mkdir( $secondary . '/duplicate', 0700, true );
		$keys     = array( 'aculect_ai_companion_test_themes', 'aculect_ai_companion_test_active_stylesheet', 'aculect_ai_companion_test_theme_roots' );
		$previous = array();
		foreach ( $keys as $key ) {
			$previous[ $key ] = $GLOBALS[ $key ] ?? null;
		}
		try {
			$GLOBALS['aculect_ai_companion_test_active_stylesheet'] = 'active';
			$GLOBALS['aculect_ai_companion_test_themes']            = array(
				'active'    => array(
					'Name'       => 'Active',
					'Stylesheet' => 'active',
					'Template'   => 'active',
					'Version'    => '1.0',
				),
				'duplicate' => array(
					'Name'       => 'Duplicate',
					'Stylesheet' => 'duplicate',
					'Template'   => 'duplicate',
					'Version'    => '2.0',
				),
			);
			$GLOBALS['aculect_ai_companion_test_theme_roots']       = array(
				''          => $default,
				'duplicate' => $secondary,
			);
			$policy  = new ExtensionDeletionPolicy();
			$blocked = $policy->inspect( 'theme', 'duplicate' );
			self::assertSame( 'unsupported_theme_root', $blocked['error'] );
			self::assertDirectoryExists( $default . '/duplicate' );
			self::assertDirectoryExists( $secondary . '/duplicate' );
			$GLOBALS['aculect_ai_companion_test_theme_roots']['duplicate'] = $default;
			$before = $policy->inspect( 'theme', 'duplicate' );
			self::assertArrayHasKey( 'binding', $before );
			$GLOBALS['aculect_ai_companion_test_theme_roots'] = array(
				''          => $secondary,
				'duplicate' => $secondary,
			);
			$after = $policy->inspect( 'theme', 'duplicate' );
			self::assertArrayHasKey( 'binding', $after );
			self::assertFalse( $policy->binding_matches( $before['binding'], $after['binding'] ) );
		} finally {
			foreach ( $previous as $key => $value ) {
				if ( null === $value ) {
					unset( $GLOBALS[ $key ] );
				} else {
					$GLOBALS[ $key ] = $value;
				}
			}
			rmdir( $default . '/duplicate' );
			rmdir( $secondary . '/duplicate' );
			rmdir( $default );
			rmdir( $secondary );
			rmdir( $base );
		}
	}
}

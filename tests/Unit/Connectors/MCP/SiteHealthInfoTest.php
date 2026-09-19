<?php
/**
 * Safe native diagnostics projection tests.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\SiteHealthInfo;
use PHPUnit\Framework\TestCase;

/**
 * Never return private or unreviewed debug fields.
 */
final class SiteHealthInfoTest extends TestCase {

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		parent::tearDown();
	}

	public function test_denial_precedes_native_reader(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'view_site_health_checks' );
		$service = new SiteHealthInfo(
			static function () {
				self::fail( 'Unauthorized reader invoked.' );
			}
		);
		self::assertSame( array( 'error' => 'forbidden' ), $service->read( array( 'section' => 'wp-core' ) ) );
	}

	public function test_excludes_unreviewed_private_and_complex_fields(): void {
		$service = new SiteHealthInfo(
			static fn () => array(
				'wp-server' => array(
					'fields' => array(
						'php_version'    => array( 'value' => '8.2.0' ),
						'php_sapi'       => array(
							'value'   => 'private',
							'private' => true,
						),
						'memory_limit'   => array( 'value' => new \stdClass() ),
						'max_input_time' => array( 'value' => str_repeat( 'a', 257 ) ),
						'private_path'   => array( 'value' => '/private/secret' ),
					),
				),
			)
		);
		$result  = $service->read( array( 'section' => 'wp-server' ) );
		self::assertSame( array( 'php_version' => '8.2.0' ), $result['fields'] );
		self::assertFalse( $result['private_fields_included'] );
	}

	public function test_private_sections_and_log_paths_are_excluded(): void {
		foreach ( array( false, true ) as $private ) {
			$service = new SiteHealthInfo(
				static fn () => array(
					'wp-constants' => array(
						'private' => $private,
						'fields'  => array(
							'WP_DEBUG_LOG' => array( 'value' => '/private/log.txt' ),
							'WP_DEBUG'     => array( 'value' => true ),
						),
					),
				)
			);
			self::assertSame( $private ? array() : array( 'WP_DEBUG' => true ), $service->read( array( 'section' => 'wp-constants' ) )['fields'] );
		}
	}

	public function test_malformed_privacy_flags_fail_closed(): void {
		foreach ( array( null, 0, '', array() ) as $flag ) {
			$service = new SiteHealthInfo(
				static fn () => array(
					'wp-core' => array(
						'fields' => array(
							'version' => array(
								'value'   => 'must-not-leak',
								'private' => $flag,
							),
						),
					),
				)
			);
			self::assertSame( array(), $service->read( array( 'section' => 'wp-core' ) )['fields'] );
		}
	}

	public function test_invalid_native_data_and_exceptions_fail_without_payload(): void {
		foreach ( array( null, false, new \stdClass(), array(), array( 'wp-core' => null ) ) as $raw ) {
			self::assertSame( array( 'error' => 'native_info_unavailable' ), ( new SiteHealthInfo( static fn () => $raw ) )->read( array( 'section' => 'wp-core' ) ) );
		}
		$service = new SiteHealthInfo(
			static function () {
				throw new \RuntimeException( 'secret' );
			}
		);
		self::assertSame( array( 'error' => 'native_info_unavailable' ), $service->read( array( 'section' => 'wp-core' ) ) );
		self::assertSame( array( 'error' => 'invalid_section' ), $service->read( array( 'section' => array() ) ) );
	}
}

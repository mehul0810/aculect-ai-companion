<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Settings;

use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Settings\McpAppsSettings;
use PHPUnit\Framework\TestCase;

final class McpAppsSettingsTest extends TestCase {

	private array $previous_options;

	private array $previous_filters;

	protected function setUp(): void {
		$this->previous_options = $GLOBALS['aculect_ai_companion_test_options'];
		$this->previous_filters = $GLOBALS['aculect_ai_companion_test_filter_callbacks'] ?? array();
		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_options']          = $this->previous_options;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = $this->previous_filters;
	}

	public function test_default_and_malformed_options_never_enable_apps(): void {
		self::assertFalse( McpAppsNegotiation::enabled() );
		foreach ( array( '0', 'true', '', array( '1' ), null ) as $value ) {
			update_option( McpAppsSettings::OPTION_ENABLED, $value );
			self::assertFalse( McpAppsNegotiation::enabled() );
		}
	}

	public function test_saved_opt_in_still_requires_client_support_and_disables_immediately(): void {
		$request = array(
			'params' => array(
				'capabilities' => array(
					'extensions' => array( McpAppsNegotiation::EXTENSION => array( 'mimeTypes' => array( McpAppsNegotiation::MIME_TYPE ) ) ),
				),
			),
		);
		$auth = array( 'client_id' => 'settings-test', 'token_id' => 'synthetic', 'user_id' => 1 );

		McpAppsSettings::set_enabled( true );
		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'initialize', $request, '2025-11-25', $auth ) );
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'initialize', array(), '2025-11-25', $auth ) );
		self::assertArrayHasKey( 'ui', McpAppsNegotiation::tool_metadata( 'site.get_info', array(), 'Reading', 'Read', true ) );
		self::assertArrayNotHasKey( 'ui', McpAppsNegotiation::tool_metadata( 'site.get_info', array(), 'Reading', 'Read', false ) );

		McpAppsNegotiation::with_request_enabled(
			true,
			static function (): void {
				self::assertTrue( McpAppsNegotiation::request_enabled() );
				McpAppsSettings::set_enabled( false );
				self::assertFalse( McpAppsNegotiation::request_enabled() );
			}
		);
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'initialize', $request, '2025-11-25', $auth ) );
		self::assertSame( array(), McpAppsNegotiation::initialize_capabilities( array(), true ) );
	}

	public function test_filter_override_and_saved_preference_remain_distinct(): void {
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'][ McpAppsSettings::OPTION_ENABLED ] = static fn (): bool => true;
		self::assertSame( array( 'enabled' => false, 'effectiveEnabled' => true ), McpAppsSettings::admin_payload() );
		self::assertTrue( McpAppsNegotiation::enabled() );

		McpAppsSettings::set_enabled( true );
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'][ McpAppsSettings::OPTION_ENABLED ] = static fn (): bool => false;
		self::assertSame( array( 'enabled' => true, 'effectiveEnabled' => false ), McpAppsSettings::admin_payload() );
		self::assertFalse( McpAppsNegotiation::enabled() );

		$GLOBALS['aculect_ai_companion_test_filter_callbacks'][ McpAppsSettings::OPTION_ENABLED ] = static fn (): string => '1';
		self::assertFalse( McpAppsNegotiation::enabled() );
		unset( $GLOBALS['aculect_ai_companion_test_filter_callbacks'][ McpAppsSettings::OPTION_ENABLED ] );
		self::assertTrue( McpAppsNegotiation::enabled() );
		McpAppsSettings::delete();
		self::assertFalse( McpAppsNegotiation::enabled() );
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\McpProtocolVersion;
use Aculect\AICompanion\Connectors\MCP\McpRequestCapabilities;
use Aculect\AICompanion\Diagnostics\Logger;
use Aculect\AICompanion\Diagnostics\LogSettings;
use Aculect\AICompanion\Diagnostics\LogSinkInterface;
use Aculect\AICompanion\Settings\McpAppsSettings;
use PHPUnit\Framework\TestCase;

/**
 * Observability must not widen or confuse negotiation and authentication.
 */
final class McpRequestCapabilitiesTest extends TestCase {

	private array $options;
	private array $filters;

	protected function setUp(): void {
		$this->options                                = $GLOBALS['aculect_ai_companion_test_options'];
		$this->filters                                = $GLOBALS['aculect_ai_companion_test_filter_callbacks'] ?? array();
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_options']          = $this->options;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = $this->filters;
	}

	public function test_logging_is_opt_in_and_records_negotiation_without_credentials(): void {
		$sink    = new CapabilityLogSink();
		$logger  = new Logger( $sink );
		$request = new \WP_REST_Request();
		$auth    = array(
			'user_id'   => 7,
			'client_id' => 'private-client',
			'token_id'  => 'private-token',
		);
		$body    = array(
			'params' => array(
				'_meta'     => array(
					'io.modelcontextprotocol/clientCapabilities' => array(
						'extensions' => array(
							McpAppsNegotiation::EXTENSION => array( 'mimeTypes' => array( McpAppsNegotiation::MIME_TYPE ) ),
						),
					),
				),
				'arguments' => array( 'content' => 'private-body' ),
			),
		);
		McpAppsSettings::set_enabled( true );
		self::assertTrue( McpRequestCapabilities::apps_enabled( 'tools/call', $body, McpProtocolVersion::CURRENT, $auth, $request, $logger ) );
		self::assertSame( array(), $sink->entries );
		LogSettings::set_enabled( true );
		self::assertTrue( McpRequestCapabilities::apps_enabled( 'tools/call', $body, McpProtocolVersion::CURRENT, $auth, $request, $logger ) );
		self::assertTrue( $sink->entries[0]['context']['apps_negotiated'] );
		self::assertTrue( $sink->entries[0]['context']['skills_protocol_supported'] );
		self::assertFalse( $sink->entries[0]['context']['rendering_verified'] );
		self::assertStringNotContainsString( 'private-', (string) wp_json_encode( $sink->entries ) );
		self::assertFalse( McpRequestCapabilities::apps_enabled( 'tools/list', array(), McpProtocolVersion::CURRENT, $auth, $request, $logger ) );
		self::assertFalse( $sink->entries[1]['context']['apps_negotiated'] );
		McpAppsSettings::set_enabled( false );
		self::assertFalse( McpRequestCapabilities::apps_enabled( 'tools/list', $body, McpProtocolVersion::CURRENT, $auth, $request, $logger ) );
		self::assertFalse( $sink->entries[2]['context']['apps_enabled'] );
	}

	public function test_unverified_initialization_and_unauthenticated_requests_are_not_logged_as_negotiated(): void {
		$sink    = new CapabilityLogSink();
		$logger  = new Logger( $sink );
		$request = new \WP_REST_Request();
		LogSettings::set_enabled( true );
		McpRequestCapabilities::apps_enabled( 'initialize', array(), McpProtocolVersion::LEGACY, array( 'user_id' => 7 ), $request, $logger );
		McpRequestCapabilities::apps_enabled( 'tools/list', array(), McpProtocolVersion::CURRENT, array(), $request, $logger );
		self::assertSame( array(), $sink->entries );
	}
}

/**
 * Capture only the final sanitized logger entries.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Private log sink fixture for this test only.
final class CapabilityLogSink implements LogSinkInterface {

	/**
	 * Captured diagnostic entries.
	 *
	 * @var list<array<string,mixed>>
	 */
	public array $entries = array();

	public function insert( array $entry ): bool {
		$this->entries[] = $entry;
		return true;
	}

	public function prune( int $retention_days ): int {
		return 0;
	}
}

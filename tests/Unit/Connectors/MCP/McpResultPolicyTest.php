<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpProtocolVersion;
use Aculect\AICompanion\Connectors\MCP\McpResultPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Verifies dormant version-aware MCP result shaping.
 */
final class McpResultPolicyTest extends TestCase {

	public function test_legacy_results_are_returned_without_shape_changes(): void {
		$result = array(
			'tools' => array( array( 'name' => 'search' ) ),
			'_meta' => array( 'aculect/toolListFingerprint' => 'abc' ),
		);

		self::assertSame( $result, ( new McpResultPolicy() )->shape( McpProtocolVersion::LEGACY, 'tools/list', $result ) );
	}

	public function test_current_cacheable_results_use_conservative_cache_contracts(): void {
		$policy = new McpResultPolicy();

		self::assertSame(
			array(
				'resultType' => 'complete',
				'tools'      => array(),
				'ttlMs'      => 0,
				'cacheScope' => 'private',
			),
			$policy->shape( McpProtocolVersion::CURRENT, 'tools/list', array( 'tools' => array() ) )
		);
		self::assertSame( 'private', $policy->shape( McpProtocolVersion::CURRENT, 'resources/list', array( 'resources' => array() ) )['cacheScope'] );
		self::assertSame( 'public', $policy->shape( McpProtocolVersion::CURRENT, 'resources/list', array( 'resources' => array() ), true )['cacheScope'] );
		self::assertSame( 0, $policy->shape( McpProtocolVersion::CURRENT, 'server/discover', array() )['ttlMs'] );
		self::assertSame( 3600000, $policy->shape( McpProtocolVersion::CURRENT, 'server/discover', array(), true )['ttlMs'] );
	}

	public function test_current_non_cacheable_result_has_result_type_without_cache_claim(): void {
		$result = ( new McpResultPolicy() )->shape( McpProtocolVersion::CURRENT, 'tools/call', array( 'content' => array() ) );

		self::assertSame( 'complete', $result['resultType'] );
		self::assertArrayNotHasKey( 'ttlMs', $result );
		self::assertArrayNotHasKey( 'cacheScope', $result );
	}

	public function test_unknown_protocol_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new McpResultPolicy() )->shape( '2099-01-01', 'tools/list', array() );
	}
}

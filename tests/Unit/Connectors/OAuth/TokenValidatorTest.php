<?php
/**
 * Tests for OAuth bearer-token challenge headers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\TokenValidator;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * Verifies MCP auth challenge headers remain client-readable.
 */
final class TokenValidatorTest extends TestCase {

	public function test_www_authenticate_header_includes_resource_scope_and_error(): void {
		$header = TokenValidator::www_authenticate_header( 'content:draft', 'insufficient_scope' );

		self::assertStringStartsWith( 'Bearer ', $header );
		self::assertStringContainsString( 'resource_metadata="https://example.com/.well-known/oauth-protected-resource"', $header );
		self::assertStringContainsString( 'scope="content:draft"', $header );
		self::assertStringContainsString( 'error="insufficient_scope"', $header );
	}

	public function test_resource_mismatch_returns_a_fixed_non_secret_failure_category(): void {
		$validator = new TokenValidator();
		$request   = new WP_REST_Request(
			array( 'resource' => 'https://other.example/mcp' ),
			array(),
			array(),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);

		self::assertSame( array(), $validator->authenticate( $request ) );
		self::assertSame( 'resource_mismatch', $validator->failure_reason() );
	}
}

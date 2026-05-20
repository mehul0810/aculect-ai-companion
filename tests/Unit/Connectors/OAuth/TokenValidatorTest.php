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
}

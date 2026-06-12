<?php
/**
 * Tests for OAuth Dynamic Client Registration helpers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies DCR metadata is narrowed before storage.
 */
final class ClientRegistrationControllerTest extends TestCase {

	public function test_registration_permission_does_not_rate_limit_valid_dcr_retries(): void {
		$controller = new ClientRegistrationController();

		self::assertTrue( $controller->check_registration_permission() );
	}

	public function test_redirect_uris_are_validated_deduplicated_and_limited_to_safe_schemes(): void {
		$uris = $this->invokePrivate(
			new ClientRegistrationController(),
			'redirect_uris',
			array(
				array(
					'https://chatgpt.com/oauth/callback',
					'https://chatgpt.com/oauth/callback',
					'http://localhost/oauth/callback',
					'http://example.com/insecure',
					'https://example.com/with-fragment#token',
					array( 'not-scalar' ),
				),
			)
		);

		self::assertSame(
			array(
				'https://chatgpt.com/oauth/callback',
				'http://localhost/oauth/callback',
			),
			$uris
		);
	}

	public function test_raw_redirect_uris_keep_only_scalar_candidates_for_diagnostics(): void {
		$uris = $this->invokePrivate(
			new ClientRegistrationController(),
			'raw_redirect_uris',
			array(
				array(
					'https://chatgpt.com/oauth/callback',
					123,
					array( 'drop' ),
					false,
				),
			)
		);

		self::assertSame(
			array(
				'https://chatgpt.com/oauth/callback',
				'123',
			),
			$uris
		);
	}

	public function test_provider_attribution_identifies_common_ai_clients(): void {
		self::assertSame( 'chatgpt', Helpers::provider_from_client( 'ChatGPT Connector', array( 'https://chatgpt.com/oauth/callback' ) ) );
		self::assertSame( 'codex', Helpers::provider_from_client( 'Codex MCP Client', array( 'http://127.0.0.1:1455/callback' ) ) );
		self::assertSame( 'claude', Helpers::provider_from_client( 'Claude Desktop', array( 'http://localhost/callback' ) ) );
		self::assertSame( 'mcp', Helpers::provider_from_client( 'Local MCP Client', array( 'http://localhost/callback' ) ) );
		self::assertSame( 'mcp', Helpers::provider_from_client( 'Custom Client', array( 'https://example.org/callback' ) ) );
	}

	/**
	 * Invoke a private method for focused unit coverage.
	 *
	 * @param object            $object    Object instance.
	 * @param string            $method    Method name.
	 * @param array<int, mixed> $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}
}

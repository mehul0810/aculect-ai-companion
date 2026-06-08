<?php
/**
 * Tests for OAuth token endpoint helpers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\TokenController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Verifies token endpoint resource handling and error response shape.
 */
final class TokenControllerTest extends TestCase {

	public function test_resource_from_request_prefers_resource_then_audience_then_default(): void {
		$controller = new TokenController();

		self::assertSame(
			'https://client.example/resource',
			$this->invokePrivate(
				$controller,
				'resource_from_request',
				array( new WP_REST_Request( array( 'resource' => 'https://client.example/resource/' ) ) )
			)
		);

		self::assertSame(
			'https://client.example/audience',
			$this->invokePrivate(
				$controller,
				'resource_from_request',
				array( new WP_REST_Request( array( 'audience' => 'https://client.example/audience/' ) ) )
			)
		);

		self::assertSame(
			Helpers::mcp_resource(),
			$this->invokePrivate( $controller, 'resource_from_request', array( new WP_REST_Request() ) )
		);
	}

	public function test_error_response_uses_oauth_shape_and_no_store_headers(): void {
		$response = $this->invokePrivate(
			new TokenController(),
			'error',
			array( 'invalid_target', 'Resource mismatch.', 400 )
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 400, $response->get_status() );
		self::assertSame(
			array(
				'error'             => 'invalid_target',
				'error_description' => 'Resource mismatch.',
			),
			$response->get_data()
		);
		self::assertSame( 'no-store', $response->header( 'Cache-Control' ) );
		self::assertSame( 'no-cache', $response->header( 'Pragma' ) );
	}

	public function test_server_error_description_does_not_expose_exception_details(): void {
		$description = $this->invokePrivate( new TokenController(), 'server_error_description' );

		self::assertSame( 'The OAuth token request failed. Try again or reconnect the client.', $description );
		self::assertStringNotContainsString( 'SQL', $description );
		self::assertStringNotContainsString( 'Exception', $description );
	}

	/**
	 * Invoke a private method for focused unit coverage.
	 *
	 * @param object      $object    Object instance.
	 * @param string      $method    Method name.
	 * @param list<mixed> $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}
}

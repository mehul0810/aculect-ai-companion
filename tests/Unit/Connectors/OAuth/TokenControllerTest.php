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

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused token timeline tests replace wpdb with a local test double.

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

	public function test_token_timeline_event_classifies_exchange_and_refresh_grants(): void {
		$controller = new TokenController();

		self::assertSame(
			'token_exchange',
			$this->invokePrivate(
				$controller,
				'token_timeline_event',
				array( new WP_REST_Request( array( 'grant_type' => 'authorization_code' ) ) )
			)
		);
		self::assertSame(
			'token_refresh',
			$this->invokePrivate(
				$controller,
				'token_timeline_event',
				array( new WP_REST_Request( array( 'grant_type' => 'refresh_token' ) ) )
			)
		);
	}

	public function test_token_result_class_is_bounded_for_timeline_metadata(): void {
		$controller = new TokenController();

		self::assertSame( 'issued', $this->invokePrivate( $controller, 'token_result_class', array( 200 ) ) );
		self::assertSame( 'oauth_error', $this->invokePrivate( $controller, 'token_result_class', array( 400 ) ) );
		self::assertSame( 'server_error', $this->invokePrivate( $controller, 'token_result_class', array( 500 ) ) );
	}

	public function test_timeline_auth_context_uses_client_identifier_without_secret_material(): void {
		$context = $this->invokePrivate(
			new TokenController(),
			'timeline_auth_context',
			array(
				new WP_REST_Request(
					array(
						'client_id'     => '',
						'client_secret' => 'do-not-store',
						'refresh_token' => 'do-not-store',
					)
				),
			)
		);

		self::assertSame( '', $context['provider'] );
		self::assertSame( '', $context['client_id'] );
		self::assertArrayNotHasKey( 'client_secret', $context );
		self::assertArrayNotHasKey( 'refresh_token', $context );
	}

	public function test_invalid_grant_refresh_context_is_pre_auth_and_storage_backed(): void {
		$raw             = 'raw-refresh-token';
		$wpdb            = new FakeTokenTimelineWpdb(
			array(
				'revoked'       => '0',
				'expires_at'    => '2000-01-01 00:00:00',
				'connection_id' => '42',
				'client_id'     => 'stored-codex-client',
				'provider'      => 'codex',
			)
		);
		$GLOBALS['wpdb'] = $wpdb;
		$controller      = new TokenController();
		$request         = new WP_REST_Request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $raw,
			)
		);

		$context      = $this->invokePrivate( $controller, 'refresh_rejection_context', array( $request, 'invalid_grant' ) );
		$auth         = $this->invokePrivate(
			$controller,
			'correlate_timeline_auth',
			array(
				array(
					'provider'  => '',
					'client_id' => '',
				),
				$context,
			)
		);
		$request_auth = $this->invokePrivate(
			$controller,
			'correlate_timeline_auth',
			array(
				array(
					'provider'  => 'chatgpt',
					'client_id' => 'request-client',
				),
				$context,
			)
		);

		self::assertSame( 'unavailable_pre_auth', $context['identity_status'] );
		self::assertSame( 'reconnect_assistant', $context['recovery_action'] );
		self::assertSame( 'expired', $context['refresh_token_state'] );
		self::assertSame( 42, $context['connection_id'] );
		self::assertSame( 'codex', $auth['provider'] );
		self::assertSame( 'stored-codex-client', $auth['client_id'] );
		self::assertSame( 'chatgpt', $request_auth['provider'] );
		self::assertSame( 'request-client', $request_auth['client_id'] );
		self::assertArrayNotHasKey( 'user_id', $context );
		self::assertArrayNotHasKey( 'refresh_token', $context );
		self::assertSame( hash( 'sha256', $raw ), $wpdb->prepared[0]['args'][3] );
		self::assertNotContains( $raw, $wpdb->prepared[0]['args'] );
	}

	public function test_other_refresh_errors_get_pre_auth_guidance_without_token_state(): void {
		$request = new WP_REST_Request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'do-not-read',
			)
		);

		$context = $this->invokePrivate( new TokenController(), 'refresh_rejection_context', array( $request, 'invalid_client' ) );

		self::assertSame( 'unavailable_pre_auth', $context['identity_status'] );
		self::assertSame( 'reconnect_assistant', $context['recovery_action'] );
		self::assertArrayNotHasKey( 'refresh_token_state', $context );
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

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- This test double is intentionally local to token timeline coverage.

/**
 * Minimal wpdb test double for refresh-token timeline correlation.
 */
final class FakeTokenTimelineWpdb {

	public string $prefix = 'wp_';

	/**
	 * Prepared SQL calls.
	 *
	 * @var array<int, array{query: string, args: array<int, mixed>}>
	 */
	public array $prepared = array();

	/**
	 * Initialize the test double.
	 *
	 * @param array<string, mixed>|null $row Configured row.
	 */
	public function __construct( private ?array $row ) {
	}

	/**
	 * Record a prepared SQL template and arguments.
	 *
	 * @param string $query SQL query with placeholders.
	 * @param mixed  ...$args Placeholder arguments.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->prepared[] = array(
			'query' => $query,
			'args'  => $args,
		);

		return $query;
	}

	/**
	 * Return the configured row.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output format.
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, string $output ): ?array {
		unset( $query, $output );

		return $this->row;
	}
}

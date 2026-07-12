<?php
/**
 * Tests for OAuth authorization request handling.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\AuthorizationController;
use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies OAuth request parameters are sanitized and validated before use.
 */
final class AuthorizationControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$_GET  = array();
		$_POST = array();
	}

	public function test_params_from_array_allowlists_and_sanitizes_oauth_parameters(): void {
		$challenge = str_repeat( 'a', 43 );
		$params    = $this->invokePrivate(
			new AuthorizationController(),
			'params_from_array',
			array(
				array(
					'response_type'          => 'code',
					'client_id'              => '<strong>client-1</strong>',
					'redirect_uri'           => 'https://chatgpt.com/oauth/callback?client=1',
					'scope'                  => "content:read\tcontent:draft",
					'state'                  => '<b>state-value</b>',
					'code_challenge'         => $challenge . '=!',
					'code_challenge_method'  => 's256',
					'resource'               => 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
					'_wpnonce'               => 'nonce-value',
					'decision'               => 'approve',
					'unexpected_extra_value' => 'discard me',
				),
			)
		);

		self::assertSame(
			array(
				'response_type',
				'client_id',
				'redirect_uri',
				'scope',
				'state',
				'code_challenge',
				'code_challenge_method',
				'resource',
			),
			array_keys( $params )
		);
		self::assertSame( 'client-1', $params['client_id'] );
		self::assertSame( 'content:read content:draft', $params['scope'] );
		self::assertSame( 'state-value', $params['state'] );
		self::assertSame( $challenge, $params['code_challenge'] );
		self::assertSame( 'S256', $params['code_challenge_method'] );
	}

	public function test_posted_helpers_unslash_sanitize_and_validate_form_fields(): void {
		$_POST = array(
			'client_id' => 'client\\\\id',
			'_wpnonce'  => 'nonce\\value',
			'decision'  => 'approve',
		);

		$controller = new AuthorizationController();

		self::assertSame( 'client\\id', $this->invokePrivate( $controller, 'posted_params' )['client_id'] );
		self::assertSame( 'noncevalue', $this->invokePrivate( $controller, 'posted_nonce' ) );
		self::assertSame( 'approve', $this->invokePrivate( $controller, 'posted_decision' ) );
	}

	public function test_pkce_and_scope_validation_are_strict(): void {
		$controller = new AuthorizationController();

		self::assertTrue( $this->invokePrivate( $controller, 'valid_code_challenge', array( str_repeat( 'a', 43 ) ) ) );
		self::assertFalse( $this->invokePrivate( $controller, 'valid_code_challenge', array( str_repeat( 'a', 42 ) ) ) );
		self::assertTrue( $this->invokePrivate( $controller, 'scope_tokens_supported', array( array( 'content:read', 'content:draft' ) ) ) );
		self::assertFalse( $this->invokePrivate( $controller, 'scope_tokens_supported', array( array( 'content:read', 'options:write' ) ) ) );
	}

	public function test_admin_consent_url_uses_wordpress_settings_page(): void {
		$url = $this->invokePrivate(
			new AuthorizationController(),
			'admin_consent_url',
			array(
				array(
					'response_type'         => 'code',
					'client_id'             => 'client-1',
					'redirect_uri'          => 'https://chatgpt.com/oauth/callback',
					'code_challenge'        => str_repeat( 'a', 43 ),
					'code_challenge_method' => 'S256',
				),
			)
		);

		self::assertIsString( $url );
		self::assertStringStartsWith( 'https://example.com/wp-admin/options-general.php?', $url );
		self::assertStringContainsString( 'page=aculect-ai-companion', $url );
		self::assertStringContainsString( 'view=oauth-consent', $url );
		self::assertStringContainsString( 'client_id=client-1', $url );
	}

	public function test_authorization_endpoint_uses_root_route_for_browser_cookie_auth(): void {
		self::assertSame(
			'https://example.com/oauth/authorize',
			Helpers::authorization_endpoint()
		);
		self::assertStringNotContainsString(
			'/wp-json/',
			Helpers::authorization_endpoint()
		);
	}

	public function test_server_error_description_does_not_expose_exception_details(): void {
		$description = $this->invokePrivate( new AuthorizationController(), 'server_error_description' );

		self::assertSame( 'Aculect AI Companion could not complete the authorization request. Try reconnecting the client.', $description );
		self::assertStringNotContainsString( 'SQL', $description );
		self::assertStringNotContainsString( 'Exception', $description );
	}

	public function test_consent_markup_uses_connection_request_framing(): void {
		$client = new ClientEntity();
		$client->setName( 'OpenAI ChatGPT Connector' );
		$client->setProvider( 'chatgpt' );

		ob_start();
		$this->invokePrivate(
			new AuthorizationController(),
			'render_consent_markup',
			array(
				array(
					'response_type'         => 'code',
					'client_id'             => 'client-1',
					'redirect_uri'          => 'https://chatgpt.com/oauth/callback',
					'scope'                 => 'content:read content:draft',
					'code_challenge'        => str_repeat( 'a', 43 ),
					'code_challenge_method' => 'S256',
					'resource'              => Helpers::mcp_resource(),
				),
				$client,
				Helpers::mcp_resource(),
			)
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Review connection request', $html );
		self::assertStringContainsString(
			'ChatGPT is requesting permission to connect to your WordPress site through Aculect AI Companion.',
			$html
		);
		self::assertStringContainsString( 'Connection details', $html );
		self::assertStringContainsString( 'What ChatGPT will be able to do', $html );
		self::assertStringContainsString( 'Security and privacy', $html );
		self::assertStringContainsString( 'Approve connection', $html );
		self::assertStringContainsString( 'Deny', $html );
		self::assertStringNotContainsString( 'Approve AI assistant access', $html );
	}

	/**
	 * Invoke a private method for focused unit coverage without widening runtime API.
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

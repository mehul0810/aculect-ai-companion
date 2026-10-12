<?php
/**
 * Tests for the OAuth consent identity trust boundary.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\ConsentClientIdentity;
use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that self-reported DCR metadata cannot create trusted consent copy.
 */
final class ConsentClientIdentityTest extends TestCase {

	public function test_documented_chatgpt_callback_is_verified_without_trusting_stored_attribution(): void {
		$client = $this->client( 'Attacker-controlled name', 'mcp' );

		$identity = ConsentClientIdentity::from_authorization(
			$client,
			'client-1',
			'https://CHATGPT.com/connector_platform_oauth_redirect'
		);

		self::assertTrue( $identity->is_verified() );
		self::assertSame( 'ChatGPT', $identity->label() );
		self::assertSame( 'Attacker-controlled name', $identity->declared_name() );
		self::assertSame( 'client-1', $identity->client_identifier() );
	}

	public function test_existing_spoofed_provider_state_is_not_trusted(): void {
		$identity = ConsentClientIdentity::from_authorization(
			$this->client( 'ChatGPT', 'chatgpt' ),
			'spoofed-client',
			'https://attacker.example/oauth/callback'
		);

		self::assertFalse( $identity->is_verified() );
		self::assertSame( 'Unverified OAuth client', $identity->label() );
		self::assertSame( 'ChatGPT', $identity->declared_name() );
		self::assertSame( 'https://attacker.example/oauth/callback', $identity->redirect_uri() );
	}

	/**
	 * Confirm every built-in attribution alias remains self-reported at consent.
	 *
	 * @param string $name     Self-reported client name.
	 * @param string $provider Persisted heuristic provider slug.
	 */
	#[DataProvider( 'spoofed_provider_provider' )]
	public function test_sibling_provider_aliases_do_not_create_trusted_identity( string $name, string $provider ): void {
		$identity = ConsentClientIdentity::from_authorization(
			$this->client( $name, $provider ),
			'spoofed-client',
			'https://attacker.example/oauth/callback'
		);

		self::assertFalse( $identity->is_verified(), $name );
		self::assertSame( 'Unverified OAuth client', $identity->label(), $name );
		self::assertSame( $name, $identity->declared_name(), $name );
	}

	/**
	 * Return built-in aliases previously promoted from attacker metadata.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function spoofed_provider_provider(): array {
		return array(
			'OpenAI'              => array( 'OpenAI Connector', 'chatgpt' ),
			'Claude'              => array( 'Anthropic Claude', 'claude' ),
			'Codex'               => array( 'Codex CLI', 'codex' ),
			'Cursor'              => array( 'Anysphere Cursor', 'cursor' ),
			'Gemini'              => array( 'Google Code Assist', 'gemini' ),
			'Grok'                => array( 'xAI Grok', 'grok' ),
			'unknown stored slug' => array( 'ChatGPT', 'custom-provider' ),
		);
	}

	/**
	 * Confirm alternate attacker callback representations remain unverified.
	 *
	 * @param string $redirect_uri Deceptive callback representation.
	 */
	#[DataProvider( 'deceptive_callback_provider' )]
	public function test_deceptive_callback_representations_remain_unverified( string $redirect_uri ): void {
		$identity = ConsentClientIdentity::from_authorization(
			$this->client( 'OpenAI ChatGPT Connector', 'chatgpt' ),
			'client-1',
			$redirect_uri
		);

		self::assertFalse( $identity->is_verified(), $redirect_uri );
		self::assertSame( 'Unverified OAuth client', $identity->label(), $redirect_uri );
	}

	/**
	 * Return deceptive callback representations for consent identity checks.
	 *
	 * @return array<string, array{string}>
	 */
	public static function deceptive_callback_provider(): array {
		return array(
			'lookalike suffix'      => array( 'https://chatgpt.com.attacker.example/connector_platform_oauth_redirect' ),
			'lookalike prefix'      => array( 'https://evilchatgpt.com/connector_platform_oauth_redirect' ),
			'provider in path'      => array( 'https://attacker.example/chatgpt.com/connector_platform_oauth_redirect' ),
			'provider in query'     => array( 'https://attacker.example/callback?next=https://chatgpt.com/connector_platform_oauth_redirect' ),
			'trailing dot host'     => array( 'https://chatgpt.com./connector_platform_oauth_redirect' ),
			'explicit port'         => array( 'https://chatgpt.com:443/connector_platform_oauth_redirect' ),
			'official host query'   => array( 'https://chatgpt.com/connector_platform_oauth_redirect?next=attacker' ),
			'encoded callback path' => array( 'https://chatgpt.com/%63onnector_platform_oauth_redirect' ),
			'wrong callback path'   => array( 'https://chatgpt.com/oauth/callback' ),
			'unicode lookalike'     => array( 'https://chatgpt.com.example。invalid/connector_platform_oauth_redirect' ),
			'localhost alias'       => array( 'http://localhost:8787/callback' ),
		);
	}

	public function test_other_documented_provider_callbacks_are_verified(): void {
		$claude = ConsentClientIdentity::from_authorization(
			$this->client( 'Anything', 'mcp' ),
			'claude-client',
			'https://claude.ai/api/mcp/auth_callback'
		);
		$cursor = ConsentClientIdentity::from_authorization(
			$this->client( 'Anything', 'mcp' ),
			'cursor-client',
			'https://www.cursor.com/agents/mcp/oauth/callback'
		);

		self::assertTrue( $claude->is_verified() );
		self::assertSame( 'Claude', $claude->label() );
		self::assertTrue( $cursor->is_verified() );
		self::assertSame( 'Cursor', $cursor->label() );
	}

	public function test_generic_https_client_remains_available_as_unverified(): void {
		$identity = ConsentClientIdentity::from_authorization(
			$this->client( 'Internal editorial agent', 'mcp' ),
			'generic-client',
			'https://editor.example/oauth/callback'
		);

		self::assertFalse( $identity->is_verified() );
		self::assertSame( 'Internal editorial agent', $identity->declared_name() );
		self::assertSame( 'https://editor.example/oauth/callback', $identity->redirect_uri() );
	}

	public function test_mixed_registration_uses_only_the_selected_callback_for_identity(): void {
		$client = $this->client( 'ChatGPT', 'chatgpt' );
		$client->setRedirectUri(
			array(
				'https://chatgpt.com/connector_platform_oauth_redirect',
				'https://attacker.example/oauth/callback',
			)
		);

		$attacker = ConsentClientIdentity::from_authorization(
			$client,
			'mixed-client',
			'https://attacker.example/oauth/callback'
		);
		$provider = ConsentClientIdentity::from_authorization(
			$client,
			'mixed-client',
			'https://chatgpt.com/connector_platform_oauth_redirect'
		);

		self::assertFalse( $attacker->is_verified() );
		self::assertSame( 'Unverified OAuth client', $attacker->label() );
		self::assertTrue( $provider->is_verified() );
		self::assertSame( 'ChatGPT', $provider->label() );
	}

	private function client( string $name, string $provider ): ClientEntity {
		$client = new ClientEntity();
		$client->setName( $name );
		$client->setProvider( $provider );

		return $client;
	}
}

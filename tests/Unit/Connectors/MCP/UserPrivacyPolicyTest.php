<?php
/**
 * User policy refusals never retrieve, echo or mutate user data.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpInputValidator;
use Aculect\AICompanion\Connectors\MCP\Modules\UserPrivacyAbilityModules;
use Aculect\AICompanion\Connectors\MCP\UserPrivacyPolicy;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesBridge;
use PHPUnit\Framework\TestCase;

/** Verifies stable refusals without introducing a privileged deletion path. */
final class UserPrivacyPolicyTest extends TestCase {

	public function test_core_user_info_cannot_bypass_privacy_through_the_registered_bridge(): void {
		$bridge = new WordPressAbilitiesBridge();
		foreach ( array( 'id', 'name' ) as $key ) {
			self::assertSame(
				UserPrivacyPolicy::sensitive_data(),
				$bridge->run(
					array(
						$key        => 'core/get-user-info',
						'arguments' => array( 'fields' => array( 'user_login' ) ),
					)
				)
			);
			self::assertSame( UserPrivacyPolicy::sensitive_data(), $bridge->run( array( $key => ' core/get-user-info ' ) ) );
		}
	}

	public function test_refusals_are_registered_read_only_and_accept_no_personal_data(): void {
		$registry  = new AbilitiesRegistry();
		$validator = new McpInputValidator();
		foreach ( ( new UserPrivacyAbilityModules() )->all() as $module ) {
			self::assertArrayHasKey( $module->id(), $registry->modules() );
			self::assertTrue( $module->is_read_only() );
			self::assertSame( array( 'content:read' ), $module->required_scopes() );
			self::assertSame( array(), $module->input_schema()['properties'] );
			self::assertFalse( $module->input_schema()['additionalProperties'] );
			self::assertNull( $validator->arguments_error( array(), $module->input_schema() ) );
			foreach ( array( 'email', 'user_id', 'password', 'confirmation_token' ) as $key ) {
				self::assertNotNull( $validator->arguments_error( array( $key => 'private-fixture' ), $module->input_schema() ) );
			}
		}
	}

	public function test_direct_handlers_ignore_arguments_and_never_echo_sensitive_values(): void {
		foreach ( ( new UserPrivacyAbilityModules() )->all() as $module ) {
			$expected = $module->execute( array() );
			self::assertFalse( $expected['allowed'] );
			self::assertTrue( $expected['terminal'] );
			self::assertTrue( $expected['read_only'] );
			self::assertSame(
				$expected,
				$module->execute(
					array(
						'user_id'            => 99,
						'email'              => 'private-fixture@example.test',
						'confirmation_token' => 'fixture-confirmed',
					)
				)
			);
			self::assertStringNotContainsString( 'private-fixture', (string) wp_json_encode( $expected ) );
		}
	}

	public function test_policy_messages_explain_privacy_and_unconditional_refusal(): void {
		self::assertSame( 'user_deletion_not_allowed', UserPrivacyPolicy::deletion()['error'] );
		self::assertSame( 'sensitive_user_information_not_allowed', UserPrivacyPolicy::sensitive_data()['error'] );
		self::assertStringContainsString( 'privacy and safety', UserPrivacyPolicy::deletion()['message'] );
		self::assertStringContainsString( 'not retrieved or shown', UserPrivacyPolicy::sensitive_data()['message'] );
		self::assertContains( 'users.delete_user', UserPrivacyPolicy::operations() );
		self::assertContains( 'users.read_sensitive', UserPrivacyPolicy::operations() );
	}
}

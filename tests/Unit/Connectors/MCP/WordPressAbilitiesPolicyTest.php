<?php
/**
 * WordPress Abilities policy tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesPolicy;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/wordpress-abilities-stubs.php';

/**
 * Verifies safe defaults and explicit third-party ability decisions.
 */
final class WordPressAbilitiesPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']      = array();
		$GLOBALS['aculect_ai_companion_test_wp_abilities'] = array();
	}

	public function test_fresh_policy_defaults_only_valid_read_only_abilities_on(): void {
		$this->register_ability( 'example/read', true, false );
		$this->register_ability( 'example/write', false, false );
		$this->register_ability( 'example/destructive', true, true );
		$this->register_ability( 'example/no-permission', true, false, false );
		$this->register_ability( 'example/invalid-schema', true, false, true, 'anything' );

		$policy = new WordPressAbilitiesPolicy();

		self::assertSame( array( 'example/read' ), $policy->allowed_ids() );
		self::assertTrue( $policy->is_allowed( 'example/read' ) );
		self::assertFalse( $policy->is_allowed( 'example/write' ) );
		self::assertFalse( $policy->is_allowed( 'example/destructive' ) );
		self::assertFalse( $policy->is_allowed( 'example/no-permission' ) );
		self::assertFalse( $policy->is_allowed( 'example/invalid-schema' ) );

		$definitions = array_column( $policy->public_definitions(), null, 'id' );
		self::assertTrue( $definitions['example/read']['defaultEnabled'] );
		self::assertSame( 'default', $definitions['example/read']['decision'] );
		self::assertFalse( $definitions['example/write']['defaultEnabled'] );
	}

	public function test_explicit_decisions_override_defaults_and_preserve_unavailable_ids(): void {
		$this->register_ability( 'example/read', true, false );
		$this->register_ability( 'example/write', false, false );

		$policy = new WordPressAbilitiesPolicy();
		$policy->save_allowed_ids( array( 'example/write' ) );

		self::assertFalse( $policy->is_allowed( 'example/read' ) );
		self::assertTrue( $policy->is_allowed( 'example/write' ) );
		self::assertSame(
			array(
				'example/read'  => false,
				'example/write' => true,
			),
			$policy->saved_decisions()
		);

		$GLOBALS['aculect_ai_companion_test_wp_abilities'] = array();
		$reloaded = new WordPressAbilitiesPolicy();
		self::assertSame( array( 'example/write' ), $reloaded->allowed_ids() );

		$this->register_ability( 'example/read', true, false );
		$this->register_ability( 'example/write', false, false );
		self::assertFalse( $reloaded->is_allowed( 'example/read' ) );
		self::assertTrue( $reloaded->is_allowed( 'example/write' ) );
	}

	public function test_legacy_allowlist_preserves_upgrade_behavior(): void {
		$this->register_ability( 'example/read', true, false );
		update_option( WordPressAbilitiesPolicy::OPTION_ALLOWED_ABILITIES, array(), false );

		$policy = new WordPressAbilitiesPolicy();

		self::assertSame( array(), $policy->allowed_ids() );
		self::assertFalse( $policy->is_allowed( 'example/read' ) );
	}

	public function test_new_safe_ability_uses_default_after_policy_initialization(): void {
		$this->register_ability( 'example/first', true, false );
		$policy = new WordPressAbilitiesPolicy();
		$policy->save_allowed_ids( array() );

		$this->register_ability( 'example/new-read', true, false );

		self::assertFalse( $policy->is_allowed( 'example/first' ) );
		self::assertTrue( $policy->is_allowed( 'example/new-read' ) );
	}

	/**
	 * Register a public test ability.
	 *
	 * @param string $name            Ability name.
	 * @param bool   $readonly        Whether the ability is read-only.
	 * @param bool   $destructive     Whether the ability is destructive.
	 * @param bool   $with_permission Whether to register a permission callback.
	 * @param string $schema_type     Root JSON Schema type.
	 */
	private function register_ability( string $name, bool $readonly, bool $destructive, bool $with_permission = true, string $schema_type = 'object' ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => 'Example ability',
				'description'         => 'Example third-party ability.',
				'category'            => 'example',
				'input_schema'        => array( 'type' => $schema_type ),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $with_permission ? static fn(): bool => true : null,
				'execute_callback'    => static fn(): array => array( 'ok' => true ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => $readonly,
						'destructive' => $destructive,
					),
				),
			)
		);
	}
}

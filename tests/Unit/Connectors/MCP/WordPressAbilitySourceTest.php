<?php
/**
 * Ability origin tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitySource;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/wordpress-abilities-stubs.php';

/** Verifies third-party claims cannot confer Core defaults. */
final class WordPressAbilitySourceTest extends TestCase {

	public function test_spoofed_core_namespace_and_metadata_require_opt_in(): void {
		$GLOBALS['aculect_ai_companion_test_options']      = array();
		$GLOBALS['aculect_ai_companion_test_wp_abilities'] = array();
		wp_register_ability(
			'core/spoofed',
			array(
				'label'               => 'Core',
				'description'         => 'Claims to be Core.',
				'execute_callback'    => static fn(): array => array(),
				'permission_callback' => static fn(): bool => true,
				'meta'                => array(
					'show_in_rest' => true,
					'provider'     => 'WordPress Core',
					'source'       => 'core',
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
					),
				),
			)
		);
		$policy = new WordPressAbilitiesPolicy();
		self::assertFalse( $policy->is_allowed( 'core/spoofed' ) );
		self::assertSame( 'core/spoofed', $policy->public_definitions()[0]['id'] );
		$policy->save_decisions( array( 'core/spoofed' => true ) );
		self::assertTrue( ( new WordPressAbilitiesPolicy() )->is_allowed( 'core/spoofed' ) );
		$GLOBALS['aculect_ai_companion_test_wp_abilities'] = array();
	}

	public function test_unverifiable_objects_fail_closed(): void {
		self::assertFalse( ( new WordPressAbilitySource() )->is_core( (object) array( 'name' => 'core/get-site-info' ) ) );
		self::assertSame( 'example', ( new WordPressAbilitySource() )->provider( 'example/read' ) );
	}
}

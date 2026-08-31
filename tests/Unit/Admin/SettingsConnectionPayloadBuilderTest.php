<?php
/**
 * Connection settings payload builder tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\SettingsConnectionPayloadBuilder;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies effective connection-row projections remain policy-aware.
 */
final class SettingsConnectionPayloadBuilderTest extends TestCase {

	/**
	 * Original test users.
	 *
	 * @var mixed
	 */
	private mixed $original_users = null;

	/**
	 * Original test options.
	 *
	 * @var mixed
	 */
	private mixed $original_options = null;

	protected function setUp(): void {
		parent::setUp();

		$this->original_users  = $GLOBALS['aculect_ai_companion_test_users'] ?? null;
		$this->original_options = $GLOBALS['aculect_ai_companion_test_options'] ?? null;
		$GLOBALS['aculect_ai_companion_test_users'] = array(
			7 => (object) array(
				'ID'           => 7,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Admin User',
				'user_login'   => 'admin',
			),
		);
		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_users ) {
			$GLOBALS['aculect_ai_companion_test_users'] = $this->original_users;
		} else {
			unset( $GLOBALS['aculect_ai_companion_test_users'] );
		}

		if ( null !== $this->original_options ) {
			$GLOBALS['aculect_ai_companion_test_options'] = $this->original_options;
		} else {
			unset( $GLOBALS['aculect_ai_companion_test_options'] );
		}

		parent::tearDown();
	}

	public function test_empty_sessions_preserve_the_compatibility_shape(): void {
		$builder = new SettingsConnectionPayloadBuilder();

		self::assertSame( array(), $builder->build( array(), new AbilitiesRegistry() ) );
	}

	public function test_build_adds_scope_aware_effective_ability_details(): void {
		$session = array(
			'id'          => '5',
			'user_id'     => '7',
			'scopes'      => array( 'content:read' ),
			'client_name' => 'ChatGPT',
		);

		$result = ( new SettingsConnectionPayloadBuilder() )->build(
			array( $session ),
			new AbilitiesRegistry()
		);

		self::assertCount( 1, $result );
		self::assertSame( 'ChatGPT', $result[0]['client_name'] );
		$ids = array_column( $result[0]['effective_abilities'], 'id' );

		self::assertContains( 'content.get_item', $ids );
		self::assertNotContains( 'content.update_item', $ids );
		self::assertTrue( $result[0]['effective_ability_summary']['scope_aware'] );
		self::assertSame( count( $result[0]['effective_abilities'] ), $result[0]['effective_ability_summary']['available_count'] );
		self::assertSame( $result[0]['effective_ability_summary']['write_count'], $result[0]['effective_write_ability_count'] );
	}
}

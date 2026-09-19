<?php
/**
 * Guarded classic menu location assignment tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\Modules\NavigationLocationAbilityModules;
use Aculect\AICompanion\Connectors\MCP\NavigationLocationAbilities;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class NavigationLocationAbilitiesTest extends TestCase {
	private NavigationLocationAbilities $service;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/fixtures/navigation-location-ability-stubs.php';

		$GLOBALS['aculect_ai_companion_test_blog_id']              = 1;
		$GLOBALS['aculect_ai_companion_test_stylesheet']           = 'classic-child';
		$GLOBALS['aculect_ai_companion_test_template']             = 'classic-parent';
		$GLOBALS['aculect_ai_companion_test_registered_nav_menus'] = array(
			'primary' => 'Primary Navigation',
			'footer'  => 'Footer Navigation',
		);
		$GLOBALS['aculect_ai_companion_test_nav_menu_locations']   = array(
			'primary' => 11,
			'footer'  => 0,
			'legacy'  => 12,
		);
		$GLOBALS['aculect_ai_companion_test_theme_mods']           = array(
			'nav_menu_locations' => $GLOBALS['aculect_ai_companion_test_nav_menu_locations'],
			'custom_theme_mod'   => 'preserved',
		);
		$GLOBALS['aculect_ai_companion_test_nav_menus']            = array(
			new \WP_Term(
				array(
					'term_id'  => 11,
					'name'     => 'Primary',
					'slug'     => 'primary-menu',
					'taxonomy' => 'nav_menu',
				)
			),
			new \WP_Term(
				array(
					'term_id'  => 12,
					'name'     => 'Footer',
					'slug'     => 'footer-menu',
					'taxonomy' => 'nav_menu',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps']          = array();
		$GLOBALS['aculect_ai_companion_test_theme_mod_calls']      = array();
		$GLOBALS['aculect_ai_companion_test_theme_mod_callback']   = null;
		$this->service = new NavigationLocationAbilities();
	}

	public function test_read_context_returns_bounded_full_map_and_keyed_state(): void {
		$result = $this->service->read_context();

		self::assertSame( 1, $result['site_id'] );
		self::assertSame(
			array(
				'stylesheet' => 'classic-child',
				'template'   => 'classic-parent',
			),
			$result['active_theme']
		);
		self::assertSame(
			array(
				'footer'  => 'Footer Navigation',
				'primary' => 'Primary Navigation',
			),
			$result['registered_locations']
		);
		self::assertSame(
			array(
				'footer'  => 0,
				'legacy'  => 12,
				'primary' => 11,
			),
			$result['location_map']
		);
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['expected_state'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_theme_mod_calls'] );
	}

	public function test_filtered_effective_location_map_divergence_fails_closed(): void {
		$args = $this->args();
		$GLOBALS['aculect_ai_companion_test_nav_menu_locations']['legacy'] = 10;

		self::assertSame( 'invalid_location_state', $this->service->read_context()['error'] );
		self::assertSame( 'invalid_location_state', $this->service->assign_location( $args )['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_theme_mod_calls'] );
	}

	public function test_read_and_assignment_require_theme_options_capability(): void {
		$args = $this->args();
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_theme_options' );

		self::assertSame( 'forbidden', $this->service->read_context()['error'] );
		self::assertSame( 'forbidden', $this->service->assign_location( $args )['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_theme_mod_calls'] );
	}

	public function test_location_menu_and_state_inputs_are_strict_and_bounded(): void {
		$args = $this->args();
		foreach ( array( null, '', 'Primary', 'not_registered', array( 'primary' ) ) as $location ) {
			$invalid             = $args;
			$invalid['location'] = $location;
			self::assertSame( 'invalid_location', $this->service->assign_location( $invalid )['error'] );
		}

		foreach ( array( null, -1, '12', 12.0, true, array( 12 ) ) as $menu_id ) {
			$invalid            = $args;
			$invalid['menu_id'] = $menu_id;
			self::assertSame( 'invalid_menu', $this->service->assign_location( $invalid )['error'] );
		}
		$args['menu_id'] = 999;
		self::assertSame( 'invalid_menu', $this->service->assign_location( $args )['error'] );
		$args['menu_id']        = 12;
		$args['expected_state'] = 'not-a-state';
		self::assertSame( 'invalid_state', $this->service->assign_location( $args )['error'] );

		$GLOBALS['aculect_ai_companion_test_registered_nav_menus'] = array_fill_keys( array_map( static fn( int $index ): string => 'location_' . $index, range( 1, 101 ) ), 'Location' );
		self::assertSame( 'invalid_location_state', $this->service->read_context()['error'] );
		$GLOBALS['aculect_ai_companion_test_registered_nav_menus']             = array( 'primary' => 'Primary Navigation' );
		$GLOBALS['aculect_ai_companion_test_nav_menu_locations']               = array_fill_keys( array_map( static fn( int $index ): string => 'location_' . $index, range( 1, 101 ) ), 0 );
		$GLOBALS['aculect_ai_companion_test_theme_mods']['nav_menu_locations'] = $GLOBALS['aculect_ai_companion_test_nav_menu_locations'];
		self::assertSame( 'invalid_location_state', $this->service->read_context()['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_theme_mod_calls'] );
	}

	public function test_stale_full_map_or_theme_state_cannot_write(): void {
		$args = $this->args();
		$GLOBALS['aculect_ai_companion_test_nav_menu_locations']['legacy']               = 10;
		$GLOBALS['aculect_ai_companion_test_theme_mods']['nav_menu_locations']['legacy'] = 10;
		self::assertSame( 'stale_state', $this->service->assign_location( $args )['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_theme_mod_calls'] );

		$args = $this->args();
		$GLOBALS['aculect_ai_companion_test_stylesheet'] = 'changed-theme';
		self::assertSame( 'stale_state', $this->service->assign_location( $args )['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_theme_mod_calls'] );
	}

	public function test_preview_discloses_displacement_without_writing(): void {
		$args            = $this->args();
		$args['dry_run'] = true;
		$result          = $this->service->assign_location( $args );

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'navigation.assign_location', $result['action'] );
		self::assertSame( 11, $result['target']['current_menu_id'] );
		self::assertSame( 12, $result['target']['menu_id'] );
		self::assertSame( 11, $result['target']['displaced_menu_id'] );
		self::assertSame( 11, $result['changes'][0]['from'] );
		self::assertSame( 12, $result['changes'][0]['to'] );
		self::assertNotEmpty( $result['warnings'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_theme_mod_calls'] );
	}

	public function test_assignment_uses_native_setter_and_preserves_other_locations(): void {
		$result = $this->service->assign_location( $this->args() );

		self::assertTrue( $result['success'] );
		self::assertTrue( $result['changed'] );
		self::assertSame( 11, $result['displaced_menu_id'] );
		self::assertSame(
			array(
				'footer'  => 0,
				'legacy'  => 12,
				'primary' => 12,
			),
			$result['location_map']
		);
		self::assertSame(
			array(
				'footer'  => 0,
				'legacy'  => 12,
				'primary' => 12,
			),
			$GLOBALS['aculect_ai_companion_test_nav_menu_locations']
		);
		self::assertSame( 'preserved', $GLOBALS['aculect_ai_companion_test_theme_mods']['custom_theme_mod'] );
		self::assertCount( 1, $GLOBALS['aculect_ai_companion_test_theme_mod_calls'] );
		self::assertSame( 'nav_menu_locations', $GLOBALS['aculect_ai_companion_test_theme_mod_calls'][0]['name'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['expected_state'] );
	}

	public function test_zero_explicitly_unassigns_and_same_value_is_a_noop(): void {
		$args            = $this->args();
		$args['menu_id'] = 0;
		$result          = $this->service->assign_location( $args );
		self::assertTrue( $result['changed'] );
		self::assertSame( 0, $result['location_map']['primary'] );
		self::assertSame( 12, $result['location_map']['legacy'] );

		$args                   = $this->args();
		$args['location']       = 'footer';
		$args['menu_id']        = 0;
		$args['expected_state'] = $result['expected_state'];
		$call_count             = count( $GLOBALS['aculect_ai_companion_test_theme_mod_calls'] );
		$no_op                  = $this->service->assign_location( $args );
		self::assertTrue( $no_op['success'] );
		self::assertFalse( $no_op['changed'] );
		self::assertSame( $call_count, count( $GLOBALS['aculect_ai_companion_test_theme_mod_calls'] ) );
	}

	public function test_state_is_rechecked_immediately_before_save(): void {
		$args  = $this->args();
		$calls = 0;
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability ) use ( &$calls ): bool {
			++$calls;
			if ( 2 === $calls ) {
				$GLOBALS['aculect_ai_companion_test_nav_menu_locations']['legacy']               = 10;
				$GLOBALS['aculect_ai_companion_test_theme_mods']['nav_menu_locations']['legacy'] = 10;
			}
			return 'edit_theme_options' === $capability;
		};

		self::assertSame( 'stale_state', $this->service->assign_location( $args )['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_theme_mod_calls'] );
	}

	public function test_uncertain_native_write_is_terminal_and_unchanged_write_is_reported(): void {
		$args = $this->args();
		$GLOBALS['aculect_ai_companion_test_theme_mod_callback'] = static function ( string $name, mixed $value ): void {
			unset( $name, $value );
		};
		$unchanged = $this->service->assign_location( $args );
		self::assertSame( 'partial_write', $unchanged['error'] );
		self::assertTrue( $unchanged['terminal'] );

		$GLOBALS['aculect_ai_companion_test_theme_mod_callback'] = static function ( string $name, mixed $value ): never {
			unset( $name, $value );
			throw new \RuntimeException( 'Simulated theme-mod hook failure.' );
		};
		$thrown = $this->service->assign_location( $args );
		self::assertSame( 'partial_write', $thrown['error'] );
		self::assertTrue( $thrown['terminal'] );

		$GLOBALS['aculect_ai_companion_test_theme_mod_callback'] = static function ( string $name, mixed $value ): void {
			unset( $name );
			$value['primary']                                        = 99;
			$GLOBALS['aculect_ai_companion_test_nav_menu_locations'] = $value;
			$GLOBALS['aculect_ai_companion_test_theme_mods']['nav_menu_locations'] = $value;
		};
		$mismatch = $this->service->assign_location( $args );
		self::assertSame( 'partial_write', $mismatch['error'] );
		self::assertTrue( $mismatch['terminal'] );
	}

	public function test_non_classic_menu_is_rejected_and_modules_have_fixed_scopes(): void {
		$GLOBALS['aculect_ai_companion_test_nav_menus'][1]->taxonomy = 'category';
		self::assertSame( 'invalid_menu', $this->service->assign_location( $this->args() )['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_theme_mod_calls'] );

		$modules = array_values( ( new NavigationLocationAbilityModules() )->all() );
		self::assertCount( 2, $modules );
		self::assertSame( 'navigation.read_location_context', $modules[0]->id() );
		self::assertTrue( $modules[0]->is_read_only() );
		self::assertSame( array( 'content:read' ), $modules[0]->required_scopes() );
		self::assertSame( 'navigation.assign_location', $modules[1]->id() );
		self::assertFalse( $modules[1]->is_read_only() );
		self::assertSame( array( 'content:draft' ), $modules[1]->required_scopes() );
		self::assertSame( array( 'location', 'menu_id', 'expected_state' ), $modules[1]->input_schema()['required'] );
		self::assertStringContainsString( 'explicit confirmation', $modules[1]->description() );
	}

	/** Build a valid assignment from the current full native state. */
	private function args(): array {
		$state = $this->service->read_context();
		return array(
			'location'       => 'primary',
			'menu_id'        => 12,
			'expected_state' => $state['expected_state'],
		);
	}
}

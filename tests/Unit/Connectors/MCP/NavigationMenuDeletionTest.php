<?php
/**
 * Confirmed classic navigation menu deletion tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\Modules\NavigationMenuDeletionAbilityModules;
use Aculect\AICompanion\Connectors\MCP\NavigationMenuDeletion;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class NavigationMenuDeletionTest extends TestCase {
	private NavigationMenuDeletion $service;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/fixtures/navigation-menu-deletion-stubs.php';
		$this->service = new NavigationMenuDeletion();
		$this->reset_fixture();
	}

	public function test_capability_denial_prevents_inspection_and_deletion(): void {
		$inspect = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		self::assertArrayHasKey( 'expected_state', $inspect );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_theme_options' );

		self::assertSame( 'forbidden', $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) )['error'] );
		self::assertSame(
			'forbidden',
			$this->service->delete_menu(
				array(
					'menu_id'        => 11,
					'expected_state' => $inspect['expected_state'],
				)
			)['error']
		);
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
	}

	public function test_stale_item_state_is_rejected_without_native_write(): void {
		$inspect = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		$GLOBALS['aculect_ai_companion_test_nav_menu_items'][11][0]['post_title'] = 'Changed after inspect';

		$result = $this->service->delete_menu(
			array(
				'menu_id'        => 11,
				'expected_state' => $inspect['expected_state'],
			)
		);
		self::assertSame( 'stale_state', $result['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
	}

	public function test_assigned_menu_requires_explicit_unassignment_first(): void {
		$GLOBALS['aculect_ai_companion_test_nav_menu_locations']['primary']    = 11;
		$GLOBALS['aculect_ai_companion_test_theme_mods']['nav_menu_locations'] = $GLOBALS['aculect_ai_companion_test_nav_menu_locations'];

		$result = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		self::assertSame( 'assigned_menu', $result['error'] );
		self::assertStringContainsString( 'unassign', strtolower( $result['message'] ) );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
	}

	public function test_overbound_menu_is_refused_before_item_membership_reads(): void {
		$items = array();
		for ( $index = 1; $index <= 501; ++$index ) {
			$items[] = array(
				'ID'         => 1000 + $index,
				'menu_order' => $index,
				'post_title' => 'Item ' . $index,
			);
		}
		$GLOBALS['aculect_ai_companion_test_nav_menu_items'][11] = $items;

		$result = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		self::assertSame( 'overbound_menu', $result['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
	}

	public function test_shared_item_membership_is_refused_before_delete(): void {
		$GLOBALS['aculect_ai_companion_test_nav_menu_relationships'][12][] = 101;

		$result = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		self::assertSame( 'shared_membership', $result['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
	}

	public function test_filtered_inventory_cannot_hide_a_raw_member(): void {
		array_pop( $GLOBALS['aculect_ai_companion_test_nav_menu_items'][11] );

		$result = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		self::assertSame( 'raw_membership_mismatch', $result['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
	}

	public function test_non_menu_post_in_raw_membership_set_is_refused(): void {
		$GLOBALS['aculect_ai_companion_test_nav_menu_relationships'][11][] = 103;
		$GLOBALS['aculect_ai_companion_test_posts'][103]                   = new \WP_Post(
			array(
				'ID'        => 103,
				'post_type' => 'post',
			)
		);

		$result = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		self::assertSame( 'non_menu_item_membership', $result['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
	}

	public function test_raw_relationship_query_error_is_not_interpreted_as_empty_menu(): void {
		$GLOBALS['wpdb']->last_error = 'database unavailable';

		$result = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		self::assertSame( 'invalid_membership', $result['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
	}

	public function test_nested_object_item_shape_is_refused_fail_closed(): void {
		$GLOBALS['aculect_ai_companion_test_nav_menu_items'][11][0]['unexpected'] = new \stdClass();

		$result = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		self::assertSame( 'invalid_menu_state', $result['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
	}

	public function test_location_raw_and_effective_divergence_fails_closed(): void {
		$GLOBALS['aculect_ai_companion_test_theme_mods']['nav_menu_locations']['legacy'] = 12;

		$result = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		self::assertSame( 'invalid_location_state', $result['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
	}

	public function test_preview_is_mandatory_destructive_warning_and_has_no_writes(): void {
		$inspect = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		$result  = $this->service->delete_menu(
			array(
				'menu_id'        => 11,
				'expected_state' => $inspect['expected_state'],
				'dry_run'        => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'navigation.delete_menu', $result['action'] );
		self::assertSame( 2, $result['target']['item_count'] );
		self::assertStringContainsString( 'exactly 2', strtolower( implode( ' ', $result['warnings'] ) ) );
		self::assertStringContainsString( 'no trash', strtolower( implode( ' ', $result['warnings'] ) ) );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
		self::assertArrayHasKey( 101, $GLOBALS['aculect_ai_companion_test_posts'] );
	}

	public function test_native_success_proves_menu_items_and_location_map(): void {
		$inspect = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		$result  = $this->service->delete_menu(
			array(
				'menu_id'        => 11,
				'expected_state' => $inspect['expected_state'],
			)
		);

		self::assertTrue( $result['success'] );
		self::assertTrue( $result['postcondition']['menu_absent'] );
		self::assertTrue( $result['postcondition']['items_absent'] );
		self::assertTrue( $result['postcondition']['location_map_preserved'] );
		self::assertSame( array( 11 ), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
		self::assertNull( wp_get_nav_menu_object( 11 ) );
		self::assertSame(
			array(
				'primary' => 0,
				'footer'  => 0,
			),
			$GLOBALS['aculect_ai_companion_test_nav_menu_locations']
		);
	}

	public function test_native_delete_item_set_mismatch_is_rejected_without_native_write(): void {
		$inspect = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		$GLOBALS['aculect_ai_companion_test_nav_menu_objects_callback'] = static fn(): array => array( 101, 102, 999 );

		$result = $this->service->delete_menu(
			array(
				'menu_id'        => 11,
				'expected_state' => $inspect['expected_state'],
			)
		);

		self::assertSame( 'stale_state', $result['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
	}

	public function test_native_hook_exception_is_terminal_partial_write_without_retry(): void {
		$inspect = $this->service->inspect_menu_deletion( array( 'menu_id' => 11 ) );
		$GLOBALS['aculect_ai_companion_test_nav_menu_delete_callback'] = static function (): never {
			throw new \RuntimeException( 'Simulated native menu hook failure.' );
		};

		$result = $this->service->delete_menu(
			array(
				'menu_id'        => 11,
				'expected_state' => $inspect['expected_state'],
			)
		);
		self::assertSame( 'partial_write', $result['error'] );
		self::assertTrue( $result['terminal'] );
		self::assertSame( array( 11 ), $GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'] );
	}

	public function test_modules_expose_exact_scopes_and_closed_confirmation_schema(): void {
		$modules = array_values( ( new NavigationMenuDeletionAbilityModules() )->all() );
		self::assertCount( 2, $modules );
		self::assertSame( 'navigation.inspect_menu_deletion', $modules[0]->id() );
		self::assertTrue( $modules[0]->is_read_only() );
		self::assertSame( array( 'content:read' ), $modules[0]->required_scopes() );
		self::assertSame( array( 'menu_id' ), $modules[0]->input_schema()['required'] );
		self::assertSame( 'navigation.delete_menu', $modules[1]->id() );
		self::assertFalse( $modules[1]->is_read_only() );
		self::assertSame( array( 'content:draft' ), $modules[1]->required_scopes() );
		self::assertSame( array( 'menu_id', 'expected_state' ), $modules[1]->input_schema()['required'] );
		self::assertFalse( $modules[1]->input_schema()['additionalProperties'] );
		self::assertStringContainsString( 'explicit confirmation', $modules[1]->description() );
	}

	public function test_input_menu_id_and_expected_state_are_strict(): void {
		foreach ( array( null, 0, -1, '11', 11.0, true, array( 11 ) ) as $menu_id ) {
			$result = $this->service->inspect_menu_deletion( array( 'menu_id' => $menu_id ) );
			self::assertSame( 'invalid_target', $result['error'] );
		}
		self::assertSame( 'invalid_state', $this->service->delete_menu( array( 'menu_id' => 11 ) )['error'] );
		self::assertSame(
			'invalid_state',
			$this->service->delete_menu(
				array(
					'menu_id'        => 11,
					'expected_state' => 'not-a-token',
				)
			)['error']
		);
	}

	/** Reset all state used by the isolated fixture. */
	private function reset_fixture(): void {
		$GLOBALS['aculect_ai_companion_test_blog_id']                = 1;
		$GLOBALS['aculect_ai_companion_test_stylesheet']             = 'classic-child';
		$GLOBALS['aculect_ai_companion_test_template']               = 'classic-parent';
		$GLOBALS['aculect_ai_companion_test_registered_nav_menus']   = array(
			'primary' => 'Primary Navigation',
			'footer'  => 'Footer Navigation',
		);
		$GLOBALS['aculect_ai_companion_test_nav_menu_locations']     = array(
			'primary' => 0,
			'footer'  => 0,
		);
		$GLOBALS['aculect_ai_companion_test_theme_mods']             = array( 'nav_menu_locations' => $GLOBALS['aculect_ai_companion_test_nav_menu_locations'] );
		$GLOBALS['aculect_ai_companion_test_nav_menus']              = array(
			new \WP_Term(
				array(
					'term_id'     => 11,
					'name'        => 'Primary Menu',
					'slug'        => 'primary-menu',
					'taxonomy'    => 'nav_menu',
					'description' => 'Primary navigation',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_nav_menu_items']         = array(
			11 => array(
				array(
					'ID'         => 101,
					'menu_order' => 1,
					'post_title' => 'Home',
					'url'        => '/',
					'object'     => 'page',
				),
				array(
					'ID'         => 102,
					'menu_order' => 2,
					'post_title' => 'About',
					'url'        => '/about/',
					'object'     => 'page',
				),
			),
		);
		$GLOBALS['aculect_ai_companion_test_object_terms']           = array(
			101 => array(
				new \WP_Term(
					array(
						'term_id'  => 11,
						'taxonomy' => 'nav_menu',
					)
				),
			),
			102 => array(
				new \WP_Term(
					array(
						'term_id'  => 11,
						'taxonomy' => 'nav_menu',
					)
				),
			),
		);
		$GLOBALS['aculect_ai_companion_test_posts']                  = array(
			101 => new \WP_Post(
				array(
					'ID'        => 101,
					'post_type' => 'nav_menu_item',
				)
			),
			102 => new \WP_Post(
				array(
					'ID'        => 102,
					'post_type' => 'nav_menu_item',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_nav_menu_relationships'] = array(
			11 => array( 101, 102 ),
			12 => array(),
		);
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated raw-relation fixture.
		$GLOBALS['wpdb']                                  = new \NavigationMenuDeletionWpdbStub();
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback']       = null;
		$GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls']     = array();
		$GLOBALS['aculect_ai_companion_test_nav_menu_delete_callback']  = null;
		$GLOBALS['aculect_ai_companion_test_nav_menu_objects_callback'] = null;
	}
}

<?php
/**
 * Guarded classic menu mutation tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\NavigationFixturePost;
use Aculect\AICompanion\Connectors\MCP\NavigationItemWriteAbilities;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Separate processes prevent native API doubles affecting any other suite.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class NavigationItemWriteAbilitiesTest extends TestCase {
	private NavigationItemWriteAbilities $service;

	protected function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/NavigationItemFunctions.php';
		$this->service                                    = new NavigationItemWriteAbilities();
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		$GLOBALS['navigation_fixture_mode']               = '';
		$GLOBALS['navigation_fixture_writes']             = 0;
		$GLOBALS['navigation_fixture_posts']              = array();
		$GLOBALS['navigation_fixture_meta']               = array();
		$this->add_item( 101 );
		$this->add_item( 102 );
	}

	private function add_item( int $id ): void {
		$GLOBALS['navigation_fixture_posts'][ $id ] = new NavigationFixturePost(
			array(
				'ID'           => $id,
				'post_type'    => 'nav_menu_item',
				'post_status'  => 'publish',
				'post_title'   => 'Original',
				'post_content' => 'Description',
				'post_excerpt' => 'Attribute',
			)
		);
		$GLOBALS['navigation_fixture_meta'][ $id ]  = array(
			'_menu_item_url'              => '/old/',
			'_menu_item_menu_item_parent' => 0,
			'_menu_item_target'           => '',
			'_menu_item_xfn'              => 'friend',
			'_menu_item_classes'          => array( 'primary', 'existing' ),
			'_menu_item_type'             => 'custom',
			'_menu_item_object'           => 'custom',
			'_menu_item_object_id'        => $id,
		);
	}

	private function args( array $changes = array( 'label' => 'Updated' ) ): array {
		$read = $this->service->read_item(
			array(
				'menu_id' => 11,
				'item_id' => 101,
			)
		);
		return array(
			'menu_id'        => 11,
			'item_id'        => 101,
			'expected_state' => $read['expected_state'],
			'changes'        => $changes,
		);
	}

	public function test_read_and_preview_do_not_write(): void {
		$args            = $this->args();
		$args['dry_run'] = true;
		$result          = $this->service->update_item( $args );
		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'navigation.update_item', $result['action'] );
		self::assertSame( 'Original', $result['changes'][0]['from'] );
		self::assertSame( 'Updated', $result['changes'][0]['to'] );
		self::assertSame( 0, $GLOBALS['navigation_fixture_writes'] );
		self::assertSame( 501, $GLOBALS['navigation_fixture_query']['posts_per_page'] );
		self::assertSame( 'nav_menu', $GLOBALS['navigation_fixture_query']['tax_query'][0]['taxonomy'] );
	}

	public function test_write_sanitizes_and_preserves_uneditable_fields(): void {
		$GLOBALS['navigation_fixture_posts'][101]->post_date     = '2026-01-02 12:00:00';
		$GLOBALS['navigation_fixture_posts'][101]->post_date_gmt = '2026-01-02 06:30:00';
		$result = $this->service->update_item(
			$this->args(
				array(
					'label'     => '<b>New</b>',
					'url'       => 'https://example.org/new',
					'parent_id' => 102,
					'order'     => 4,
					'target'    => '_blank',
					'rel'       => 'nofollow noopener',
				)
			)
		);
		self::assertTrue( $result['success'] );
		self::assertSame( 'New', $result['item']['label'] );
		self::assertSame( array( 'primary', 'existing' ), $result['item']['classes'] );
		self::assertSame( 'Description', $result['item']['description'] );
		self::assertSame( 'Attribute', $result['item']['attr_title'] );
		self::assertSame( 101, $result['item']['object_id'] );
		self::assertSame( 'publish', $result['item']['status'] );
		self::assertSame( '2026-01-02 12:00:00', $result['item']['date'] );
		self::assertSame( '2026-01-02 06:30:00', $result['item']['date_gmt'] );
		self::assertSame( 1, $GLOBALS['navigation_fixture_writes'] );
	}

	public function test_authorization_and_target_fail_closed(): void {
		$args = $this->args();
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_theme_options' );
		self::assertSame( 'forbidden', $this->service->update_item( $args )['error'] );
		self::assertSame( 'forbidden', $this->service->read_item( $args )['error'] );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		foreach ( array( null, array(), new \stdClass(), '11', 0, -1 ) as $target ) {
			self::assertSame(
				'invalid_target',
				$this->service->read_item(
					array(
						'menu_id' => $target,
						'item_id' => 101,
					)
				)['error']
			);
		}
		self::assertSame(
			'unsupported_target',
			$this->service->read_item(
				array(
					'menu_id' => 12,
					'item_id' => 101,
				)
			)['error']
		);
		self::assertSame(
			'unsupported_target',
			$this->service->read_item(
				array(
					'menu_id' => 11,
					'item_id' => 999,
				)
			)['error']
		);
		$GLOBALS['navigation_fixture_posts'][101]->post_type = 'wp_navigation';
		self::assertSame(
			'unsupported_target',
			$this->service->read_item(
				array(
					'menu_id' => 11,
					'item_id' => 101,
				)
			)['error']
		);
		self::assertSame( 0, $GLOBALS['navigation_fixture_writes'] );
	}

	public function test_stale_menu_or_missing_token_cannot_write(): void {
		$args = $this->args();
		$GLOBALS['navigation_fixture_meta'][102]['_menu_item_classes'][] = 'changed';
		self::assertSame( 'stale_state', $this->service->update_item( $args )['error'] );
		unset( $args['expected_state'] );
		self::assertSame( 'invalid_state', $this->service->update_item( $args )['error'] );
		self::assertSame( 0, $GLOBALS['navigation_fixture_writes'] );
	}

	public function test_closed_changes_reject_malformed_values(): void {
		$invalid = array( null, new \stdClass(), array(), array( 'x' ), array( 'classes' => array() ), array( 'label' => array() ), array( 'label' => '' ), array( 'label' => str_repeat( 'x', 256 ) ), array( 'order' => 0 ), array( 'order' => 501 ), array( 'parent_id' => '102' ), array( 'target' => '_top' ), array( 'rel' => '"onclick=' ) );
		foreach ( $invalid as $changes ) {
			$args            = $this->args();
			$args['changes'] = $changes;
			self::assertArrayHasKey( 'error', $this->service->update_item( $args ) );
		}
		self::assertSame( 0, $GLOBALS['navigation_fixture_writes'] );
	}

	public function test_url_protocols_and_noncustom_links_are_rejected(): void {
		foreach ( array( 'javascript:alert(1)', '//example.org', '/\\example.org', 'data:text/plain,x', 'https://user:pass@example.org', '/%0aevil', '/hello world', '' ) as $url ) {
			self::assertSame( 'invalid_url', $this->service->update_item( $this->args( array( 'url' => $url ) ) )['error'] );
		}
		$GLOBALS['navigation_fixture_meta'][101]['_menu_item_type'] = 'post_type';
		self::assertSame( 'invalid_url', $this->service->update_item( $this->args( array( 'url' => '/safe' ) ) )['error'] );
		self::assertSame( 0, $GLOBALS['navigation_fixture_writes'] );
	}

	public function test_parent_cycles_and_foreign_parent_fail(): void {
		foreach ( array( 101, 999 ) as $parent ) {
			self::assertSame( 'invalid_parent', $this->service->update_item( $this->args( array( 'parent_id' => $parent ) ) )['error'] );
		}
		$GLOBALS['navigation_fixture_meta'][102]['_menu_item_menu_item_parent'] = 101;
		self::assertSame( 'invalid_parent', $this->service->update_item( $this->args( array( 'parent_id' => 102 ) ) )['error'] );
		self::assertSame( 0, $GLOBALS['navigation_fixture_writes'] );
	}

	public function test_large_menu_is_rejected(): void {
		for ( $id = 103; $id <= 601; ++$id ) {
			$this->add_item( $id );
		}
		self::assertSame(
			'unsupported_target',
			$this->service->read_item(
				array(
					'menu_id' => 11,
					'item_id' => 101,
				)
			)['error']
		);
	}

	public function test_deep_parent_chain_is_rejected(): void {
		for ( $id = 102; $id <= 203; ++$id ) {
			$this->add_item( $id );
			$GLOBALS['navigation_fixture_meta'][ $id ]['_menu_item_menu_item_parent'] = 203 === $id ? 0 : $id + 1;
		}
		self::assertSame( 'invalid_parent', $this->service->update_item( $this->args( array( 'parent_id' => 102 ) ) )['error'] );
		self::assertSame( 0, $GLOBALS['navigation_fixture_writes'] );
	}

	public function test_safe_relative_and_fragment_urls_and_quoted_label_roundtrip(): void {
		foreach ( array( '/safe/path?query=1', '#section', 'http://example.org/' ) as $url ) {
			$result = $this->service->update_item(
				$this->args(
					array(
						'url'   => $url,
						'label' => "Owner's link",
					)
				)
			);
			self::assertTrue( $result['success'] );
			self::assertSame( $url, $result['item']['url'] );
			self::assertSame( "Owner's link", $result['item']['label'] );
		}
	}

	public function test_unpreservable_native_states_fail_before_writing(): void {
		$GLOBALS['navigation_fixture_meta'][101]['_menu_item_classes'] = new \stdClass();
		self::assertSame(
			'unsupported_target',
			$this->service->read_item(
				array(
					'menu_id' => 11,
					'item_id' => 101,
				)
			)['error']
		);
		self::assertSame( 0, $GLOBALS['navigation_fixture_writes'] );
	}

	public function test_failed_write_without_mutation_does_not_retry(): void {
		$GLOBALS['navigation_fixture_mode'] = 'fail';
		self::assertSame( 'write_failed', $this->service->update_item( $this->args() )['error'] );
		self::assertSame( 1, $GLOBALS['navigation_fixture_writes'] );
	}

	public function test_partial_write_is_compensated_once(): void {
		$GLOBALS['navigation_fixture_mode'] = 'rollback';
		self::assertSame( 'write_failed', $this->service->update_item( $this->args() )['error'] );
		self::assertSame( 'Original', $GLOBALS['navigation_fixture_posts'][101]->post_title );
		self::assertSame( 2, $GLOBALS['navigation_fixture_writes'] );
	}

	public function test_unverifiable_compensation_is_terminal(): void {
		$GLOBALS['navigation_fixture_mode'] = 'partial';
		$result                             = $this->service->update_item( $this->args() );
		self::assertSame( 'partial_write', $result['error'] );
		self::assertTrue( $result['terminal'] );
		self::assertSame( 'partial_write', $result['status'] );
		self::assertSame( 2, $GLOBALS['navigation_fixture_writes'] );
	}

	public function test_unchanged_payload_does_not_invoke_native_hooks(): void {
		$result = $this->service->update_item( $this->args( array( 'label' => 'Original' ) ) );
		self::assertTrue( $result['success'] );
		self::assertTrue( $result['unchanged'] );
		self::assertSame( 0, $GLOBALS['navigation_fixture_writes'] );
	}
}

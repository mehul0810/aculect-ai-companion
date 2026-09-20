<?php
/**
 * Guarded Site Editor record trash regression tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\EditorRecordAbilities;
use Aculect\AICompanion\Connectors\MCP\EditorRecordDeletion;
use Aculect\AICompanion\Connectors\MCP as EditorFixture;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class EditorRecordDeletionTest extends TestCase {

	private EditorRecordDeletion $service;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/fixtures/editor-record-stubs.php';

		$GLOBALS['aculect_ai_companion_test_posts']                  = array();
		$GLOBALS['aculect_ai_companion_test_post_revisions']         = array();
		$GLOBALS['aculect_ai_companion_test_post_meta']              = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']            = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback']    = null;
		$GLOBALS['aculect_ai_companion_test_blog_id']                = 1;
		$GLOBALS['aculect_ai_companion_test_stylesheet']             = 'test-theme';
		$GLOBALS['editor_record_test_terms']                         = array();
		$GLOBALS['editor_record_test_terms_callback']                = null;
		$GLOBALS['editor_record_test_lock_callback']                 = null;
		$GLOBALS['editor_record_test_update_calls']                  = 0;
		$GLOBALS['aculect_ai_companion_test_wp_trash_post_callback'] = function ( int $post_id ): \WP_Post|false {
			return $this->native_trash_fixture( $post_id );
		};
		$this->service = new EditorRecordDeletion();

		EditorFixture\editor_record_test_seed_records();
	}

	public function test_preview_rechecks_state_without_writing_and_discloses_type_risks(): void {
		define( 'EMPTY_TRASH_DAYS', 30 );

		$published = $this->service->delete(
			array(
				'post_id'        => 101,
				'expected_state' => $this->expected_state( 101 ),
				'dry_run'        => true,
			)
		);
		self::assertSame( 'preview', $published['status'] );
		self::assertTrue( $published['dry_run'] );
		self::assertStringContainsString( 'fall back', implode( ' ', $published['warnings'] ) );
		self::assertSame( 'publish', get_post( 101 )->post_status );

		$styles = $this->service->delete(
			array(
				'post_id'        => 120,
				'expected_state' => $this->expected_state( 120 ),
				'dry_run'        => true,
			)
		);
		self::assertStringContainsString( 'global-style', implode( ' ', $styles['warnings'] ) );

		$navigation = $this->service->delete(
			array(
				'post_id'        => 110,
				'expected_state' => $this->expected_state( 110 ),
				'dry_run'        => true,
			)
		);
		self::assertStringContainsString( 'navigation', implode( ' ', $navigation['warnings'] ) );
		self::assertSame( 'publish', get_post( 110 )->post_status );
	}

	public function test_disabled_trash_refuses_preview_and_execution_without_native_call(): void {
		define( 'EMPTY_TRASH_DAYS', 0 );
		$expected = $this->expected_state( 100 );
		foreach ( array( true, false ) as $dry_run ) {
			$result = $this->service->delete(
				array(
					'post_id'        => 100,
					'expected_state' => $expected,
					'dry_run'        => $dry_run,
				)
			);
			self::assertSame( 'editor_trash_disabled', $result['error'] );
		}
		self::assertSame( 'draft', get_post( 100 )->post_status );
	}

	public function test_delete_requires_native_delete_permission_and_unlocked_record(): void {
		define( 'EMPTY_TRASH_DAYS', 30 );
		$expected = $this->expected_state( 100 );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'delete_post' );
		self::assertSame(
			'forbidden',
			$this->service->delete(
				array(
					'post_id'        => 100,
					'expected_state' => $expected,
				)
			)['error']
		);

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		$GLOBALS['editor_record_test_lock_callback']      = static fn ( int $post_id ): int => $post_id;
		self::assertSame(
			'post_locked',
			$this->service->delete(
				array(
					'post_id'        => 100,
					'expected_state' => $expected,
				)
			)['error']
		);
		self::assertSame( 'draft', get_post( 100 )->post_status );
	}

	public function test_state_is_rechecked_before_preview_and_before_native_mutation(): void {
		define( 'EMPTY_TRASH_DAYS', 30 );
		$expected = $this->expected_state( 100 );
		$checks   = 0;
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability ) use ( &$checks ): bool {
			if ( 'edit_theme_options' === $capability ) {
				++$checks;
				if ( 2 === $checks ) {
					$GLOBALS['aculect_ai_companion_test_posts'][100]->post_title = 'Changed during fresh read';
				}
			}
			return true;
		};
		$preview = $this->service->delete(
			array(
				'post_id'        => 100,
				'expected_state' => $expected,
				'dry_run'        => true,
			)
		);
		self::assertSame( 'stale_editor_state', $preview['error'] );
		self::assertSame( 'draft', get_post( 100 )->post_status );

		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_title = 'Draft template';
		$expected = $this->expected_state( 100 );
		$checks   = 0;
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability ) use ( &$checks ): bool {
			if ( 'edit_theme_options' === $capability ) {
				++$checks;
				if ( 3 === $checks ) {
					$GLOBALS['aculect_ai_companion_test_posts'][100]->post_content = EditorFixture\editor_record_test_block( 'Changed before trash' );
				}
			}
			return true;
		};
		$deleted = $this->service->delete(
			array(
				'post_id'        => 100,
				'expected_state' => $expected,
			)
		);
		self::assertSame( 'stale_editor_state', $deleted['error'] );
		self::assertSame( 'draft', get_post( 100 )->post_status );
	}

	public function test_native_trash_preserves_content_identity_terms_and_metadata_for_all_record_types(): void {
		define( 'EMPTY_TRASH_DAYS', 30 );
		foreach ( array( 100, 101, 110, 120, 130 ) as $post_id ) {
			$GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ] = array( '_editor_keep' => array( 'value-' . $post_id ) );
			$before = clone get_post( $post_id );
			$result = $this->service->delete(
				array(
					'post_id'        => $post_id,
					'expected_state' => $this->expected_state( $post_id ),
				)
			);
			self::assertTrue( $result['success'], (string) wp_json_encode( $result ) );
			self::assertTrue( $result['verified'] );
			self::assertSame( 'trash', get_post( $post_id )->post_status );
			self::assertSame( $before->ID, get_post( $post_id )->ID );
			self::assertSame( $before->post_type, get_post( $post_id )->post_type );
			self::assertSame( EditorFixture\_truncate_post_slug( $before->post_name, 191 ) . '__trashed', get_post( $post_id )->post_name );
			self::assertSame( $before->post_content, get_post( $post_id )->post_content );
			self::assertSame( array( 'value-' . $post_id ), $GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ]['_editor_keep'] );
			self::assertSame( array( $before->post_name ), $GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ]['_wp_desired_post_slug'] );
		}
	}

	public function test_native_trash_slug_and_desired_meta_follow_exact_core_contract(): void {
		define( 'EMPTY_TRASH_DAYS', 30 );
		$cases = array(
			100 => 'editor-record-100',
			101 => 'already__trashed',
			110 => str_repeat( 'long-segment-', 20 ),
		);
		foreach ( $cases as $post_id => $slug ) {
			$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ]->post_name = $slug;
			$result = $this->service->delete(
				array(
					'post_id'        => $post_id,
					'expected_state' => $this->expected_state( $post_id ),
				)
			);
			self::assertTrue( $result['success'], (string) wp_json_encode( $result ) );
			$expected_slug = str_ends_with( $slug, '__trashed' ) ? $slug : EditorFixture\_truncate_post_slug( $slug, 191 ) . '__trashed';
			self::assertSame( $expected_slug, get_post( $post_id )->post_name );
			if ( str_ends_with( $slug, '__trashed' ) ) {
				self::assertArrayNotHasKey( '_wp_desired_post_slug', $GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ] );
			} else {
				self::assertSame( array( $slug ), $GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ]['_wp_desired_post_slug'] );
			}
		}
	}

	public function test_malformed_inputs_and_stale_tokens_do_not_call_native_trash(): void {
		define( 'EMPTY_TRASH_DAYS', 30 );
		foreach ( array( null, 0, -1, '100', 1.5, array( 100 ) ) as $post_id ) {
			$result = $this->service->delete(
				array(
					'post_id'        => $post_id,
					'expected_state' => str_repeat( 'a', 64 ),
				)
			);
			self::assertSame( 'invalid_editor_record', $result['error'] );
		}
		foreach ( array( null, '', str_repeat( 'a', 63 ), str_repeat( 'A', 64 ), str_repeat( 'a', 65 ) ) as $expected ) {
			$result = $this->service->delete(
				array(
					'post_id'        => 100,
					'expected_state' => $expected,
				)
			);
			self::assertSame( 'stale_editor_state', $result['error'] );
		}
		$stale = $this->expected_state( 100 );
		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_title = 'Changed';
		self::assertSame(
			'stale_editor_state',
			$this->service->delete(
				array(
					'post_id'        => 100,
					'expected_state' => $stale,
				)
			)['error']
		);
		self::assertSame( 'draft', get_post( 100 )->post_status );
	}

	public function test_native_errors_and_postcondition_failures_are_terminal_without_retry_or_compensation(): void {
		define( 'EMPTY_TRASH_DAYS', 30 );
		foreach ( array( 'false', 'throw', 'unchanged' ) as $mode ) {
			$GLOBALS['aculect_ai_companion_test_wp_trash_post_callback'] = static function ( int $post_id ) use ( $mode ): \WP_Post|false {
				if ( 'throw' === $mode ) {
					throw new \RuntimeException( 'Synthetic native hook failure.' );
				}
				if ( 'false' === $mode ) {
					return false;
				}
				return get_post( $post_id );
			};
			$result = $this->service->delete(
				array(
					'post_id'        => 100,
					'expected_state' => $this->expected_state( 100 ),
				)
			);
			self::assertSame( 'partial_write', $result['error'] );
			self::assertTrue( $result['terminal'] );
			self::assertSame( 'draft', get_post( 100 )->post_status );
		}
		$GLOBALS['aculect_ai_companion_test_wp_trash_post_callback'] = static function ( int $post_id ): \WP_Post {
			$post              = get_post( $post_id );
			$post->post_status = 'trash';
			$GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ]['_editor_keep'] = 'changed-after-write';
			return $post;
		};
		$GLOBALS['aculect_ai_companion_test_post_meta'][100]         = array( '_editor_keep' => 'before' );
		$result = $this->service->delete(
			array(
				'post_id'        => 100,
				'expected_state' => $this->expected_state( 100 ),
			)
		);
		self::assertSame( 'partial_write', $result['error'] );
		self::assertTrue( $result['terminal'] );
	}

	public function test_unsupported_meta_shapes_fail_closed_before_native_trash(): void {
		define( 'EMPTY_TRASH_DAYS', 30 );
		$object = new \stdClass();
		foreach ( array( array( 'object' => $object ), array( 'float' => NAN ) ) as $meta ) {
			$GLOBALS['aculect_ai_companion_test_post_meta'][100] = $meta;
			$result = $this->service->delete(
				array(
					'post_id'        => 100,
					'expected_state' => $this->expected_state( 100 ),
				)
			);
			self::assertSame( 'editor_delete_unavailable', $result['error'] );
			self::assertSame( 'draft', get_post( 100 )->post_status );
		}
		$cyclic         = array();
		$cyclic['self'] =& $cyclic;
		$GLOBALS['aculect_ai_companion_test_post_meta'][100] = $cyclic;
		$result = $this->service->delete(
			array(
				'post_id'        => 100,
				'expected_state' => $this->expected_state( 100 ),
			)
		);
		self::assertSame( 'editor_delete_unavailable', $result['error'] );
		self::assertSame( 'draft', get_post( 100 )->post_status );
	}

	private function expected_state( int $post_id ): string {
		$read = ( new EditorRecordAbilities() )->read( array( 'post_id' => $post_id ) );
		self::assertArrayHasKey( 'expected_state', $read );

		return $read['expected_state'];
	}

	/**
	 * Model WordPress 7.1 native trash bookkeeping for this isolated fixture.
	 *
	 * @param int $post_id Target record.
	 * @return \WP_Post|false
	 */
	private function native_trash_fixture( int $post_id ): \WP_Post|false {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		$meta = (array) ( $GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ] ?? array() );
		if ( ! str_ends_with( $post->post_name, '__trashed' ) ) {
			$desired                       = $meta['_wp_desired_post_slug'] ?? array();
			$desired                       = is_array( $desired ) ? $desired : array( $desired );
			$desired[]                     = $post->post_name;
			$meta['_wp_desired_post_slug'] = $desired;
			$post->post_name               = EditorFixture\_truncate_post_slug( $post->post_name, 191 ) . '__trashed';
		}
		$meta['_wp_trash_meta_status'] = array( $post->post_status );
		$meta['_wp_trash_meta_time']   = array( '1726390000' );
		$post->post_status             = 'trash';
		$GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ] = $meta;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ]     = $post;

		return $post;
	}
}

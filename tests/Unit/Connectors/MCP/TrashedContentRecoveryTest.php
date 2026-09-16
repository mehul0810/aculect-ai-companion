<?php
/**
 * Bounded native trashed-content recovery regression tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\Modules\TrashedContentAbilityModules;
use Aculect\AICompanion\Connectors\MCP\TrashedContentRecovery;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class TrashedContentRecoveryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/fixtures/trashed-content-recovery-stubs.php';

		$GLOBALS['aculect_ai_companion_test_options']                    = array();
		$GLOBALS['aculect_ai_companion_test_transients']                 = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']            = 1;
		$GLOBALS['aculect_ai_companion_test_blog_id']                    = 1;
		$GLOBALS['aculect_ai_companion_test_posts']                      = array();
		$GLOBALS['aculect_ai_companion_test_post_meta']                  = array();
		$GLOBALS['aculect_ai_companion_test_post_types']                 = array(
			'post' => new \WP_Post_Type( 'post' ),
			'page' => new \WP_Post_Type( 'page' ),
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps']                = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids']            = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback']        = null;
		$GLOBALS['aculect_ai_companion_test_hooks']                      = array(
			'actions' => array(),
			'filters' => array(),
		);
		$GLOBALS['trashed_content_recovery_test_lock_callback']          = null;
		$GLOBALS['trashed_content_recovery_test_lock_calls']             = 0;
		$GLOBALS['trashed_content_recovery_test_untrash_calls']          = 0;
		$GLOBALS['trashed_content_recovery_test_untrash_override']       = null;
		$GLOBALS['trashed_content_recovery_test_untrash_after_callback'] = null;
		$GLOBALS['trashed_content_recovery_test_comments_restored']      = array();

		$this->seed_posts();
	}

	public function test_inspection_returns_only_minimal_state_and_rejects_invalid_targets(): void {
		$result = ( new TrashedContentRecovery() )->inspect_trashed( $this->target_args() );

		self::assertSame(
			array( 'post_id', 'post_type', 'prior_status', 'expected_state' ),
			array_keys( $result )
		);
		self::assertSame( 100, $result['post_id'] );
		self::assertSame( 'post', $result['post_type'] );
		self::assertSame( 'publish', $result['prior_status'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['expected_state'] );
		$encoded = wp_json_encode( $result );
		self::assertStringNotContainsString( 'Private trashed content', (string) $encoded );
		self::assertStringNotContainsString( 'metadata-secret', (string) $encoded );
		self::assertStringNotContainsString( '_wp_trash_meta_time', (string) $encoded );

		foreach ( array( 0, -1, '100', 1.5, true, array( 100 ) ) as $invalid_id ) {
			$args            = $this->target_args();
			$args['post_id'] = $invalid_id;
			self::assertSame( 'invalid_trashed_target', ( new TrashedContentRecovery() )->inspect_trashed( $args )['error'] );
		}
	}

	public function test_inspection_requires_native_read_and_edit_capabilities(): void {
		foreach ( array( 'read_post', 'edit_post' ) as $denied_capability ) {
			$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn ( string $capability ): bool => $denied_capability !== $capability;
			self::assertSame( 'forbidden', ( new TrashedContentRecovery() )->inspect_trashed( $this->target_args() )['error'] );
		}
	}

	public function test_inspection_accepts_only_posts_pages_and_known_prior_statuses(): void {
		$service = new TrashedContentRecovery();
		$page    = $service->inspect_trashed( array( 'post_id' => 101 ) );
		self::assertSame( 101, $page['post_id'] );
		self::assertSame( 'page', $page['post_type'] );

		foreach ( array( 'draft', 'pending', 'private', 'publish', 'future' ) as $status ) {
			$GLOBALS['aculect_ai_companion_test_post_meta'][100]['_wp_trash_meta_status'] = $status;
			self::assertSame( $status, $service->inspect_trashed( $this->target_args() )['prior_status'] );
		}

		$GLOBALS['aculect_ai_companion_test_post_meta'][100]['_wp_trash_meta_status'] = 'inherit';
		self::assertSame( 'invalid_trash_state', $service->inspect_trashed( $this->target_args() )['error'] );
		unset( $GLOBALS['aculect_ai_companion_test_post_meta'][100]['_wp_trash_meta_time'] );
		self::assertSame( 'invalid_trash_state', $service->inspect_trashed( $this->target_args() )['error'] );
		$GLOBALS['aculect_ai_companion_test_post_meta'][100]['_wp_trash_meta_time'] = '1789478400';

		$GLOBALS['aculect_ai_companion_test_post_meta'][100]['_wp_trash_meta_status'] = 'draft';
		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_status                 = 'draft';
		self::assertSame( 'not_trashed', $service->inspect_trashed( $this->target_args() )['error'] );

		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_status = 'trash';
		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_type   = 'book';
		self::assertSame( 'unsupported_post', $service->inspect_trashed( $this->target_args() )['error'] );
	}

	public function test_restore_rejects_denied_delete_permission_and_stale_state(): void {
		$service  = new TrashedContentRecovery();
		$expected = $service->inspect_trashed( $this->target_args() )['expected_state'];
		$args     = $this->restore_args( $expected );

		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn ( string $capability ): bool => 'delete_post' !== $capability;
		self::assertSame( 'forbidden', $service->restore_trashed( $args )['error'] );
		self::assertSame( 0, $GLOBALS['trashed_content_recovery_test_untrash_calls'] );

		$GLOBALS['aculect_ai_companion_test_capability_callback']      = null;
		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_content = 'Changed content';
		self::assertSame( 'stale_trashed_state', $service->restore_trashed( $args )['error'] );
		self::assertSame( 0, $GLOBALS['trashed_content_recovery_test_untrash_calls'] );

		foreach ( array( null, 1, str_repeat( '0', 63 ), str_repeat( 'G', 64 ) ) as $invalid_expected ) {
			$invalid_args                   = $this->restore_args( $expected );
			$invalid_args['expected_state'] = $invalid_expected;
			self::assertSame( 'stale_trashed_state', $service->restore_trashed( $invalid_args )['error'] );
		}
	}

	public function test_state_is_bound_to_site_and_native_trash_metadata(): void {
		$service  = new TrashedContentRecovery();
		$expected = $service->inspect_trashed( $this->target_args() )['expected_state'];
		$args     = $this->restore_args( $expected );

		$GLOBALS['aculect_ai_companion_test_blog_id'] = 2;
		self::assertSame( 'stale_trashed_state', $service->restore_trashed( $args )['error'] );
		$GLOBALS['aculect_ai_companion_test_blog_id'] = 1;

		$GLOBALS['aculect_ai_companion_test_post_meta'][100]['_wp_trash_meta_time'] = '1789478401';
		self::assertSame( 'stale_trashed_state', $service->restore_trashed( $args )['error'] );
		self::assertSame( 0, $GLOBALS['trashed_content_recovery_test_untrash_calls'] );
	}

	public function test_restore_rechecks_state_immediately_before_native_write(): void {
		$service  = new TrashedContentRecovery();
		$expected = $service->inspect_trashed( $this->target_args() )['expected_state'];
		$args     = $this->restore_args( $expected );
		$calls    = 0;
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability ) use ( &$calls ): bool {
			++$calls;
			if ( 4 === $calls ) {
				$GLOBALS['aculect_ai_companion_test_posts'][100]->post_title = 'Changed at final check';
			}
			return '' !== $capability;
		};

		$result = $service->restore_trashed( $args );
		self::assertSame( 'stale_trashed_state', $result['error'] );
		self::assertSame( 0, $GLOBALS['trashed_content_recovery_test_untrash_calls'] );
	}

	public function test_restore_stops_for_a_native_edit_lock(): void {
		$service  = new TrashedContentRecovery();
		$expected = $service->inspect_trashed( $this->target_args() )['expected_state'];
		$GLOBALS['trashed_content_recovery_test_lock_callback'] = static fn ( int $post_id ): int => 100 === $post_id ? 7 : 8;

		$result = $service->restore_trashed( $this->restore_args( $expected ) );
		self::assertSame( 'post_locked', $result['error'] );
		self::assertSame( 1, $GLOBALS['trashed_content_recovery_test_lock_calls'] );
		self::assertSame( 0, $GLOBALS['trashed_content_recovery_test_untrash_calls'] );
	}

	public function test_preview_is_side_effect_free_and_discloses_draft_and_comment_behavior(): void {
		$service  = new TrashedContentRecovery();
		$expected = $service->inspect_trashed( $this->target_args() )['expected_state'];
		$args     = $this->restore_args( $expected ) + array( 'dry_run' => true );
		$result   = $service->restore_trashed( $args );

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'draft', $result['target']['restore_status'] );
		self::assertStringContainsString( 'comments', strtolower( implode( ' ', $result['warnings'] ) ) );
		self::assertStringContainsString( 'nothing is republished', strtolower( implode( ' ', $result['warnings'] ) ) );
		self::assertSame( 'trash', get_post( 100 )->post_status );
		self::assertTrue( metadata_exists( 'post', 100, '_wp_trash_meta_status' ) );
		self::assertSame( 0, $GLOBALS['trashed_content_recovery_test_untrash_calls'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_hooks']['filters'] );
	}

	public function test_native_restore_forces_published_content_to_draft_and_cleans_only_its_filter(): void {
		$other_filter = static function ( string $status, int $post_id ): string {
			return 100 === $post_id ? 'publish' : $status;
		};
		add_filter( 'wp_untrash_post_status', $other_filter, PHP_INT_MAX, 2 );
		$service  = new TrashedContentRecovery();
		$expected = $service->inspect_trashed( $this->target_args() )['expected_state'];
		$result   = $service->restore_trashed( $this->restore_args( $expected ) );

		self::assertTrue( $result['success'] );
		self::assertTrue( $result['changed'] );
		self::assertSame( 'publish', $result['prior_status'] );
		self::assertSame( 'draft', get_post( 100 )->post_status );
		self::assertFalse( metadata_exists( 'post', 100, '_wp_trash_meta_status' ) );
		self::assertFalse( metadata_exists( 'post', 100, '_wp_trash_meta_time' ) );
		self::assertSame( 'metadata-secret', get_post_meta( 100, '_private_fixture_meta', true ) );
		self::assertSame( array( 100 ), $GLOBALS['trashed_content_recovery_test_comments_restored'] );
		self::assertCount( 1, $GLOBALS['aculect_ai_companion_test_hooks']['filters'] );
		self::assertSame( $other_filter, $GLOBALS['aculect_ai_companion_test_hooks']['filters'][0]['callback'] );
		self::assertSame( PHP_INT_MAX, $GLOBALS['aculect_ai_companion_test_hooks']['filters'][0]['priority'] );
	}

	public function test_native_false_and_throwing_hooks_are_terminal_and_filter_is_cleaned(): void {
		$service  = new TrashedContentRecovery();
		$expected = $service->inspect_trashed( $this->target_args() )['expected_state'];
		$GLOBALS['trashed_content_recovery_test_untrash_override'] = static function ( int $post_id ): false {
			self::assertSame( 100, $post_id );
			return false;
		};

		$failed = $service->restore_trashed( $this->restore_args( $expected ) );
		self::assertSame( 'partial_write', $failed['error'] );
		self::assertTrue( $failed['terminal'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_hooks']['filters'] );

		$GLOBALS['trashed_content_recovery_test_untrash_override']       = null;
		$GLOBALS['trashed_content_recovery_test_untrash_after_callback'] = static function ( int $post_id ): never {
			unset( $post_id );
			throw new \RuntimeException( 'Simulated native untrash hook failure.' );
		};
		$thrown = $service->restore_trashed( $this->restore_args( $expected ) );
		self::assertSame( 'partial_write', $thrown['error'] );
		self::assertTrue( $thrown['terminal'] );
		self::assertSame( 'draft', get_post( 100 )->post_status );
		self::assertFalse( metadata_exists( 'post', 100, '_wp_trash_meta_status' ) );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_hooks']['filters'] );
	}

	public function test_module_descriptors_keep_read_and_confirmed_write_scopes_narrow(): void {
		$modules = ( new TrashedContentAbilityModules() )->all();
		self::assertCount( 2, $modules );
		self::assertSame( 'content.inspect_trashed', $modules[0]->id() );
		self::assertTrue( $modules[0]->is_read_only() );
		self::assertSame( array( 'content:read' ), $modules[0]->required_scopes() );
		self::assertSame( array( 'post_id' ), $modules[0]->input_schema()['required'] );
		self::assertSame( 'content.restore_trashed', $modules[1]->id() );
		self::assertFalse( $modules[1]->is_read_only() );
		self::assertSame( array( 'content:draft' ), $modules[1]->required_scopes() );
		self::assertSame( array( 'post_id', 'expected_state' ), $modules[1]->input_schema()['required'] );
		self::assertSame( '^[a-f0-9]{64}$', $modules[1]->input_schema()['properties']['expected_state']['pattern'] );
		self::assertStringContainsString( 'always returns the item to draft', $modules[1]->description() );
		self::assertStringContainsString( 'explicit confirmation', $modules[1]->description() );
	}

	/** Seed one trashed post and its native trash metadata. */
	private function seed_posts(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][100]     = new \WP_Post(
			array(
				'ID'                => 100,
				'post_type'         => 'post',
				'post_status'       => 'trash',
				'post_name'         => 'trashed-post',
				'post_author'       => 1,
				'post_parent'       => 0,
				'post_date_gmt'     => '2026-09-01 09:00:00',
				'post_modified_gmt' => '2026-09-14 09:00:00',
				'post_title'        => 'Private trashed title',
				'post_content'      => 'Private trashed content',
				'post_excerpt'      => 'Private trashed excerpt',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][101]     = new \WP_Post(
			array(
				'ID'                => 101,
				'post_type'         => 'page',
				'post_status'       => 'trash',
				'post_name'         => 'trashed-page',
				'post_author'       => 1,
				'post_parent'       => 0,
				'post_date_gmt'     => '2026-09-01 09:00:00',
				'post_modified_gmt' => '2026-09-14 09:00:00',
				'post_title'        => 'Trashed page title',
				'post_content'      => 'Trashed page content',
				'post_excerpt'      => 'Trashed page excerpt',
			)
		);
		$GLOBALS['aculect_ai_companion_test_post_meta'][100] = array(
			'_wp_trash_meta_status' => 'publish',
			'_wp_trash_meta_time'   => '1789478400',
			'_private_fixture_meta' => 'metadata-secret',
		);
		$GLOBALS['aculect_ai_companion_test_post_meta'][101] = array(
			'_wp_trash_meta_status' => 'draft',
			'_wp_trash_meta_time'   => '1789478400',
		);
	}

	/**
	 * Exact target arguments for the seeded post.
	 *
	 * @return array{post_id:int}
	 */
	private function target_args(): array {
		return array( 'post_id' => 100 );
	}

	/**
	 * Restore arguments with one fresh expected-state token.
	 *
	 * @param mixed $expected_state HMAC from inspection.
	 * @return array{post_id:int,expected_state:mixed}
	 */
	private function restore_args( mixed $expected_state ): array {
		return $this->target_args() + array( 'expected_state' => $expected_state );
	}
}

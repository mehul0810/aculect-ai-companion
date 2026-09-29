<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\PendingApprovalInstaller;
use Aculect\AICompanion\Connectors\MCP\PendingOperationApprovalStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/McpApprovalWpdb.php';

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused persistence boundary tests use an isolated wpdb double.
final class PendingOperationApprovalStoreTest extends TestCase {

	private mixed $original_wpdb;
	private array $original_globals = array();
	private \McpApprovalWpdb $wpdb;
	private int $now = 1790330400;

	protected function setUp(): void {
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		foreach ( array( 'aculect_ai_companion_test_options', 'aculect_ai_companion_test_current_user_id', 'aculect_ai_companion_test_blog_id', 'aculect_ai_companion_test_denied_caps', 'aculect_ai_companion_test_db_delta_callback', 'aculect_ai_companion_test_scheduled_events', 'aculect_ai_companion_test_posts', 'mcp_approval_schedule_failure', 'mcp_approval_test_salt' ) as $key ) {
			$this->original_globals[ $key ] = $GLOBALS[ $key ] ?? null;
		}
		$this->wpdb                                   = new \McpApprovalWpdb();
		$GLOBALS['wpdb']                              = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']   = 17;
		$GLOBALS['aculect_ai_companion_test_blog_id']           = 3;
		$GLOBALS['aculect_ai_companion_test_denied_caps']       = array();
		$GLOBALS['aculect_ai_companion_test_posts']             = array(
			9  => array(
				'ID'                => 9,
				'post_modified_gmt' => '2026-09-29 10:00:00',
			),
			81 => array(
				'ID'                => 81,
				'post_modified_gmt' => '2026-09-29 10:00:00',
			),
		);
		$GLOBALS['mcp_approval_test_salt']                      = 'test-salt-generation-one';
		$GLOBALS['aculect_ai_companion_test_scheduled_events']  = array();
		$GLOBALS['mcp_approval_schedule_failure']               = false;
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = function ( string $sql ): array {
			self::assertStringContainsString( 'ENGINE=InnoDB', $sql );
			self::assertStringContainsString( 'exact_request', $sql );
			$this->wpdb->table_exists = true;
			return array();
		};
		PendingApprovalInstaller::reset_request_cache();
	}

	protected function tearDown(): void {
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		foreach ( $this->original_globals as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $key ] );
			} else {
				$GLOBALS[ $key ] = $value;
			}
		}
		PendingApprovalInstaller::reset_request_cache();
		parent::tearDown();
	}

	public function test_create_encrypts_exact_payload_and_binds_actor_site_client_and_fingerprint(): void {
		$store = $this->store();
		$id    = $store->create(
			'content_update',
			array(
				'title'  => 'Private exact title',
				'target' => array( 'id' => 81 ),
			),
			$this->summary( 'Private exact title' ),
			'oauth-client-abc',
			'edit_post',
			array( 81 ),
			'access-token-1'
		);

		self::assertIsInt( $id );
		$row = $this->wpdb->rows[ $id ];
		self::assertSame( 3, $row['blog_id'] );
		self::assertSame( 17, $row['user_id'] );
		self::assertNotSame( 'oauth-client-abc', $row['client_hash'] );
		self::assertStringNotContainsString( 'Private exact title', $row['payload_ciphertext'] );
		self::assertArrayNotHasKey( 'arguments', $row );
		self::assertStringStartsWith( 'v1:', $row['payload_ciphertext'] );
		self::assertSame( 'pending', $row['status'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', $this->now + PendingOperationApprovalStore::TTL_SECONDS ), $row['expires_at'] );

		$pending = $store->pending_for_current_actor();
		self::assertCount( 1, $pending );
		self::assertSame( 'Private exact title', $pending[0]['review_summary']['changes'][0]['after'] );
		self::assertNotSame( hash( 'sha256', "content_update\0{\"target\":{\"id\":81},\"title\":\"Private exact title\"}" ), $pending[0]['operation_fingerprint'] );
		self::assertArrayHasKey( PendingOperationApprovalStore::CLEANUP_HOOK, $GLOBALS['aculect_ai_companion_test_scheduled_events'] );
	}

	public function test_fingerprint_is_key_order_independent_and_tampering_fails_closed(): void {
		$store  = $this->store();
		$first  = $store->create(
			'content_update',
			array(
				'title'  => 'Same',
				'target' => array(
					'id'   => 9,
					'type' => 'post',
				),
			),
			$this->summary( 'Same', 9 ),
			'client-1',
			'edit_post',
			array( 9 ),
			'access-token-1'
		);
		$second = $store->create(
			'content_update',
			array(
				'target' => array(
					'type' => 'post',
					'id'   => 9,
				),
				'title'  => 'Same',
			),
			$this->summary( 'Same', 9 ),
			'client-1',
			'edit_post',
			array( 9 ),
			'access-token-1'
		);
		self::assertIsInt( $first );
		self::assertIsInt( $second );
		$first_request  = $store->find_pending_for_current_actor( $first );
		$second_request = $store->find_pending_for_current_actor( $second );
		self::assertIsArray( $first_request );
		self::assertIsArray( $second_request );
		self::assertSame( $first_request['operation_fingerprint'], $second_request['operation_fingerprint'] );
		self::assertTrue( $store->decide_for_current_actor( $first, 'approved' ) );
		$exact_args = array(
			'title'  => 'Same',
			'target' => array(
				'id'   => 9,
				'type' => 'post',
			),
		);
		self::assertTrue( $store->matches_approved_operation( $first, 'content_update', $exact_args, 'client-1', 'access-token-1' ) );
		self::assertFalse( $store->matches_approved_operation( $first, 'content_update', array( 'title' => 'Changed' ), 'client-1', 'access-token-1' ) );
		self::assertFalse( $store->matches_approved_operation( $first, 'content_update', $exact_args, 'another-client', 'access-token-1' ) );

		$this->wpdb->rows[ $first ]['operation_fingerprint'] = str_repeat( '0', 64 );
		self::assertNull( $store->find_pending_for_current_actor( $first ) );
	}

	public function test_wrong_actor_or_site_cannot_read_or_decide_and_decision_is_single_use(): void {
		$store = $this->store();
		$id    = $this->create_request( $store, array( 'target' => array( 'id' => 81 ) ) );
		self::assertIsInt( $id );
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 18;
		self::assertSame( array(), $store->pending_for_current_actor() );
		self::assertFalse( $store->decide_for_current_actor( $id, 'approved' ) );
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 17;
		$GLOBALS['aculect_ai_companion_test_blog_id']         = 4;
		self::assertNull( $store->find_pending_for_current_actor( $id ) );
		self::assertFalse( $store->decide_for_current_actor( $id, 'approved' ) );
		$GLOBALS['aculect_ai_companion_test_blog_id'] = 3;

		self::assertTrue( $store->decide_for_current_actor( $id, 'approved' ) );
		self::assertFalse( $store->decide_for_current_actor( $id, 'declined' ) );
		self::assertSame( 'approved', $this->wpdb->rows[ $id ]['status'] );
		self::assertSame( 17, $this->wpdb->rows[ $id ]['decided_by'] );
	}

	public function test_execution_claim_requires_same_session_exact_args_policy_target_and_is_one_use(): void {
		$store      = $this->store();
		$arguments  = array(
			'id'                    => 81,
			'title'                 => 'Approved exact title',
			'expected_modified_gmt' => '2026-09-29 10:00:00',
		);
		$client_id  = 'approval-client';
		$session_id = 'approval-session-a';
		$policy     = hash( 'sha256', 'approval-policy-v1' );
		$id         = $store->create(
			'content_workflow.update_post',
			$arguments,
			$this->summary( 'Approved exact title' ),
			$client_id,
			'edit_post',
			array( 81 ),
			$session_id,
			$policy
		);
		self::assertIsInt( $id );
		self::assertTrue( $store->decide_for_current_actor( $id, 'approved' ) );
		self::assertSame( 'approved', $store->exact_operation_status( 'content_workflow.update_post', $arguments, $client_id, $session_id, 'edit_post', array( 81 ), $policy ) );
		self::assertNull( $store->exact_operation_status( 'content_workflow.update_post', $arguments, $client_id, 'approval-session-b', 'edit_post', array( 81 ), $policy ) );
		self::assertNull(
			$store->exact_operation_status(
				'content_workflow.update_post',
				array(
					'id'                    => 81,
					'title'                 => 'Changed title',
					'expected_modified_gmt' => '2026-09-29 10:00:00',
				),
				$client_id,
				$session_id,
				'edit_post',
				array( 81 ),
				$policy
			)
		);
		self::assertNull( $store->exact_operation_status( 'content_workflow.update_post', $arguments, $client_id, $session_id, 'edit_post', array( 81 ), hash( 'sha256', 'approval-policy-v2' ) ) );
		$GLOBALS['aculect_ai_companion_test_posts'][81]['post_title'] = 'Changed within the same second';
		self::assertSame( 'stale', $store->exact_operation_status( 'content_workflow.update_post', $arguments, $client_id, $session_id, 'edit_post', array( 81 ), $policy ) );
		self::assertFalse( $store->consume_approved_operation( 'content_workflow.update_post', $arguments, $client_id, $session_id, 'edit_post', array( 81 ), $policy ) );
		$GLOBALS['aculect_ai_companion_test_posts'][81]['post_title'] = '';
		$second_arguments = array(
			'id'                    => 81,
			'title'                 => 'Second approved title',
			'expected_modified_gmt' => '2026-09-29 10:00:00',
		);
		$second_id        = $store->create( 'content_workflow.update_post', $second_arguments, $this->summary( 'Second approved title' ), $client_id, 'edit_post', array( 81 ), $session_id, $policy );
		self::assertIsInt( $second_id );
		self::assertTrue( $store->decide_for_current_actor( $second_id, 'approved' ) );
		self::assertSame( 'approved', $store->exact_operation_status( 'content_workflow.update_post', $second_arguments, $client_id, $session_id, 'edit_post', array( 81 ), $policy ) );
		self::assertTrue( $store->consume_approved_operation( 'content_workflow.update_post', $second_arguments, $client_id, $session_id, 'edit_post', array( 81 ), $policy ) );
		self::assertFalse( $store->consume_approved_operation( 'content_workflow.update_post', $second_arguments, $client_id, $session_id, 'edit_post', array( 81 ), $policy ) );
		self::assertSame( 'consumed', $store->exact_operation_status( 'content_workflow.update_post', $second_arguments, $client_id, $session_id, 'edit_post', array( 81 ), $policy ) );
	}

	public function test_target_drift_during_preview_prevents_approval_row_creation(): void {
		$store  = $this->store();
		$before = $store->target_state_fingerprint_for( 81 );
		self::assertIsString( $before );
		$GLOBALS['aculect_ai_companion_test_posts'][81]['post_title'] = 'Same-second concurrent edit';
		$result = $store->create(
			'content_update',
			array(
				'id'    => 81,
				'title' => 'Proposed title',
			),
			$this->summary( 'Proposed title' ),
			'approval-client',
			'edit_post',
			array( 81 ),
			'approval-session',
			hash( 'sha256', 'approval-policy' ),
			$before
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'approval_target_changed', $result->get_error_code() );
		self::assertSame( array(), $this->wpdb->rows );
	}

	public function test_decision_rechecks_capability_and_rejects_invalid_or_expired_requests(): void {
		$store = $this->store();
		$id    = $this->create_request( $store, array( 'target' => array( 'id' => 81 ) ) );
		self::assertIsInt( $id );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_post' );
		self::assertSame( array(), $store->pending_for_current_actor() );
		self::assertNull( $store->find_pending_for_current_actor( $id ) );
		self::assertFalse( $store->decide_for_current_actor( $id, 'approved' ) );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		self::assertFalse( $store->decide_for_current_actor( $id, 'execute' ) );

		$this->now += PendingOperationApprovalStore::TTL_SECONDS + 1;
		self::assertNull( $store->find_pending_for_current_actor( $id ) );
		self::assertFalse( $store->decide_for_current_actor( $id, 'declined' ) );
		self::assertSame( 0, $store->prune_expired( 1 ) );
		self::assertArrayNotHasKey( $id, $this->wpdb->rows );
	}

	public function test_outstanding_quota_and_cleanup_schedule_is_best_effort(): void {
		$store = $this->store();
		for ( $index = 0; $index < PendingOperationApprovalStore::MAX_OUTSTANDING_PER_ACTOR; ++$index ) {
			self::assertIsInt( $this->create_request( $store, array( 'title' => 'Change ' . $index ) ) );
		}
		self::assertSame( 'approval_queue_full', $this->create_request( $store, array( 'title' => 'One more' ) )->get_error_code() );
		self::assertCount( PendingOperationApprovalStore::MAX_OUTSTANDING_PER_ACTOR, $store->pending_for_current_actor() );
		$this->now += PendingOperationApprovalStore::TTL_SECONDS + 1;
		self::assertSame( PendingOperationApprovalStore::MAX_OUTSTANDING_PER_ACTOR, $store->prune_expired() );
		self::assertSame( array(), $this->wpdb->rows );

		$GLOBALS['aculect_ai_companion_test_scheduled_events'] = array();
		$GLOBALS['mcp_approval_schedule_failure']              = true;
		self::assertIsInt( $this->create_request( $store, array( 'title' => 'Cleanup is traffic dependent' ) ) );
		self::assertArrayNotHasKey( PendingOperationApprovalStore::CLEANUP_HOOK, $GLOBALS['aculect_ai_companion_test_scheduled_events'] );
	}

	public function test_invalid_payload_size_depth_and_capability_fail_closed(): void {
		$store = $this->store();
		$deep  = 'too deep';
		for ( $level = 0; $level <= 14; ++$level ) {
			$deep = array( 'child' => $deep );
		}
		foreach ( array(
			$store->create( 'content_update', array( 'content' => str_repeat( 'x', PendingOperationApprovalStore::MAX_ARGUMENTS ) ), $this->summary(), 'client-1', 'edit_post', array( 81 ) ),
			$store->create( 'not a public tool', array(), $this->summary(), 'client-1', 'edit_post', array( 81 ) ),
			$store->create( 'content_update', array( 'nested' => $deep ), $this->summary(), 'client-1', 'edit_post', array( 81 ) ),
		) as $result ) {
			self::assertInstanceOf( \WP_Error::class, $result );
		}
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_post' );
		self::assertInstanceOf( \WP_Error::class, $store->create( 'content_update', array(), $this->summary(), 'client-1', 'edit_post', array( 81 ) ) );
		self::assertSame( array(), $this->wpdb->rows );
	}

	public function test_rotation_of_wordpress_auth_salt_makes_payload_unreadable(): void {
		$store = $this->store();
		$id    = $this->create_request( $store, array( 'title' => 'Secret' ), 'edit_posts', array() );
		self::assertIsInt( $id );
		$GLOBALS['mcp_approval_test_salt'] = 'test-salt-generation-two';
		self::assertSame( array(), $store->pending_for_current_actor() );
	}

	private function store(): PendingOperationApprovalStore {
		return new PendingOperationApprovalStore( fn (): int => $this->now );
	}

	private function create_request( PendingOperationApprovalStore $store, array $arguments, string $capability = 'edit_post', array $capability_args = array( 81 ) ): int|\WP_Error {
		return $store->create( 'content_update', $arguments, $this->summary(), 'client-1', $capability, $capability_args, 'access-token-1' );
	}

	private function summary( string $after = 'Proposed change', int $target_id = 81 ): array {
		return array(
			'operation'      => 'Update content',
			'target'         => 'Post ' . $target_id,
			'target_id'      => $target_id,
			'target_version' => '2026-09-29 10:00:00',
			'changes'        => array(
				array(
					'label'  => 'Title',
					'before' => 'Current title',
					'after'  => $after,
				),
			),
		);
	}
}

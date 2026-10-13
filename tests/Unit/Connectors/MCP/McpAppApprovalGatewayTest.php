<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Admin\McpApprovalQueue;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionRequest;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpAppApprovalHandoff;
use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\PendingApprovalInstaller;
use Aculect\AICompanion\Connectors\MCP\PendingOperationApprovalStore;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use Aculect\AICompanion\Tests\Support\InMemoryExecutionClaimStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/McpApprovalWpdb.php';
require_once dirname( __DIR__, 3 ) . '/Support/InMemoryExecutionClaimStore.php';

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- End-to-end approval workflow uses the plugin's deterministic WordPress fixture.
final class McpAppApprovalGatewayTest extends TestCase {

	private mixed $previous_wpdb;
	private mixed $previous_db_delta;
	private mixed $previous_salt;
	private mixed $previous_post_meta;
	private mixed $previous_filter_callbacks;
	private mixed $previous_users;
	private mixed $previous_blocks;
	private \McpApprovalWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb                          = $GLOBALS['wpdb'] ?? null;
		$this->previous_db_delta                      = $GLOBALS['aculect_ai_companion_test_db_delta_callback'] ?? null;
		$this->previous_salt                          = $GLOBALS['mcp_approval_test_salt'] ?? null;
		$this->previous_post_meta                     = $GLOBALS['aculect_ai_companion_test_post_meta'] ?? null;
		$this->previous_filter_callbacks              = $GLOBALS['aculect_ai_companion_test_filter_callbacks'] ?? null;
		$this->previous_users                         = $GLOBALS['aculect_ai_companion_test_users'] ?? null;
		$this->previous_blocks                        = $GLOBALS['aculect_ai_companion_test_blocks'] ?? null;
		$this->wpdb                                   = new \McpApprovalWpdb();
		$GLOBALS['wpdb']                              = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']  = array(
			'aculect_ai_companion_mcp_apps_enabled' => static fn (): bool => true,
		);
		$GLOBALS['aculect_ai_companion_test_current_user_id']   = 7;
		$GLOBALS['aculect_ai_companion_test_blog_id']           = 1;
		$GLOBALS['aculect_ai_companion_test_denied_caps']       = array();
		$GLOBALS['aculect_ai_companion_test_users'][7]          = (object) array(
			'ID'           => 7,
			'roles'        => array( 'administrator' ),
			'display_name' => 'Site Administrator',
			'user_login'   => 'site-admin',
		);
		$GLOBALS['aculect_ai_companion_test_posts']             = array(
			123 => new \WP_Post(
				// @phpstan-ignore argument.type (The deterministic test WP_Post stub accepts array input.)
				array(
					'ID'                => 123,
					'post_type'         => 'post',
					'post_status'       => 'draft',
					'post_title'        => 'Original title',
					'post_content'      => 'Original content',
					'post_modified_gmt' => '2026-09-25 10:00:00',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_post_meta']         = array();
		$GLOBALS['aculect_ai_companion_test_scheduled_events']  = array();
		$GLOBALS['mcp_approval_test_salt']                      = 'gateway-approval-test-salt';
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = function (): array {
			$this->wpdb->table_exists = true;
			return array();
		};
		PendingApprovalInstaller::reset_request_cache();
	}

	protected function tearDown(): void {
		$this->restore_global( 'wpdb', $this->previous_wpdb );
		$this->restore_global( 'aculect_ai_companion_test_db_delta_callback', $this->previous_db_delta );
		$this->restore_global( 'mcp_approval_test_salt', $this->previous_salt );
		$this->restore_global( 'aculect_ai_companion_test_post_meta', $this->previous_post_meta );
		$this->restore_global( 'aculect_ai_companion_test_filter_callbacks', $this->previous_filter_callbacks );
		$this->restore_global( 'aculect_ai_companion_test_users', $this->previous_users );
		$this->restore_global( 'aculect_ai_companion_test_blocks', $this->previous_blocks );
		PendingApprovalInstaller::reset_request_cache();
		parent::tearDown();
	}

	public function test_human_approval_executes_exact_call_once_and_does_not_leak_replay_across_token_sessions(): void {
		$gateway = $this->gateway();
		$request = $this->request( 'session-a', 'Approved title' );
		$pending = $this->call_app( $gateway, $request );
		self::assertSame( 'approval_pending', $pending->data['result']['status'] ?? '' );
		self::assertArrayNotHasKey( 'confirmation_token', $pending->data['result'] ?? array() );

		$id       = $this->approve_pending_request();
		$executed = $this->call_app( $gateway, $request );
		self::assertSame( 'success', $executed->data['result']['outcome'] ?? '' );
		self::assertSame( 'Approved title', $this->post_title() );
		self::assertSame( 'consumed', $this->wpdb->rows[ $id ]['status'] ?? '' );

		$post = get_post( 123 );
		self::assertInstanceOf( \WP_Post::class, $post );
		$post->post_modified_gmt = '2026-09-25 10:01:00';
		$post->post_title        = 'Later human edit';

		$replay = $this->call_app( $gateway, $request );
		self::assertSame( 'success', $replay->data['result']['outcome'] ?? '' );
		self::assertSame( 'Later human edit', $this->post_title() );
		self::assertSame( 'consumed', $this->wpdb->rows[ $id ]['status'] ?? '' );

		$this->set_post_title( 'Original title' );
		$other_session = $this->call_app( $gateway, $this->request( 'session-b', 'Approved title' ) );
		self::assertSame( 'approval_pending', $other_session->data['result']['status'] ?? '' );
		self::assertSame( 'Original title', $this->post_title() );
		$approval_events = array_filter( $this->wpdb->activity_rows, static fn ( array $row ): bool => str_starts_with( (string) ( $row['action'] ?? '' ), 'mcp.timeline.approval_' ) );
		$references      = array();
		foreach ( $approval_events as $event ) {
			$context = json_decode( (string) ( $event['context'] ?? '' ), true );
			if ( is_array( $context ) && isset( $context['approval_ref'] ) ) {
				$references[ $event['action'] ] = $context['approval_ref'];
			}
		}
		self::assertArrayHasKey( 'mcp.timeline.approval_decision', $references );
		self::assertArrayHasKey( 'mcp.timeline.approval_consumed', $references );
		self::assertArrayHasKey( 'mcp.timeline.approval_execution', $references );
		self::assertSame( $references['mcp.timeline.approval_decision'], $references['mcp.timeline.approval_execution'] );
	}

	public function test_partial_write_audit_is_truthful_and_correlated_without_payload_data(): void {
		$events  = array();
		$handoff = new McpAppApprovalHandoff(
			new AbilitiesRegistry(),
			new ToolSafety( new InMemoryExecutionClaimStore() ),
			static function ( string $event, array $metadata ) use ( &$events ): void {
				$events[] = array(
					'event'    => $event,
					'metadata' => $metadata,
				);
			}
		);
		$request = array(
			'id'                    => 41,
			'operation_fingerprint' => str_repeat( 'a', 64 ),
		);
		$handoff->record_execution_result( 'content_workflow.update_post', array( 'user_id' => 7 ), array( 'error' => 'partial_write' ), $request );

		self::assertSame( 'validated', $events[0]['metadata']['status'] );
		self::assertSame( 'partial_write', $events[0]['metadata']['result_class'] );
		self::assertSame( PendingOperationApprovalStore::audit_reference( $request ), $events[0]['metadata']['approval_ref'] );
		self::assertNotSame(
			PendingOperationApprovalStore::audit_reference( $request ),
			PendingOperationApprovalStore::audit_reference(
				array(
					'id'                    => 42,
					'operation_fingerprint' => str_repeat( 'a', 64 ),
				)
			)
		);
		self::assertArrayNotHasKey( 'arguments', $events[0]['metadata'] );
	}

	public function test_retry_without_client_idempotency_key_replays_after_target_version_changes(): void {
		$gateway = $this->gateway();
		$request = $this->request( 'session-no-key', 'Approved without key', false );
		self::assertSame( 'approval_pending', $this->call_app( $gateway, $request )->data['result']['status'] ?? '' );
		$id = $this->approve_pending_request();
		self::assertSame( 'success', $this->call_app( $gateway, $request )->data['result']['outcome'] ?? '' );
		$post = get_post( 123 );
		self::assertInstanceOf( \WP_Post::class, $post );
		$post->post_title        = 'Later human edit';
		$post->post_modified_gmt = '2026-09-25 10:01:00';

		$replayed = $this->call_app( $gateway, $request )->data['result'];
		self::assertTrue( $replayed['replayed'] ?? false );
		self::assertSame( 'Later human edit', $this->post_title() );
		self::assertSame( 'consumed', $this->wpdb->rows[ $id ]['status'] ?? '' );
	}

	public function test_mixed_changed_title_and_unchanged_status_can_receive_exact_human_approval(): void {
		\WP_Block_Type_Registry::get_instance()->register(
			'core/paragraph',
			array(
				'title'    => 'Paragraph',
				'category' => 'text',
				'supports' => array( 'inserter' => true ),
			)
		);
		$content = '<!-- wp:paragraph --><p>Approved content.</p><!-- /wp:paragraph -->';
		$gateway = $this->gateway();
		$request = $this->request(
			'session-mixed',
			'Approved title',
			false,
			array(
				'content' => $content,
				'status'  => 'draft',
			)
		);
		$pending = $this->call_app( $gateway, $request );

		self::assertSame( 'approval_pending', $pending->data['result']['status'] ?? '' );
		self::assertSame( 'Original title', $this->post_title() );
		self::assertSame( 'Original content', get_post( 123 )->post_content );
		$id     = max( array_keys( $this->wpdb->rows ) );
		$record = ( new PendingOperationApprovalStore() )->find_pending_for_current_actor( $id );
		self::assertIsArray( $record );
		self::assertSame( array( 'title', 'content' ), array_column( $record['review_summary']['changes'], 'label' ) );

		$this->approve_pending_request();
		$executed = $this->call_app( $gateway, $request );
		self::assertSame( 'success', $executed->data['result']['outcome'] ?? '' );
		self::assertSame( 'Approved title', $this->post_title() );
		self::assertSame( $content, get_post( 123 )->post_content );
		self::assertSame( 'draft', get_post( 123 )->post_status );
		self::assertSame( 'consumed', $this->wpdb->rows[ $id ]['status'] ?? '' );
	}

	public function test_all_no_op_fields_do_not_create_an_approval(): void {
		$result = $this->call_app(
			$this->gateway(),
			$this->request( 'session-no-op', 'Original title', false, array( 'status' => 'draft' ) )
		);

		self::assertSame( 'approval_unsupported', $result->data['result']['error'] ?? '' );
		self::assertTrue( $result->data['result']['no_write'] ?? false );
		self::assertSame( array(), $this->wpdb->rows );
		self::assertSame( 'Original title', $this->post_title() );
	}

	public function test_unsupported_metadata_stays_blocked_even_with_a_changed_title(): void {
		$result = $this->call_app(
			$this->gateway(),
			$this->request( 'session-unsupported', 'Approved title', false, array( 'meta_title' => 'Unsupported metadata' ) )
		);

		self::assertSame( 'approval_unsupported', $result->data['result']['error'] ?? '' );
		self::assertTrue( $result->data['result']['no_write'] ?? false );
		self::assertSame( array(), $this->wpdb->rows );
		self::assertSame( 'Original title', $this->post_title() );
	}

	public function test_malformed_no_op_or_unsupported_diff_cannot_create_an_approval(): void {
		$handoff          = new McpAppApprovalHandoff(
			new AbilitiesRegistry(),
			new ToolSafety( new InMemoryExecutionClaimStore() ),
			static function (): void {}
		);
		$store            = new PendingOperationApprovalStore();
		$unchanged_status = array(
			'field'   => 'status',
			'changed' => false,
			'before'  => array(
				'available' => true,
				'value'     => 'draft',
			),
			'after'   => array(
				'available' => true,
				'value'     => 'draft',
			),
		);
		$preview          = array(
			'target'  => array( 'id' => 123 ),
			'dry_run' => true,
			'diff'    => array(
				'fields'      => array(
					array(
						'field'   => 'title',
						'changed' => true,
						'before'  => array(
							'available' => true,
							'value'     => 'Original title',
						),
						'after'   => array(
							'available' => true,
							'value'     => 'Approved title',
						),
					),
					$unchanged_status,
				),
				'unsupported' => array(),
			),
		);
		$invalid_fields   = array(
			'unreadable_before' => array( 'before' => array( 'available' => false ) ),
			'unreadable_after'  => array( 'after' => array( 'available' => false ) ),
			'missing_value'     => array( 'before' => array( 'available' => true ) ),
			'non_boolean'       => array( 'changed' => 0 ),
			'unequal_values'    => array(
				'after' => array(
					'available' => true,
					'value'     => 'publish',
				),
			),
		);
		foreach ( $invalid_fields as $label => $changes ) {
			$candidate                      = $preview;
			$candidate['diff']['fields'][1] = array_replace( $unchanged_status, $changes );
			$result                         = $this->create_pending_from_preview( $handoff, $store, $candidate );
			self::assertSame( 'approval_unsupported', $result['error'] ?? '', $label );
			self::assertTrue( $result['no_write'] ?? false, $label );
			self::assertSame( array(), $this->wpdb->rows, $label );
		}
		$preview['diff']['unsupported'] = array( 'unreviewable_field' );
		$result                         = $this->create_pending_from_preview( $handoff, $store, $preview );
		self::assertSame( 'approval_unsupported', $result['error'] ?? '' );
		self::assertSame( array(), $this->wpdb->rows );
	}

	public function test_declined_exact_call_stays_blocked_without_writing(): void {
		$gateway = $this->gateway();
		$request = $this->request( 'session-a', 'Declined title' );
		$pending = $this->call_app( $gateway, $request );
		self::assertSame( 'approval_pending', $pending->data['result']['status'] ?? '' );
		$id = $this->approve_pending_request( 'declined' );

		$declined = $this->call_app( $gateway, $request );
		self::assertSame( 'approval_declined', $declined->data['result']['error'] ?? '' );
		self::assertSame( 'declined', $this->wpdb->rows[ $id ]['status'] ?? '' );
		self::assertSame( 'Original title', $this->post_title() );
	}

	private function gateway(): AbilityExecutionGateway {
		return new AbilityExecutionGateway( null, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
	}

	private function create_pending_from_preview( McpAppApprovalHandoff $handoff, PendingOperationApprovalStore $store, array $preview ): array {
		return $handoff->create_pending(
			'content_workflow.update_post',
			array(
				'id'     => 123,
				'title'  => 'Approved title',
				'status' => 'draft',
			),
			array( 'expected_modified_gmt' => '2026-09-25 10:00:00' ),
			$preview,
			array(
				'client_id' => 'malformed-diff-client',
				'token_id'  => 'malformed-diff-token',
				'user_id'   => 7,
			),
			$store,
			'policy-fingerprint',
			array( 123 ),
			str_repeat( 'a', 64 )
		);
	}

	private function request( string $session, string $title, bool $with_idempotency_key = true, array $additional_arguments = array() ): AbilityExecutionRequest {
		$arguments = array(
			'id'    => 123,
			'title' => $title,
		);
		$arguments = array_merge( $arguments, $additional_arguments );
		if ( $with_idempotency_key ) {
			$arguments['idempotency_key'] = 'approval-gateway-same-key';
		}
		return new AbilityExecutionRequest(
			array(
				'name'      => 'content_workflow_update_post',
				'arguments' => $arguments,
			),
			array(
				'user_id'      => 7,
				'client_id'    => 'approval-gateway-client',
				'token_id'     => $session,
				'provider'     => 'chatgpt',
				'scopes'       => array( 'content:read', 'content:draft' ),
				'profile'      => 'full_access',
				'access_level' => ConnectionAccessLevel::READ,
			)
		);
	}

	private function call_app( AbilityExecutionGateway $gateway, AbilityExecutionRequest $request ): \Aculect\AICompanion\Connectors\MCP\AbilityExecutionOutcome {
		return McpAppsNegotiation::with_request_enabled( true, static fn () => $gateway->execute( $request ) );
	}

	private function approve_pending_request( string $decision = 'approved' ): int {
		$id      = max( array_keys( $this->wpdb->rows ) );
		$store   = new PendingOperationApprovalStore();
		$pending = $store->find_pending_for_current_actor( $id );
		self::assertIsArray( $pending );
		$nonce = wp_create_nonce( 'aculect_mcp_approval_' . $id . '_' . $pending['operation_fingerprint'] );
		self::assertSame(
			$decision,
			( new McpApprovalQueue( $store ) )->process_decision(
				array(
					'request_id' => (string) $id,
					'decision'   => $decision,
					'_wpnonce'   => $nonce,
				)
			)
		);
		return $id;
	}

	private function post_title(): string {
		$post = get_post( 123 );
		self::assertInstanceOf( \WP_Post::class, $post );
		return $post->post_title;
	}

	private function set_post_title( string $title ): void {
		$post = get_post( 123 );
		self::assertInstanceOf( \WP_Post::class, $post );
		$post->post_title = $title;
	}

	private function restore_global( string $key, mixed $value ): void {
		if ( null === $value ) {
			unset( $GLOBALS[ $key ] );
			return;
		}
		$GLOBALS[ $key ] = $value;
	}
}

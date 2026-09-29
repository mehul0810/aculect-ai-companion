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
	private \McpApprovalWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb                          = $GLOBALS['wpdb'] ?? null;
		$this->previous_db_delta                      = $GLOBALS['aculect_ai_companion_test_db_delta_callback'] ?? null;
		$this->previous_salt                          = $GLOBALS['mcp_approval_test_salt'] ?? null;
		$this->previous_post_meta                     = $GLOBALS['aculect_ai_companion_test_post_meta'] ?? null;
		$this->previous_filter_callbacks              = $GLOBALS['aculect_ai_companion_test_filter_callbacks'] ?? null;
		$this->previous_users                         = $GLOBALS['aculect_ai_companion_test_users'] ?? null;
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

		$replay = $this->call_app( $gateway, $request );
		self::assertSame( 'success', $replay->data['result']['outcome'] ?? '' );
		self::assertSame( 'Approved title', $this->post_title() );

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

	private function request( string $session, string $title ): AbilityExecutionRequest {
		return new AbilityExecutionRequest(
			array(
				'name'      => 'content_workflow_update_post',
				'arguments' => array(
					'id'              => 123,
					'title'           => $title,
					'idempotency_key' => 'approval-gateway-same-key',
				),
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

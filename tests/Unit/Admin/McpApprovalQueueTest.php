<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\McpApprovalQueue;
use Aculect\AICompanion\Admin\SettingsPage;
use Aculect\AICompanion\Connectors\MCP\PendingApprovalInstaller;
use Aculect\AICompanion\Connectors\MCP\PendingOperationApprovalStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/McpApprovalWpdb.php';
require_once dirname( __DIR__, 2 ) . '/Support/InMemoryExecutionClaimStore.php';
require_once dirname( __DIR__, 2 ) . '/Support/AdminAssetSpies.php';

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused authenticated admin action tests use the pending-store wpdb fixture.
final class McpApprovalQueueTest extends TestCase {

	private mixed $original_wpdb;
	/**
	 * Original routing input restored even when an assertion fails.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_get = array();
	/**
	 * Captured global fixture values for restoration.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_globals = array();
	private \McpApprovalWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Snapshot test routing input without processing a form.
		$this->original_get  = $_GET;
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		foreach ( array( 'aculect_ai_companion_test_options', 'aculect_ai_companion_test_current_user_id', 'aculect_ai_companion_test_blog_id', 'aculect_ai_companion_test_denied_caps', 'aculect_ai_companion_test_db_delta_callback', 'aculect_ai_companion_test_hooks', 'aculect_ai_companion_test_admin_pages', 'aculect_ai_companion_test_admin_assets', 'aculect_ai_companion_test_posts', 'aculect_ai_companion_test_transients', 'mcp_approval_test_salt' ) as $key ) {
			$this->original_globals[ $key ] = $GLOBALS[ $key ] ?? null;
		}
		$this->wpdb                                   = new \McpApprovalWpdb();
		$GLOBALS['wpdb']                              = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']   = 17;
		$GLOBALS['aculect_ai_companion_test_blog_id']           = 3;
		$GLOBALS['aculect_ai_companion_test_denied_caps']       = array();
		$GLOBALS['aculect_ai_companion_test_posts']             = array(
			81 => array(
				'ID'                => 81,
				'post_modified_gmt' => '2026-09-29 10:00:00',
			),
		);
		$GLOBALS['aculect_ai_companion_test_hooks']             = array(
			'actions' => array(),
			'filters' => array(),
		);
		$GLOBALS['aculect_ai_companion_test_admin_pages']       = array(
			'menu'    => array(),
			'options' => array(),
			'submenu' => array(),
		);
		$GLOBALS['aculect_ai_companion_test_transients']        = array();
		$GLOBALS['aculect_ai_companion_test_admin_assets']      = array(
			'scripts'   => array(),
			'localized' => array(),
			'styles'    => array(),
		);
		$GLOBALS['mcp_approval_test_salt']                      = 'test-salt-for-admin';
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = function (): array {
			$this->wpdb->table_exists = true;
			return array();
		};
		PendingApprovalInstaller::reset_request_cache();
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
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

	public function test_registers_only_logged_in_post_action_without_separate_settings_page(): void {
		$queue = new McpApprovalQueue();
		$queue->register();

		$hooks = array_column( $GLOBALS['aculect_ai_companion_test_hooks']['actions'], 'hook_name' );
		self::assertContains( 'admin_post_aculect_mcp_approval_decide', $hooks );
		self::assertNotContains( 'admin_post_nopriv_aculect_mcp_approval_decide', $hooks );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_admin_pages']['options'] );
	}

	public function test_decision_receipt_is_actor_scoped_one_time_and_never_claims_execution(): void {
		set_transient( 'aculect_mcp_approval_notice_17', 'approved', 60 );
		ob_start();
		try {
			( new McpApprovalQueue() )->render();
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
		self::assertStringContainsString( 'Approval recorded.', $html );
		self::assertStringContainsString( 'This did not execute the operation', $html );
		self::assertStringNotContainsString( 'Operation executed successfully', $html );
		self::assertFalse( get_transient( 'aculect_mcp_approval_notice_17' ) );
	}

	public function test_shared_admin_page_routes_read_only_actor_to_queue_without_settings_payload(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		$_GET = array( 'page' => 'aculect-ai-companion' );
		$page = new SettingsPage();
		ob_start();
		try {
			$page->enqueue_assets( 'settings_page_aculect-ai-companion' );
			$page->render();
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
		self::assertStringContainsString( 'Pending MCP approvals', $html );
		self::assertStringNotContainsString( 'aculect-ai-companion-settings-app-root', $html );
		self::assertStringNotContainsString( '>Overview</a>', $html );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_admin_assets']['scripts'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_admin_assets']['localized'] );
	}

	public function test_reader_cannot_force_settings_payload_and_manager_approval_tab_is_script_free(): void {
		$page = new SettingsPage();
		foreach ( array( 'advanced', 'approvals' ) as $tab ) {
			$_GET = array(
				'page' => 'aculect-ai-companion',
				'tab'  => $tab,
			);
			$GLOBALS['aculect_ai_companion_test_denied_caps'] = 'advanced' === $tab ? array( 'manage_options' ) : array();
			ob_start();
			try {
				$page->enqueue_assets( 'settings_page_aculect-ai-companion' );
				$page->render();
				$html = (string) ob_get_contents();
			} finally {
				ob_end_clean();
			}
			self::assertStringContainsString( 'Pending MCP approvals', $html );
			self::assertStringNotContainsString( 'aculect-ai-companion-settings-app-root', $html );
		}
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_admin_assets']['scripts'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_admin_assets']['localized'] );
	}

	public function test_review_page_shows_exact_escaped_operation_and_keeps_nonce_in_post_body(): void {
		$store = $this->store();
		$id    = $store->create(
			'content_update',
			array(
				'title'  => '<script>alert(1)</script>',
				'target' => array( 'id' => 81 ),
			),
			$this->summary( '<script>alert(1)</script>' ),
			'client-review',
			'edit_post',
			array( 81 ),
			'access-token-review'
		);
		self::assertIsInt( $id );

		ob_start();
		try {
			( new McpApprovalQueue( $store ) )->render();
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}

		self::assertStringContainsString( 'Pending MCP approvals', $html );
		self::assertStringContainsString( 'options-general.php?page=aculect-ai-companion&tab=approvals', $html );
		self::assertStringContainsString( 'content_update', $html );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		self::assertStringNotContainsString( '<script>alert(1)</script>', $html );
		self::assertStringContainsString( 'method="post"', $html );
		self::assertStringContainsString( 'name="request_id" value="' . $id . '"', $html );
		self::assertStringContainsString( 'name="_wpnonce"', $html );
		self::assertStringNotContainsString( 'request_id=' . $id, $html );
		self::assertStringNotContainsString( '_wpnonce=', $html );
		self::assertStringContainsString( 'it does not run the operation', $html );
	}

	public function test_decision_requires_actor_bound_row_valid_nonce_and_current_operation_capability(): void {
		$store = $this->store();
		$id    = $store->create(
			'content_update',
			array(
				'title'  => 'Exact title',
				'target' => array( 'id' => 81 ),
			),
			$this->summary( 'Exact title' ),
			'client-review',
			'edit_post',
			array( 81 ),
			'access-token-review'
		);
		self::assertIsInt( $id );
		$request = $store->find_pending_for_current_actor( $id );
		self::assertIsArray( $request );
		$nonce  = wp_create_nonce( 'aculect_mcp_approval_' . $id . '_' . $request['operation_fingerprint'] );
		$events = array();
		$queue  = new McpApprovalQueue(
			$store,
			static function ( string $event, array $metadata, array $auth ) use ( &$events ): void {
				$events[] = array(
					'event'    => $event,
					'metadata' => $metadata,
					'auth'     => $auth,
				);
			}
		);

		self::assertSame(
			'invalid',
			$queue->process_decision(
				array(
					'request_id' => (string) $id,
					'decision'   => 'approved',
					'_wpnonce'   => 'wrong',
				)
			)
		);
		self::assertSame( 'pending', $this->wpdb->rows[ $id ]['status'] );
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 99;
		self::assertSame(
			'unavailable',
			$queue->process_decision(
				array(
					'request_id' => (string) $id,
					'decision'   => 'approved',
					'_wpnonce'   => $nonce,
				)
			)
		);
		self::assertSame( 'pending', $this->wpdb->rows[ $id ]['status'] );
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 17;
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array( 'edit_post' );
		self::assertSame(
			'unavailable',
			$queue->process_decision(
				array(
					'request_id' => (string) $id,
					'decision'   => 'approved',
					'_wpnonce'   => $nonce,
				)
			)
		);
		self::assertSame( 'pending', $this->wpdb->rows[ $id ]['status'] );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		self::assertSame(
			'approved',
			$queue->process_decision(
				array(
					'request_id' => (string) $id,
					'decision'   => 'approved',
					'_wpnonce'   => $nonce,
				)
			)
		);
		self::assertSame( 'approved', $this->wpdb->rows[ $id ]['status'] );
		self::assertSame( 'approval_decision', $events[0]['event'] ?? '' );
		self::assertSame( 'approved', $events[0]['metadata']['result_class'] ?? '' );
		self::assertSame( 'validated', $events[0]['metadata']['status'] ?? '' );
		self::assertSame( 17, $events[0]['auth']['user_id'] ?? 0 );
		self::assertSame(
			'unavailable',
			$queue->process_decision(
				array(
					'request_id' => (string) $id,
					'decision'   => 'declined',
					'_wpnonce'   => $nonce,
				)
			)
		);
	}

	public function test_decision_rejects_unexpected_values_without_mutating_record(): void {
		$store = $this->store();
		$id    = $store->create( 'content_update', array( 'target' => array( 'id' => 81 ) ), $this->summary(), 'client-review', 'edit_post', array( 81 ), 'access-token-review' );
		self::assertIsInt( $id );
		$request = $store->find_pending_for_current_actor( $id );
		self::assertIsArray( $request );
		$nonce  = wp_create_nonce( 'aculect_mcp_approval_' . $id . '_' . $request['operation_fingerprint'] );
		$result = ( new McpApprovalQueue( $store ) )->process_decision(
			array(
				'request_id' => (string) $id,
				'decision'   => 'execute',
				'_wpnonce'   => $nonce,
			)
		);
		self::assertSame( 'invalid', $result );
		self::assertSame( 'pending', $this->wpdb->rows[ $id ]['status'] );
	}

	private function store(): PendingOperationApprovalStore {
		return new PendingOperationApprovalStore( static fn (): int => 1790330400 );
	}

	/**
	 * Build an encrypted-store summary fixture.
	 *
	 * @param string $after Proposed value.
	 * @return array<string,mixed>
	 */
	private function summary( string $after = 'Proposed change' ): array {
		return array(
			'operation'      => 'Update content',
			'target'         => 'Post 81',
			'target_id'      => 81,
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

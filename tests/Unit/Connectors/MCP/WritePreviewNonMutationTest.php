<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionRequest;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\MCP\WorkflowLoopStore;
use Aculect\AICompanion\Connectors\MCP\WorkflowSessionStore;
use Aculect\AICompanion\Tests\Support\InMemoryExecutionClaimStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/McpApprovalWpdb.php';

/**
 * Ensure preview callbacks cannot mutate related workflow state before confirmation.
 */
final class WritePreviewNonMutationTest extends TestCase {

	private AbilityExecutionGateway $gateway;
	private mixed $original_wpdb;
	private array $original_globals = array();

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \McpApprovalWpdb();
		foreach ( array( 'options', 'transients', 'current_user_id', 'users', 'denied_caps', 'capability_callback', 'filter_callbacks', 'posts' ) as $key ) {
			$name = 'aculect_ai_companion_test_' . $key;
			$this->original_globals[ $name ] = $GLOBALS[ $name ] ?? null;
		}
		$this->gateway = new AbilityExecutionGateway( null, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$GLOBALS['aculect_ai_companion_test_transients'] = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_users'] = array( 1 => (object) array( 'ID' => 1, 'roles' => array( 'administrator' ) ) );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();
		$GLOBALS['aculect_ai_companion_test_posts'] = array( 123 => new \WP_Post( array( 'ID' => 123, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Original' ) ) );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		foreach ( $this->original_globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
	}

	private function call( string $name, array $args ): array {
		$auth = array( 'user_id' => 1, 'client_id' => 'preview-client', 'token_id' => 'preview-token', 'scopes' => array( 'content:read', 'content:draft' ), 'access_level' => 'read' );
		return $this->gateway->execute( new AbilityExecutionRequest( array( 'name' => $name, 'arguments' => $args ), $auth ) )->data['result'];
	}

	public function test_loop_pause_and_cancel_do_not_mutate_on_confirmation_or_explicit_preview(): void {
		$store = new WorkflowLoopStore();
		foreach ( array( 'pause', 'cancel' ) as $operation ) {
			foreach ( array( false, true ) as $dry_run ) {
				$created = $store->create( array( 'source' => 'provided_items', 'items' => array( array( 'id' => 123 ) ) ) );
				$args = array( 'workflow_loop_id' => $created['workflow_loop']['id'] );
				$before = $store->get( $args );
				$result = $this->call( 'workflow_loop_' . $operation, $args + array( 'dry_run' => $dry_run ) );
				self::assertSame( $before, $store->get( $args ), $operation );
				self::assertTrue( $dry_run ? ( $result['dry_run'] ?? false ) : 'confirmation_required' === ( $result['status'] ?? '' ) );
				if ( ! $dry_run ) {
					$confirmed = $this->call( 'workflow_loop_' . $operation, $args + array( 'confirmation_token' => $result['confirmation_token'] ) );
					self::assertSame( 'pause' === $operation ? 'paused' : 'cancelled', $confirmed['workflow_loop']['state'] );
					$persisted = $store->get( $args );
					$this->call( 'workflow_loop_' . $operation, $args + array( 'confirmation_token' => $result['confirmation_token'] ) );
					self::assertSame( $persisted, $store->get( $args ) );
				}
			}
		}
	}

	public function test_nested_content_preview_does_not_advance_existing_session(): void {
		$store = new WorkflowSessionStore();
		$created = $store->start( array( 'workflow' => 'synthetic' ) );
		$id = $created['workflow_session']['id'];
		$before = $store->get( array( 'workflow_session_id' => $id ) );
		$result = $this->call( 'content_workflow_update_post', array( 'id' => 123, 'title' => 'Proposed', 'workflow_session_id' => $id ) );
		self::assertSame( 'confirmation_required', $result['status'] ?? '' );
		self::assertSame( $before, $store->get( array( 'workflow_session_id' => $id ) ) );
		self::assertSame( 'Original', get_post( 123 )->post_title );
		$this->call( 'content_workflow_update_post', array( 'id' => 123, 'content' => '<!-- wp:missing/block /-->', 'workflow_session_id' => $id, 'dry_run' => true ) );
		self::assertSame( $before, $store->get( array( 'workflow_session_id' => $id ) ) );
		$confirmed = $this->call( 'content_workflow_update_post', array( 'id' => 123, 'title' => 'Proposed', 'workflow_session_id' => $id, 'confirmation_token' => $result['confirmation_token'] ) );
		self::assertArrayNotHasKey( 'error', $confirmed, (string) ( $confirmed['error'] ?? '' ) . ':' . (string) ( $confirmed['message'] ?? '' ) );
		self::assertSame( 'updated', $store->get( array( 'workflow_session_id' => $id ) )['workflow_session']['state'] );
	}
	public function test_existing_snapshot_previews_keep_native_targets_and_fingerprint_changes(): void {
		foreach ( array( 'site_editor_refresh_context' => 'site_editor_snapshot', 'admin_menu_refresh_context' => 'admin_menu_snapshot' ) as $tool => $target_type ) {
			$result = $this->call( $tool, array( 'dry_run' => true ) );
			self::assertSame( $target_type, $result['target']['type'] ?? '' );
			self::assertNotEmpty( $result['changes'] );
		}
	}

}

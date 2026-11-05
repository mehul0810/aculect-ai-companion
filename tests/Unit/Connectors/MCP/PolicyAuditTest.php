<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpNegotiatedResourceReader;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/McpApprovalWpdb.php';

/**
 * Audit current role configuration and resource outcomes without retaining payloads.
 */
final class PolicyAuditTest extends TestCase {

	private mixed $original_wpdb;
	private array $original_globals = array();
	private \McpApprovalWpdb $wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		foreach ( array( 'options', 'current_user_id', 'failed_option_updates' ) as $key ) {
			$name = 'aculect_ai_companion_test_' . $key;
			$this->original_globals[ $name ] = $GLOBALS[ $name ] ?? null;
		}
		$this->wpdb = new \McpApprovalWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_options'] = array( 'aculect_ai_companion_activity_last_pruned_at' => time() );
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		foreach ( $this->original_globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
	}

	public function test_save_copy_reset_import_and_toggle_audit_actual_changes_once(): void {
		$registry = new AbilitiesRegistry();
		$policy = new RoleAbilitiesPolicy();
		$policy->save_role_policy( 'editor', array( 'content.update_item' ), $registry );
		$policy->save_role_policy( 'editor', array( 'content.update_item' ), $registry );
		$policy->copy_role_policy( 'editor', 'author', $registry );
		$policy->reset_role_policy( 'editor', $registry );
		$policy->replace_policies( array( 'contributor' => array() ), $registry );
		RoleAbilitiesPolicy::set_editing_enabled( true );
		self::assertCount( 5, $this->wpdb->activity_rows );
		self::assertSame( array( 'mcp.timeline.role_policy_change' ), array_values( array_unique( array_column( $this->wpdb->activity_rows, 'action' ) ) ) );
		self::assertStringNotContainsString( 'content.update_item', (string) wp_json_encode( $this->wpdb->activity_rows ) );
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array( RoleAbilitiesPolicy::OPTION_ROLE_ABILITIES );
		$policy->save_role_policy( 'editor', array(), $registry );
		self::assertCount( 5, $this->wpdb->activity_rows );
	}

	public function test_resource_success_and_scope_denial_are_recorded_without_uri_or_content(): void {
		$reader = new McpNegotiatedResourceReader();
		$uri = 'aculect://site/summary';
		$reader->read( array( 'uri' => $uri ), false, '2025-03-26', array( 'user_id' => 1, 'scopes' => array( 'content:read' ) ) );
		$reader->read( array( 'uri' => $uri ), false, '2025-03-26', array( 'user_id' => 1, 'scopes' => array( 'content:draft' ) ) );
		self::assertSame( array( 'mcp.timeline.resources_read', 'mcp.timeline.resources_read' ), array_column( $this->wpdb->activity_rows, 'action' ) );
		self::assertSame( array( 'success', 'error' ), array_column( $this->wpdb->activity_rows, 'status' ) );
		self::assertStringNotContainsString( $uri, (string) wp_json_encode( $this->wpdb->activity_rows ) );
		self::assertStringContainsString( 'insufficient_scope', (string) wp_json_encode( $this->wpdb->activity_rows ) );
	}
	public function test_native_capability_denial_inside_resource_envelope_is_a_blocked_audit_event(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_theme_options' );
		$result = ( new McpNegotiatedResourceReader() )->read( array( 'uri' => 'aculect://site-editor/context' ), false, '2025-03-26', array( 'user_id' => 1, 'scopes' => array( 'content:read' ) ) );
		self::assertArrayHasKey( 'contents', $result );
		self::assertSame( 'forbidden', json_decode( $result['contents'][0]['text'], true )['error'] );
		self::assertSame( 'error', $this->wpdb->activity_rows[0]['status'] );
		self::assertSame( 'forbidden', $this->wpdb->activity_rows[0]['error_code'] );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
	}

}

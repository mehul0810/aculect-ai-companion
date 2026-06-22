<?php
/**
 * Admin self-management MCP ability tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\AdminSelfManagementAbilities;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesPolicy;
use Aculect\AICompanion\Diagnostics\Logger;
use Aculect\AICompanion\Diagnostics\LogSettings;
use Aculect\AICompanion\Diagnostics\LogSinkInterface;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused unit tests replace wpdb with a local test double.

/**
 * Verifies admin-only policy management stays dry-run and confirmation gated.
 */
final class AdminSelfManagementAbilitiesTest extends TestCase {

	private const CONFIRMATION_TEXT = 'I understand this changes Aculect AI Companion admin policy.';

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new class() {
			public string $prefix = 'wp_';

			/**
			 * Delete one stored row.
			 *
			 * @param string              $table         Table name.
			 * @param array<string,mixed> $where         Where clause data.
			 * @param array<int,string>   $where_formats Where formats.
			 */
			public function delete( string $table, array $where, array $where_formats ): int {
				unset( $table, $where, $where_formats );

				return 0;
			}
		};

		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
	}

	public function test_non_admin_cannot_inspect_or_mutate_self_management_state(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		$abilities                                        = new AdminSelfManagementAbilities();

		self::assertSame( 'forbidden', $abilities->inspect()['error'] ?? '' );
		self::assertSame(
			'forbidden',
			$abilities->update_enabled_abilities(
				array(
					'enabled_ids' => array( 'content.list_items' ),
					'dry_run'     => true,
				)
			)['error'] ?? ''
		);
	}

	public function test_inspect_redacts_raw_options_and_surfaces_structured_state(): void {
		update_option( 'aculect_ai_companion_oauth_private_key', 'raw-secret-value', false );
		$result = ( new AdminSelfManagementAbilities() )->inspect();

		self::assertSame( 'inspected', $result['status'] );
		self::assertArrayHasKey( 'policy', $result );
		self::assertArrayHasKey( 'wordpress_abilities', $result );
		self::assertArrayHasKey( 'learning_queue', $result );
		self::assertStringNotContainsString( 'raw-secret-value', wp_json_encode( $result ) );
		self::assertStringNotContainsString( 'aculect_ai_companion_oauth_private_key', wp_json_encode( $result ) );
	}

	public function test_enabled_abilities_dry_run_does_not_persist_and_warns_for_always_on_tools(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.list_items' ) );

		$result = ( new AdminSelfManagementAbilities() )->update_enabled_abilities(
			array(
				'enabled_ids' => array( 'content.list_items', 'content.get_item', 'search' ),
				'dry_run'     => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( array( 'content.get_item' ), $result['changes']['added'] );
		self::assertSame( array( 'content.list_items' ), $registry->enabled_ids() );
		self::assertNotEmpty( $result['warnings'] );
		self::assertStringContainsString( 'search', $result['warnings'][0] );
	}

	public function test_enabled_abilities_requires_confirmation_before_mutation(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.list_items' ) );
		$abilities = new AdminSelfManagementAbilities();

		$blocked = $abilities->update_enabled_abilities(
			array(
				'enabled_ids' => array( 'content.get_item' ),
				'dry_run'     => false,
			)
		);
		self::assertSame( 'confirmation_required', $blocked['status'] );
		self::assertSame( array( 'content.list_items' ), $registry->enabled_ids() );

		$updated = $abilities->update_enabled_abilities(
			array(
				'enabled_ids'       => array( 'content.get_item' ),
				'dry_run'           => false,
				'confirmation_text' => self::CONFIRMATION_TEXT,
			)
		);

		self::assertSame( 'updated', $updated['status'] );
		self::assertSame( array( 'content.get_item' ), $registry->enabled_ids() );
	}

	public function test_wordpress_abilities_policy_mutation_is_dry_run_and_confirmation_gated(): void {
		$abilities = new AdminSelfManagementAbilities();
		$dry_run   = $abilities->update_wordpress_abilities(
			array(
				'allowed_ids' => array( 'third-party/action' ),
				'dry_run'     => true,
			)
		);

		self::assertSame( 'preview', $dry_run['status'] );
		self::assertSame( array(), ( new WordPressAbilitiesPolicy() )->allowed_ids() );

		$updated = $abilities->update_wordpress_abilities(
			array(
				'allowed_ids'       => array( 'third-party/action' ),
				'dry_run'           => false,
				'confirmation_text' => self::CONFIRMATION_TEXT,
			)
		);

		self::assertSame( 'updated', $updated['status'] );
		self::assertSame( array( 'third-party/action' ), ( new WordPressAbilitiesPolicy() )->allowed_ids() );
	}

	public function test_admin_self_management_tools_require_draft_scope_in_manifest_availability(): void {
		$availability    = new McpToolAvailability();
		$read_only_tools = $availability->tool_modules_for_user( 1, null, null, array( 'content:read' ) );
		$write_tools     = $availability->tool_modules_for_user( 1, null, null, array( 'content:read', 'content:draft' ) );

		self::assertArrayNotHasKey( 'admin_self_management.inspect', $read_only_tools );
		self::assertArrayNotHasKey( 'admin_self_management.update_enabled_abilities', $read_only_tools );
		self::assertArrayHasKey( 'admin_self_management.inspect', $write_tools );
		self::assertArrayHasKey( 'admin_self_management.update_enabled_abilities', $write_tools );
	}

	public function test_confirmed_enabled_ability_mutation_writes_diagnostic_event(): void {
		LogSettings::set_enabled( true );
		$sink      = new class() implements LogSinkInterface {
			/**
			 * Inserted diagnostic entries.
			 *
			 * @var list<array<string, mixed>>
			 */
			public array $entries = array();

			/**
			 * Persist one diagnostic log entry.
			 *
			 * @param array<string, mixed> $entry Log entry data.
			 */
			public function insert( array $entry ): bool {
				$this->entries[] = $entry;

				return true;
			}

			/**
			 * Prune expired diagnostic log entries.
			 *
			 * @param int $retention_days Retention window.
			 */
			public function prune( int $retention_days ): int {
				unset( $retention_days );

				return 0;
			}
		};
		$abilities = new AdminSelfManagementAbilities( new Logger( $sink ) );

		$result = $abilities->update_enabled_abilities(
			array(
				'enabled_ids'       => array( 'content.get_item' ),
				'dry_run'           => false,
				'confirmation_text' => self::CONFIRMATION_TEXT,
			)
		);

		self::assertSame( 'updated', $result['status'] );
		self::assertCount( 1, $sink->entries );
		self::assertSame( 'admin_self_management.enabled_abilities_updated', $sink->entries[0]['event'] );
		self::assertSame( 'aculect_admin', $sink->entries[0]['context']['tool_group'] );
		self::assertArrayHasKey( 'changes', $sink->entries[0]['context'] );
		self::assertStringNotContainsString( 'confirmation_text', wp_json_encode( $sink->entries[0] ) );
	}

	public function test_learning_review_lists_and_rejects_with_confirmation(): void {
		update_option(
			'aculect_ai_companion_learning_suggestions',
			array(
				array(
					'id'               => 'learn_test',
					'domain'           => 'content',
					'issue'            => 'Missing editorial rule.',
					'evidence'         => 'Assistant repeated stale guidance.',
					'suggested_update' => 'Use the current editorial rule.',
					'confidence'       => 'high',
					'status'           => 'pending',
					'created_at'       => '2026-06-22T00:00:00Z',
					'updated_at'       => '2026-06-22T00:00:00Z',
					'review_note'      => '',
					'source'           => array( 'provider' => 'codex' ),
				),
			),
			false
		);

		$abilities = new AdminSelfManagementAbilities();
		$list      = $abilities->review_learning( array( 'action' => 'list' ) );

		self::assertSame( 'listed', $list['status'] );
		self::assertSame( 'learn_test', $list['items'][0]['id'] );

		$preview = $abilities->review_learning(
			array(
				'action'  => 'reject',
				'id'      => 'learn_test',
				'dry_run' => true,
			)
		);
		self::assertSame( 'preview', $preview['status'] );
		self::assertSame( 'pending', get_option( 'aculect_ai_companion_learning_suggestions', array() )[0]['status'] );

		$updated = $abilities->review_learning(
			array(
				'action'            => 'reject',
				'id'                => 'learn_test',
				'dry_run'           => false,
				'confirmation_text' => self::CONFIRMATION_TEXT,
			)
		);

		self::assertSame( 'updated', $updated['status'] );
		self::assertSame( 'dismissed', get_option( 'aculect_ai_companion_learning_suggestions', array() )[0]['status'] );
	}
}

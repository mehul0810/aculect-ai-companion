<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\McpAppsPostUpdateResource;
use Aculect\AICompanion\Connectors\MCP\McpAppsPostUpdateResult;
use Aculect\AICompanion\Connectors\MCP\McpResourceRegistry;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionRequest;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use Aculect\AICompanion\Tests\Support\InMemoryExecutionClaimStore;
use Aculect\AICompanion\Tests\Support\PluginContentIndexWpdb;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/PluginContentIndexWpdb.php';

final class McpAppsPostUpdateContractTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array(
			'aculect_ai_companion_mcp_apps_enabled' => static fn (): bool => true,
		);
		$GLOBALS['aculect_ai_companion_test_current_user_id']  = 7;
		$GLOBALS['aculect_ai_companion_test_blog_id']          = 1;
		$GLOBALS['aculect_ai_companion_test_posts']            = array();
		$GLOBALS['aculect_ai_companion_test_post_revisions']   = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']      = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids']  = array();
		$GLOBALS['aculect_ai_companion_test_home_url']         = 'https://example.com';
		$GLOBALS['aculect_ai_companion_test_site_url']         = 'https://example.com';
	}

	public function test_success_includes_new_revision_matching_the_post_saved_by_wordpress(): void {
		$before  = new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Old title',
				'post_content'      => 'Old content',
				'post_excerpt'      => 'Old excerpt',
				'post_modified_gmt' => '2026-09-25 10:00:00',
			)
		);
		$current = new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Updated title',
				'post_content'      => 'Updated content',
				'post_excerpt'      => 'Updated excerpt',
				'post_modified_gmt' => '2026-09-25 10:05:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][123]               = $current;
		$GLOBALS['aculect_ai_companion_test_post_revisions'][123][456] = new \WP_Post(
			array(
				'ID'           => 456,
				'post_type'    => 'revision',
				'post_parent'  => 123,
				'post_title'   => 'Updated title',
				'post_content' => 'Updated content',
				'post_excerpt' => 'Updated excerpt',
			)
		);

		$result = $this->build(
			123,
			array( 'modified_gmt' => '2026-09-25 10:05:00' ),
			array(
				'post'         => $before,
				'revision_ids' => array(),
			)
		);

		self::assertSame( 'aculect.post-update.v1', $result['schema'] );
		self::assertSame( 'success', $result['outcome'] );
		self::assertArrayNotHasKey( 'error', $result, 'A successful app result must not release the write execution claim.' );
		self::assertSame( 'Updated title', $result['content']['title'] );
		self::assertSame( 'publish', $result['content']['status'] );
		self::assertSame( 'Revision 456', $result['content']['revision'] );
		self::assertSame( 'https://example.com/?p=123', $result['links']['view'] );
		self::assertSame( 'https://example.com/wp-admin/post.php?post=123&action=edit', $result['links']['edit'] );
		self::assertSame( 'https://example.com/wp-admin/revision.php?revision=456', $result['links']['compare'] );
		self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T.*Z$/', $result['content']['completed_at'] );
		self::assertFalse( $result['undo']['available'] );
		self::assertFalse( $result['undo']['safe_snapshot'] );
		self::assertSame( '', $result['links']['undo'] );
		self::assertStringContainsString( 'Content update completed.', (string) McpAppsPostUpdateResult::text_fallback( $result ) );
	}

	public function test_negotiated_success_completes_claim_and_replays_without_another_write(): void {
		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$wpdb          = new PluginContentIndexWpdb();
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Scoped database double for an isolated gateway test.
		$GLOBALS['wpdb'] = $wpdb;
		try {
			$this->assert_negotiated_success_replays_without_write( $wpdb );
		} finally {
			if ( null === $previous_wpdb ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $previous_wpdb;
			}
			// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_negotiated_app_preview_does_not_mint_legacy_token_and_rejects_legacy_token_execution(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]   = (object) array(
			'ID'           => 7,
			'roles'        => array( 'administrator' ),
			'display_name' => 'Site Editor',
			'user_login'   => 'site-editor',
		);
		$GLOBALS['aculect_ai_companion_test_posts'][123] = new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Original title',
				'post_content'      => '<!-- wp:paragraph --><p>Original content.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-09-25 10:00:00',
			)
		);
		$gateway = new AbilityExecutionGateway( null, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
		$auth    = array(
			'user_id'      => 7,
			'client_id'    => 'apps-approval-test-client',
			'token_id'     => 'apps-approval-session-a',
			'provider'     => 'chatgpt',
			'scopes'       => array( 'content:read', 'content:draft' ),
			'profile'      => 'full_access',
			'access_level' => ConnectionAccessLevel::READ,
		);
		$preview = McpAppsNegotiation::with_request_enabled(
			true,
			static fn () => $gateway->execute(
				new AbilityExecutionRequest(
					array(
						'name'      => 'content_workflow_update_post',
						'arguments' => array(
							'id'      => 123,
							'title'   => 'Reviewed title',
							'dry_run' => true,
						),
					),
					$auth
				)
			)
		);
		self::assertSame( AbilityExecutionGateway::OUTCOME_SUCCESS, $preview->type );
		self::assertTrue( $preview->data['result']['approval_required'] ?? false );
		self::assertArrayNotHasKey( 'confirmation_token', $preview->data['result'] ?? array() );

		$legacy = McpAppsNegotiation::with_request_enabled(
			true,
			static fn () => $gateway->execute(
				new AbilityExecutionRequest(
					array(
						'name'      => 'content_workflow_update_post',
						'arguments' => array(
							'id'                 => 123,
							'title'              => 'Reviewed title',
							'confirmation_token' => 'legacy-token',
						),
					),
					$auth
				)
			)
		);
		self::assertSame( AbilityExecutionGateway::OUTCOME_SUCCESS, $legacy->type );
		self::assertSame( 'approval_legacy_confirmation', $legacy->data['result']['error'] ?? '' );
		self::assertSame( 'Original title', get_post( 123 )?->post_title );
	}

	private function assert_negotiated_success_replays_without_write( PluginContentIndexWpdb $wpdb ): void {
		$GLOBALS['aculect_ai_companion_test_options'] = array();

		$GLOBALS['aculect_ai_companion_test_users'][7] = (object) array(
			'ID'           => 7,
			'roles'        => array( 'administrator' ),
			'display_name' => 'Site Editor',
			'user_login'   => 'site-editor',
		);

		$GLOBALS['aculect_ai_companion_test_posts'][123] = new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Original title',
				'post_content'      => '<!-- wp:paragraph --><p>Original content.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-09-25 10:00:00',
			)
		);
		$gateway = new AbilityExecutionGateway( null, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
		$auth    = array(
			'user_id'                  => 7,
			'client_id'                => 'apps-claim-test-client',
			'provider'                 => 'chatgpt',
			'scopes'                   => array( 'content:read', 'content:draft' ),
			'profile'                  => 'full_access',
			'access_level'             => ConnectionAccessLevel::WRITE,
			'write_permission_enabled' => true,
		);
		$request = new AbilityExecutionRequest(
			array(
				'name'      => 'content_workflow_update_post',
				'arguments' => array(
					'id'              => 123,
					'title'           => 'Committed once',
					'idempotency_key' => 'apps-post-update-claim-replay',
				),
			),
			$auth
		);
		$written = McpAppsNegotiation::with_request_enabled( true, static fn () => $gateway->execute( $request ) );
		self::assertSame( 'aculect.post-update.v1', $written->data['result']['schema'] ?? '' );
		self::assertSame( 'Committed once', get_post( 123 )?->post_title );
		self::assertArrayNotHasKey( 'error', $written->data['result'] ?? array() );
		$writes = $wpdb->replace_calls;

		$GLOBALS['aculect_ai_companion_test_posts'][123]->post_title = 'Changed after first write';
		$replayed = McpAppsNegotiation::with_request_enabled( true, static fn () => $gateway->execute( $request ) );
		self::assertTrue( $replayed->data['result']['replayed'] ?? false );
		self::assertSame( 'Changed after first write', get_post( 123 )?->post_title, 'Replay must not dispatch another update.' );
		self::assertSame( $writes, $wpdb->replace_calls );
	}

	public function test_undo_is_available_only_for_the_exact_prechange_revision_and_requires_existing_recovery_flow(): void {
		$before = new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Original title',
				'post_content'      => 'Original content',
				'post_excerpt'      => 'Original excerpt',
				'post_modified_gmt' => '2026-09-25 10:00:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][123]               = $before;
		$GLOBALS['aculect_ai_companion_test_post_revisions'][123][450] = new \WP_Post(
			array(
				'ID'           => 450,
				'post_type'    => 'revision',
				'post_parent'  => 123,
				'post_title'   => 'Original title',
				'post_content' => 'Original content',
				'post_excerpt' => 'Original excerpt',
			)
		);
		$captured = $this->capture_before( 123 );

		$current = new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Updated title',
				'post_content'      => 'Updated content',
				'post_excerpt'      => 'Updated excerpt',
				'post_modified_gmt' => '2026-09-25 10:05:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][123]               = $current;
		$GLOBALS['aculect_ai_companion_test_post_revisions'][123][451] = new \WP_Post(
			array(
				'ID'           => 451,
				'post_type'    => 'revision',
				'post_parent'  => 123,
				'post_title'   => 'Updated title',
				'post_content' => 'Updated content',
				'post_excerpt' => 'Updated excerpt',
			)
		);
		$result   = $this->build( 123, $this->saved_result( $current ), $captured );
		$fallback = McpAppsPostUpdateResult::text_fallback( $result );

		self::assertTrue( $result['undo']['available'] );
		self::assertTrue( $result['undo']['safe_snapshot'] );
		self::assertSame( 123, $result['undo']['post_id'] );
		self::assertSame( 450, $result['undo']['revision_id'] );
		self::assertSame( 450, $captured['prior_revision_id'] );
		self::assertSame( 'wordpress_revision_review', $result['undo']['reason'] );
		self::assertSame( 'https://example.com/wp-admin/revision.php?revision=450', $result['links']['undo'] );
		self::assertArrayNotHasKey( 'status', $result['undo'] );
		self::assertArrayNotHasKey( 'terms', $result['undo'] );
		self::assertArrayNotHasKey( 'metadata', $result['undo'] );
		self::assertStringContainsString( 'revision 450', (string) $fallback );
		self::assertStringContainsString( 'Review prior revision in WordPress: https://example.com/wp-admin/revision.php?revision=450', (string) $fallback );
		self::assertStringContainsString( 'Opening it does not restore content', (string) $fallback );
		self::assertStringContainsString( 'native Restore can also change revisioned metadata', (string) $fallback );
		self::assertStringContainsString( 'does not undo status, terms, or featured image changes', (string) $fallback );
		self::assertStringContainsString( 'The widget did not perform an undo.', (string) $fallback );
	}

	public function test_prior_revision_identity_is_fixed_before_the_write_even_when_another_identical_revision_appears(): void {
		$captured = $this->capture_complete_before_snapshot();
		$current  = $GLOBALS['aculect_ai_companion_test_posts'][123];
		$result   = $this->saved_result( $current );
		$GLOBALS['aculect_ai_companion_test_post_revisions'][123][449]     = clone $GLOBALS['aculect_ai_companion_test_post_revisions'][123][450];
		$GLOBALS['aculect_ai_companion_test_post_revisions'][123][449]->ID = 449;

		self::assertSame( 450, $this->build( 123, $result, $captured )['undo']['revision_id'] );
		unset( $GLOBALS['aculect_ai_companion_test_post_revisions'][123][450] );
		$missing = $this->build( 123, $result, $captured );
		self::assertFalse( $missing['undo']['available'] );
		self::assertSame( 'revision_unavailable', $missing['undo']['reason'] );
		self::assertSame( '', $missing['links']['undo'] );
	}

	public function test_revision_handoff_fails_closed_when_revision_access_or_site_link_changes(): void {
		$captured = $this->capture_complete_before_snapshot();
		$current  = $GLOBALS['aculect_ai_companion_test_posts'][123];
		$result   = $this->saved_result( $current );
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability, array $args ): bool {
			return ! ( 'read_post' === $capability && 450 === absint( $args[0] ?? 0 ) );
		};
		self::assertFalse( $this->build( 123, $result, $captured )['undo']['available'] );
		unset( $GLOBALS['aculect_ai_companion_test_capability_callback'] );

		$GLOBALS['aculect_ai_companion_test_home_url'] = 'https://other.example';
		$GLOBALS['aculect_ai_companion_test_site_url'] = 'https://other.example';
		$off_site                                      = $this->build( 123, $result, $captured );
		self::assertFalse( $off_site['undo']['available'] );
		self::assertSame( '', $off_site['links']['undo'] );
	}

	public function test_same_second_status_drift_does_not_offer_a_prior_revision_handoff(): void {
		$captured             = $this->capture_complete_before_snapshot();
		$current              = $GLOBALS['aculect_ai_companion_test_posts'][123];
		$result               = $this->saved_result( $current );
		$current->post_status = 'publish';
		$stale                = $this->build( 123, $result, $captured );

		self::assertSame( 'stale', $stale['outcome'] );
		self::assertSame( '', $stale['content']['revision'] );
		self::assertSame( '', $stale['links']['compare'] );
		self::assertFalse( $stale['undo']['available'] );
		self::assertSame( '', $stale['links']['undo'] );
	}

	public function test_same_second_content_drift_is_stale_even_when_modified_time_is_unchanged(): void {
		$captured = $this->capture_complete_before_snapshot();
		$current  = $GLOBALS['aculect_ai_companion_test_posts'][123];
		$prior    = $this->saved_result( $current );

		// Simulate another write within the same WordPress timestamp second.
		$current->post_content                           = 'Concurrent content';
		$GLOBALS['aculect_ai_companion_test_posts'][123] = $current;
		$result = $this->build( 123, $prior, $captured );

		self::assertSame( 'stale', $result['outcome'] );
		self::assertFalse( $result['undo']['available'] );
		self::assertSame( '', $result['links']['undo'] );
	}

	public function test_undo_fails_closed_for_stale_cross_post_cross_user_cross_site_or_missing_snapshots(): void {
		$captured = $this->capture_complete_before_snapshot();
		$current  = $GLOBALS['aculect_ai_companion_test_posts'][123];
		$result   = $this->saved_result( $current );

		$wrong_post            = $captured;
		$wrong_post['post_id'] = 999;
		self::assertFalse( $this->build( 123, $result, $wrong_post )['undo']['available'] );

		$wrong_user            = $captured;
		$wrong_user['user_id'] = 8;
		self::assertFalse( $this->build( 123, $result, $wrong_user )['undo']['available'] );

		$wrong_site            = $captured;
		$wrong_site['site_id'] = 2;
		self::assertFalse( $this->build( 123, $result, $wrong_site )['undo']['available'] );

		$stale                 = $result;
		$stale['modified_gmt'] = '2026-09-25 10:04:59';
		self::assertFalse( $this->build( 123, $stale, $captured )['undo']['available'] );

		$missing_revision                      = $captured;
		$missing_revision['prior_revision_id'] = 0;
		self::assertFalse( $this->build( 123, $result, $missing_revision )['undo']['available'] );

		$wrong_parent = $captured;
		$GLOBALS['aculect_ai_companion_test_post_revisions'][123][450]->post_parent = 999;
		self::assertFalse( $this->build( 123, $result, $wrong_parent )['undo']['available'] );
	}

	public function test_unrelated_existing_autosave_wrong_parent_and_non_revision_records_are_not_comparable(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123] = new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Updated title',
				'post_content'      => 'Updated content',
				'post_excerpt'      => 'Updated excerpt',
				'post_modified_gmt' => '2026-09-25 10:05:00',
			)
		);
		$revision_fields                                 = array(
			'post_title'   => 'Updated title',
			'post_content' => 'Updated content',
			'post_excerpt' => 'Updated excerpt',
		);
		$GLOBALS['aculect_ai_companion_test_post_revisions'][123] = array(
			100 => new \WP_Post(
				array_merge(
					array(
						'ID'          => 100,
						'post_type'   => 'revision',
						'post_parent' => 123,
					),
					$revision_fields
				)
			),
			457 => new \WP_Post(
				array(
					'ID'           => 457,
					'post_type'    => 'revision',
					'post_parent'  => 123,
					'post_title'   => 'Unrelated concurrent edit',
					'post_content' => 'Different content',
					'post_excerpt' => 'Different excerpt',
				)
			),
			458 => new \WP_Post(
				array_merge(
					array(
						'ID'          => 458,
						'post_type'   => 'revision',
						'post_name'   => '123-autosave-v1',
						'post_parent' => 123,
					),
					$revision_fields
				)
			),
			459 => new \WP_Post(
				array_merge(
					array(
						'ID'          => 459,
						'post_type'   => 'revision',
						'post_parent' => 999,
					),
					$revision_fields
				)
			),
			460 => new \WP_Post(
				array_merge(
					array(
						'ID'          => 460,
						'post_type'   => 'post',
						'post_parent' => 123,
					),
					$revision_fields
				)
			),
		);

		$result = $this->build(
			123,
			array( 'modified_gmt' => '2026-09-25 10:05:00' ),
			array(
				'post'         => new \WP_Post(
					array(
						'ID'         => 123,
						'post_type'  => 'post',
						'post_title' => 'Before',
					)
				),
				'revision_ids' => array( 100 ),
			)
		);

		self::assertSame( '', $result['content']['revision'] );
		self::assertSame( '', $result['links']['compare'] );
	}

	public function test_taxonomy_only_change_without_a_new_content_revision_has_no_compare_link(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123] = new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Unchanged title',
				'post_content'      => 'Unchanged content',
				'post_excerpt'      => 'Unchanged excerpt',
				'post_modified_gmt' => '2026-09-25 10:05:00',
			)
		);
		$result = $this->build(
			123,
			array( 'modified_gmt' => '2026-09-25 10:05:00' ),
			array(
				'post'         => clone $GLOBALS['aculect_ai_companion_test_posts'][123],
				'revision_ids' => array(),
			)
		);

		self::assertSame( '', $result['content']['revision'] );
		self::assertSame( '', $result['links']['compare'] );
	}

	public function test_compare_link_requires_revision_access_and_same_site_https_host(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123]               = new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Updated',
				'post_modified_gmt' => '2026-09-25 10:05:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_post_revisions'][123][456] = new \WP_Post(
			array(
				'ID'           => 456,
				'post_type'    => 'revision',
				'post_parent'  => 123,
				'post_title'   => 'Updated',
				'post_content' => '',
				'post_excerpt' => '',
			)
		);
		$before = array(
			'post'         => new \WP_Post(
				array(
					'ID'         => 123,
					'post_type'  => 'post',
					'post_title' => 'Before',
				)
			),
			'revision_ids' => array(),
		);
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability, array $args ): bool {
			return ! ( 'read_post' === $capability && 456 === absint( $args[0] ?? 0 ) );
		};
		$denied = $this->build( 123, array(), $before );
		unset( $GLOBALS['aculect_ai_companion_test_capability_callback'] );

		$GLOBALS['aculect_ai_companion_test_home_url'] = 'https://other.example';
		$GLOBALS['aculect_ai_companion_test_site_url'] = 'https://other.example';
		$off_site                                      = $this->build( 123, array(), $before );

		self::assertSame( '', $denied['links']['compare'] );
		self::assertSame( '', $off_site['links']['compare'] );
	}

	public function test_partial_and_stale_results_are_explicit(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123] = new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Changed while processing',
				'post_modified_gmt' => '2026-09-25 10:05:00',
			)
		);
		$builder = new McpAppsPostUpdateResult();
		$before  = array(
			'post'         => new \WP_Post(
				array(
					'ID'          => 123,
					'post_type'   => 'post',
					'post_status' => 'draft',
					'post_title'  => 'Original',
				)
			),
			'revision_ids' => array(),
		);

		$partial = $this->build(
			123,
			array(
				'error'  => 'partial_write',
				'status' => 'partial',
			),
			$before
		);
		$stale   = $this->build( 123, array( 'modified_gmt' => '2026-09-25 10:00:00' ), $before );
		$preview = $this->build( 123, array( 'dry_run' => true ), $before );

		self::assertSame( 'partial', $partial['outcome'] );
		self::assertSame( 'partial_write', $partial['error'] );
		self::assertStringContainsString( 'Content update partial.', (string) McpAppsPostUpdateResult::text_fallback( $partial ) );
		self::assertSame( 'stale', $stale['outcome'] );
		self::assertNull( $preview );
	}

	public function test_deleted_or_no_longer_editable_content_fails_closed_without_links(): void {
		$builder = new McpAppsPostUpdateResult();
		$before  = array(
			'post'         => new \WP_Post(
				array(
					'ID'         => 123,
					'post_type'  => 'post',
					'post_title' => 'Was here',
				)
			),
			'revision_ids' => array(),
		);

		$deleted = $this->build( 123, array(), $before );
		$GLOBALS['aculect_ai_companion_test_posts'][123]  = new \WP_Post(
			array(
				'ID'         => 123,
				'post_type'  => 'post',
				'post_title' => 'Access changed',
			)
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_post' );
		$denied = $this->build( 123, array(), $before );

		self::assertSame( 'unavailable', $deleted['outcome'] );
		self::assertSame(
			array(
				'view'    => '',
				'edit'    => '',
				'compare' => '',
				'undo'    => '',
			),
			$deleted['links']
		);
		self::assertSame( 'unavailable', $denied['outcome'] );
		self::assertSame(
			array(
				'view'    => '',
				'edit'    => '',
				'compare' => '',
				'undo'    => '',
			),
			$denied['links']
		);
	}

	public function test_opt_out_hides_post_update_resource_and_result_contract(): void {
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_apps_enabled'] = static fn (): bool => false;
		$uris = array_column( ( new McpResourceRegistry() )->list_resources( false )['resources'], 'uri' );
		$data = ( new McpAppsPostUpdateResult() )->build( 123, array(), null );

		self::assertNotContains( McpAppsNegotiation::POST_UPDATE_URI, $uris );
		self::assertNull( $data );
	}

	public function test_contract_is_absent_when_the_host_did_not_negotiate_apps(): void {
		self::assertNull( ( new McpAppsPostUpdateResult() )->build( 123, array(), null ) );
	}

	public function test_ui_metadata_is_limited_to_the_negotiated_update_tool_and_context_resets_after_errors(): void {
		$metadata = McpAppsNegotiation::tool_metadata( 'content_workflow.update_post', array(), 'Updating', 'Updated', true );
		self::assertSame( McpAppsNegotiation::POST_UPDATE_URI, $metadata['ui']['resourceUri'] ?? '' );
		self::assertArrayNotHasKey( 'ui', McpAppsNegotiation::tool_metadata( 'content.update_item', array(), 'Updating', 'Updated', true ) );
		self::assertArrayNotHasKey( 'ui', McpAppsNegotiation::tool_metadata( 'content_workflow.update_post', array(), 'Updating', 'Updated', false ) );

		try {
			McpAppsNegotiation::with_request_enabled(
				true,
				static function (): void {
					throw new \RuntimeException( 'Expected test exception.' );
				}
			);
		} catch ( \RuntimeException ) {
			self::assertFalse( McpAppsNegotiation::request_enabled() );
		}
	}

	public function test_pre_write_capture_is_immutable_and_negotiation_does_not_leak_to_next_call(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123] = new \WP_Post(
			array(
				'ID'           => 123,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => 'Before',
				'post_content' => 'Old content',
				'post_excerpt' => 'Old excerpt',
			)
		);
		$before = McpAppsNegotiation::with_request_enabled(
			true,
			static fn (): ?array => ( new McpAppsPostUpdateResult() )->capture_before( 123 )
		);
		$GLOBALS['aculect_ai_companion_test_posts'][123]->post_title = 'After';

		self::assertNotSame( $GLOBALS['aculect_ai_companion_test_posts'][123], $before['post'] );
		self::assertSame( 'Before', $before['post']->post_title );
		self::assertNull( ( new McpAppsPostUpdateResult() )->build( 123, array(), null ) );
		self::assertFalse( McpAppsNegotiation::request_enabled() );
	}

	public function test_resource_serves_packaged_html_only_when_discovery_is_opted_in(): void {
		$registry = new McpResourceRegistry();
		$unlisted = $registry->read_resource( array( 'uri' => McpAppsNegotiation::POST_UPDATE_URI ) );
		$resource = ( new McpAppsPostUpdateResource() )->read();
		$content  = $resource['contents'][0] ?? array();

		self::assertSame( 'resource_not_found', $unlisted['error'] );
		self::assertSame( McpAppsNegotiation::POST_UPDATE_URI, $content['uri'] ?? '' );
		self::assertSame( McpAppsNegotiation::MIME_TYPE, $content['mimeType'] ?? '' );
		self::assertStringContainsString( 'aculect.post-update.v1', (string) ( $content['text'] ?? '' ) );
		self::assertSame( array(), $content['_meta']['ui']['csp']['connectDomains'] ?? null );
	}

	public function test_text_fallback_contains_useful_safe_links_and_keeps_undo_and_publish_claims_honest(): void {
		$summary = McpAppsPostUpdateResult::text_fallback(
			array(
				'schema'  => 'aculect.post-update.v1',
				'outcome' => 'success',
				'status'  => 'success',
				'content' => array(
					'title'        => 'A page',
					'type'         => 'Page',
					'status'       => 'Draft',
					'completed_at' => '2026-09-25T10:30:00Z',
					'revision'     => 'Revision 42',
				),
				'links'   => array(
					'view'    => 'https://example.com/page/',
					'edit'    => 'https://example.com/wp-admin/post.php?id=123',
					'compare' => 'http://example.com/revision.php?id=42',
				),
				'undo'    => array(
					'available'     => false,
					'safe_snapshot' => false,
				),
			)
		);

		self::assertStringContainsString( 'Title: A page', (string) $summary );
		self::assertStringContainsString( 'View page: https://example.com/page/', (string) $summary );
		self::assertStringContainsString( 'Revision: Revision 42', (string) $summary );
		self::assertStringNotContainsString( 'Compare revision: http://', (string) $summary );
		self::assertStringContainsString( 'verified pre-change revision handoff is unavailable', (string) $summary );
		self::assertStringContainsString( 'does not authorize a separate publish action', (string) $summary );
		$stale_handoff = McpAppsPostUpdateResult::text_fallback(
			array(
				'schema'  => 'aculect.post-update.v1',
				'outcome' => 'stale',
				'links'   => array( 'undo' => 'https://example.com/wp-admin/revision.php?revision=40' ),
				'undo'    => array(
					'available'     => true,
					'safe_snapshot' => true,
					'post_id'       => 123,
					'revision_id'   => 40,
					'reason'        => 'wordpress_revision_review',
				),
			)
		);
		self::assertStringContainsString( 'handoff is unavailable', (string) $stale_handoff );
		self::assertStringNotContainsString( 'Review prior revision in WordPress:', (string) $stale_handoff );
		$published = McpAppsPostUpdateResult::text_fallback(
			array(
				'schema'  => 'aculect.post-update.v1',
				'outcome' => 'success',
				'status'  => 'success',
				'content' => array(
					'title'  => 'Published page',
					'status' => 'Published',
				),
			)
		);
		self::assertStringContainsString( 'Status: Published', (string) $published );
		self::assertStringNotContainsString( 'does not confirm or perform a publish action', (string) $published );
		self::assertNull( McpAppsPostUpdateResult::text_fallback( array( 'schema' => 'wrong' ) ) );
		self::assertStringContainsString(
			'error',
			(string) McpAppsPostUpdateResult::text_fallback(
				array(
					'schema'  => 'aculect.post-update.v1',
					'outcome' => 'success',
					'status'  => 'error',
					'error'   => 'forbidden',
				)
			)
		);
		$legacy = McpAppsPostUpdateResult::text_fallback(
			array(
				'workflow'  => 'content_workflow_update_post',
				'status'    => 'success',
				'post_type' => 'page',
				'title'     => 'Legacy draft',
				'edit_url'  => 'https://example.com/wp-admin/post.php?post=123&action=edit',
				'permalink' => 'http://example.com/page/',
				'fields'    => array( 'status' => 'draft' ),
			)
		);
		self::assertStringContainsString( 'Content update completed.', (string) $legacy );
		self::assertStringContainsString( 'Edit in WordPress: https://example.com/wp-admin/', (string) $legacy );
		self::assertStringNotContainsString( 'View page:', (string) $legacy );
		self::assertNull(
			McpAppsPostUpdateResult::text_fallback(
				array(
					'workflow' => 'content_workflow_update_post',
					'status'   => 'error',
					'error'    => 'forbidden',
				)
			)
		);
		self::assertNull(
			McpAppsPostUpdateResult::text_fallback(
				array(
					'workflow' => 'content_workflow_update_post',
					'status'   => 'success',
					'dry_run'  => true,
				)
			)
		);
	}

	/**
	 * Build a contract under an explicit server-derived Apps request context.
	 *
	 * @param int                       $post_id Content ID.
	 * @param array<string, mixed>      $result  Atomic result.
	 * @param array<string, mixed>|null $before  Captured before state.
	 * @return array<string, mixed>|null
	 */
	private function build( int $post_id, array $result, ?array $before ): ?array {
		return McpAppsNegotiation::with_request_enabled(
			true,
			static fn (): ?array => ( new McpAppsPostUpdateResult() )->build( $post_id, $result, $before )
		);
	}

	/**
	 * Capture a complete pre-change post and matching, pre-existing revision.
	 *
	 * @return array<string, mixed>
	 */
	private function capture_complete_before_snapshot(): array {
		$before = new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Original title',
				'post_content'      => 'Original content',
				'post_excerpt'      => 'Original excerpt',
				'post_modified_gmt' => '2026-09-25 10:00:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][123]               = $before;
		$GLOBALS['aculect_ai_companion_test_post_revisions'][123][450] = new \WP_Post(
			array(
				'ID'           => 450,
				'post_type'    => 'revision',
				'post_parent'  => 123,
				'post_title'   => 'Original title',
				'post_content' => 'Original content',
				'post_excerpt' => 'Original excerpt',
			)
		);
		$captured = $this->capture_before( 123 );
		$current  = new \WP_Post(
			array(
				'ID'                => 123,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Updated title',
				'post_content'      => 'Updated content',
				'post_excerpt'      => 'Updated excerpt',
				'post_modified_gmt' => '2026-09-25 10:05:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][123]               = $current;
		$GLOBALS['aculect_ai_companion_test_post_revisions'][123][451] = new \WP_Post(
			array(
				'ID'           => 451,
				'post_type'    => 'revision',
				'post_parent'  => 123,
				'post_title'   => 'Updated title',
				'post_content' => 'Updated content',
				'post_excerpt' => 'Updated excerpt',
			)
		);
		return $captured;
	}

	/**
	 * Capture a snapshot under negotiated MCP Apps request context.
	 *
	 * @param int $post_id Content ID.
	 * @return array<string, mixed>|null
	 */
	private function capture_before( int $post_id ): ?array {
		return McpAppsNegotiation::with_request_enabled(
			true,
			static fn (): ?array => ( new McpAppsPostUpdateResult() )->capture_before( $post_id )
		);
	}

	/**
	 * Build a workflow result that exactly matches the current saved post.
	 *
	 * @param \WP_Post $post Current saved post.
	 * @return array<string, mixed>
	 */
	private function saved_result( \WP_Post $post ): array {
		return array(
			'id'           => (int) $post->ID,
			'type'         => (string) $post->post_type,
			'status'       => (string) $post->post_status,
			'title'        => (string) $post->post_title,
			'content'      => (string) $post->post_content,
			'excerpt'      => (string) $post->post_excerpt,
			'modified_gmt' => (string) $post->post_modified_gmt,
		);
	}
}

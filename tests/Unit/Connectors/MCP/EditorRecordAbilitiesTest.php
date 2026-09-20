<?php
/**
 * Guarded database-backed Site Editor record regression tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\EditorContentPolicy;
use Aculect\AICompanion\Connectors\MCP\EditorRecordAbilities;
use Aculect\AICompanion\Connectors\MCP\EditorRecordState;
use Aculect\AICompanion\Connectors\MCP\EditorStylePolicy;
use Aculect\AICompanion\Connectors\MCP\NativePostRecoveryPoint;
use Aculect\AICompanion\Connectors\MCP as EditorFixture;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class EditorRecordAbilitiesTest extends TestCase {

	private EditorRecordAbilities $service;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/fixtures/editor-record-stubs.php';

		$GLOBALS['aculect_ai_companion_test_posts']               = array();
		$GLOBALS['aculect_ai_companion_test_post_revisions']      = array();
		$GLOBALS['aculect_ai_companion_test_post_meta']           = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_blog_id']             = 1;
		$GLOBALS['aculect_ai_companion_test_stylesheet']          = 'test-theme';
		$GLOBALS['editor_record_test_terms']                      = array();
		$GLOBALS['editor_record_test_queries']                    = array();
		$GLOBALS['editor_record_test_query_callback']             = null;
		$GLOBALS['editor_record_test_terms_callback']             = null;
		$GLOBALS['editor_record_test_lock_callback']              = null;
		$GLOBALS['editor_record_test_lock_calls']                 = 0;
		$GLOBALS['editor_record_test_revisions_enabled']          = true;
		$GLOBALS['editor_record_test_revision_limit']             = 5;
		$GLOBALS['editor_record_test_revision_callback']          = null;
		$GLOBALS['editor_record_test_revision_save_calls']        = 0;
		$GLOBALS['editor_record_test_next_revision_id']           = 900;
		$GLOBALS['editor_record_test_slash_calls']                = 0;
		$GLOBALS['editor_record_test_update_calls']               = 0;
		$GLOBALS['editor_record_test_update_payloads']            = array();
		$GLOBALS['editor_record_test_update_callback']            = null;
		$GLOBALS['editor_record_test_block_filter_callback']      = null;
		$this->service = new EditorRecordAbilities();

		$registry = \WP_Block_Type_Registry::get_instance();
		$registry->unregister_all();
		$registry->register( 'core/paragraph' );
		$registry->register( 'core/group' );
		$registry->register( 'core/navigation-link' );
		$registry->register( 'core/navigation-submenu' );

		EditorFixture\editor_record_test_seed_records();
	}

	public function test_list_records_is_gated_bounded_and_filters_to_the_active_theme(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_theme_options' );
		self::assertSame( 'forbidden', $this->service->list_records( array( 'type' => 'wp_template' ) )['error'] );
		self::assertSame( array(), $GLOBALS['editor_record_test_queries'] );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();

		$invalid = array(
			array( 'type' => 'post' ),
			array( 'type' => array( 'wp_template' ) ),
			array(
				'type' => 'wp_template',
				'page' => 0,
			),
			array(
				'type' => 'wp_template',
				'page' => 5001,
			),
			array(
				'type' => 'wp_template',
				'page' => '1',
			),
			array(
				'type'     => 'wp_template',
				'per_page' => 0,
			),
			array(
				'type'     => 'wp_template',
				'per_page' => 51,
			),
		);
		foreach ( $invalid as $args ) {
			self::assertSame( 'invalid_editor_query', $this->service->list_records( $args )['error'] );
		}
		self::assertSame( array(), $GLOBALS['editor_record_test_queries'] );

		$result = $this->service->list_records(
			array(
				'type'     => 'wp_template',
				'page'     => 1,
				'per_page' => 1,
			)
		);
		self::assertSame(
			array(
				array(
					'post_id' => 100,
					'type'    => 'wp_template',
					'title'   => 'Draft template',
					'status'  => 'draft',
				),
			),
			$result['items']
		);
		self::assertTrue( $result['has_more'] );
		self::assertTrue( $result['database_only'] );
		self::assertFalse( $result['theme_files_included'] );
		$query = $GLOBALS['editor_record_test_queries'][0];
		self::assertSame( array( 'publish', 'draft' ), $query['post_status'] );
		self::assertSame( 2, $query['posts_per_page'] );
		self::assertSame( 0, $query['offset'] );
		self::assertSame( 'ID', $query['orderby'] );
		self::assertTrue( $query['no_found_rows'] );
		self::assertSame( 'wp_theme', $query['tax_query'][0]['taxonomy'] );
		self::assertSame( 'test-theme', $query['tax_query'][0]['terms'] );

		$page_two = $this->service->list_records(
			array(
				'type'     => 'wp_template',
				'page'     => 2,
				'per_page' => 1,
			)
		);
		self::assertSame( 101, $page_two['items'][0]['post_id'] );
		self::assertTrue( $page_two['has_more'] );
		self::assertSame( 1, $GLOBALS['editor_record_test_queries'][1]['offset'] );
	}

	public function test_read_accepts_only_fixed_database_record_types_with_active_theme_ownership(): void {
		self::assertSame( array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' ), EditorRecordState::TYPES );
		foreach ( array( 100, 110, 120, 130 ) as $post_id ) {
			$result = $this->service->read( array( 'post_id' => $post_id ) );
			self::assertArrayNotHasKey( 'error', $result );
			self::assertSame( $post_id, $result['post_id'] );
			self::assertArrayNotHasKey( 'file_path', $result );
		}
		self::assertSame( 'test-theme', $this->service->read( array( 'post_id' => 100 ) )['theme'] );
		self::assertStringContainsString( 'wp:navigation-link', $this->service->read( array( 'post_id' => 110 ) )['content'] );
		self::assertArrayHasKey( 'style_overrides', $this->service->read( array( 'post_id' => 120 ) ) );

		foreach ( array( 102, 103, 150 ) as $foreign_id ) {
			self::assertSame( 'editor_theme_mismatch', $this->service->read( array( 'post_id' => $foreign_id ) )['error'] );
		}
		self::assertSame( 'unsupported_editor_record', $this->service->read( array( 'post_id' => 140 ) )['error'] );

		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_status = 'trash';
		self::assertSame( 'unsupported_editor_record', $this->service->read( array( 'post_id' => 100 ) )['error'] );
		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_status  = 'draft';
		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_content = str_repeat( 'x', EditorRecordState::MAX_BYTES + 1 );
		self::assertSame( 'unsupported_editor_record', $this->service->read( array( 'post_id' => 100 ) )['error'] );
	}

	public function test_reads_require_site_editor_and_object_read_edit_capabilities(): void {
		foreach ( array( 'edit_theme_options', 'read_post', 'edit_post' ) as $denied ) {
			$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability ) use ( $denied ): bool {
				return $capability !== $denied;
			};
			self::assertSame( 'forbidden', $this->service->read( array( 'post_id' => 100 ) )['error'] );
		}

		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability, array $args ): bool {
			return ! ( 'read_post' === $capability && 100 === ( $args[0] ?? null ) )
				&& ! ( 'edit_post' === $capability && 101 === ( $args[0] ?? null ) );
		};
		$result = $this->service->list_records(
			array(
				'type'     => 'wp_template',
				'per_page' => 50,
			)
		);
		self::assertSame( array(), array_column( $result['items'], 'post_id' ) );
	}

	public function test_expected_state_rejects_record_drift_and_binds_revisions_and_site(): void {
		$token = $this->expected_state( 100 );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
		$args = array(
			'post_id'        => 100,
			'expected_state' => $token,
			'changes'        => array( 'title' => 'Changed title' ),
		);
		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_modified_gmt = '2026-09-15 12:00:00';
		self::assertSame( 'stale_editor_state', $this->service->write( 'update_record', $args )['error'] );
		self::assertSame( 0, $GLOBALS['editor_record_test_revision_save_calls'] );
		self::assertSame( 0, $GLOBALS['editor_record_test_update_calls'] );

		foreach ( array( null, 'bad-token', str_repeat( '0', 64 ) ) as $invalid ) {
			$stale_args                   = $args;
			$stale_args['expected_state'] = $invalid;
			self::assertSame( 'stale_editor_state', $this->service->write( 'update_record', $stale_args )['error'] );
		}

		EditorFixture\editor_record_test_add_revision(
			201,
			100,
			array(
				'post_title'   => 'Historical title',
				'post_content' => EditorFixture\editor_record_test_block( 'Historical content' ),
				'post_excerpt' => 'Historical excerpt',
			)
		);
		$with_revision = $this->service->read(
			array(
				'post_id'     => 100,
				'revision_id' => 201,
			)
		);
		self::assertSame( 201, $with_revision['revision_id'] );
		$revision_token = $with_revision['expected_state'];
		$GLOBALS['aculect_ai_companion_test_posts'][201]->post_content = EditorFixture\editor_record_test_block( 'Changed revision' );
		self::assertSame(
			'stale_editor_state',
			$this->service->write(
				'restore_record',
				array(
					'post_id'        => 100,
					'revision_id'    => 201,
					'expected_state' => $revision_token,
				)
			)['error']
		);
		self::assertSame( 0, $GLOBALS['editor_record_test_update_calls'] );

		$before_site                                  = $this->expected_state( 100 );
		$GLOBALS['aculect_ai_companion_test_blog_id'] = 2;
		self::assertNotSame( $before_site, $this->expected_state( 100 ) );
	}

	public function test_revision_reads_reject_autosaves_foreign_revisions_and_malformed_targets(): void {
		EditorFixture\editor_record_test_add_revision(
			201,
			100,
			array(
				'post_title'   => 'Saved',
				'post_content' => EditorFixture\editor_record_test_block( 'Saved' ),
				'post_excerpt' => 'Saved excerpt',
			)
		);
		self::assertSame(
			201,
			$this->service->read(
				array(
					'post_id'     => 100,
					'revision_id' => 201,
				)
			)['revision_id']
		);
		EditorFixture\editor_record_test_add_revision(
			202,
			100,
			array(
				'post_title'   => 'Auto',
				'post_content' => EditorFixture\editor_record_test_block( 'Auto' ),
				'post_excerpt' => 'Auto',
			),
			'autosave-202'
		);
		EditorFixture\editor_record_test_add_revision(
			203,
			101,
			array(
				'post_title'   => 'Foreign',
				'post_content' => EditorFixture\editor_record_test_block( 'Foreign' ),
				'post_excerpt' => 'Foreign',
			)
		);
		EditorFixture\editor_record_test_add_revision(
			204,
			100,
			array(
				'post_title'   => 'Large',
				'post_content' => str_repeat( 'x', EditorRecordState::MAX_BYTES + 1 ),
				'post_excerpt' => '',
			)
		);
		EditorFixture\editor_record_test_add_revision(
			205,
			100,
			array(
				'post_title'   => 'Wrong kind',
				'post_content' => EditorFixture\editor_record_test_block( 'Wrong' ),
				'post_excerpt' => '',
			),
			'revision-100-205',
			'post'
		);

		foreach ( array( '202', 202, 203, 204, 205, 999 ) as $revision_id ) {
			self::assertSame(
				'invalid_revision',
				$this->service->read(
					array(
						'post_id'     => 100,
						'revision_id' => $revision_id,
					)
				)['error']
			);
		}
		foreach ( array( 0, -1, '100', 1.5, true, array( 100 ) ) as $post_id ) {
			self::assertSame( 'invalid_editor_record', $this->service->read( array( 'post_id' => $post_id ) )['error'] );
		}
	}

	public function test_content_policy_rejects_malformed_active_unregistered_and_navigation_incompatible_blocks(): void {
		$policy       = new EditorContentPolicy();
		$paragraph    = EditorFixture\editor_record_test_block( 'Safe paragraph' );
		$navigation   = '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->';
		$malformed    = '<!-- wp:paragraph --><p>Unclosed';
		$unregistered = '<!-- wp:unknown/widget /-->';
		$custom_html  = '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->';
		self::assertTrue( $policy->valid( $paragraph ) );
		self::assertTrue( $policy->valid( $navigation, true ) );
		self::assertFalse( $policy->valid( $paragraph, true ) );
		self::assertFalse( $policy->valid( $malformed ) );
		self::assertFalse( $policy->valid( $unregistered ) );
		self::assertFalse( $policy->valid( $custom_html ) );
		self::assertFalse( $policy->valid( str_repeat( 'x', EditorRecordState::MAX_BYTES + 1 ) ) );

		$GLOBALS['editor_record_test_block_filter_callback'] = static fn ( string $content ): string => str_replace( '<script>alert(1)</script>', '', $content );
		self::assertFalse( $policy->valid( '<!-- wp:paragraph --><p><script>alert(1)</script></p><!-- /wp:paragraph -->' ) );
	}

	public function test_style_policy_is_finite_preserves_unrelated_data_and_rejects_malformed_json(): void {
		$policy = new EditorStylePolicy();
		self::assertTrue( $policy->valid( 'styles.color.text', '#abc' ) );
		self::assertTrue( $policy->valid( 'styles.typography.fontSize', '48px' ) );
		self::assertTrue( $policy->valid( 'styles.typography.lineHeight', '1.5' ) );
		self::assertTrue( $policy->valid( 'styles.spacing.padding.left', '2rem' ) );
		self::assertTrue( $policy->valid( 'settings.layout.contentSize', '960px' ) );
		self::assertTrue( $policy->valid( 'styles.color.background', null ) );
		foreach (
			array(
				array( 'styles.color.custom', '#ffffff' ),
				array( 'styles.color.text', 'url(https://evil.test)' ),
				array( 'styles.typography.fontSize', 'calc(100% - 1px)' ),
				array( 'styles.typography.fontStyle', 'oblique' ),
				array( 'styles.typography.fontWeight', '401' ),
				array( 'styles.typography.lineHeight', '5' ),
				array( 'styles.spacing.blockGap', '3001px' ),
				array( 'settings.layout.wideSize', '101%' ),
				array( 'settings.layout.contentSize', str_repeat( '1', 65 ) ),
				array( 'settings.layout.contentSize', 960 ),
			)
			as $invalid
		) {
			self::assertFalse( $policy->valid( $invalid[0], $invalid[1] ) );
		}

		$config  = EditorFixture\editor_record_test_style_config();
		$changed = $policy->apply( $config, 'styles.color.background', '#abcdef' );
		$removed = $policy->apply( $changed, 'styles.typography.fontSize', null );
		$values  = $policy->values( $changed );
		self::assertSame( '#abcdef', $changed['styles']['color']['background'] );
		self::assertSame( $config['settings']['custom'], $changed['settings']['custom'] );
		self::assertFalse( isset( $removed['styles']['typography']['fontSize'] ) );
		self::assertSame( EditorStylePolicy::PATHS, array_keys( $values ) );
		self::assertArrayNotHasKey( 'custom', $values );

		$state = new EditorRecordState();
		self::assertSame( $config, $state->styles( (string) wp_json_encode( $config ) ) );
		foreach ( array( '{', '[]', '{"version":"3","isGlobalStylesUserThemeJSON":true}', '{"version":3,"isGlobalStylesUserThemeJSON":false}', '{"version":3,"isGlobalStylesUserThemeJSON":true,"extra":1}', '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":"bad"}' ) as $malformed ) {
			self::assertNull( $state->styles( $malformed ) );
		}
		self::assertTrue( $policy->restorable( $changed, EditorFixture\editor_record_test_style_config( array( 'background' => '#ffffff' ) ) ) );
		self::assertFalse( $policy->restorable( $changed, EditorFixture\editor_record_test_style_config( array( 'custom_css' => 'body { color:red }' ) ) ) );
	}

	public function test_style_writes_reject_bad_paths_and_update_only_finite_overrides(): void {
		$read = $this->service->read( array( 'post_id' => 120 ) );
		foreach ( array( array( 'styles.color.link', '#ffffff' ), array( 'styles.color.text', 'var(--unsafe)' ) ) as $invalid ) {
			$result = $this->service->write(
				'set_style',
				array(
					'post_id'        => 120,
					'expected_state' => $read['expected_state'],
					'path'           => $invalid[0],
					'value'          => $invalid[1],
				)
			);
			self::assertSame( 'invalid_style', $result['error'] );
		}
		self::assertSame(
			'unsupported_editor_operation',
			$this->service->write(
				'set_style',
				array(
					'post_id'        => 100,
					'expected_state' => $this->expected_state( 100 ),
					'path'           => 'styles.color.text',
					'value'          => '#ffffff',
				)
			)['error']
		);
		self::assertSame( 0, $GLOBALS['editor_record_test_update_calls'] );

		$result = $this->service->write(
			'set_style',
			array(
				'post_id'        => 120,
				'expected_state' => $read['expected_state'],
				'path'           => 'styles.color.background',
				'value'          => '#abcdef',
			)
		);
		self::assertTrue( $result['success'], (string) wp_json_encode( $result ) );
		self::assertSame( 1, $GLOBALS['editor_record_test_update_calls'] );
		$config = json_decode( get_post( 120 )->post_content, true );
		self::assertSame( '#abcdef', $config['styles']['color']['background'] );
		self::assertSame( 'preserve-me', $config['settings']['custom']['owner_note'] );
		self::assertSame( '700', $config['styles']['blocks']['core/paragraph']['typography']['fontWeight'] );
	}

	public function test_draft_and_live_record_updates_preserve_identity_and_capture_exact_preimages(): void {
		$GLOBALS['aculect_ai_companion_test_post_meta'][100] = array( '_private_editor_test' => 'keep' );
		$draft_before                                        = clone get_post( 100 );
		$draft_result                                        = $this->service->write(
			'update_record',
			array(
				'post_id'        => 100,
				'expected_state' => $this->expected_state( 100 ),
				'changes'        => array(
					'title'   => 'Draft updated',
					'content' => EditorFixture\editor_record_test_block( 'Draft updated' ),
				),
			)
		);
		self::assertTrue( $draft_result['success'], (string) wp_json_encode( $draft_result ) );
		self::assertSame( 'draft', get_post( 100 )->post_status );
		self::assertSame( $draft_before->post_name, get_post( 100 )->post_name );
		self::assertSame( $draft_before->post_author, get_post( 100 )->post_author );
		self::assertSame( $draft_before->post_parent, get_post( 100 )->post_parent );
		self::assertSame( $draft_before->post_excerpt, get_post( 100 )->post_excerpt );
		self::assertSame( $draft_before->post_title, get_post( $draft_result['recovery_revision_id'] )->post_title );
		self::assertSame( $draft_before->post_content, get_post( $draft_result['recovery_revision_id'] )->post_content );
		self::assertSame( array( '_private_editor_test' => 'keep' ), $GLOBALS['aculect_ai_companion_test_post_meta'][100] );
		$payload = $GLOBALS['editor_record_test_update_payloads'][0];
		self::assertArrayNotHasKey( 'post_status', $payload );
		self::assertArrayNotHasKey( 'post_name', $payload );
		self::assertArrayNotHasKey( 'post_author', $payload );
		self::assertArrayNotHasKey( 'post_parent', $payload );

		$live_before = clone get_post( 110 );
		$live_result = $this->service->write(
			'update_record',
			array(
				'post_id'        => 110,
				'expected_state' => $this->expected_state( 110 ),
				'changes'        => array( 'content' => '<!-- wp:navigation-link {"label":"About","url":"/about"} /-->' ),
			)
		);
		self::assertTrue( $live_result['success'], (string) wp_json_encode( $live_result ) );
		self::assertSame( 'publish', get_post( 110 )->post_status );
		self::assertSame( $live_before->post_name, get_post( 110 )->post_name );
		self::assertSame( $live_before->post_author, get_post( 110 )->post_author );
		self::assertSame( $live_before->post_parent, get_post( 110 )->post_parent );
		self::assertSame( $live_before->post_excerpt, get_post( 110 )->post_excerpt );
		self::assertStringContainsString( 'affect the live site immediately', implode( ' ', $live_result['warnings'] ) );
		self::assertSame( 2, $GLOBALS['editor_record_test_revision_save_calls'] );
		self::assertSame( 2, $GLOBALS['editor_record_test_update_calls'] );
	}

	public function test_preview_noop_and_malformed_content_do_not_create_revisions_or_write(): void {
		$args = array(
			'post_id'        => 100,
			'expected_state' => $this->expected_state( 100 ),
			'changes'        => array( 'content' => EditorFixture\editor_record_test_block( 'Preview only' ) ),
			'dry_run'        => true,
		);
		self::assertSame( 'preview', $this->service->write( 'update_record', $args )['status'] );
		self::assertSame( 0, $GLOBALS['editor_record_test_revision_save_calls'] );
		self::assertSame( 0, $GLOBALS['editor_record_test_update_calls'] );

		$no_op = array(
			'post_id'        => 100,
			'expected_state' => $this->expected_state( 100 ),
			'changes'        => array( 'title' => get_post( 100 )->post_title ),
		);
		self::assertFalse( $this->service->write( 'update_record', $no_op )['changed'] );
		self::assertSame( 0, $GLOBALS['editor_record_test_revision_save_calls'] );

		foreach (
			array(
				array( 'content' => '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->' ),
				array( 'status' => 'publish' ),
				array( 'title' => array( 'not a string' ) ),
				array(),
			)
			as $changes
		) {
			$invalid            = $no_op;
			$invalid['changes'] = $changes;
			self::assertArrayHasKey( 'error', $this->service->write( 'update_record', $invalid ) );
		}
		self::assertSame( 0, $GLOBALS['editor_record_test_update_calls'] );
	}

	public function test_content_restore_uses_a_valid_revision_and_preserves_status_and_identity(): void {
		$revision_fields = array(
			'post_title'   => 'Historical title',
			'post_content' => EditorFixture\editor_record_test_block( 'Historical content' ),
			'post_excerpt' => 'Historical excerpt',
		);
		EditorFixture\editor_record_test_add_revision( 201, 100, $revision_fields );
		$read           = $this->service->read(
			array(
				'post_id'     => 100,
				'revision_id' => 201,
			)
		);
		$revision_token = $read['expected_state'];
		$GLOBALS['aculect_ai_companion_test_posts'][201]->post_excerpt = 'Changed after preview';
		self::assertSame(
			'stale_editor_state',
			$this->service->write(
				'restore_record',
				array(
					'post_id'        => 100,
					'revision_id'    => 201,
					'expected_state' => $revision_token,
				)
			)['error']
		);
		self::assertSame( 0, $GLOBALS['editor_record_test_update_calls'] );
		$GLOBALS['aculect_ai_companion_test_posts'][201]->post_excerpt = $revision_fields['post_excerpt'];

		$before = clone get_post( 100 );
		$result = $this->service->write(
			'restore_record',
			array(
				'post_id'        => 100,
				'revision_id'    => 201,
				'expected_state' => $this->expected_state( 100, 201 ),
			)
		);
		self::assertTrue( $result['success'], (string) wp_json_encode( $result ) );
		self::assertSame( $revision_fields['post_title'], get_post( 100 )->post_title );
		self::assertSame( $revision_fields['post_content'], get_post( 100 )->post_content );
		self::assertSame( $revision_fields['post_excerpt'], get_post( 100 )->post_excerpt );
		self::assertSame( $before->post_status, get_post( 100 )->post_status );
		self::assertSame( $before->post_name, get_post( 100 )->post_name );
		self::assertSame( $before->post_author, get_post( 100 )->post_author );
		self::assertSame( $before->post_parent, get_post( 100 )->post_parent );
		self::assertSame( $before->post_title, get_post( $result['recovery_revision_id'] )->post_title );
	}

	public function test_global_style_restore_accepts_only_allowlisted_differences(): void {
		$current = EditorFixture\editor_record_test_style_config(
			array(
				'background'   => '#222222',
				'content_size' => '800px',
			)
		);
		$prior   = EditorFixture\editor_record_test_style_config(
			array(
				'background'   => '#ffffff',
				'content_size' => '720px',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][120]->post_content = (string) wp_json_encode( $current );
		EditorFixture\editor_record_test_add_revision( 220, 120, array( 'post_content' => (string) wp_json_encode( $prior ) ) );
		$read   = $this->service->read(
			array(
				'post_id'     => 120,
				'revision_id' => 220,
			)
		);
		$result = $this->service->write(
			'restore_record',
			array(
				'post_id'        => 120,
				'revision_id'    => 220,
				'expected_state' => $read['expected_state'],
			)
		);
		self::assertTrue( $result['success'], (string) wp_json_encode( $result ) );
		$restored = json_decode( get_post( 120 )->post_content, true );
		self::assertSame( '#ffffff', $restored['styles']['color']['background'] );
		self::assertSame( '720px', $restored['settings']['layout']['contentSize'] );
		self::assertSame( 'preserve-me', $restored['settings']['custom']['owner_note'] );
		self::assertSame( '700', $restored['styles']['blocks']['core/paragraph']['typography']['fontWeight'] );

		$bad_history                              = $restored;
		$bad_history['styles']['css']             = 'body { background: url(https://example.invalid) }';
		$bad_history['settings']['custom']['old'] = 'arbitrary historical setting';
		EditorFixture\editor_record_test_add_revision( 221, 120, array( 'post_content' => (string) wp_json_encode( $bad_history ) ) );
		$read_bad = $this->service->read(
			array(
				'post_id'     => 120,
				'revision_id' => 221,
			)
		);
		$rejected = $this->service->write(
			'restore_record',
			array(
				'post_id'        => 120,
				'revision_id'    => 221,
				'expected_state' => $read_bad['expected_state'],
			)
		);
		self::assertSame( 'invalid_style_revision', $rejected['error'] );
		self::assertSame( 1, $GLOBALS['editor_record_test_update_calls'] );
	}

	public function test_native_recovery_points_require_retention_and_verify_the_exact_preimage(): void {
		$point = new NativePostRecoveryPoint();
		$post  = get_post( 100 );
		self::assertTrue( $point->available( $post ) );
		$GLOBALS['editor_record_test_revision_limit'] = 1;
		self::assertFalse( $point->available( $post ) );
		$GLOBALS['editor_record_test_revision_limit'] = 2;
		self::assertTrue( $point->available( $post ) );
		$GLOBALS['editor_record_test_revision_limit'] = -1;
		self::assertTrue( $point->available( $post ) );
		$GLOBALS['editor_record_test_revisions_enabled'] = false;
		self::assertFalse( $point->available( $post ) );
		$GLOBALS['editor_record_test_revisions_enabled'] = true;

		$GLOBALS['editor_record_test_lock_callback'] = static fn ( int $post_id ): int => $post_id;
		self::assertTrue( $point->locked( 100 ) );
		self::assertInstanceOf( \WP_Error::class, $point->capture( $post ) );
		$GLOBALS['editor_record_test_lock_callback'] = null;

		$revision_id = $point->capture( $post );
		self::assertSame( 900, $revision_id );
		self::assertTrue( $point->matches( $revision_id, $post ) );
		self::assertSame( array( 'post_title', 'post_content', 'post_excerpt' ), array_keys( $point->fields( $post ) ) );
		$GLOBALS['aculect_ai_companion_test_posts'][900]->post_title = 'Changed recovery copy';
		self::assertFalse( $point->matches( $revision_id, $post ) );
		$GLOBALS['aculect_ai_companion_test_posts'][900]->post_title = $post->post_title;
		$GLOBALS['aculect_ai_companion_test_posts'][900]->post_name  = 'autosave-900';
		self::assertFalse( $point->matches( $revision_id, $post ) );
		$GLOBALS['aculect_ai_companion_test_posts'][900]->post_name   = 'revision-100-900';
		$GLOBALS['aculect_ai_companion_test_posts'][900]->post_parent = 101;
		self::assertFalse( $point->matches( $revision_id, $post ) );
		unset( $GLOBALS['aculect_ai_companion_test_posts'][900] );
		self::assertFalse( $point->matches( $revision_id, $post ) );
	}

	public function test_recovery_capture_failure_and_unverified_writes_are_terminal(): void {
		$GLOBALS['editor_record_test_revision_callback'] = static function ( int $post_id ): false {
			unset( $post_id );
			return false;
		};
		$failed_capture                                  = $this->service->write(
			'update_record',
			array(
				'post_id'        => 100,
				'expected_state' => $this->expected_state( 100 ),
				'changes'        => array( 'title' => 'Never saved' ),
			)
		);
		self::assertSame( 'partial_write', $failed_capture['error'] );
		self::assertTrue( $failed_capture['terminal'] );
		self::assertSame( 0, $GLOBALS['editor_record_test_update_calls'] );
		$GLOBALS['editor_record_test_revision_callback'] = null;

		$GLOBALS['editor_record_test_update_callback'] = static function ( array $payload ): \WP_Error {
			unset( $payload );
			return new \WP_Error( 'simulated_update_failure', 'Native update failed.' );
		};
		$failed_update                                 = $this->service->write(
			'update_record',
			array(
				'post_id'        => 100,
				'expected_state' => $this->expected_state( 100 ),
				'changes'        => array( 'title' => 'Failed update' ),
			)
		);
		self::assertSame( 'partial_write', $failed_update['error'] );
		self::assertTrue( $failed_update['terminal'] );
		self::assertSame( 'Draft template', get_post( 100 )->post_title );

		$GLOBALS['editor_record_test_update_callback'] = static function ( array $payload, bool $wp_error ): never {
			EditorFixture\editor_record_test_apply_post_update( $payload, $wp_error );
			throw new \RuntimeException( 'Simulated native post-save hook failure.' );
		};
		$thrown                                        = $this->service->write(
			'update_record',
			array(
				'post_id'        => 100,
				'expected_state' => $this->expected_state( 100 ),
				'changes'        => array( 'title' => 'Saved before hook failed' ),
			)
		);
		self::assertSame( 'partial_write', $thrown['error'] );
		self::assertTrue( $thrown['terminal'] );
		self::assertSame( 'Saved before hook failed', get_post( 100 )->post_title );
		self::assertSame( 2, $GLOBALS['editor_record_test_update_calls'] );
	}

	/**
	 * Return a fresh state token for one target and optional saved revision.
	 *
	 * @param int      $post_id Target post ID.
	 * @param int|null $revision_id Saved revision ID.
	 */
	private function expected_state( int $post_id, ?int $revision_id = null ): string {
		$result = $this->service->read(
			null === $revision_id ? array( 'post_id' => $post_id ) : array(
				'post_id'     => $post_id,
				'revision_id' => $revision_id,
			)
		);
		self::assertArrayHasKey( 'expected_state', $result );
		return $result['expected_state'];
	}
}

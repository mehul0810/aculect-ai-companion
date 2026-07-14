<?php
/**
 * Tests for MCP intelligence index abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\IntelligenceIndexAbilities;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused MCP unit tests replace wpdb and keep the test double local to the test file.

/**
 * Verifies provider-facing intelligence index responses stay aligned with public tool names.
 */
final class IntelligenceIndexAbilitiesTest extends TestCase {

	private mixed $original_wpdb = null;

	private IntelligenceIndexMemoryWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb          = new IntelligenceIndexMemoryWpdb();

		$GLOBALS['wpdb']                                      = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_posts']           = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = array();
		$GLOBALS['aculect_ai_companion_test_options']         = array(
			'blogname' => 'Aculect Demo',
		);

		$this->registerTestBlocks();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
	}

	private function registerTestBlocks(): void {
		\WP_Block_Type_Registry::get_instance()->unregister_all();
		foreach ( array( 'core/paragraph', 'core/heading', 'core/group', 'core/html' ) as $name ) {
			\WP_Block_Type_Registry::get_instance()->register(
				$name,
				array(
					'title'    => $name,
					'category' => 'text',
					'supports' => array(
						'inserter' => true,
					),
				)
			);
		}
	}

	public function test_memory_save_dry_run_uses_registered_internal_action(): void {
		$result = ( new IntelligenceIndexAbilities() )->save_memory(
			array(
				'key'     => 'brand.voice.primary',
				'value'   => 'Use a concise, expert tone.',
				'dry_run' => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'memory.save', $result['action'] );
		self::assertSame( 'update', $result['risk_level'] );
		self::assertTrue( $result['confirmation_required'] );
		self::assertSame( 'status', $result['changes'][1]['field'] );
		self::assertSame( 'pending', $result['changes'][1]['to'] );
		self::assertStringContainsString( 'admin review', $result['warnings'][0] );
	}

	public function test_empty_memory_save_bootstraps_initial_memory_preview(): void {
		$result = ( new IntelligenceIndexAbilities() )->save_memory(
			array(
				'dry_run' => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'memory.save', $result['action'] );
		self::assertSame( 'memory_bootstrap', $result['target']['type'] );
		self::assertTrue( $result['confirmation_required'] );
		self::assertNotEmpty( $result['items'] );
		self::assertContains( 'workflow.blocks.no_custom_html', array_column( $result['items'], 'key' ) );
	}

	public function test_memory_bootstrap_saves_initial_guidance(): void {
		$result = ( new IntelligenceIndexAbilities() )->bootstrap_memory(
			array(
				'status' => 'approved',
			)
		);

		self::assertSame( 'success', $result['status'] );
		self::assertSame( 'approved', $result['review_status']['status'] );
		self::assertFalse( $result['review_status']['admin_review_required'] );
		self::assertArrayHasKey( 'workflow.blocks.no_custom_html', $this->wpdb->rows );
		self::assertSame( 'approved', $this->wpdb->rows['workflow.blocks.no_custom_html']['status'] );
	}

	public function test_canonical_search_returns_empty_results_without_query(): void {
		$result = ( new IntelligenceIndexAbilities() )->canonical_search( array( 'query' => '' ) );

		self::assertSame( array(), $result['results'] );
	}

	public function test_canonical_fetch_returns_readable_post_document(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123] = new \WP_Post(
			array(
				'ID'           => 123,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Canonical Retrieval',
				'post_content' => '<!-- wp:paragraph --><p>Hello <strong>world</strong>.</p><!-- /wp:paragraph -->',
				'post_name'    => 'canonical-retrieval',
			)
		);

		$result = ( new IntelligenceIndexAbilities() )->canonical_fetch( array( 'id' => 'wp-post:123' ) );

		self::assertSame( 'wp-post:123', $result['id'] );
		self::assertSame( 'Canonical Retrieval', $result['title'] );
		self::assertSame( 'Hello world.', $result['text'] );
		self::assertSame( 'https://example.com/?p=123', $result['url'] );
		self::assertSame( 123, $result['metadata']['post_id'] );
		self::assertSame( 'post', $result['metadata']['post_type'] );
		self::assertSame( 'publish', $result['metadata']['status'] );

		$plain_id_result = ( new IntelligenceIndexAbilities() )->canonical_fetch( array( 'id' => '123' ) );
		self::assertSame( $result['id'], $plain_id_result['id'] );
	}

	public function test_canonical_fetch_respects_read_post_permission(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123]  = new \WP_Post(
			array(
				'ID'           => 123,
				'post_title'   => 'Private Post',
				'post_content' => 'Secret',
			)
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'read_post' );

		$result = ( new IntelligenceIndexAbilities() )->canonical_fetch( array( 'id' => 'wp-post:123' ) );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_search_items_degraded_fallback_respects_thin_page_filters(): void {
		$GLOBALS['aculect_ai_companion_test_posts'] = array(
			11 => new \WP_Post(
				array(
					'ID'           => 11,
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Short Page',
					'post_content' => str_repeat( 'short ', 120 ),
				)
			),
			12 => new \WP_Post(
				array(
					'ID'           => 12,
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Long Page',
					'post_content' => str_repeat( 'long ', 650 ),
				)
			),
			13 => new \WP_Post(
				array(
					'ID'           => 13,
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_title'   => 'Short Post',
					'post_content' => str_repeat( 'post ', 90 ),
				)
			),
		);

		$result = ( new IntelligenceIndexAbilities() )->search_items(
			array(
				'post_type'      => 'page',
				'status'         => 'publish',
				'max_word_count' => 300,
				'per_page'       => 10,
			)
		);

		self::assertTrue( $result['degraded'] );
		self::assertSame( 'index_empty', $result['degraded_reason'] );
		self::assertSame( array( 11 ), array_column( $result['items'], 'id' ) );
		self::assertSame( 120, $result['items'][0]['word_count'] );
	}

	public function test_audit_internal_links_returns_read_only_health_signals(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][11] = new \WP_Post(
			array(
				'ID'          => 11,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Orphan Landing Page',
			)
		);
		$this->wpdb->rows['content-11']                 = array(
			'object_id'               => 11,
			'object_type'             => 'post',
			'post_type'               => 'page',
			'post_status'             => 'publish',
			'title'                   => 'Orphan Landing Page',
			'slug'                    => 'orphan-landing-page',
			'permalink'               => 'https://example.com/orphan-landing-page/',
			'excerpt'                 => '',
			'summary'                 => 'Short indexed summary.',
			'word_count'              => 180,
			'content_hash'            => str_repeat( 'a', 64 ),
			'indexed_at'              => '2026-06-19 09:00:00',
			'modified_gmt'            => '2026-06-19 08:00:00',
			'stale'                   => 1,
			'search_text'             => '',
			'metadata'                => '{}',
			'inbound_internal_links'  => 0,
			'outbound_internal_links' => 4,
		);

		$result = ( new IntelligenceIndexAbilities() )->audit_internal_links(
			array(
				'state'           => 'needs_review',
				'thin_word_count' => 300,
			)
		);

		self::assertSame( 1, $result['visible_total'] );
		self::assertFalse( $result['filtered_by_access'] );
		self::assertFalse( $result['total_is_estimated'] );
		self::assertSame( 11, $result['items'][0]['post_id'] );
		self::assertSame( array( 'orphan', 'thin', 'stale_index' ), $result['items'][0]['flags'] );
		self::assertTrue( $result['items'][0]['needs_review'] );
		self::assertTrue( '' !== $result['usage']['read_only'] );
		self::assertSame( 'needs_review', $result['summary']['state'] );
		self::assertSame( 300, $result['summary']['thresholds']['thin_word_count'] );
		self::assertSame( 3, $result['policy']['limits']['max_new_links_per_source'] );
		self::assertFalse( $result['policy']['mutation_policy']['content_mutation'] );
		self::assertArrayHasKey( 'health_summary', $result );
		self::assertSame( 'internal-link-health-v1', $result['health_summary']['methodology']['version'] );
		self::assertSame( 'site_index', $result['health_summary']['scope'] );
		self::assertSame( 1, $result['health_summary']['counts']['orphan_content'] );
		self::assertSame( 1, $result['health_summary']['counts']['stale_index_rows'] );
		self::assertSame( 'refresh_stale_index', $result['action_queue']['items'][0]['action'] );
		self::assertTrue( $result['action_queue']['read_only'] );
		self::assertStringContainsString( 'not an SEO ranking guarantee', $result['health_summary']['methodology']['claim'] );
	}

	public function test_audit_internal_links_prioritizes_broken_targets_before_add_link_actions(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][11]            = new \WP_Post(
			array(
				'ID'          => 11,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Source Page',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][22]            = new \WP_Post(
			array(
				'ID'          => 22,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Orphan Page',
			)
		);
		$this->wpdb->rows['content-22']                            = $this->indexedContentRow( 22, 'Orphan Page', 'Detailed evergreen guide.' );
		$this->wpdb->rows['content-22']['inbound_internal_links']  = 0;
		$this->wpdb->rows['content-22']['outbound_internal_links'] = 2;
		$this->wpdb->rows['link-missing']                          = array(
			'link_id'                    => 901,
			'source_id'                  => 11,
			'target_id'                  => 0,
			'target_url'                 => 'https://example.com/missing/',
			'anchor_text'                => 'Missing target',
			'rel'                        => '',
			'link_context'               => 'Missing target context.',
			'link_created_at'            => '2026-07-03 05:00:00',
			'source_post_type'           => 'page',
			'source_post_status'         => 'publish',
			'source_title'               => 'Source Page',
			'source_slug'                => 'source-page',
			'source_permalink'           => 'https://example.com/source-page/',
			'source_indexed_at'          => '2026-07-03 04:00:00',
			'source_modified_gmt'        => '2026-07-03 03:00:00',
			'source_stale'               => 0,
			'indexed_target_post_type'   => '',
			'indexed_target_post_status' => '',
			'indexed_target_title'       => '',
			'indexed_target_slug'        => '',
			'indexed_target_permalink'   => '',
			'indexed_target_stale'       => 0,
		);

		$result = ( new IntelligenceIndexAbilities() )->audit_internal_links(
			array(
				'state'       => 'needs_review',
				'queue_limit' => 5,
			)
		);

		self::assertSame( 'fix_broken_link', $result['action_queue']['items'][0]['action'] );
		self::assertSame( 'add_inbound_links', $result['action_queue']['items'][1]['action'] );
		self::assertGreaterThan( $result['action_queue']['items'][1]['priority_score'], $result['action_queue']['items'][0]['priority_score'] );
		self::assertSame( 1, $result['health_summary']['counts']['broken_internal_links'] );
	}

	public function test_audit_internal_links_reports_empty_index_health(): void {
		$result = ( new IntelligenceIndexAbilities() )->audit_internal_links( array( 'queue_limit' => 10 ) );

		self::assertSame( 0, $result['health_summary']['score'] );
		self::assertSame( 'empty_index', $result['health_summary']['status'] );
		self::assertSame( 0, $result['health_summary']['counts']['total_indexed_items'] );
		self::assertSame( array(), $result['action_queue']['items'] );
	}

	public function test_audit_internal_links_bounds_large_site_action_queue(): void {
		for ( $i = 1; $i <= 70; ++$i ) {
			$post_id = 1000 + $i;
			$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new \WP_Post(
				array(
					'ID'          => $post_id,
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => 'Orphan Page ' . $i,
				)
			);
			$this->wpdb->rows[ 'content-' . $post_id ]              = array_merge(
				$this->indexedContentRow( $post_id, 'Orphan Page ' . $i, 'Indexed page summary.' ),
				array(
					'inbound_internal_links'  => 0,
					'outbound_internal_links' => 1,
				)
			);
		}

		$result = ( new IntelligenceIndexAbilities() )->audit_internal_links(
			array(
				'queue_limit' => 5,
			)
		);

		self::assertCount( 5, $result['action_queue']['items'] );
		self::assertTrue( $result['action_queue']['has_more'] );
		self::assertSame( 15, $result['action_queue']['bounds']['candidate_scan_limit'] );
		self::assertSame( 5, $result['health_summary']['counts']['action_queue_return_limit'] );
	}

	public function test_audit_internal_links_uses_visible_scope_when_global_summary_is_denied(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array( 'manage_options' );
		$GLOBALS['aculect_ai_companion_test_posts'][11]       = new \WP_Post(
			array(
				'ID'          => 11,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Visible Orphan',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][12]       = new \WP_Post(
			array(
				'ID'          => 12,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Denied Orphan',
			)
		);
		$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = array( 12 );
		$this->wpdb->rows['content-11']                       = array_merge(
			$this->indexedContentRow( 11, 'Visible Orphan', 'Visible indexed summary.' ),
			array(
				'inbound_internal_links'  => 0,
				'outbound_internal_links' => 1,
			)
		);
		$this->wpdb->rows['content-12']                       = array_merge(
			$this->indexedContentRow( 12, 'Denied Orphan', 'Denied indexed summary.' ),
			array(
				'inbound_internal_links'  => 0,
				'outbound_internal_links' => 1,
			)
		);

		$result = ( new IntelligenceIndexAbilities() )->audit_internal_links( array() );

		self::assertTrue( $result['health_summary']['counts']['filtered_by_access'] );
		self::assertSame( 'visible_content', $result['health_summary']['scope'] );
		self::assertSame( 1, $result['health_summary']['counts']['total_indexed_items'] );
		self::assertSame( array( 11 ), array_column( $result['action_queue']['items'], 'post_id' ) );
	}

	public function test_internal_link_policy_context_exposes_active_limits_without_mutation(): void {
		update_option(
			'aculect_ai_companion_internal_link_policy',
			array(
				'limits' => array(
					'max_suggestions_per_source' => 4,
					'max_new_links_per_source'   => 2,
				),
			),
			false
		);

		$result = ( new IntelligenceIndexAbilities() )->internal_link_policy_context();

		self::assertSame( 'internal_link_policy', $result['type'] );
		self::assertSame( 4, $result['limits']['max_suggestions_per_source'] );
		self::assertSame( 2, $result['limits']['max_new_links_per_source'] );
		self::assertTrue( $result['capabilities']['applies_content_links'] );
		self::assertStringContainsString( 'do not mutate content', $result['guidance']['read_only'] );
	}

	public function test_find_internal_links_returns_quality_scoring_and_warnings(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][101]                                = new \WP_Post(
			array(
				'ID'          => 101,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Source Guide',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][202]                                = new \WP_Post(
			array(
				'ID'          => 202,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Internal Link Strategy',
			)
		);
		$GLOBALS['aculect_ai_companion_test_post_meta'][101]['rank_math_focus_keyword'] = 'link strategy';
		$GLOBALS['aculect_ai_companion_test_post_meta'][202]['rank_math_focus_keyword'] = 'link strategy';
		$this->wpdb->rows['content-101']    = $this->indexedContentRow(
			101,
			'Source Guide',
			'Source guide about link strategy.',
			array( 'content' )
		);
		$this->wpdb->rows['content-202']    = $this->indexedContentRow(
			202,
			'Internal Link Strategy',
			'Practical internal link strategy for content teams.',
			array( 'content' )
		);
		$this->wpdb->rows['content-303']    = $this->indexedContentRow(
			303,
			'Already Linked Target',
			'Internal link strategy already linked from the source.',
			array( 'content' )
		);
		$this->wpdb->linked_target_ids[101] = array( 303 );
		$this->wpdb->link_stats             = array(
			101 => array(
				'object_id'               => 101,
				'inbound_internal_links'  => 2,
				'outbound_internal_links' => 26,
			),
			202 => array(
				'object_id'               => 202,
				'inbound_internal_links'  => 1,
				'outbound_internal_links' => 2,
			),
		);
		$this->wpdb->anchor_usage           = array(
			array(
				'anchor_text'    => 'Internal Link Strategy',
				'total'          => 3,
				'source_total'   => 2,
				'target_total'   => 3,
				'source_matches' => 0,
			),
		);

		$result = ( new IntelligenceIndexAbilities() )->find_internal_links(
			array(
				'source_id' => 101,
				'topic'     => 'internal link strategy',
				'limit'     => 5,
			)
		);

		self::assertSame( 1, $result['total'] );
		self::assertSame( 202, $result['items'][0]['post_id'] );
		self::assertSame( 'Internal Link Strategy', $result['items'][0]['anchor_text'] );
		self::assertIsInt( $result['items'][0]['quality_score'] );
		self::assertContains( 'duplicate_anchor', $result['items'][0]['warnings'] );
		self::assertContains( 'repeated_exact_match_anchor', $result['items'][0]['warnings'] );
		self::assertContains( 'over_optimized_anchor', $result['items'][0]['warnings'] );
		self::assertContains( 'Source and target share indexed taxonomy context.', $result['items'][0]['reasons'] );
		self::assertContains( 'Source and target share locally available SEO keyword context.', $result['items'][0]['reasons'] );
		self::assertSame( 1, $result['quality_summary']['excluded']['self_links'] );
		self::assertSame( 1, $result['quality_summary']['excluded']['already_linked_targets'] );
		self::assertTrue( $result['quality_summary']['read_only'] );
		self::assertSame( 15, $result['quality_summary']['bounds']['candidate_scan_limit'] );
		self::assertSame( 3, $result['quality_summary']['bounds']['max_new_links'] );
		self::assertSame( 1, $result['quality_summary']['bounds']['max_repeated_targets'] );
		self::assertSame( 10, $result['policy']['limits']['max_suggestions_per_source'] );
	}

	public function test_find_internal_links_enforces_policy_exclusions_and_limits(): void {
		update_option(
			'aculect_ai_companion_internal_link_policy',
			array(
				'excluded_post_ids' => array( 202 ),
				'limits'            => array(
					'max_suggestions_per_source' => 1,
					'max_new_links_per_source'   => 1,
				),
			),
			false
		);

		$GLOBALS['aculect_ai_companion_test_posts'][101] = new \WP_Post(
			array(
				'ID'          => 101,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Source Guide',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][202] = new \WP_Post(
			array(
				'ID'          => 202,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Excluded Candidate',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][303] = new \WP_Post(
			array(
				'ID'          => 303,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Allowed Candidate',
			)
		);
		$this->wpdb->rows['content-101']                 = $this->indexedContentRow( 101, 'Source Guide', 'Source guide about policy limits.' );
		$this->wpdb->rows['content-202']                 = $this->indexedContentRow( 202, 'Excluded Candidate', 'Policy limits should exclude this candidate.' );
		$this->wpdb->rows['content-303']                 = $this->indexedContentRow( 303, 'Allowed Candidate', 'Policy limits allow this candidate.' );

		$result = ( new IntelligenceIndexAbilities() )->find_internal_links(
			array(
				'source_id' => 101,
				'topic'     => 'policy limits',
				'limit'     => 5,
			)
		);

		self::assertSame( 1, $result['total'] );
		self::assertSame( array( 303 ), array_column( $result['items'], 'post_id' ) );
		self::assertSame( 1, $result['policy']['limits']['max_suggestions_per_source'] );
		self::assertSame( 1, $result['quality_summary']['bounds']['return_limit'] );
	}

	public function test_internal_link_suggestion_workflow_requires_review_before_dry_run_apply(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][11] = new \WP_Post(
			array(
				'ID'           => 11,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Source Page',
				'post_content' => '<!-- wp:paragraph --><p>Read the Target Post before applying policy limits.</p><!-- /wp:paragraph -->',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][22] = new \WP_Post(
			array(
				'ID'          => 22,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Target Post',
			)
		);

		$abilities = new IntelligenceIndexAbilities();
		$created   = $abilities->create_internal_link_suggestions(
			array(
				'source_id'   => 11,
				'target_id'   => 22,
				'anchor_text' => 'Target Post',
				'reason'      => 'Target explains the source topic.',
				'score'       => 80,
			)
		);

		self::assertSame( 'created', $created['status'] );
		self::assertTrue( $created['capabilities']['execute_apply'] );
		$id = (string) $created['items'][0]['id'];

		$blocked = $abilities->apply_internal_link_suggestion(
			array(
				'id'      => $id,
				'dry_run' => true,
			)
		);

		self::assertSame( 'suggestion_not_approved', $blocked['error'] );

		$reviewed = $abilities->review_internal_link_suggestion(
			array(
				'id'     => $id,
				'action' => 'approve',
				'note'   => 'Relevant.',
			)
		);

		self::assertSame( 'approved', $reviewed['suggestion']['status'] );

		$preview = $abilities->apply_internal_link_suggestion(
			array(
				'id'      => $id,
				'dry_run' => true,
			)
		);

		self::assertSame( 'preview', $preview['status'] );
		self::assertTrue( $preview['dry_run'] );
		self::assertSame( 11, $preview['target']['id'] );
		self::assertSame( 'block.internal_link', $preview['diff']['fields'][0]['field'] );

		$execute = $abilities->apply_internal_link_suggestion( array( 'id' => $id ) );
		self::assertSame( 'applied', $execute['suggestion']['status'] );
		self::assertStringContainsString( '<a href="https://example.com/?p=22">Target Post</a>', $GLOBALS['aculect_ai_companion_test_posts'][11]->post_content );
	}

	public function test_internal_link_suggestion_create_checks_source_edit_capability(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][11]   = new \WP_Post(
			array(
				'ID'         => 11,
				'post_type'  => 'page',
				'post_title' => 'Source Page',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][22]   = new \WP_Post(
			array(
				'ID'         => 22,
				'post_type'  => 'post',
				'post_title' => 'Target Post',
			)
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_post' );

		$result = ( new IntelligenceIndexAbilities() )->create_internal_link_suggestions(
			array(
				'source_id'   => 11,
				'target_id'   => 22,
				'anchor_text' => 'Target Post',
				'reason'      => 'Target explains the source topic.',
			)
		);

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_audit_internal_links_reports_broken_targets_with_source_access_filtering(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][11]       = new \WP_Post(
			array(
				'ID'          => 11,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Readable Source',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][12]       = new \WP_Post(
			array(
				'ID'          => 12,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Denied Source',
			)
		);
		$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = array( 12 );
		$this->wpdb->rows['link-readable']                    = array(
			'link_id'                    => 101,
			'source_id'                  => 11,
			'target_id'                  => 0,
			'target_url'                 => 'https://example.com/missing-target/',
			'anchor_text'                => 'Missing target',
			'rel'                        => '',
			'link_context'               => 'Read more about the missing target.',
			'link_created_at'            => '2026-07-03 05:00:00',
			'source_post_type'           => 'page',
			'source_post_status'         => 'publish',
			'source_title'               => 'Readable Source',
			'source_slug'                => 'readable-source',
			'source_permalink'           => 'https://example.com/readable-source/',
			'source_indexed_at'          => '2026-07-03 04:00:00',
			'source_modified_gmt'        => '2026-07-03 03:00:00',
			'source_stale'               => 0,
			'indexed_target_post_type'   => '',
			'indexed_target_post_status' => '',
			'indexed_target_title'       => '',
			'indexed_target_slug'        => '',
			'indexed_target_permalink'   => '',
			'indexed_target_stale'       => 0,
		);
		$this->wpdb->rows['link-denied']                      = array_merge(
			$this->wpdb->rows['link-readable'],
			array(
				'link_id'          => 102,
				'source_id'        => 12,
				'source_title'     => 'Denied Source',
				'source_slug'      => 'denied-source',
				'source_permalink' => 'https://example.com/denied-source/',
			)
		);

		$result = ( new IntelligenceIndexAbilities() )->audit_internal_links(
			array(
				'state'    => 'broken',
				'per_page' => 10,
			)
		);

		self::assertSame( 1, $result['visible_total'] );
		self::assertSame( 11, $result['items'][0]['source_post']['post_id'] );
		self::assertSame( 'missing_target', $result['items'][0]['state'] );
		self::assertSame( 'internal_link_targets', $result['summary']['audit_type'] );
		self::assertSame( 'broken', $result['summary']['state'] );
		self::assertArrayHasKey( 'evidence', $result['items'][0] );
		self::assertStringContainsString( 'does not rewrite links', $result['usage']['read_only'] );
	}

	/**
	 * Build an indexed content row for MCP tests.
	 *
	 * @param int           $id    Post ID.
	 * @param string        $title Title.
	 * @param string        $summary Summary.
	 * @param array<string> $terms Term slugs.
	 * @return array<string, mixed>
	 */
	private function indexedContentRow( int $id, string $title, string $summary, array $terms = array() ): array {
		return array(
			'object_id'    => $id,
			'object_type'  => 'post',
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'title'        => $title,
			'slug'         => sanitize_title( $title ),
			'permalink'    => 'https://example.com/?p=' . $id,
			'excerpt'      => '',
			'summary'      => $summary,
			'word_count'   => 500,
			'content_hash' => str_repeat( 'a', 64 ),
			'indexed_at'   => '2026-07-03 09:00:00',
			'modified_gmt' => '2026-07-03 08:00:00',
			'stale'        => 0,
			'search_text'  => $title . ' ' . $summary,
			'metadata'     => wp_json_encode(
				array(
					'terms' => array_map(
						static fn ( string $term ): array => array(
							'taxonomy' => 'category',
							'slug'     => $term,
							'name'     => ucwords( str_replace( '-', ' ', $term ) ),
						),
						$terms
					),
				)
			),
		);
	}
}

/**
 * Minimal wpdb double for memory bootstrap side effects.
 */
final class IntelligenceIndexMemoryWpdb {

	public string $prefix = 'wp_';

	/**
	 * Stored table rows.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = array();

	/**
	 * Linked target IDs keyed by source post ID.
	 *
	 * @var array<int, list<int>>
	 */
	public array $linked_target_ids = array();

	/**
	 * Internal-link stats keyed by object ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $link_stats = array();

	/**
	 * Existing anchor usage aggregate rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $anchor_usage = array();

	/**
	 * Last prepared query arguments.
	 *
	 * @var array<int, mixed>
	 */
	private array $last_args = array();

	/**
	 * Record prepared query arguments.
	 *
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Prepared values.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->last_args = $args;

		return $query;
	}

	/**
	 * Return scalar query results from stored rows.
	 *
	 * @param string $query Prepared query.
	 */
	public function get_var( string $query ): ?int {
		if ( str_contains( $query, 'FROM (SELECT idx.object_id' ) ) {
			return $this->audit_count_for_query( $query );
		}

		if ( str_contains( $query, 'SELECT COUNT(*) FROM %i links' ) ) {
			return count(
				array_filter(
					$this->rows,
					static fn ( array $row ): bool => isset( $row['link_id'] )
				)
			);
		}

		if ( str_contains( $query, 'COUNT(*)' ) ) {
			return count( $this->content_rows() );
		}

		$key = $this->last_memory_key();

		return isset( $this->rows[ $key ] ) ? (int) $this->rows[ $key ]['id'] : null;
	}

	/**
	 * Return stored query rows.
	 *
	 * @param string $query  Prepared query.
	 * @param string $output Output type.
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );

		if ( str_contains( $query, 'FROM %i links' ) ) {
			return array_values(
				array_filter(
					$this->rows,
					static fn ( array $row ): bool => isset( $row['link_id'] )
				)
			);
		}

		if ( str_contains( $query, 'COUNT(DISTINCT inbound.source_id)' ) && str_contains( $query, 'idx.object_id IN' ) ) {
			return array_values( $this->link_stats );
		}

		if ( str_contains( $query, 'COUNT(*) AS total' ) && str_contains( $query, 'anchor_text' ) ) {
			return $this->anchor_usage;
		}

		if ( str_contains( $query, 'aculect_ai_content_index' ) ) {
			return array_values(
				array_filter(
					$this->rows,
					static fn ( array $row ): bool => isset( $row['object_id'] )
				)
			);
		}

		return array_values( $this->rows );
	}

	/**
	 * Return linked target IDs for the last source ID argument.
	 *
	 * @param string $query Prepared query.
	 * @return list<int>
	 */
	public function get_col( string $query ): array {
		unset( $query );

		$source_id = (int) ( $this->last_args[1] ?? 0 );
		return $this->linked_target_ids[ $source_id ] ?? array();
	}

	/**
	 * Insert one stored row.
	 *
	 * @param string               $table   Table name.
	 * @param array<string, mixed> $data    Row data.
	 * @param array<int, string>   $formats Insert formats.
	 */
	public function insert( string $table, array $data, array $formats ): int {
		unset( $table, $formats );

		$data['id']                                 = count( $this->rows ) + 1;
		$this->rows[ (string) $data['memory_key'] ] = $data;

		return 1;
	}

	/**
	 * Update one stored row.
	 *
	 * @param string               $table         Table name.
	 * @param array<string, mixed> $data          Row data.
	 * @param array<string, mixed> $where         Where clause data.
	 * @param array<int, string>   $formats       Update formats.
	 * @param array<int, string>   $where_formats Where formats.
	 */
	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int {
		unset( $table, $formats, $where_formats );

		$key = (string) ( $where['memory_key'] ?? '' );
		if ( isset( $this->rows[ $key ] ) ) {
			$this->rows[ $key ] = array_merge( $this->rows[ $key ], $data );
		}

		return 1;
	}

	/**
	 * Return one stored row.
	 *
	 * @param string $query  Prepared query.
	 * @param string $output Output type.
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, string $output ): ?array {
		unset( $output );

		if ( str_contains( $query, 'COUNT(*) AS total' ) && str_contains( $query, 'latest_indexed_at' ) ) {
			$rows   = $this->content_rows();
			$latest = '';
			$stale  = 0;
			foreach ( $rows as $row ) {
				if ( ! empty( $row['stale'] ) ) {
					++$stale;
				}

				$indexed_at = (string) ( $row['indexed_at'] ?? '' );
				if ( '' !== $indexed_at && $indexed_at > $latest ) {
					$latest = $indexed_at;
				}
			}

			return array(
				'total'             => count( $rows ),
				'stale'             => $stale,
				'latest_indexed_at' => $latest,
			);
		}

		$key = $this->last_memory_key();
		return $this->rows[ 'content-' . $key ] ?? $this->rows[ $key ] ?? null;
	}

	/**
	 * Return indexed content rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function content_rows(): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn ( array $row ): bool => isset( $row['object_id'] )
			)
		);
	}

	/**
	 * Return an internal-link audit count that mirrors repository thresholds.
	 *
	 * @param string $query Prepared query.
	 */
	private function audit_count_for_query( string $query ): int {
		return count(
			array_filter(
				$this->content_rows(),
				function ( array $row ) use ( $query ): bool {
					$inbound  = (int) ( $row['inbound_internal_links'] ?? 0 );
					$outbound = (int) ( $row['outbound_internal_links'] ?? 0 );
					$words    = (int) ( $row['word_count'] ?? 0 );
					$stale    = ! empty( $row['stale'] );

					if ( str_contains( $query, 'HAVING inbound_internal_links = 0' ) && ! str_contains( $query, 'OR' ) ) {
						return 0 === $inbound;
					}

					if ( str_contains( $query, 'HAVING inbound_internal_links > 0 AND inbound_internal_links < %d' ) ) {
						return $inbound > 0 && $inbound < 2;
					}

					if ( str_contains( $query, 'HAVING idx.word_count <= %d' ) && ! str_contains( $query, 'OR' ) ) {
						return $words <= 300;
					}

					if ( str_contains( $query, 'HAVING idx.stale = 1' ) ) {
						return $stale;
					}

					if ( str_contains( $query, 'HAVING outbound_internal_links > %d' ) && ! str_contains( $query, 'OR' ) ) {
						return $outbound > 25;
					}

					if ( str_contains( $query, 'HAVING inbound_internal_links = 0 OR' ) ) {
						return 0 === $inbound || ( $inbound > 0 && $inbound < 2 ) || $words <= 300 || $stale || $outbound > 25;
					}

					return true;
				}
			)
		);
	}

	/**
	 * Return the last memory key argument.
	 */
	private function last_memory_key(): string {
		return (string) ( $this->last_args[1] ?? '' );
	}
}

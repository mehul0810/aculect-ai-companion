<?php
/**
 * Tests for the MCP intelligence learning suggestion queue.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\LearningSuggestionRepository;
use Aculect\AICompanion\Intelligence\Memory\MemoryService;
use PHPUnit\Framework\TestCase;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Commenting.DocComment.MissingShort, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.ParamNameNoMatch, Squiz.Commenting.FunctionComment.IncorrectTypeHint, WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused wpdb double remains local to this test.

/**
 * Verifies learning suggestions remain bounded, sanitized, and review-first.
 */
final class LearningSuggestionRepositoryTest extends TestCase {

	private mixed $original_wpdb = null;

	private LearningSuggestionMemoryWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb          = new LearningSuggestionMemoryWpdb();

		$GLOBALS['wpdb']                              = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['aculect_ai_companion_test_failed_option_updates'] );
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
	}

	public function test_submit_queues_sanitized_pending_suggestion(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'domain'           => 'brand',
				'issue'            => '<strong>Tone drifted</strong>',
				'evidence'         => "Used casual copy\nwith no saved evidence.",
				'suggested_update' => 'Prefer concise enterprise copy.',
				'confidence'       => 'high',
			),
			array(
				'provider'    => 'gemini',
				'client_id'   => 'client-123',
				'client_name' => 'Gemini CLI',
				'user_id'     => 7,
			)
		);

		self::assertSame( 'queued', $result['status'] );
		self::assertFalse( $result['review_status']['updates_memory'] );
		self::assertTrue( $result['review_status']['admin_review_required'] );
		self::assertSame( 'brand', $result['suggestion']['domain'] );
		self::assertSame( 'Tone drifted', $result['suggestion']['issue'] );
		self::assertSame( 'gemini', $result['suggestion']['source']['provider'] );

		$payload = $repository->admin_payload();
		self::assertSame( 1, $payload['summary']['total'] );
		self::assertSame( 1, $payload['summary']['pending'] );
		self::assertSame( 'Prefer concise enterprise copy.', $payload['items'][0]['suggested_update'] );
	}

	public function test_submit_reports_storage_failure_and_allows_retry(): void {
		$repository = new LearningSuggestionRepository();
		$input      = array(
			'issue'            => 'Missing site context.',
			'suggested_update' => 'Keep an approved site description.',
		);
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array( 'aculect_ai_companion_learning_suggestions' );
		self::assertSame( 'storage_error', $repository->submit( $input )['error'] );
		self::assertSame( array(), get_option( 'aculect_ai_companion_learning_suggestions', array() ) );
		unset( $GLOBALS['aculect_ai_companion_test_failed_option_updates'] );
		self::assertSame( 'queued', $repository->submit( $input )['status'] );
		self::assertCount( 1, get_option( 'aculect_ai_companion_learning_suggestions', array() ) );
	}

	public function test_submit_preserves_known_grok_provider_attribution(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'domain'           => 'developer',
				'issue'            => 'Connector setup needs clarification.',
				'suggested_update' => 'Show the supported connection path.',
			),
			array(
				'provider'    => 'grok',
				'client_id'   => 'client-grok',
				'client_name' => 'Grok Connector',
				'user_id'     => 7,
			)
		);

		self::assertSame( 'grok', $result['suggestion']['source']['provider'] );
	}

	public function test_submit_rejects_incomplete_suggestions_without_writing(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'domain' => 'site',
				'issue'  => 'Missing context.',
			)
		);

		self::assertSame( 'rejected', $result['status'] );
		self::assertSame( array(), get_option( 'aculect_ai_companion_learning_suggestions', array() ) );
	}

	public function test_review_updates_status_without_mutating_suggestion_text(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'domain'           => 'content',
				'issue'            => 'Pattern recommendation was incomplete.',
				'suggested_update' => 'Prefer registered patterns with clear usage descriptions.',
			)
		);
		$id         = (string) $result['suggestion']['id'];

		self::assertTrue( $repository->review( $id, 'approve', 'Reviewed for beta.' ) );

		$payload = $repository->admin_payload();
		self::assertSame( 1, $payload['summary']['approved'] );
		self::assertSame( 0, $payload['summary']['pending'] );
		self::assertSame( 'approved', $payload['items'][0]['status'] );
		self::assertSame( 'Reviewed for beta.', $payload['items'][0]['review_note'] );
		self::assertSame( 'Prefer registered patterns with clear usage descriptions.', $payload['items'][0]['suggested_update'] );
		self::assertArrayHasKey( 'learning.content.' . $id, $this->wpdb->rows );
		self::assertSame( 'approved', $this->wpdb->rows[ 'learning.content.' . $id ]['status'] );
		self::assertSame( 'learning', $this->wpdb->rows[ 'learning.content.' . $id ]['source'] );
	}

	public function test_dismissing_approved_learning_tombstones_synced_memory(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'domain'           => 'content',
				'issue'            => 'Workflow memory should be reversible.',
				'suggested_update' => 'Use workflow guides for repeated tasks.',
			)
		);
		$id         = (string) $result['suggestion']['id'];

		self::assertTrue( $repository->review( $id, 'approve', 'Approved.' ) );
		self::assertArrayHasKey( 'learning.content.' . $id, $this->wpdb->rows );

		self::assertTrue( $repository->review( $id, 'dismiss', 'No longer needed.' ) );
		self::assertNotEmpty( $this->wpdb->rows[ 'learning.content.' . $id ]['deleted_at'] );
		self::assertSame( 'dismissed', $this->wpdb->rows[ 'learning.content.' . $id ]['status'] );
	}

	public function test_update_edits_pending_suggestion_without_changing_status(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'domain'           => 'site',
				'issue'            => 'Original issue',
				'suggested_update' => 'Original update',
			)
		);
		$id         = (string) $result['suggestion']['id'];

		self::assertTrue(
			$repository->update(
				$id,
				array(
					'domain'           => 'developer',
					'issue'            => 'Updated issue',
					'evidence'         => 'Updated evidence',
					'suggested_update' => 'Updated guidance',
					'confidence'       => 'high',
				),
				'Edited before approval.'
			)
		);

		$payload = $repository->admin_payload();
		self::assertSame( 'pending', $payload['items'][0]['status'] );
		self::assertSame( 'developer', $payload['items'][0]['domain'] );
		self::assertSame( 'Updated issue', $payload['items'][0]['issue'] );
		self::assertSame( 'Updated guidance', $payload['items'][0]['suggested_update'] );
		self::assertSame( 'Edited before approval.', $payload['items'][0]['review_note'] );
	}

	public function test_queue_is_bounded_to_latest_suggestions(): void {
		$repository = new LearningSuggestionRepository();

		for ( $i = 0; $i < 105; ++$i ) {
			$repository->submit(
				array(
					'domain'           => 'developer',
					'issue'            => 'Issue ' . $i,
					'suggested_update' => 'Update ' . $i,
				)
			);
		}

		$stored = get_option( 'aculect_ai_companion_learning_suggestions', array() );
		self::assertCount( 100, $stored );
		self::assertSame( 'Issue 5', $stored[0]['issue'] );
	}

	public function test_failed_memory_write_does_not_approve_and_can_be_retried(): void {
		$repository                    = new LearningSuggestionRepository();
		$result                        = $repository->submit(
			array(
				'issue'            => 'Issue',
				'suggested_update' => 'Guidance',
			)
		);
		$id                            = $result['suggestion']['id'];
		$this->wpdb->transaction_fails = true;
		self::assertFalse( $repository->review( $id, 'approve' ) );
		self::assertSame( 'pending', $repository->admin_payload()['items'][0]['status'] );
		$this->wpdb->transaction_fails = false;
		self::assertTrue( $repository->review( $id, 'approve' ) );
	}

	public function test_failed_dismissal_and_edit_are_not_reported_as_success(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'issue'            => 'Issue',
				'suggested_update' => 'Original',
			)
		);
		$id         = $result['suggestion']['id'];
		self::assertTrue( $repository->review( $id, 'approve' ) );
		$this->wpdb->transaction_fails = true;
		self::assertFalse( $repository->review( $id, 'dismiss' ) );
		self::assertFalse(
			$repository->update(
				$id,
				array(
					'issue'            => 'Issue',
					'suggested_update' => 'Changed',
				)
			)
		);
		self::assertSame( 'approved', $repository->admin_payload()['items'][0]['status'] );
		self::assertSame( 'Original', $repository->admin_payload()['items'][0]['suggested_update'] );
	}

	public function test_domain_change_preserves_synced_memory_identity(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'domain'           => 'site',
				'issue'            => 'Issue',
				'suggested_update' => 'Original',
			)
		);
		$id         = $result['suggestion']['id'];
		self::assertTrue( $repository->review( $id, 'approve' ) );
		self::assertTrue(
			$repository->update(
				$id,
				array(
					'domain'           => 'brand',
					'issue'            => 'Issue',
					'suggested_update' => 'Changed',
				)
			)
		);
		self::assertCount( 1, $this->wpdb->rows );
		self::assertSame( 'brand', $this->wpdb->rows[ 'learning.site.' . $id ]['domain'] );
	}

	public function test_batch_reuses_storage_engine_validation(): void {
		$items = array();
		for ( $i = 0; $i < 100; ++$i ) {
			$items[] = array(
				'key'   => 'batch.' . $i,
				'value' => 'Bounded memory',
			);
		}
		$result = ( new MemoryService() )->save_batch( $items );
		self::assertCount( 100, $result['saved'] );
		self::assertSame( 2, $this->wpdb->engine_queries );
	}

	public function test_option_failure_rolls_back_memory_and_history(): void {
		$repository = new LearningSuggestionRepository();
		$result     = $repository->submit(
			array(
				'issue'            => 'Issue',
				'suggested_update' => 'Guidance',
			)
		);
		$id         = $result['suggestion']['id'];
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array( 'aculect_ai_companion_learning_suggestions' );
		self::assertFalse( $repository->review( $id, 'approve' ) );
		self::assertSame( 'pending', $repository->admin_payload()['items'][0]['status'] );
		self::assertCount( 0, $this->wpdb->rows );
		self::assertCount( 0, $this->wpdb->events );
		unset( $GLOBALS['aculect_ai_companion_test_failed_option_updates'] );
		self::assertTrue( $repository->review( $id, 'approve' ) );
		self::assertCount( 1, $this->wpdb->events );
	}
}

/**
 * Minimal wpdb double for memory sync side effects.
 */
final class LearningSuggestionMemoryWpdb {
	private array $snapshot        = array();
	public bool $transaction_fails = false;
	public int $engine_queries     = 0;

	public string $prefix = 'wp_';

	/**
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = array();

	/** @var list<array<string, mixed>> */
	public array $events = array();

	/**
	 * @var array<int, mixed>
	 */
	private array $last_args = array();

	public function prepare( string $query, mixed ...$args ): string {
		$this->last_args = $args;

		return $query;
	}

	public function query( string $query ): int|false {
		if ( $this->transaction_fails && 'START TRANSACTION' === $query ) {
			return false;
		}
		if ( 'START TRANSACTION' === $query ) {
			$this->snapshot = array( $this->rows, $this->events, $GLOBALS['aculect_ai_companion_test_options'] );
		}
		if ( 'ROLLBACK' === $query && array() !== $this->snapshot ) {
			[ $this->rows, $this->events, $GLOBALS['aculect_ai_companion_test_options'] ] = $this->snapshot;
		}
		return 1;
	}

	public function get_var( string $query ): ?int {
		unset( $query );

		$key = $this->last_memory_key();

		return isset( $this->rows[ $key ] ) ? (int) $this->rows[ $key ]['id'] : null;
	}

	/**
	 * @param array<string, mixed> $data Row data.
	 * @param array<int, string>   $formats Insert formats.
	 */
	public function insert( string $table, array $data, array $formats ): int {
		unset( $formats );
		if ( str_contains( $table, 'memory_events' ) ) {
			$this->events[] = $data;
			return 1;
		}

		$data['id']                                 = count( $this->rows ) + 1;
		$this->rows[ (string) $data['memory_key'] ] = $data;

		return 1;
	}

	/**
	 * @param array<string, mixed> $data          Row data.
	 * @param array<string, mixed> $where         Where clause data.
	 * @param array<int, string>   $formats       Update formats.
	 * @param array<int, string>   $where_formats Where formats.
	 */
	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int {
		unset( $table, $formats, $where_formats );

		$key = (string) ( $where['memory_key'] ?? '' );
		if ( '' === $key && isset( $where['id'] ) ) {
			foreach ( $this->rows as $candidate_key => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === (int) $where['id'] ) {
					$key = $candidate_key;
					break;
				}
			}
		}
		if ( isset( $this->rows[ $key ] ) ) {
			$this->rows[ $key ] = array_merge( $this->rows[ $key ], $data );
		}

		return 1;
	}

	/**
	 * @param array<string, mixed> $where         Where clause data.
	 * @param array<int, string>   $where_formats Where formats.
	 */
	public function delete( string $table, array $where, array $where_formats ): int {
		unset( $table, $where_formats );

		$key = (string) ( $where['memory_key'] ?? '' );
		if ( ! isset( $this->rows[ $key ] ) ) {
			return 0;
		}

		unset( $this->rows[ $key ] );

		return 1;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, string $output ): ?array {
		unset( $output );
		if ( str_contains( $query, 'information_schema.TABLES' ) ) {
			++$this->engine_queries;
			return array(
				'Name'   => (string) ( $this->last_args[0] ?? '' ),
				'Engine' => 'InnoDB',
			);
		}

		return $this->rows[ $this->last_memory_key() ] ?? null;
	}

	private function last_memory_key(): string {
		return (string) ( $this->last_args[1] ?? '' );
	}
}

<?php
/**
 * Tests for transactional execution-claim persistence.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP\ExecutionClaims
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP\ExecutionClaims;

use Aculect\AICompanion\Connectors\MCP\ExecutionClaims\ExecutionClaimDecision;
use Aculect\AICompanion\Connectors\MCP\ExecutionClaims\WordPressExecutionClaimStore;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused stateful wpdb double.

/**
 * Proves the claim state machine and exact owner/fence transitions.
 */
final class WordPressExecutionClaimStoreTest extends TestCase {

	private mixed $original_wpdb;
	private ExecutionClaimWpdb $wpdb;
	private int $now;
	private int $token_sequence = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb          = new ExecutionClaimWpdb();
		$GLOBALS['wpdb']     = $this->wpdb;
		$this->now           = 1000;
	}

	protected function tearDown(): void {
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		parent::tearDown();
	}

	public function test_one_owner_runs_completes_and_replays_authoritative_result(): void {
		$store = $this->store();
		$first = $store->claim( $this->hash( 'payload' ), $this->hash( 'tool' ), $this->hash( 'identity' ), null, $this->hash( 'idem' ), true, null, false, 86400 );
		self::assertSame( ExecutionClaimDecision::ACQUIRED, $first->type );
		self::assertNotNull( $first->claim() );
		self::assertTrue( $store->mark_running( $first->claim() ) );

		$loser = $store->claim( $this->hash( 'payload' ), $this->hash( 'tool' ), $this->hash( 'identity' ), null, $this->hash( 'idem' ), true, null, false, 86400 );
		self::assertSame( ExecutionClaimDecision::IN_PROGRESS, $loser->type );
		self::assertTrue(
			$store->complete(
				$first->claim(),
				array(
					'status'  => 'success',
					'post_id' => 9,
				),
				86400
			)
		);

		$replay = $store->claim( $this->hash( 'payload' ), $this->hash( 'tool' ), $this->hash( 'identity' ), null, $this->hash( 'idem' ), true, null, false, 86400 );
		self::assertSame( ExecutionClaimDecision::REPLAY, $replay->type );
		self::assertSame( 9, $replay->result()['post_id'] ?? 0 );
		self::assertTrue( $replay->result()['replayed'] ?? false );
		self::assertSame( $this->hash( 'idem' ), $this->wpdb->rows[1]['idempotency_key_hash'] );
		self::assertArrayNotHasKey( 'idempotency_key', $this->wpdb->rows[1] );
	}

	public function test_expired_claim_reclaim_increments_fence_and_rejects_stale_owner(): void {
		$store     = $this->store();
		$first     = $store->claim( $this->hash( 'payload' ), $this->hash( 'tool' ), $this->hash( 'identity' ), $this->hash( 'confirmation' ), null, true, null, false, 3600 );
		$this->now = 1031;
		$second    = $store->claim( $this->hash( 'payload' ), $this->hash( 'tool' ), $this->hash( 'identity' ), $this->hash( 'confirmation' ), null, true, null, false, 3600 );

		self::assertSame( ExecutionClaimDecision::ACQUIRED, $second->type );
		self::assertSame( 2, $second->claim()?->fence() );
		self::assertFalse( $store->mark_running( $first->claim() ) );
		self::assertTrue( $store->mark_running( $second->claim() ) );
		self::assertFalse( $store->complete( $first->claim(), array( 'status' => 'success' ), 3600 ) );
		self::assertTrue( $store->complete( $second->claim(), array( 'status' => 'success' ), 3600 ) );
	}

	public function test_normal_error_release_allows_retry_but_uncertain_blocks_retry(): void {
		$store = $this->store();
		$first = $store->claim( $this->hash( 'payload' ), $this->hash( 'tool' ), $this->hash( 'identity' ), null, $this->hash( 'idem' ), true, null, false, 86400 );
		self::assertTrue( $store->mark_running( $first->claim() ) );
		self::assertTrue( $store->release( $first->claim() ) );

		$retry = $store->claim( $this->hash( 'payload' ), $this->hash( 'tool' ), $this->hash( 'identity' ), null, $this->hash( 'idem' ), true, null, false, 86400 );
		self::assertSame( ExecutionClaimDecision::ACQUIRED, $retry->type );
		self::assertTrue( $store->mark_running( $retry->claim() ) );
		self::assertTrue( $store->mark_uncertain( $retry->claim() ) );

		$blocked = $store->claim( $this->hash( 'payload' ), $this->hash( 'tool' ), $this->hash( 'identity' ), null, $this->hash( 'idem' ), true, null, false, 86400 );
		self::assertSame( ExecutionClaimDecision::UNCERTAIN, $blocked->type );
		self::assertFalse( $store->release( $retry->claim() ) );
	}

	public function test_idempotency_payload_mismatch_and_alias_split_brain_fail_closed(): void {
		$store = $this->store();
		$first = $store->claim( $this->hash( 'payload-a' ), $this->hash( 'tool' ), $this->hash( 'identity' ), $this->hash( 'confirmation-a' ), $this->hash( 'idem-a' ), true, null, false, 86400 );
		self::assertSame( ExecutionClaimDecision::ACQUIRED, $first->type );

		$reuse = $store->claim( $this->hash( 'payload-b' ), $this->hash( 'tool' ), $this->hash( 'identity' ), null, $this->hash( 'idem-a' ), true, null, false, 86400 );
		self::assertSame( ExecutionClaimDecision::KEY_REUSE, $reuse->type );

		$second = $store->claim( $this->hash( 'payload-a' ), $this->hash( 'tool' ), $this->hash( 'identity' ), $this->hash( 'confirmation-b' ), $this->hash( 'idem-b' ), true, null, false, 86400 );
		self::assertSame( ExecutionClaimDecision::ACQUIRED, $second->type );
		$split = $store->claim( $this->hash( 'payload-a' ), $this->hash( 'tool' ), $this->hash( 'identity' ), $this->hash( 'confirmation-a' ), $this->hash( 'idem-b' ), true, null, false, 86400 );
		self::assertSame( ExecutionClaimDecision::UNCERTAIN, $split->type );

		$confirmation_mismatch = $store->claim( $this->hash( 'payload-a' ), $this->hash( 'tool' ), $this->hash( 'identity' ), $this->hash( 'different-confirmation' ), $this->hash( 'idem-a' ), true, null, false, 86400 );
		self::assertSame( ExecutionClaimDecision::UNCERTAIN, $confirmation_mismatch->type );
		$idempotency_mismatch = $store->claim( $this->hash( 'payload-a' ), $this->hash( 'tool' ), $this->hash( 'identity' ), $this->hash( 'confirmation-a' ), $this->hash( 'different-idempotency' ), true, null, false, 86400 );
		self::assertSame( ExecutionClaimDecision::UNCERTAIN, $idempotency_mismatch->type );
	}

	public function test_legacy_result_import_result_bound_and_bounded_pruning(): void {
		$store  = $this->store();
		$legacy = $store->claim( $this->hash( 'legacy-payload' ), $this->hash( 'tool' ), $this->hash( 'identity' ), $this->hash( 'legacy-confirmation' ), null, false, array( 'status' => 'success' ), false, 3600 );
		self::assertSame( ExecutionClaimDecision::REPLAY, $legacy->type );

		$claim = $store->claim( $this->hash( 'large-payload' ), $this->hash( 'tool' ), $this->hash( 'identity' ), null, $this->hash( 'large-idem' ), true, null, false, 86400 );
		self::assertTrue( $store->mark_running( $claim->claim() ) );
		self::assertFalse( $store->complete( $claim->claim(), array( 'value' => str_repeat( 'x', 1048576 ) ), 86400 ) );

		$this->now = 5000;
		self::assertSame( 1, $store->prune_completed( 500 ) );
		self::assertArrayNotHasKey( 1, $this->wpdb->rows );
		self::assertArrayHasKey( 2, $this->wpdb->rows );
	}

	public function test_transient_deadlock_selection_and_insert_failures_retry_before_dispatch(): void {
		$this->wpdb->failed_selections = 1;
		$this->wpdb->failed_inserts    = 1;

		$decision = $this->store()->claim( $this->hash( 'retry-payload' ), $this->hash( 'tool' ), $this->hash( 'identity' ), null, $this->hash( 'retry-idem' ), true, null, false, 86400 );

		self::assertSame( ExecutionClaimDecision::ACQUIRED, $decision->type );
		self::assertCount( 1, $this->wpdb->rows );
	}

	private function store(): WordPressExecutionClaimStore {
		return new WordPressExecutionClaimStore(
			fn (): int => $this->now,
			fn (): string => str_repeat( chr( ++$this->token_sequence ), 32 )
		);
	}

	private function hash( string $value ): string {
		return hash( 'sha256', $value );
	}
}

/** Prepared query value understood by the local wpdb double. */
final readonly class ExecutionClaimPreparedQuery {
	/**
	 * Construct a prepared-query value.
	 *
	 * @param string $template SQL template.
	 * @param array  $args Prepared values.
	 */
	public function __construct( public string $template, public array $args ) {}
}

/** Stateful wpdb double implementing the fixed claim-store SQL contract. */
final class ExecutionClaimWpdb {
	public string $prefix         = 'wp_';
	public string $last_error     = '';
	public int $insert_id         = 0;
	public int $failed_selections = 0;
	public int $failed_inserts    = 0;
	/**
	 * In-memory claim rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $rows = array();
	/**
	 * Capture a prepared query.
	 *
	 * @param string $template SQL template.
	 * @param mixed  ...$args  Prepared values.
	 */
	public function prepare( string $template, mixed ...$args ): ExecutionClaimPreparedQuery {
		return new ExecutionClaimPreparedQuery( $template, $args );
	}
	/**
	 * Insert one in-memory row.
	 *
	 * @param string               $table Ignored table name.
	 * @param array<string, mixed> $data  Row data.
	 */
	public function insert( string $table, array $data ): int|false {
		unset( $table );
		if ( 0 < $this->failed_inserts-- ) {
			$this->last_error = 'Deadlock found when trying to get lock';
			return false;
		}
		foreach ( $this->rows as $row ) {
			foreach ( array( 'confirmation_key_hash', 'idempotency_key_hash' ) as $key ) {
				if ( null !== ( $data[ $key ] ?? null ) && ( $data[ $key ] ?? null ) === ( $row[ $key ] ?? null ) ) {
					$this->last_error = 'duplicate';
					return false;
				}
			}
		}
		$this->insert_id                = count( $this->rows ) + 1;
		$data['id']                     = $this->insert_id;
		$data['result_json']            = $data['result_json'] ?? null;
		$data['result_hash']            = $data['result_hash'] ?? null;
		$data['owner_hash']             = $data['owner_hash'] ?? null;
		$data['lease_expires_at']       = $data['lease_expires_at'] ?? null;
		$this->rows[ $this->insert_id ] = $data;
		return 1;
	}
	/**
	 * Execute one supported row selection.
	 *
	 * @param ExecutionClaimPreparedQuery $query  Prepared query.
	 * @param string                      $format Ignored output format.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function get_results( ExecutionClaimPreparedQuery $query, string $format ): array {
		unset( $format );
		if ( 0 < $this->failed_selections-- ) {
			$this->last_error = 'Deadlock found when trying to get lock';
			return array();
		}
		if ( str_starts_with( $query->template, 'SELECT id FROM' ) ) {
			$cutoff = (string) $query->args[2];
			$limit  = (int) $query->args[3];
			$rows   = array();
			foreach ( $this->rows as $row ) {
				if ( 'completed' === ( $row['state'] ?? '' ) && null !== ( $row['retain_until'] ?? null ) && (string) $row['retain_until'] <= $cutoff ) {
					$rows[] = array( 'id' => $row['id'] );
				}
			}
			return array_slice( $rows, 0, $limit );
		}

		$confirmation = str_contains( $query->template, 'confirmation_key_hash = %s' ) ? (string) $query->args[1] : null;
		$idempotency  = str_contains( $query->template, 'idempotency_key_hash = %s' ) ? (string) $query->args[ null === $confirmation ? 1 : 2 ] : null;
		return array_values(
			array_filter(
				$this->rows,
				static fn ( array $row ): bool => ( null !== $confirmation && ( $row['confirmation_key_hash'] ?? null ) === $confirmation )
					|| ( null !== $idempotency && ( $row['idempotency_key_hash'] ?? null ) === $idempotency )
			)
		);
	}
	public function query( string|ExecutionClaimPreparedQuery $query ): int|false {
		if ( is_string( $query ) ) {
			return in_array( $query, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ? 0 : false;
		}
		$args = $query->args;
		if ( str_starts_with( $query->template, 'UPDATE %i SET owner_hash' ) ) {
			$id = (int) $args[5];
			if ( ! $this->matches( $id, 'claimed', (string) $args[7], (int) $args[8] ) || (string) $args[9] < (string) $this->rows[ $id ]['lease_expires_at'] ) {
				return 0;
			}
			$this->rows[ $id ]['owner_hash']       = $args[1];
			$this->rows[ $id ]['fence']            = $args[2];
			$this->rows[ $id ]['lease_expires_at'] = $args[3];
			$this->rows[ $id ]['updated_at']       = $args[4];
			return 1;
		}
		if ( str_starts_with( $query->template, 'UPDATE %i SET state = %s, started_at' ) ) {
			$id = (int) $args[4];
			if ( ! $this->matches( $id, (string) $args[5], (string) $args[6], (int) $args[7] ) || (string) $args[8] >= (string) $this->rows[ $id ]['lease_expires_at'] ) {
				return 0;
			}
			$this->rows[ $id ]['state']            = $args[1];
			$this->rows[ $id ]['started_at']       = $args[2];
			$this->rows[ $id ]['lease_expires_at'] = null;
			return 1;
		}
		if ( str_starts_with( $query->template, 'UPDATE %i SET state = %s, result_json' ) ) {
			$id = (int) $args[7];
			if ( ! $this->matches( $id, (string) $args[8], (string) $args[9], (int) $args[10] ) ) {
				return 0;
			}
			$this->rows[ $id ]['state']        = $args[1];
			$this->rows[ $id ]['result_json']  = $args[2];
			$this->rows[ $id ]['result_hash']  = $args[3];
			$this->rows[ $id ]['owner_hash']   = null;
			$this->rows[ $id ]['completed_at'] = $args[4];
			$this->rows[ $id ]['retain_until'] = $args[5];
			return 1;
		}
		if ( str_starts_with( $query->template, 'UPDATE %i SET state = %s, owner_hash = NULL' ) ) {
			$id = (int) $args[3];
			if ( ! $this->matches( $id, (string) $args[4], (string) $args[5], (int) $args[6] ) ) {
				return 0;
			}
			$this->rows[ $id ]['state']      = $args[1];
			$this->rows[ $id ]['owner_hash'] = null;
			return 1;
		}
		if ( str_starts_with( $query->template, 'DELETE FROM %i WHERE id = %d' ) ) {
			$id = (int) $args[1];
			if ( ! $this->matches( $id, (string) $args[2], (string) $args[3], (int) $args[4] ) ) {
				return 0;
			}
			unset( $this->rows[ $id ] );
			return 1;
		}
		if ( str_starts_with( $query->template, 'DELETE FROM %i WHERE id IN' ) ) {
			$deleted = 0;
			foreach ( array_slice( $args, 1, -2 ) as $id ) {
				$id = (int) $id;
				if ( isset( $this->rows[ $id ] ) && 'completed' === $this->rows[ $id ]['state'] && (string) $args[ count( $args ) - 1 ] >= (string) $this->rows[ $id ]['retain_until'] ) {
					unset( $this->rows[ $id ] );
					++$deleted;
				}
			}
			return $deleted;
		}
		return false;
	}
	private function matches( int $id, string $state, string $owner, int $fence ): bool {
		return isset( $this->rows[ $id ] ) && ( $this->rows[ $id ]['state'] ?? '' ) === $state && ( $this->rows[ $id ]['owner_hash'] ?? '' ) === $owner && (int) ( $this->rows[ $id ]['fence'] ?? 0 ) === $fence;
	}
}

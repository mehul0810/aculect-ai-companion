<?php
/**
 * Tests for OAuth protocol storage maintenance.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AuthCodeRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\RefreshTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\StorageMaintenance;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused repository tests replace wpdb with a local test double.

/**
 * Verifies pruning and write-throttle behavior for OAuth storage.
 */
final class StorageMaintenanceTest extends TestCase {

	private FakeOAuthWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb                                   = new FakeOAuthWpdb();
		$GLOBALS['wpdb']                              = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array();
	}

	public function test_prunes_expired_auth_codes(): void {
		$deleted = ( new AuthCodeRepository() )->prune_expired( '2026-05-20 00:00:00' );

		self::assertSame( 3, $deleted );
		self::assertSame( 'SELECT id FROM %i WHERE expires_at < %s ORDER BY expires_at ASC, id ASC LIMIT %d', $this->wpdb->prepared[0]['query'] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_auth_codes', $this->wpdb->prepared[0]['args'][0] );
		self::assertSame( '2026-05-20 00:00:00', $this->wpdb->prepared[0]['args'][1] );
		self::assertSame( 500, $this->wpdb->prepared[0]['args'][2] );
		self::assertStringStartsWith( 'DELETE FROM %i WHERE id IN', $this->wpdb->prepared[1]['query'] );
		self::assertStringNotContainsString( 'LIMIT', $this->wpdb->prepared[1]['query'] );
	}

	public function test_prunes_expired_access_tokens(): void {
		$deleted = ( new AccessTokenRepository() )->prune_expired( '2026-05-20 00:00:00' );

		self::assertSame( 3, $deleted );
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $this->wpdb->prepared[0]['args'][0] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_refresh_tokens', $this->wpdb->prepared[0]['args'][2] );
		self::assertStringContainsString( 'expires_at < %s', $this->wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'token_hash NOT IN', $this->wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'revoked = 0', $this->wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'expires_at >= %s', $this->wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'LIMIT %d', $this->wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'token_hash NOT IN', $this->wpdb->prepared[1]['query'] );
		self::assertStringContainsString( 'revoked = 0', $this->wpdb->prepared[1]['query'] );
		self::assertStringContainsString( 'expires_at >= %s', $this->wpdb->prepared[1]['query'] );
		self::assertStringNotContainsString( 'LIMIT', $this->wpdb->prepared[1]['query'] );
	}

	public function test_prunes_expired_refresh_tokens(): void {
		$deleted = ( new RefreshTokenRepository() )->prune_expired( '2026-05-20 00:00:00' );

		self::assertSame( 3, $deleted );
		self::assertSame( 'wp_aculect_ai_companion_oauth_refresh_tokens', $this->wpdb->prepared[0]['args'][0] );
		self::assertStringContainsString( 'expires_at < %s', $this->wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'LIMIT %d', $this->wpdb->prepared[0]['query'] );
		self::assertStringStartsWith( 'DELETE FROM %i WHERE id IN', $this->wpdb->prepared[1]['query'] );
		self::assertStringNotContainsString( 'LIMIT', $this->wpdb->prepared[1]['query'] );
	}

	public function test_failed_prune_delete_is_reported(): void {
		$this->wpdb->query_result = false;

		self::assertFalse( ( new AccessTokenRepository() )->prune_expired( '2026-05-20 00:00:00' ) );
	}

	public function test_failed_prune_selection_is_reported(): void {
		$this->wpdb->get_col_result = false;

		self::assertFalse( ( new AuthCodeRepository() )->prune_expired( '2026-05-20 00:00:00' ) );
		self::assertSame( array(), $this->wpdb->queries );
		self::assertCount( 1, $this->wpdb->selections );
	}

	public function test_storage_prune_includes_revoked_clients(): void {
		$result = StorageMaintenance::prune();

		self::assertSame(
			array(
				'auth_codes'     => 3,
				'access_tokens'  => 3,
				'refresh_tokens' => 3,
				'clients'        => 3,
			),
			$result
		);
		self::assertSame( 'wp_aculect_ai_companion_oauth_clients', $this->wpdb->prepared[6]['args'][0] );
		self::assertSame( 500, $this->wpdb->prepared[6]['args'][2] );
		self::assertStringContainsString( 'revoked = 1', $this->wpdb->prepared[6]['query'] );
		self::assertStringContainsString( 'updated_at < %s', $this->wpdb->prepared[6]['query'] );
		self::assertStringContainsString( 'LIMIT %d', $this->wpdb->prepared[6]['query'] );
		self::assertStringContainsString( 'revoked = 1', $this->wpdb->prepared[7]['query'] );
		self::assertStringNotContainsString( 'LIMIT', $this->wpdb->prepared[7]['query'] );
	}

	public function test_repository_prunes_are_bounded_to_one_thousand_ids(): void {
		$this->wpdb->get_col_result = range( 1, 1200 );

		$deleted = ( new ClientRepository() )->prune_revoked_clients( '2026-05-20 00:00:00', 5000 );

		self::assertSame( 3, $deleted );
		self::assertSame( 1000, $this->wpdb->prepared[0]['args'][2] );
		self::assertCount( 1002, $this->wpdb->prepared[1]['args'] );
		self::assertSame( range( 1, 1000 ), array_slice( $this->wpdb->prepared[1]['args'], 1, 1000 ) );
	}

	public function test_maybe_prune_does_not_record_success_after_query_failure(): void {
		$this->wpdb->query_result = false;

		StorageMaintenance::maybe_prune();

		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_last_pruned_at', 'missing' ) );
		self::assertStringStartsWith(
			'outcome:failure:',
			(string) get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', '' )
		);
		self::assertGreaterThan( time(), (int) get_option( 'aculect_ai_companion_oauth_prune_failure_retry_after', 0 ) );
		self::assertCount( 4, $this->wpdb->queries );
	}

	public function test_maybe_prune_backs_off_after_failure_and_retries_after_expiry(): void {
		$this->wpdb->query_result = false;

		StorageMaintenance::maybe_prune();
		StorageMaintenance::maybe_prune();

		self::assertCount( 4, $this->wpdb->queries );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_last_pruned_at', 'missing' ) );

		update_option( 'aculect_ai_companion_oauth_prune_failure_retry_after', time() - 1, false );
		update_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', time() - 1, false );
		$this->wpdb->query_result = 3;

		StorageMaintenance::maybe_prune();

		self::assertCount( 8, $this->wpdb->queries );
		self::assertGreaterThan( 0, (int) get_option( 'aculect_ai_companion_oauth_last_pruned_at', 0 ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_prune_failure_retry_after', 'missing' ) );
		self::assertStringStartsWith(
			'outcome:success:',
			(string) get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', '' )
		);
	}

	public function test_maybe_prune_retains_lock_when_failure_backoff_cannot_be_stored(): void {
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array(
			'aculect_ai_companion_oauth_prune_failure_retry_after',
		);
		$this->wpdb->query_result                                   = false;

		StorageMaintenance::maybe_prune();
		StorageMaintenance::maybe_prune();

		self::assertCount( 4, $this->wpdb->queries );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_last_pruned_at', 'missing' ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_prune_failure_retry_after', 'missing' ) );
		self::assertGreaterThan( time(), $this->lockExpiresAt() );

		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array();
		update_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', time() - 1, false );
		$this->wpdb->query_result = 3;

		StorageMaintenance::maybe_prune();

		self::assertCount( 8, $this->wpdb->queries );
		self::assertGreaterThan( 0, (int) get_option( 'aculect_ai_companion_oauth_last_pruned_at', 0 ) );
		self::assertStringStartsWith(
			'outcome:success:',
			(string) get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', '' )
		);
	}

	public function test_maybe_prune_retains_lock_when_success_timestamp_cannot_be_stored(): void {
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array(
			'aculect_ai_companion_oauth_last_pruned_at',
		);

		StorageMaintenance::maybe_prune();
		StorageMaintenance::maybe_prune();

		self::assertCount( 4, $this->wpdb->queries );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_last_pruned_at', 'missing' ) );
		self::assertGreaterThan( time(), $this->lockExpiresAt() );

		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array();
		update_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', time() - 1, false );

		StorageMaintenance::maybe_prune();

		self::assertCount( 8, $this->wpdb->queries );
		self::assertGreaterThan( 0, (int) get_option( 'aculect_ai_companion_oauth_last_pruned_at', 0 ) );
		self::assertStringStartsWith(
			'outcome:success:',
			(string) get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', '' )
		);
	}

	public function test_stale_failure_owner_cannot_publish_or_release_reclaimed_lock(): void {
		$now          = time();
		$stale_token  = $this->acquireLock( $now - 301 );
		$active_token = $this->acquireLock( $now );

		self::assertNotSame( '', $stale_token );
		self::assertNotSame( '', $active_token );
		self::assertNotSame( $stale_token, $active_token );

		$this->finalizePrune( false, $stale_token );
		self::assertFalse( $this->deleteLock( $stale_token ) );
		StorageMaintenance::maybe_prune();

		self::assertSame( $active_token, get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', '' ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_prune_failure_retry_after', 'missing' ) );
		self::assertSame( array(), $this->wpdb->queries );
	}

	public function test_stale_success_owner_cannot_publish_or_release_reclaimed_lock(): void {
		$now          = time();
		$stale_token  = $this->acquireLock( $now - 301 );
		$active_token = $this->acquireLock( $now );

		self::assertNotSame( '', $stale_token );
		self::assertNotSame( '', $active_token );
		self::assertNotSame( $stale_token, $active_token );

		$this->finalizePrune(
			array(
				'auth_codes'     => 0,
				'access_tokens'  => 0,
				'refresh_tokens' => 0,
				'clients'        => 0,
			),
			$stale_token
		);
		self::assertFalse( $this->deleteLock( $stale_token ) );
		StorageMaintenance::maybe_prune();

		self::assertSame( $active_token, get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', '' ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_last_pruned_at', 'missing' ) );
		self::assertSame( array(), $this->wpdb->queries );
	}

	public function test_expired_finalizer_cannot_overwrite_newer_failure_with_stale_success(): void {
		$base             = time();
		$stale_worker     = $this->acquireLock( $base );
		$stale_finalizer  = $this->beginFinalization( $stale_worker, $base );
		$active_worker    = $this->acquireLock( $base + 301 );
		$active_finalizer = $this->beginFinalization( $active_worker, $base + 301 );

		$this->publishOutcome( false, $active_finalizer, $base + 301 );
		$active_outcome = (string) get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', '' );
		$active_retry   = (int) get_option( 'aculect_ai_companion_oauth_prune_failure_retry_after', 0 );

		$this->publishOutcome( $this->successfulPruneResult(), $stale_finalizer, $base + 302 );
		self::assertFalse( $this->deleteLock( $stale_finalizer ) );
		StorageMaintenance::maybe_prune();

		self::assertStringStartsWith( 'outcome:failure:', $active_outcome );
		self::assertSame( $active_outcome, get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', '' ) );
		self::assertSame( $base + 601, $active_retry );
		self::assertSame( $active_retry, (int) get_option( 'aculect_ai_companion_oauth_prune_failure_retry_after', 0 ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_last_pruned_at', 'missing' ) );
		self::assertSame( array(), $this->wpdb->queries );
	}

	public function test_expired_finalizer_cannot_overwrite_newer_success_with_stale_failure(): void {
		$base             = time();
		$stale_worker     = $this->acquireLock( $base );
		$stale_finalizer  = $this->beginFinalization( $stale_worker, $base );
		$active_worker    = $this->acquireLock( $base + 301 );
		$active_finalizer = $this->beginFinalization( $active_worker, $base + 301 );

		$this->publishOutcome( $this->successfulPruneResult(), $active_finalizer, $base + 301 );
		$active_outcome = (string) get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', '' );

		$this->publishOutcome( false, $stale_finalizer, $base + 302 );
		self::assertFalse( $this->deleteLock( $stale_finalizer ) );
		StorageMaintenance::maybe_prune();

		self::assertStringStartsWith( 'outcome:success:', $active_outcome );
		self::assertSame( $active_outcome, get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', '' ) );
		self::assertSame( $base + 301, (int) get_option( 'aculect_ai_companion_oauth_last_pruned_at', 0 ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_prune_failure_retry_after', 'missing' ) );
		self::assertSame( array(), $this->wpdb->queries );
	}

	public function test_failure_retry_deadline_uses_finalization_time(): void {
		$lock_token = $this->acquireLock( time() - 299 );

		$this->finalizePrune( false, $lock_token );

		self::assertGreaterThan(
			time() + 290,
			(int) get_option( 'aculect_ai_companion_oauth_prune_failure_retry_after', 0 )
		);
	}

	public function test_maybe_prune_records_success_after_all_stores_succeed(): void {
		StorageMaintenance::maybe_prune();

		self::assertGreaterThan( 0, (int) get_option( 'aculect_ai_companion_oauth_last_pruned_at', 0 ) );
		self::assertStringStartsWith(
			'outcome:success:',
			(string) get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', '' )
		);
	}

	public function test_last_used_updates_are_throttled(): void {
		$repository = new AccessTokenRepository();

		self::assertTrue( $this->shouldTouch( $repository, '', 1000 ) );
		self::assertFalse( $this->shouldTouch( $repository, gmdate( 'Y-m-d H:i:s', 900 ), 1000 ) );
		self::assertTrue( $this->shouldTouch( $repository, gmdate( 'Y-m-d H:i:s', 699 ), 1000 ) );
		self::assertTrue( $this->shouldTouch( $repository, 'not a date', 1000 ) );
	}

	public function test_maybe_prune_skips_when_throttled(): void {
		update_option( 'aculect_ai_companion_oauth_last_pruned_at', time(), false );

		StorageMaintenance::maybe_prune();

		self::assertSame( array(), $this->wpdb->queries );
	}

	public function test_maybe_prune_skips_when_lock_is_active(): void {
		update_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', time() + 300, false );

		StorageMaintenance::maybe_prune();

		self::assertSame( array(), $this->wpdb->queries );
	}

	public function test_delete_options_removes_oauth_prune_timestamp(): void {
		update_option( 'aculect_ai_companion_oauth_last_pruned_at', 123, false );
		update_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', time() + 300, false );
		update_option( 'aculect_ai_companion_oauth_prune_failure_retry_after', time() + 300, false );

		StorageMaintenance::delete_options();

		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_last_pruned_at', 'missing' ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', 'missing' ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_prune_failure_retry_after', 'missing' ) );
	}

	/**
	 * Invoke private touch-throttle decision logic.
	 *
	 * @param AccessTokenRepository $repository   Repository instance.
	 * @param string                $last_used_at Existing last-used timestamp.
	 * @param int                   $now          Current Unix timestamp.
	 */
	private function shouldTouch( AccessTokenRepository $repository, string $last_used_at, int $now ): bool {
		$reflection = new ReflectionMethod( $repository, 'should_touch' );

		return (bool) $reflection->invokeArgs( $repository, array( $last_used_at, $now ) );
	}

	/**
	 * Acquire a prune lock at a controlled timestamp.
	 *
	 * @param int $now Simulated acquisition timestamp.
	 */
	private function acquireLock( int $now ): string {
		$reflection = new ReflectionMethod( StorageMaintenance::class, 'acquire_prune_lock' );

		return (string) $reflection->invoke( null, $now );
	}

	/**
	 * Invoke fenced prune finalization.
	 *
	 * @param array{auth_codes: int, access_tokens: int, refresh_tokens: int, clients: int}|false $result Prune result.
	 * @param string                                                                              $lock_token Claimed lease token.
	 */
	private function finalizePrune( array|false $result, string $lock_token ): void {
		$reflection = new ReflectionMethod( StorageMaintenance::class, 'finalize_prune' );
		$reflection->invoke( null, $result, $lock_token );
	}

	/**
	 * Begin finalization at a controlled timestamp.
	 *
	 * @param string $lock_token Expected worker token.
	 * @param int    $now        Simulated finalization timestamp.
	 */
	private function beginFinalization( string $lock_token, int $now ): string {
		$reflection = new ReflectionMethod( StorageMaintenance::class, 'begin_owned_finalization' );

		return (string) $reflection->invoke( null, $lock_token, $now );
	}

	/**
	 * Publish an outcome at a controlled timestamp.
	 *
	 * @param array{auth_codes: int, access_tokens: int, refresh_tokens: int, clients: int}|false $result Prune result.
	 * @param string                                                                              $finalization_token Exact finalization token.
	 * @param int                                                                                 $now Simulated publication timestamp.
	 */
	private function publishOutcome( array|false $result, string $finalization_token, int $now ): void {
		$reflection = new ReflectionMethod( StorageMaintenance::class, 'publish_prune_outcome' );
		$reflection->invoke( null, $result, $finalization_token, $now );
	}

	/**
	 * Return a zero-deletion successful prune result.
	 *
	 * @return array{auth_codes: int, access_tokens: int, refresh_tokens: int, clients: int}
	 */
	private function successfulPruneResult(): array {
		return array(
			'auth_codes'     => 0,
			'access_tokens'  => 0,
			'refresh_tokens' => 0,
			'clients'        => 0,
		);
	}

	/**
	 * Attempt an exact-owner lock deletion.
	 *
	 * @param string $lock_token Expected owner token.
	 */
	private function deleteLock( string $lock_token ): bool {
		$reflection = new ReflectionMethod( StorageMaintenance::class, 'delete_prune_lock_if_value' );

		return (bool) $reflection->invoke( null, $lock_token );
	}

	/**
	 * Return the expiry suffix from the currently stored lock token.
	 */
	private function lockExpiresAt(): int {
		$parts = explode(
			':',
			(string) get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', '' )
		);

		return absint( end( $parts ) );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test double is intentionally local to this file.

/**
 * Minimal wpdb test double for focused repository unit tests.
 */
final class FakeOAuthWpdb {

	public string $prefix     = 'wp_';
	public string $last_error = '';

	/**
	 * Prepared SQL calls.
	 *
	 * @var array<int, array{query: string, args: array<array-key, mixed>}>
	 */
	public array $prepared = array();

	/**
	 * Raw SQL query calls.
	 *
	 * @var string[]
	 */
	public array $queries = array();

	/**
	 * Candidate selection query calls.
	 *
	 * @var string[]
	 */
	public array $selections = array();

	public int|false $query_result = 3;

	/**
	 * Candidate IDs returned by get_results().
	 *
	 * @var int[]|false
	 */
	public array|false $get_col_result = array( 11, 12, 13 );

	/**
	 * Record a prepared SQL template and arguments.
	 *
	 * @param string $query SQL query with placeholders.
	 * @param mixed  ...$args Placeholder arguments.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->prepared[] = array(
			'query' => $query,
			'args'  => $args,
		);

		return $query;
	}

	/**
	 * Return candidate ID rows for a selection query.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output format.
	 * @return array<int, array{id: int}>|false
	 */
	public function get_results( string $query, string $output ): array|false {
		unset( $output );

		$this->selections[] = $query;
		if ( false === $this->get_col_result ) {
			$this->last_error = 'simulated query failure';

			// Core wpdb returns an empty results array even when the query failed.
			return array();
		}

		return array_map(
			static fn ( int $id ): array => array( 'id' => $id ),
			$this->get_col_result
		);
	}

	/**
	 * Record a query call.
	 *
	 * @param string $query SQL query.
	 */
	public function query( string $query ): int|false {
		$this->queries[] = $query;

		return $this->query_result;
	}
}

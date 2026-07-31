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
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', 'missing' ) );
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
		$this->wpdb->query_result = 3;

		StorageMaintenance::maybe_prune();

		self::assertCount( 8, $this->wpdb->queries );
		self::assertGreaterThan( 0, (int) get_option( 'aculect_ai_companion_oauth_last_pruned_at', 0 ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_prune_failure_retry_after', 'missing' ) );
	}

	public function test_maybe_prune_records_success_after_all_stores_succeed(): void {
		StorageMaintenance::maybe_prune();

		self::assertGreaterThan( 0, (int) get_option( 'aculect_ai_companion_oauth_last_pruned_at', 0 ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', 'missing' ) );
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

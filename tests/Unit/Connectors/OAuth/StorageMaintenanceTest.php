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
use Aculect\AICompanion\Connectors\OAuth\Repositories\RefreshTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\StorageMaintenance;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies pruning and write-throttle behavior for OAuth storage.
 */
final class StorageMaintenanceTest extends TestCase {

	private FakeOAuthWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb = new FakeOAuthWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	public function test_prunes_expired_auth_codes(): void {
		$deleted = ( new AuthCodeRepository() )->prune_expired( '2026-05-20 00:00:00' );

		self::assertSame( 3, $deleted );
		self::assertSame( 'DELETE FROM %i WHERE expires_at < %s', $this->wpdb->prepared[0]['query'] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_auth_codes', $this->wpdb->prepared[0]['args'][0] );
		self::assertSame( '2026-05-20 00:00:00', $this->wpdb->prepared[0]['args'][1] );
	}

	public function test_prunes_expired_access_tokens(): void {
		$deleted = ( new AccessTokenRepository() )->prune_expired( '2026-05-20 00:00:00' );

		self::assertSame( 3, $deleted );
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $this->wpdb->prepared[0]['args'][0] );
		self::assertStringContainsString( 'expires_at < %s', $this->wpdb->prepared[0]['query'] );
	}

	public function test_prunes_expired_refresh_tokens(): void {
		$deleted = ( new RefreshTokenRepository() )->prune_expired( '2026-05-20 00:00:00' );

		self::assertSame( 3, $deleted );
		self::assertSame( 'wp_aculect_ai_companion_oauth_refresh_tokens', $this->wpdb->prepared[0]['args'][0] );
		self::assertStringContainsString( 'expires_at < %s', $this->wpdb->prepared[0]['query'] );
	}

	public function test_failed_prune_query_returns_zero_deleted_rows(): void {
		$this->wpdb->query_result = false;

		self::assertSame( 0, ( new AccessTokenRepository() )->prune_expired( '2026-05-20 00:00:00' ) );
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
		self::assertSame( 'wp_aculect_ai_companion_oauth_clients', $this->wpdb->prepared[3]['args'][0] );
		self::assertStringContainsString( 'revoked = 1', $this->wpdb->prepared[3]['query'] );
		self::assertStringContainsString( 'updated_at < %s', $this->wpdb->prepared[3]['query'] );
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

	public function test_delete_options_removes_oauth_prune_timestamp(): void {
		update_option( 'aculect_ai_companion_oauth_last_pruned_at', 123, false );

		StorageMaintenance::delete_options();

		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_last_pruned_at', 'missing' ) );
	}

	/**
	 * Invoke private touch-throttle decision logic.
	 */
	private function shouldTouch( AccessTokenRepository $repository, string $last_used_at, int $now ): bool {
		$reflection = new ReflectionMethod( $repository, 'should_touch' );

		return (bool) $reflection->invokeArgs( $repository, array( $last_used_at, $now ) );
	}
}

/**
 * Minimal wpdb test double for focused repository unit tests.
 */
final class FakeOAuthWpdb {

	public string $prefix = 'wp_';

	/**
	 * @var array<int, array{query: string, args: array<int, mixed>}>
	 */
	public array $prepared = array();

	/**
	 * @var string[]
	 */
	public array $queries = array();

	public int|false $query_result = 3;

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
	 * Record a query call.
	 *
	 * @param string $query SQL query.
	 */
	public function query( string $query ): int|false {
		$this->queries[] = $query;

		return $this->query_result;
	}
}

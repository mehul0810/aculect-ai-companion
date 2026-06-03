<?php
/**
 * Full uninstall cleanup tests.
 *
 * @package Aculect\AICompanion\Tests\Unit
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit;

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused uninstall tests replace wpdb with a local test double.

/**
 * Verifies uninstall.php removes all opt-in plugin data.
 */
final class UninstallTest extends TestCase {

	private FakeUninstallWpdb $wpdb;

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb          = new FakeUninstallWpdb();

		$GLOBALS['wpdb']                              = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_options'] = array(
			'aculect_ai_companion_remove_data_on_uninstall' => '1',
			'aculect_ai_companion_brand_profile'        => array( 'site_name' => 'Delete Me' ),
			'aculect_ai_companion_learning_suggestions' => array( array( 'id' => 'learn_test' ) ),
			'aculect_ai_companion_role_abilities'       => array( 'editor' => array( 'content.get_item' ) ),
			'aculect_ai_companion_paused_user_access'   => array( 7 ),
			'aculect_ai_companion_oauth_last_pruned_at' => 123,
			'aculect_ai_companion_oauth_prune_lock_expires_at' => 456,
			'aculect_ai_companion_logging_enabled'      => '1',
			'aculect_ai_companion_log_retention_days'   => 90,
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

	public function test_uninstall_file_removes_added_cleanup_options(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		self::assertSame( 'missing', get_option( 'aculect_ai_companion_brand_profile', 'missing' ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_learning_suggestions', 'missing' ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_role_abilities', 'missing' ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_paused_user_access', 'missing' ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_last_pruned_at', 'missing' ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_oauth_prune_lock_expires_at', 'missing' ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_remove_data_on_uninstall', 'missing' ) );
		self::assertTrue( $this->wpdb->has_query_fragment( 'wp_aculect_ai_companion_oauth_clients' ) );
		self::assertTrue( $this->wpdb->has_query_fragment( 'wp_aculect_ai_companion_logs' ) );
		self::assertTrue( $this->wpdb->has_query_fragment( 'wp_aculect_ai_companion_activity' ) );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test double is intentionally local to this file.

/**
 * Minimal wpdb test double for uninstall table drops.
 */
final class FakeUninstallWpdb {

	public string $prefix = 'wp_';

	/**
	 * Recorded SQL queries.
	 *
	 * @var string[]
	 */
	public array $queries = array();

	/**
	 * Record a prepared SQL template.
	 *
	 * @param string $query SQL query.
	 * @param mixed  ...$args Placeholder args.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->queries[] = str_replace( '%i', (string) ( $args[0] ?? '' ), $query );

		return $this->queries[ array_key_last( $this->queries ) ];
	}

	/**
	 * Record a query call.
	 *
	 * @param string $query SQL query.
	 */
	public function query( string $query ): int {
		$this->queries[] = $query;

		return 1;
	}

	/**
	 * Check whether any query contains a fragment.
	 *
	 * @param string $fragment Query fragment.
	 */
	public function has_query_fragment( string $fragment ): bool {
		foreach ( $this->queries as $query ) {
			if ( str_contains( $query, $fragment ) ) {
				return true;
			}
		}

		return false;
	}
}

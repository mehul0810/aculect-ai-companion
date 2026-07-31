<?php
/**
 * Real-SQLite persistence regressions for Dynamic Client Registration retries.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationResult;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use PDO;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exercises repository SQL against an isolated in-memory database.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused repository test replaces wpdb with an isolated adapter.

/**
 * Verifies repeated successful DCR requests replace unused duplicate rows.
 */
final class OAuthDcrSqlitePersistenceTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			self::markTestSkipped( 'pdo_sqlite is required for the real-database DCR persistence test.' );
		}

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new OAuthDcrSqliteWpdb();
	}

	protected function tearDown(): void {
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}

		parent::tearDown();
	}

	public function test_identical_public_client_retries_do_not_grow_total_rows(): void {
		$repository = new ClientRepository();

		for ( $attempt = 0; $attempt < 20; ++$attempt ) {
			$result = $repository->create_client_result(
				'Repeated MCP Client',
				array( 'http://localhost/oauth/callback' ),
				false
			);

			self::assertSame( ClientRegistrationResult::CREATED, $result->status() );
			self::assertNotNull( $result->client() );
		}

		/**
		 * SQLite adapter used by this test.
		 *
		 * @var OAuthDcrSqliteWpdb $wpdb
		 */
		$wpdb = $GLOBALS['wpdb'];

		self::assertSame( 1, $wpdb->scalar( 'SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients' ) );
		self::assertSame( 1, $wpdb->scalar( 'SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients WHERE revoked = 0' ) );
	}
}

/**
 * Minimal wpdb-compatible adapter backed by a real in-memory SQLite database.
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- This real-database test adapter is intentionally local to its test.
final class OAuthDcrSqliteWpdb {

	public string $prefix = 'wp_';

	private PDO $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		$this->pdo->exec(
			'CREATE TABLE wp_aculect_ai_companion_oauth_clients (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				client_id TEXT NOT NULL UNIQUE,
				client_secret_hash TEXT NULL,
				client_name TEXT NOT NULL,
				provider TEXT NOT NULL,
				redirect_uris TEXT NOT NULL,
				registration_fingerprint TEXT NOT NULL DEFAULT \'\',
				user_id INTEGER NULL,
				is_confidential INTEGER NOT NULL DEFAULT 1,
				revoked INTEGER NOT NULL DEFAULT 0,
				created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE wp_aculect_ai_companion_oauth_access_tokens (
				token_hash TEXT NOT NULL,
				client_id TEXT NOT NULL,
				revoked INTEGER NOT NULL DEFAULT 0,
				expires_at TEXT NOT NULL
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE wp_aculect_ai_companion_oauth_auth_codes (
				token_hash TEXT NOT NULL,
				client_id TEXT NOT NULL,
				revoked INTEGER NOT NULL DEFAULT 0,
				expires_at TEXT NOT NULL
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE wp_aculect_ai_companion_oauth_refresh_tokens (
				token_hash TEXT NOT NULL,
				access_token_hash TEXT NOT NULL,
				revoked INTEGER NOT NULL DEFAULT 0,
				expires_at TEXT NOT NULL
			)'
		);
	}

	public function prepare( string $query, mixed ...$args ): string {
		$position = 0;
		$prepared = preg_replace_callback(
			'/%[isd]/',
			function ( array $match ) use ( $args, &$position ): string {
				$value = $args[ $position ] ?? null;
				++$position;

				if ( '%i' === $match[0] ) {
					$identifier = (string) $value;
					if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $identifier ) ) {
						throw new \InvalidArgumentException( 'Unsafe SQL identifier.' );
					}

					return '"' . $identifier . '"';
				}

				return '%d' === $match[0]
					? (string) (int) $value
					: $this->pdo->quote( (string) $value );
			},
			$query
		);

		if ( ! is_string( $prepared ) || count( $args ) !== $position ) {
			throw new \RuntimeException( 'Could not prepare SQLite test query.' );
		}

		return $prepared;
	}

	public function query( string $query ): int|false {
		return $this->pdo->exec( $query );
	}

	public function get_var( string $query ): int {
		return (int) $this->pdo->query( $query )->fetchColumn();
	}

	/**
	 * Insert one row into the isolated SQLite database.
	 *
	 * @param string               $table   Table name.
	 * @param array<string, mixed> $data Row values.
	 * @param string[]             $formats Ignored wpdb formats.
	 */
	public function insert( string $table, array $data, array $formats ): int|false {
		unset( $formats );

		$columns      = array_keys( $data );
		$placeholders = array_map( static fn( string $column ): string => ':' . $column, $columns );
		$sql          = sprintf(
			'INSERT INTO "%s" ("%s") VALUES (%s)',
			$table,
			implode( '", "', $columns ),
			implode( ', ', $placeholders )
		);
		$statement    = $this->pdo->prepare( $sql );

		return $statement->execute( $data ) ? $statement->rowCount() : false;
	}

	public function scalar( string $query ): int {
		return (int) $this->pdo->query( $query )->fetchColumn();
	}
}

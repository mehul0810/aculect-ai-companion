<?php
/**
 * Real-SQLite persistence regressions for Dynamic Client Registration retries.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationFingerprint;
use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationResult;
use Aculect\AICompanion\Connectors\OAuth\Database\Installer;
use Aculect\AICompanion\Connectors\OAuth\IssuerBinding;
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
		delete_option( 'aculect_ai_companion_oauth_issuer_backfill' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['aculect_ai_companion_test_wp_hash_password_calls'] );

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

	public function test_capacity_full_does_not_remove_unrelated_dormant_clients_or_hash_secret(): void {
		$wpdb = $this->wpdb();

		for ( $index = 0; $index < 100; ++$index ) {
			$this->seed_client(
				$wpdb,
				'unrelated-' . $index,
				hash( 'sha256', 'unrelated-' . $index )
			);
		}

		$GLOBALS['aculect_ai_companion_test_wp_hash_password_calls'] = 0;

		$result = ( new ClientRepository() )->create_client_result(
			'Capacity MCP Client',
			array( 'http://localhost/capacity/callback' )
		);

		self::assertSame( ClientRegistrationResult::CAPACITY_EXCEEDED, $result->status() );
		self::assertNull( $result->client() );
		self::assertSame( 100, $wpdb->scalar( 'SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients' ) );
		self::assertSame( 100, $wpdb->scalar( 'SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients WHERE revoked = 0' ) );
		self::assertSame( 0, $GLOBALS['aculect_ai_companion_test_wp_hash_password_calls'] );
	}

	public function test_capacity_retry_replaces_only_its_same_fingerprint_unused_client(): void {
		$wpdb         = $this->wpdb();
		$redirect_uri = 'http://localhost/retry/callback';
		$fingerprint  = ClientRegistrationFingerprint::from_redirect_uris( array( $redirect_uri ) );

		self::assertNotNull( $fingerprint );

		for ( $index = 0; $index < 99; ++$index ) {
			$this->seed_client(
				$wpdb,
				'unrelated-' . $index,
				hash( 'sha256', 'unrelated-' . $index )
			);
		}
		$this->seed_client( $wpdb, 'same-fingerprint-unused', $fingerprint );

		$result = ( new ClientRepository() )->create_client_result(
			'Retry MCP Client',
			array( $redirect_uri ),
			false
		);

		self::assertSame( ClientRegistrationResult::CREATED, $result->status() );
		self::assertNotNull( $result->client() );
		self::assertSame( 100, $wpdb->scalar( 'SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients' ) );
		self::assertSame(
			1,
			$wpdb->prepared_scalar(
				'SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients WHERE registration_fingerprint = %s',
				$fingerprint
			)
		);
		self::assertSame(
			0,
			$wpdb->prepared_scalar(
				'SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients WHERE client_id = %s',
				'same-fingerprint-unused'
			)
		);
		self::assertSame(
			99,
			$wpdb->scalar( "SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients WHERE client_id LIKE 'unrelated-%'" )
		);
	}

	public function test_same_fingerprint_cleanup_preserves_live_access_code_and_refresh_clients(): void {
		$wpdb         = $this->wpdb();
		$redirect_uri = 'http://localhost/live/callback';
		$fingerprint  = ClientRegistrationFingerprint::from_redirect_uris( array( $redirect_uri ) );

		self::assertNotNull( $fingerprint );

		foreach ( array( 'live-access', 'live-code', 'live-refresh', 'unused' ) as $client_id ) {
			$this->seed_client( $wpdb, $client_id, $fingerprint );
		}

		$future = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$past   = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$wpdb->insert(
			'wp_aculect_ai_companion_oauth_access_tokens',
			array(
				'token_hash' => 'access-live',
				'client_id'  => 'live-access',
				'revoked'    => 0,
				'expires_at' => $future,
			),
			array()
		);
		$wpdb->insert(
			'wp_aculect_ai_companion_oauth_auth_codes',
			array(
				'token_hash' => 'code-live',
				'client_id'  => 'live-code',
				'revoked'    => 0,
				'expires_at' => $future,
			),
			array()
		);
		$wpdb->insert(
			'wp_aculect_ai_companion_oauth_access_tokens',
			array(
				'token_hash' => 'access-for-refresh',
				'client_id'  => 'live-refresh',
				'revoked'    => 1,
				'expires_at' => $past,
			),
			array()
		);
		$wpdb->insert(
			'wp_aculect_ai_companion_oauth_refresh_tokens',
			array(
				'token_hash'        => 'refresh-live',
				'access_token_hash' => 'access-for-refresh',
				'revoked'           => 0,
				'expires_at'        => $future,
			),
			array()
		);

		$result = ( new ClientRepository() )->create_client_result(
			'Live MCP Client',
			array( $redirect_uri ),
			false
		);

		self::assertSame( ClientRegistrationResult::CREATED, $result->status() );
		self::assertSame(
			4,
			$wpdb->prepared_scalar(
				'SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients WHERE registration_fingerprint = %s',
				$fingerprint
			)
		);
		foreach ( array( 'live-access', 'live-code', 'live-refresh' ) as $client_id ) {
			self::assertSame(
				1,
				$wpdb->prepared_scalar(
					'SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients WHERE client_id = %s',
					$client_id
				)
			);
		}
		self::assertSame(
			0,
			$wpdb->prepared_scalar(
				'SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients WHERE client_id = %s',
				'unused'
			)
		);
	}

	public function test_real_sqlite_issuer_backfill_resumes_after_bounded_first_batch(): void {
		$wpdb = $this->wpdb();
		for ( $index = 0; $index < 101; ++$index ) {
			$this->seed_client( $wpdb, 'legacy-' . $index, hash( 'sha256', 'legacy-' . $index ) );
		}
		$wpdb->query( "UPDATE wp_aculect_ai_companion_oauth_clients SET issuer_hash = ''" );

		$method = new \ReflectionMethod( Installer::class, 'backfill_client_issuer_bindings' );
		$method->invoke( null );

		self::assertSame( 1, $wpdb->scalar( "SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients WHERE issuer_hash = ''" ) );
		self::assertSame( '', get_option( 'aculect_ai_companion_oauth_issuer_backfill', '' ) );

		$method->invoke( null );

		self::assertSame( 0, $wpdb->scalar( "SELECT COUNT(*) FROM wp_aculect_ai_companion_oauth_clients WHERE issuer_hash = ''" ) );
		self::assertSame( IssuerBinding::hash(), get_option( 'aculect_ai_companion_oauth_issuer_backfill', '' ) );
	}

	private function wpdb(): OAuthDcrSqliteWpdb {
		/**
		 * SQLite adapter used by this test.
		 *
		 * @var OAuthDcrSqliteWpdb $wpdb
		 */
		$wpdb = $GLOBALS['wpdb'];

		return $wpdb;
	}

	private function seed_client( OAuthDcrSqliteWpdb $wpdb, string $client_id, string $fingerprint ): void {
		$result = $wpdb->insert(
			'wp_aculect_ai_companion_oauth_clients',
			array(
				'client_id'                => $client_id,
				'client_secret_hash'       => null,
				'client_name'              => 'Dormant MCP Client',
				'provider'                 => 'mcp',
				'redirect_uris'            => '["http:\/\/localhost\/callback"]',
				'registration_fingerprint' => $fingerprint,
				'issuer_hash'              => hash( 'sha256', 'https://example.com' ),
				'application_type'         => 'legacy',
				'user_id'                  => null,
				'is_confidential'          => 0,
				'revoked'                  => 0,
				'created_at'               => '2020-01-01 00:00:00',
				'updated_at'               => '2020-01-01 00:00:00',
			),
			array()
		);

		self::assertSame( 1, $result );
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
				issuer_hash TEXT NOT NULL DEFAULT \'\',
				application_type TEXT NOT NULL DEFAULT \'legacy\',
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

	public function get_var( string $query ): int|string|null {
		$value = $this->pdo->query( $query )->fetchColumn();

		return false === $value ? null : $value;
	}

	/**
	 * Return associative rows from the isolated SQLite database.
	 *
	 * @param string $query  Prepared SQL query.
	 * @param string $output Requested output shape.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );

		return $this->pdo->query( $query )->fetchAll( PDO::FETCH_ASSOC );
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

	/**
	 * Update matching rows in the isolated SQLite database.
	 *
	 * @param string               $table        Table name.
	 * @param array<string, mixed> $data         Updated values.
	 * @param array<string, mixed> $where        Equality predicates.
	 * @param string[]             $formats      Ignored wpdb formats.
	 * @param string[]             $where_format Ignored wpdb formats.
	 */
	public function update( string $table, array $data, array $where, array $formats, array $where_format ): int|false {
		unset( $formats, $where_format );

		$set   = array_map( static fn( string $column ): string => '"' . $column . '" = :set_' . $column, array_keys( $data ) );
		$match = array_map( static fn( string $column ): string => '"' . $column . '" = :where_' . $column, array_keys( $where ) );
		$sql   = sprintf( 'UPDATE "%s" SET %s WHERE %s', $table, implode( ', ', $set ), implode( ' AND ', $match ) );
		$args  = array();
		foreach ( $data as $column => $value ) {
			$args[ 'set_' . $column ] = $value;
		}
		foreach ( $where as $column => $value ) {
			$args[ 'where_' . $column ] = $value;
		}

		$statement = $this->pdo->prepare( $sql );

		return $statement->execute( $args ) ? $statement->rowCount() : false;
	}

	public function scalar( string $query ): int {
		return (int) $this->pdo->query( $query )->fetchColumn();
	}

	public function prepared_scalar( string $query, mixed ...$args ): int {
		return $this->scalar( $this->prepare( $query, ...$args ) );
	}
}

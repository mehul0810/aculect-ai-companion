<?php
/**
 * Validate OAuth issuer migration behavior against a real MySQL-compatible engine.
 *
 * @package Aculect\AICompanion\Tests\Integration
 */

declare(strict_types=1);

use Aculect\AICompanion\Connectors\OAuth\Database\Installer;
use Aculect\AICompanion\Connectors\OAuth\IssuerBinding;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AuthCodeRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\RefreshTokenRepository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Isolated CI database proves plugin-owned schema behavior.
// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- The proof intentionally executes against real MySQL-compatible engines.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The integration harness installs its real database adapter as wpdb.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- The single-file harness keeps its private adapter beside the executable proof.

require dirname( __DIR__ ) . '/bootstrap.php';

/**
 * Fail the engine proof when an invariant is not satisfied.
 *
 * @param bool   $condition Asserted condition.
 * @param string $message   Public-safe failure message.
 * @throws RuntimeException When the condition is false.
 */
function aculect_oauth_engine_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( esc_html( $message ) );
	}
}

/**
 * Run the OAuth issuer schema, migration, and credential-binding proof.
 *
 * @throws RuntimeException When configuration or an assertion is invalid.
 */
function aculect_oauth_engine_main(): void {
	$dsn      = (string) getenv( 'ACULECT_TEST_DB_DSN' );
	$user     = (string) getenv( 'ACULECT_TEST_DB_USER' );
	$password = (string) getenv( 'ACULECT_TEST_DB_PASSWORD' );
	if ( '' === $dsn || '' === $user ) {
		throw new RuntimeException( 'Database test connection is not configured.' );
	}

	$pdo = new PDO(
		$dsn,
		$user,
		$password,
		array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		)
	);
	$pdo->exec( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" );

	$wpdb            = new AculectOAuthEngineWpdb( $pdo );
	$GLOBALS['wpdb'] = $wpdb;
	$GLOBALS['aculect_ai_companion_test_options'] = array();

	$tables = Installer::table_names();
	foreach ( array_merge( array_values( $tables ), array( 'wp_aculect_ai_companion_oauth_clients_fresh' ) ) as $table ) {
		$pdo->exec( 'DROP TABLE IF EXISTS `' . $table . '`' );
	}

	$schema_method = new ReflectionMethod( Installer::class, 'clients_table_sql' );
	$fresh_schema  = (string) $schema_method->invoke(
		null,
		'wp_aculect_ai_companion_oauth_clients_fresh',
		'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
	);
	$pdo->exec( $fresh_schema );
	aculect_oauth_engine_assert( 1 === substr_count( $fresh_schema, 'issuer_hash char(64)' ), 'Fresh schema must place issuer_hash only on clients.' );
	aculect_oauth_engine_assert( 1 === substr_count( $fresh_schema, 'application_type varchar(16)' ), 'Fresh schema must place application_type only on clients.' );
	aculect_oauth_engine_assert(
		1 === aculect_oauth_engine_scalar(
			$pdo,
			"SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'wp_aculect_ai_companion_oauth_clients_fresh' AND index_name = 'issuer_revoked'"
		),
		'Fresh schema must include issuer_revoked.'
	);
	$issuer_index_columns = aculect_oauth_engine_query(
		$pdo,
		"SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'wp_aculect_ai_companion_oauth_clients_fresh' AND index_name = 'issuer_revoked'"
	)->fetchColumn();
	aculect_oauth_engine_assert( 'issuer_hash,revoked' === $issuer_index_columns, 'Fresh issuer_revoked index must preserve exact column order.' );

	$pdo->exec(
		"CREATE TABLE `{$tables['clients']}` (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id varchar(100) NOT NULL,
			client_secret_hash varchar(255) DEFAULT NULL,
			client_name varchar(255) NOT NULL,
			provider varchar(40) NOT NULL DEFAULT 'mcp',
			redirect_uris longtext NOT NULL,
			registration_fingerprint char(64) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned DEFAULT NULL,
			is_confidential tinyint(1) NOT NULL DEFAULT 1,
			revoked tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id), UNIQUE KEY client_id (client_id)
		) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
	);
	$seed = $pdo->prepare(
		"INSERT INTO `{$tables['clients']}`
			(client_id, client_secret_hash, client_name, redirect_uris, registration_fingerprint)
			VALUES (:client_id, :secret_hash, 'Legacy client', '[\"https://client.example/callback\"]', :fingerprint)"
	);
	for ( $index = 0; $index < 101; ++$index ) {
		$seed->execute(
			array(
				'client_id'   => 'legacy-' . $index,
				'secret_hash' => 'preserved-secret-hash-' . $index,
				'fingerprint' => hash( 'sha256', 'legacy-' . $index ),
			)
		);
	}

	$preserved = aculect_oauth_engine_query( $pdo, "SELECT client_id, client_secret_hash, redirect_uris FROM `{$tables['clients']}` WHERE client_id = 'legacy-0'" )->fetch();
	$pdo->exec(
		"ALTER TABLE `{$tables['clients']}`
			ADD COLUMN issuer_hash char(64) NOT NULL DEFAULT '' AFTER registration_fingerprint,
			ADD COLUMN application_type varchar(16) NOT NULL DEFAULT 'legacy' AFTER issuer_hash,
			ADD INDEX issuer_revoked (issuer_hash, revoked)"
	);
	$after_migration = aculect_oauth_engine_query( $pdo, "SELECT client_id, client_secret_hash, redirect_uris, application_type FROM `{$tables['clients']}` WHERE client_id = 'legacy-0'" )->fetch();
	aculect_oauth_engine_assert( is_array( $preserved ) && is_array( $after_migration ), 'Migration fixture must remain readable.' );
	aculect_oauth_engine_assert( $preserved['client_id'] === $after_migration['client_id'], 'Migration changed client_id.' );
	aculect_oauth_engine_assert( $preserved['client_secret_hash'] === $after_migration['client_secret_hash'], 'Migration changed client_secret_hash.' );
	aculect_oauth_engine_assert( $preserved['redirect_uris'] === $after_migration['redirect_uris'], 'Migration changed redirect_uris.' );
	aculect_oauth_engine_assert( 'legacy' === $after_migration['application_type'], 'Migration must default existing clients to legacy.' );

	$backfill               = new ReflectionMethod( Installer::class, 'backfill_client_issuer_bindings' );
	$wpdb->fail_next_update = true;
	$backfill->invoke( null );
	aculect_oauth_engine_assert( 2 === aculect_oauth_engine_scalar( $pdo, "SELECT COUNT(*) FROM `{$tables['clients']}` WHERE issuer_hash = ''" ), 'First backfill pass must remain bounded and preserve a failed row.' );
	aculect_oauth_engine_assert( '' === (string) get_option( 'aculect_ai_companion_oauth_issuer_backfill', '' ), 'Failed first pass must not mark completion.' );
	$backfill->invoke( null );
	aculect_oauth_engine_assert( 0 === aculect_oauth_engine_scalar( $pdo, "SELECT COUNT(*) FROM `{$tables['clients']}` WHERE issuer_hash = ''" ), 'Second backfill pass must resume remaining rows.' );
	aculect_oauth_engine_assert( IssuerBinding::hash() === (string) get_option( 'aculect_ai_companion_oauth_issuer_backfill', '' ), 'Verified completion marker must match the canonical issuer.' );

	aculect_oauth_engine_create_child_tables( $pdo, $tables );
	$future = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
	$pdo->prepare( "INSERT INTO `{$tables['auth_codes']}` (code_hash, client_id, revoked, expires_at) VALUES (?, 'legacy-0', 0, ?)" )->execute( array( hash( 'sha256', 'code-id' ), $future ) );
	$pdo->prepare( "INSERT INTO `{$tables['access_tokens']}` (token_hash, client_id, revoked, expires_at) VALUES (?, 'legacy-0', 0, ?)" )->execute( array( hash( 'sha256', 'access-id' ), $future ) );
	$pdo->prepare( "INSERT INTO `{$tables['refresh_tokens']}` (token_hash, access_token_hash, revoked, expires_at) VALUES (?, ?, 0, ?)" )->execute( array( hash( 'sha256', 'refresh-id' ), hash( 'sha256', 'access-id' ), $future ) );

	$credentials = array(
		array( new AuthCodeRepository(), 'isAuthCodeRevoked', 'code-id' ),
		array( new AccessTokenRepository(), 'isAccessTokenRevoked', 'access-id' ),
		array( new RefreshTokenRepository(), 'isRefreshTokenRevoked', 'refresh-id' ),
	);
	aculect_oauth_engine_assert_credentials( $credentials, false, 'Current issuer credentials must validate.' );
	$pdo->exec( "UPDATE `{$tables['clients']}` SET issuer_hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' WHERE client_id = 'legacy-0'" );
	aculect_oauth_engine_assert_credentials( $credentials, true, 'Cross-issuer credentials must fail closed.' );
	$pdo->prepare( "UPDATE `{$tables['clients']}` SET issuer_hash = ?, revoked = 1 WHERE client_id = 'legacy-0'" )->execute( array( IssuerBinding::hash() ) );
	aculect_oauth_engine_assert_credentials( $credentials, true, 'Revoked-client credentials must fail closed.' );
	$pdo->exec( "DELETE FROM `{$tables['clients']}` WHERE client_id = 'legacy-0'" );
	aculect_oauth_engine_assert_credentials( $credentials, true, 'Pruned-client credentials must fail closed.' );

	echo "OAuth issuer engine proof passed.\n";
}

/**
 * Create the child credential tables needed by the authoritative-join proof.
 *
 * @param PDO                   $pdo    Database connection.
 * @param array<string, string> $tables Plugin-owned table names.
 */
function aculect_oauth_engine_create_child_tables( PDO $pdo, array $tables ): void {
	$pdo->exec( "CREATE TABLE `{$tables['auth_codes']}` (id bigint unsigned NOT NULL AUTO_INCREMENT, code_hash char(64) NOT NULL, client_id varchar(100) NOT NULL, revoked tinyint NOT NULL DEFAULT 0, expires_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY code_hash (code_hash))" );
	$pdo->exec( "CREATE TABLE `{$tables['access_tokens']}` (id bigint unsigned NOT NULL AUTO_INCREMENT, token_hash char(64) NOT NULL, client_id varchar(100) NOT NULL, revoked tinyint NOT NULL DEFAULT 0, expires_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY token_hash (token_hash))" );
	$pdo->exec( "CREATE TABLE `{$tables['refresh_tokens']}` (id bigint unsigned NOT NULL AUTO_INCREMENT, token_hash char(64) NOT NULL, access_token_hash char(64) NOT NULL, revoked tinyint NOT NULL DEFAULT 0, expires_at datetime NOT NULL, PRIMARY KEY (id), UNIQUE KEY token_hash (token_hash))" );
}

/**
 * Assert a shared expected revocation result for each credential repository.
 *
 * @param array<int, array{0: object, 1: string, 2: string}> $credentials Credential fixtures.
 * @param bool                                               $expected    Expected revoked state.
 * @param string                                             $message     Failure message.
 */
function aculect_oauth_engine_assert_credentials( array $credentials, bool $expected, string $message ): void {
	foreach ( $credentials as $fixture ) {
		$result = $fixture[0]->{$fixture[1]}( $fixture[2] );
		aculect_oauth_engine_assert( $expected === $result, $message );
	}
}

/**
 * Return the first column from a database query as an integer.
 *
 * @param PDO    $pdo   Database connection.
 * @param string $query SQL query.
 */
function aculect_oauth_engine_scalar( PDO $pdo, string $query ): int {
	return (int) aculect_oauth_engine_query( $pdo, $query )->fetchColumn();
}

/**
 * Execute a query and fail closed if PDO does not return a statement.
 *
 * @param PDO    $pdo   Database connection.
 * @param string $query SQL query.
 * @return PDOStatement Prepared result statement.
 * @throws RuntimeException When the query cannot be executed.
 */
function aculect_oauth_engine_query( PDO $pdo, string $query ): PDOStatement {
	$statement = $pdo->query( $query );
	if ( false === $statement ) {
		throw new RuntimeException( 'Engine test query could not be executed.' );
	}

	return $statement;
}

/**
 * Minimal wpdb-compatible adapter backed by the selected CI database engine.
 */
final class AculectOAuthEngineWpdb {

	public string $prefix = 'wp_';

	public bool $fail_next_update = false;

	public function __construct( private PDO $pdo ) {}

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
						throw new InvalidArgumentException( 'Unsafe SQL identifier.' );
					}

					return '`' . $identifier . '`';
				}

				return '%d' === $match[0] ? (string) (int) $value : $this->pdo->quote( (string) $value );
			},
			$query
		);
		if ( ! is_string( $prepared ) || count( $args ) !== $position ) {
			throw new RuntimeException( 'Could not prepare engine test query.' );
		}

		return $prepared;
	}

	/**
	 * Fetch result rows.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Ignored wpdb output mode.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );

		return $this->query( $query )->fetchAll();
	}

	/**
	 * Fetch one result row.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Ignored wpdb output mode.
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, string $output ): ?array {
		unset( $output );
		$row = $this->query( $query )->fetch();

		return is_array( $row ) ? $row : null;
	}

	public function get_var( string $query ): int|string|null {
		$value = $this->query( $query )->fetchColumn();

		return false === $value ? null : $value;
	}

	/**
	 * Update matching rows, optionally failing one write to prove resumability.
	 *
	 * @param string               $table        Table name.
	 * @param array<string, mixed> $data         Updated values.
	 * @param array<string, mixed> $where        Equality predicates.
	 * @param string[]             $formats      Ignored wpdb formats.
	 * @param string[]             $where_format Ignored wpdb formats.
	 */
	public function update( string $table, array $data, array $where, array $formats, array $where_format ): int|false {
		unset( $formats, $where_format );
		if ( $this->fail_next_update ) {
			$this->fail_next_update = false;
			return false;
		}

		$set   = array_map( static fn( string $column ): string => '`' . $column . '` = :set_' . $column, array_keys( $data ) );
		$match = array_map( static fn( string $column ): string => '`' . $column . '` = :where_' . $column, array_keys( $where ) );
		$sql   = sprintf( 'UPDATE `%s` SET %s WHERE %s', $table, implode( ', ', $set ), implode( ' AND ', $match ) );
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

	/**
	 * Execute a database query and return its statement.
	 *
	 * @param string $query SQL query.
	 * @return PDOStatement Result statement.
	 * @throws RuntimeException When the query cannot be executed.
	 */
	private function query( string $query ): PDOStatement {
		$statement = $this->pdo->query( $query );
		if ( false === $statement ) {
			throw new RuntimeException( 'Adapter query could not be executed.' );
		}

		return $statement;
	}
}

try {
	aculect_oauth_engine_main();
} catch ( Throwable $throwable ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CI failure must reach stderr before exiting.
	fwrite( STDERR, 'OAuth issuer engine proof failed: ' . $throwable->getMessage() . "\n" );
	exit( 1 );
}

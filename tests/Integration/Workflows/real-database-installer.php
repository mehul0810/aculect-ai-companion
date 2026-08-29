<?php
/**
 * Source-external workflow run storage proof against a real MySQL-compatible engine.
 *
 * @package Aculect\AICompanion\Tests\Integration\Workflows
 */

declare(strict_types=1);

use Aculect\AICompanion\Workflows\Database\RunInstaller;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- Self-contained integration harness.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- A disposable real database is the subject under proof.
// phpcs:disable WordPress.DB.RestrictedClasses.mysql__mysqli, WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- A real MySQL-compatible engine is the subject under proof.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only bounded proof output.

define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

require ABSPATH . 'vendor/autoload.php';

/**
 * Process-local option state used by the installer lease and schema version.
 *
 * @var array<string,mixed> $aculect_workflow_options
 */
$aculect_workflow_options = array();

/**
 * Read one process-local option.
 *
 * @param string $option  Option name.
 * @param mixed  $default Fallback value.
 */
function get_option( string $option, mixed $default = false ): mixed {
	global $aculect_workflow_options;

	return $aculect_workflow_options[ $option ] ?? $default;
}

/**
 * Write one process-local option.
 *
 * @param string $option Option name.
 * @param mixed  $value  Option value.
 * @param mixed  $autoload Ignored compatibility flag.
 */
function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
	global $aculect_workflow_options;
	unset( $autoload );
	$aculect_workflow_options[ $option ] = $value;

	return true;
}

/**
 * Add one process-local option only when it is absent.
 *
 * @param string $option     Option name.
 * @param mixed  $value      Option value.
 * @param mixed  $deprecated Ignored compatibility argument.
 * @param mixed  $autoload   Ignored compatibility flag.
 */
function add_option( string $option, mixed $value = '', mixed $deprecated = '', mixed $autoload = null ): bool {
	global $aculect_workflow_options;
	unset( $deprecated, $autoload );
	if ( array_key_exists( $option, $aculect_workflow_options ) ) {
		return false;
	}
	$aculect_workflow_options[ $option ] = $value;

	return true;
}

/**
 * Delete one process-local option.
 *
 * @param string $option Option name.
 */
function delete_option( string $option ): bool {
	global $aculect_workflow_options;
	unset( $aculect_workflow_options[ $option ] );

	return true;
}

/**
 * Implement the narrow dbDelta behavior needed by RunInstaller.
 *
 * @param string $sql Installer schema.
 * @return list<string> Bounded result messages.
 */
function dbDelta( string $sql ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WordPress API compatibility proof.
	global $wpdb;
	$statements = preg_split( '/;\s*(?=CREATE TABLE)/i', trim( $sql ) );
	if ( ! is_array( $statements ) ) {
		return array();
	}
	foreach ( $statements as $statement ) {
		if ( '' === trim( $statement ) || false === $wpdb->query( $statement ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Exact production installer DDL is the subject under proof.
			return array();
		}
	}

	return array( 'workflow run tables created' );
}

/**
 * Narrow wpdb-compatible adapter backed by the matrix service.
 */
final class AculectWorkflowRealWpdb {

	public string $prefix     = 'wp_';
	public string $options    = 'wp_options';
	public string $last_error = '';
	public int $insert_id     = 0;
	public bool $is_mysql     = true;

	private mysqli $connection;

	public function __construct() {
		mysqli_report( MYSQLI_REPORT_OFF );
		$this->connection = new mysqli(
			(string) getenv( 'ACULECT_WORKFLOW_DB_HOST' ),
			(string) getenv( 'ACULECT_WORKFLOW_DB_USER' ),
			(string) getenv( 'ACULECT_WORKFLOW_DB_PASSWORD' ),
			(string) getenv( 'ACULECT_WORKFLOW_DB_NAME' ),
			(int) getenv( 'ACULECT_WORKFLOW_DB_PORT' )
		);
		if ( 0 !== $this->connection->connect_errno ) {
			throw new RuntimeException( 'Database connection failed: ' . $this->connection->connect_error );
		}
		$this->connection->set_charset( 'utf8mb4' );
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	public function prepare( string $query, mixed ...$args ): string {
		$position = 0;
		$prepared = preg_replace_callback(
			'/%[isd]/',
			function ( array $match ) use ( $args, &$position ): string {
				$value = $args[ $position ] ?? null;
				++$position;
				if ( '%i' === $match[0] ) {
					return '`' . str_replace( '`', '``', (string) $value ) . '`';
				}

				return '%d' === $match[0]
					? (string) (int) $value
					: ( null === $value ? 'NULL' : "'" . $this->connection->real_escape_string( (string) $value ) . "'" );
			},
			$query
		);
		if ( ! is_string( $prepared ) || count( $args ) !== $position ) {
			throw new RuntimeException( 'Could not prepare proof query.' );
		}

		return $prepared;
	}

	public function query( string $query ): int|false {
		$this->last_error = '';
		$result           = $this->connection->query( $query );
		if ( false === $result ) {
			$this->last_error = $this->connection->error;

			return false;
		}

		return true === $result ? $this->connection->affected_rows : $result->num_rows;
	}

	/**
	 * Fetch one associative result set.
	 *
	 * @param string $query  Prepared SQL.
	 * @param string $format WordPress output format.
	 * @return list<array<string,mixed>>
	 */
	public function get_results( string $query, string $format ): array {
		unset( $format );
		$result = $this->connection->query( $query );
		if ( false === $result ) {
			$this->last_error = $this->connection->error;

			return array();
		}

		return $result->fetch_all( MYSQLI_ASSOC );
	}

	public function get_var( string $query ): mixed {
		$rows = $this->get_results( $query, ARRAY_A );

		return array() === $rows ? null : reset( $rows[0] );
	}

	/**
	 * Delete rows using the narrow wpdb API used by the installer lease.
	 *
	 * @param string              $table   Table name.
	 * @param array<string,mixed> $where   Equality predicates.
	 * @param array<string>       $formats Ignored because this proof uses %s predicates.
	 */
	public function delete( string $table, array $where, array $formats = array() ): int|false {
		unset( $formats );
		$predicates = array();
		$args       = array( $table );
		foreach ( $where as $column => $value ) {
			$predicates[] = '%i = %s';
			$args[]       = (string) $column;
			$args[]       = $value;
		}

		return $this->query( $this->prepare( 'DELETE FROM %i WHERE ' . implode( ' AND ', $predicates ), ...$args ) );
	}
}

/**
 * Throw a bounded proof failure.
 *
 * @param bool   $condition Condition that must hold.
 * @param string $message   Failure message.
 * @throws RuntimeException When the condition is false.
 */
function aculect_workflow_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$wpdb = new AculectWorkflowRealWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The real disposable wpdb adapter is required by the production installer.
aculect_workflow_assert(
	false !== $wpdb->query(
		'CREATE TABLE IF NOT EXISTS wp_options (option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL, option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT \'yes\', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name)) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
	),
	'Could not create the disposable options table for the repair lease proof.'
);

if ( ! RunInstaller::install() ) {
	throw new RuntimeException( 'RunInstaller failed against the real database.' );
}

$tables = RunInstaller::table_names();
foreach ( $tables as $table ) {
	$engine = strtoupper( trim( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ) ) );
	aculect_workflow_assert( 'INNODB' === $engine, 'Workflow table is not InnoDB after install.' );
}

$column = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $tables['runs'], 'approval_reference_hash' ) );
aculect_workflow_assert( 'approval_reference_hash' === $column, 'Approval identity column is missing from the run table.' );

$run_id = 'real-proof-run';
$wpdb->query( 'START TRANSACTION' );
$inserted = $wpdb->query(
	$wpdb->prepare(
		'INSERT INTO %i (run_id, workflow_id, workflow_version, definition_checksum, plan_hash, input_hash, input_ciphertext, state, state_version, created_by, updated_by) VALUES (%s, %s, %d, %s, %s, %s, %s, %s, %d, %d, %d)',
		$tables['runs'],
		$run_id,
		'real_workflow_proof',
		1,
		str_repeat( 'a', 64 ),
		str_repeat( 'b', 64 ),
		str_repeat( 'c', 64 ),
		'v1:proof',
		'running',
		1,
		1,
		1
	)
);
aculect_workflow_assert( false !== $inserted, 'Could not insert the transactional parent row.' );
$run_pk = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE run_id = %s', $tables['runs'], $run_id ) );
aculect_workflow_assert( $run_pk > 0, 'Transactional parent identity was not generated.' );
$wpdb->query(
	$wpdb->prepare(
		'INSERT INTO %i (run_pk, step_id, step_position, adapter_id, adapter_version, ability_id, kind) VALUES (%d, %s, %d, %s, %d, %s, %s)',
		$tables['steps'],
		$run_pk,
		'real_step',
		0,
		'proof_adapter',
		1,
		'proof/ability',
		'read'
	)
);
$invalid = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (not_a_real_column) VALUES (1)', $tables['steps'] ) );
aculect_workflow_assert( false === $invalid, 'The injected transaction failure unexpectedly succeeded.' );
aculect_workflow_assert( false !== $wpdb->query( 'ROLLBACK' ), 'The transaction rollback failed.' );
$remaining = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %s', $tables['runs'], $run_id ) );
aculect_workflow_assert( 0 === $remaining, 'The parent row survived a failed transaction rollback.' );

foreach ( $tables as $table ) {
	aculect_workflow_assert( false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $table ) ), 'Could not prepare the legacy engine repair scenario.' );
}
aculect_workflow_assert( RunInstaller::install(), 'RunInstaller did not repair the disposable legacy engine.' );
foreach ( $tables as $table ) {
	$engine = strtoupper( trim( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ) ) );
	aculect_workflow_assert( 'INNODB' === $engine, 'Legacy engine repair did not reverify InnoDB.' );
}

$version = (string) get_option( 'aculect_ai_companion_workflow_runs_db_version', '' );
aculect_workflow_assert( '2026.08.29.2' === $version, 'Run schema version was not persisted after verification.' );
echo 'PASS real workflow installer and transaction proof on ' . (string) $wpdb->get_var( 'SELECT VERSION()' ) . PHP_EOL;

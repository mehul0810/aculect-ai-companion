<?php
/**
 * Source-external durable workflow runner proof against a real MySQL engine.
 *
 * @package Aculect\AICompanion\Tests\Integration\Workflows
 */

declare(strict_types=1);

use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Database\RunInstaller;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunStore;
use Aculect\AICompanion\Workflows\Execution\WorkflowStepState;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- Self-contained integration harness.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- A disposable real database is the subject under proof.
// phpcs:disable WordPress.DB.RestrictedClasses.mysql__mysqli, WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- A real MySQL-compatible engine is the subject under proof.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only bounded proof output.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The harness supplies a process-local wpdb and WordPress API surface.

define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

require ABSPATH . 'vendor/autoload.php';

/**
 * Process-local option state used by the encryption and schema stubs.
 *
 * @var array<string, mixed> $aculect_workflow_options
 */
$aculect_workflow_options = array(
	'aculect_ai_companion_secret_storage_key' => str_repeat( 'k', 64 ),
);

function get_option( string $option, mixed $default = false ): mixed {
	global $aculect_workflow_options;

	return $aculect_workflow_options[ $option ] ?? $default;
}

function add_option( string $option, mixed $value = '', mixed $deprecated = '', mixed $autoload = null ): bool {
	global $aculect_workflow_options;
	unset( $deprecated, $autoload );
	if ( array_key_exists( $option, $aculect_workflow_options ) ) {
		return false;
	}

	$aculect_workflow_options[ $option ] = $value;

	return true;
}

function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
	global $aculect_workflow_options;
	unset( $autoload );
	$aculect_workflow_options[ $option ] = $value;

	return true;
}

function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Implements the WordPress compatibility function itself.
}

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
 * Narrow wpdb adapter that sends production SQL to the matrix service.
 */
final class AculectWorkflowRealWpdb {

	public string $prefix     = 'wp_';
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
				$value = $args[ $position++ ] ?? null;
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

	public function get_var( string $query ): mixed {
		$rows = $this->get_results( $query, ARRAY_A );

		return array() === $rows ? null : reset( $rows[0] );
	}

	/**
	 * Fetch associative rows from the matrix service.
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

	/**
	 * Fetch one associative row from the matrix service.
	 *
	 * @param string $query  Prepared SQL.
	 * @param string $format WordPress output format.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $query, string $format ): ?array {
		$rows = $this->get_results( $query, $format );

		return array() === $rows ? null : $rows[0];
	}

	public function insert( string $table, array $data, array $formats = array() ): int|false {
		unset( $formats );
		$columns         = array_map( static fn ( string $column ): string => '`' . str_replace( '`', '``', $column ) . '`', array_keys( $data ) );
		$values          = array_map(
			fn ( mixed $value ): string => null === $value ? 'NULL' : "'" . $this->connection->real_escape_string( (string) $value ) . "'",
			array_values( $data )
		);
		$result          = $this->query( 'INSERT INTO `' . str_replace( '`', '``', $table ) . '` (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')' );
		$this->insert_id = (int) $this->connection->insert_id;

		return $result;
	}

	public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): int|false {
		unset( $formats, $where_formats );
		$sets = array();
		$args = array( $table );
		foreach ( $data as $column => $value ) {
			$sets[] = '%i = %s';
			$args[] = $column;
			$args[] = $value;
		}
		$predicates = array();
		foreach ( $where as $column => $value ) {
			$predicates[] = '%i = %s';
			$args[]       = $column;
			$args[]       = $value;
		}

		return $this->query( $this->prepare( 'UPDATE %i SET ' . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $predicates ), ...$args ) );
	}
}

function aculect_workflow_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$wpdb = new AculectWorkflowRealWpdb();
global $aculect_workflow_options;
$tables = RunInstaller::table_names();
foreach ( $tables as $table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
}
aculect_workflow_assert( RunInstaller::install(), 'RunInstaller failed against the real database.' );

foreach ( $tables as $table ) {
	$engine = strtoupper( trim( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) ) ) );
	aculect_workflow_assert( 'INNODB' === $engine, 'Workflow table is not InnoDB after install.' );
}

$fixture_path = dirname( __DIR__, 3 ) . '/tests/fixtures/workflows/definitions/proposal-only-v1.json';
$fixture_json = file_get_contents( $fixture_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned proof fixture.
aculect_workflow_assert( is_string( $fixture_json ), 'Could not load the workflow proof fixture.' );
$definition = WorkflowDefinition::from_json( $fixture_json );
$input      = WorkflowInputContract::from_json( '{"post_id":9}' );
$plan       = ( new WorkflowPlanBuilder() )->build( $definition, $input );
$store      = new WorkflowRunStore( null, static fn (): int => time() );

$run = $store->create( 'real-run-proof', 'proposal_only_fixture', 1, $definition->checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
aculect_workflow_assert( WorkflowRunState::PREPARED === $run->state(), 'Real parent row did not persist.' );
$running = $store->transition( $run->run_id(), WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7 );
aculect_workflow_assert( null !== $running && WorkflowRunState::RUNNING === $running->state(), 'Real lifecycle transition failed.' );
$claimed = $store->claim_step( $run->run_id(), 'read_content', 7 );
aculect_workflow_assert( null !== $claimed && WorkflowStepState::RUNNING === $claimed->state(), 'Real step claim failed.' );
$completed = $store->complete_step( $run->run_id(), 'read_content', $claimed->fence(), WorkflowAdapterResult::success( array( 'ok' => true ) ), 7 );
aculect_workflow_assert( null !== $completed && WorkflowStepState::COMPLETED === $completed->state(), 'Real step completion failed.' );
$finished = $store->transition( $run->run_id(), WorkflowRunState::RUNNING, 2, WorkflowRunState::COMPLETED, 7, 'completed' );
aculect_workflow_assert( null !== $finished && WorkflowRunState::COMPLETED === $finished->state(), 'Real terminal transition failed.' );

$wpdb->query( 'START TRANSACTION' );
$inserted = $wpdb->query(
	$wpdb->prepare(
		'INSERT INTO %i (run_id, workflow_id, workflow_version, definition_checksum, plan_hash, input_hash, input_ciphertext, state, state_version, created_by, updated_by) VALUES (%s, %s, %d, %s, %s, %s, %s, %s, %d, %d, %d)',
		$tables['runs'],
		'real-rollback-proof',
		'rollback_workflow',
		1,
		str_repeat( 'a', 64 ),
		str_repeat( 'b', 64 ),
		str_repeat( 'c', 64 ),
		'v1:proof',
		'prepared',
		1,
		1,
		1
	)
);
aculect_workflow_assert( false !== $inserted, 'Could not insert the transaction rollback parent.' );
$invalid = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (not_a_real_column) VALUES (1)', $tables['steps'] ) );
aculect_workflow_assert( false === $invalid, 'The injected transaction failure unexpectedly succeeded.' );
aculect_workflow_assert( false !== $wpdb->query( 'ROLLBACK' ), 'The transaction rollback failed.' );
$remaining = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %s', $tables['runs'], 'real-rollback-proof' ) );
aculect_workflow_assert( 0 === $remaining, 'The parent row survived a failed transaction rollback.' );

echo 'PASS real workflow runner and transaction proof on ' . (string) $wpdb->get_var( 'SELECT VERSION()' ) . PHP_EOL;

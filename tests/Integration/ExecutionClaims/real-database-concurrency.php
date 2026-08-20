<?php
/**
 * Source-external competing-worker proof against a real transactional engine.
 *
 * @package Aculect\AICompanion\Tests\Integration\ExecutionClaims
 */

declare(strict_types=1);

use Aculect\AICompanion\Connectors\MCP\ExecutionClaims\ExecutionClaimDecision;
use Aculect\AICompanion\Connectors\MCP\ExecutionClaims\Installer;
use Aculect\AICompanion\Connectors\MCP\ExecutionClaims\WordPressExecutionClaimStore;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- Self-contained source-external process harness.
// phpcs:disable WordPress.DB.RestrictedClasses.mysql__mysqli, WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- A real engine is the subject of this integration proof.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Process isolation is the concurrency boundary under proof.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only bounded test output.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- A real wpdb-compatible global is required by production code.

define( 'ARRAY_A', 'ARRAY_A' );
define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );

require ABSPATH . 'vendor/autoload.php';

/**
 * Process-local installer options.
 *
 * @var array<string, mixed> $aculect_claims_options
 */
$aculect_claims_options = array();

/**
 * Minimal option reader used only by the installer under proof.
 *
 * @param string $option Option name.
 * @param mixed  $default Default value.
 */
function get_option( string $option, mixed $default = false ): mixed {
	global $aculect_claims_options;
	return $aculect_claims_options[ $option ] ?? $default;
}

/**
 * Minimal option writer used only by the installer under proof.
 *
 * @param string $option Option name.
 * @param mixed  $value Option value.
 */
function update_option( string $option, mixed $value ): bool {
	global $aculect_claims_options;
	$aculect_claims_options[ $option ] = $value;
	return true;
}

/**
 * Execute the exact installer DDL without emulating dbDelta rewrites.
 *
 * @param string $sql Installer DDL.
 */
function dbDelta( string $sql ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WordPress API compatibility proof.
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Exact production installer DDL is the subject under proof.
	return false === $wpdb->query( $sql ) ? array() : array( 'execution claims table created' );
}

/**
 * Return an absolute integer like WordPress core.
 *
 * @param mixed $value Input value.
 */
function absint( mixed $value ): int {
	return abs( (int) $value );
}

/**
 * Encode JSON like WordPress core for the bounded result proof.
 *
 * @param mixed $value Input value.
 */
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Implements the WordPress compatibility function itself.
}

/**
 * Narrow wpdb-compatible adapter that sends the production store's exact SQL
 * to the real service engine.
 */
final class AculectClaimsRealWpdb {
	public string $prefix     = 'wp_';
	public string $last_error = '';
	public int $insert_id     = 0;
	private mysqli $connection;

	public function __construct() {
		mysqli_report( MYSQLI_REPORT_OFF );
		$this->connection = new mysqli(
			(string) getenv( 'ACULECT_CLAIMS_DB_HOST' ),
			(string) getenv( 'ACULECT_CLAIMS_DB_USER' ),
			(string) getenv( 'ACULECT_CLAIMS_DB_PASSWORD' ),
			(string) getenv( 'ACULECT_CLAIMS_DB_NAME' ),
			(int) getenv( 'ACULECT_CLAIMS_DB_PORT' )
		);
		if ( 0 !== $this->connection->connect_errno ) {
			throw new RuntimeException( 'Database connection failed.' );
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
		$index = 0;
		return (string) preg_replace_callback(
			'/%[isd]/',
			function ( array $match ) use ( $args, &$index ): string {
				$value = $args[ $index++ ] ?? null;
				return match ( $match[0] ) {
					'%d' => (string) (int) $value,
					'%i' => '`' . str_replace( '`', '``', (string) $value ) . '`',
					default => "'" . $this->connection->real_escape_string( (string) $value ) . "'",
				};
			},
			$query
		);
	}

	/**
	 * Insert one row through the narrow wpdb-compatible surface.
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data  Row data.
	 */
	public function insert( string $table, array $data ): int|false {
		$columns         = array_map( fn ( string $column ): string => '`' . str_replace( '`', '``', $column ) . '`', array_keys( $data ) );
		$values          = array_map(
			fn ( mixed $value ): string => null === $value ? 'NULL' : "'" . $this->connection->real_escape_string( (string) $value ) . "'",
			array_values( $data )
		);
		$result          = $this->query( 'INSERT INTO `' . str_replace( '`', '``', $table ) . '` (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ')' );
		$this->insert_id = (int) $this->connection->insert_id;
		return $result;
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
	 * Fetch associative rows through the narrow wpdb-compatible surface.
	 *
	 * @param string $query  Prepared SQL.
	 * @param string $format WordPress output format.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, string $format ): array {
		unset( $format );
		$this->last_error = '';
		$result           = $this->connection->query( $query );
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
}

/**
 * Real database adapter used by production claim code.
 *
 * @var AculectClaimsRealWpdb $wpdb
 */
$wpdb = new AculectClaimsRealWpdb();

/**
 * Build deterministic hashes for one isolated scenario.
 *
 * @param string $scenario Scenario name.
 * @param string $value    Hash input.
 */
function aculect_claims_hash( string $scenario, string $value ): string {
	return hash( 'sha256', $scenario . ':' . $value );
}

/**
 * Execute one worker process and emit only a bounded decision.
 *
 * @param string $scenario Scenario name.
 * @param float  $start_at Shared process start time.
 */
function aculect_claims_worker( string $scenario, float $start_at ): never {
	while ( microtime( true ) < $start_at ) {
		usleep( 1000 );
	}

	$store        = new WordPressExecutionClaimStore();
	$confirmation = 'confirmation' === $scenario ? aculect_claims_hash( $scenario, 'confirmation' ) : null;
	$idempotency  = 'confirmation' === $scenario ? null : aculect_claims_hash( $scenario, 'idempotency' );
	$decision     = $store->claim(
		aculect_claims_hash( $scenario, 'payload' ),
		aculect_claims_hash( $scenario, 'tool' ),
		aculect_claims_hash( $scenario, 'identity' ),
		$confirmation,
		$idempotency,
		true,
		null,
		false,
		86400
	);

	if ( ExecutionClaimDecision::ACQUIRED === $decision->type ) {
		$claim = $decision->claim();
		if ( null === $claim || ! $store->mark_running( $claim ) ) {
			fwrite( STDERR, "failed to enter running state\n" );
			exit( 2 );
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (scenario) VALUES (%s)', 'wp_aculect_claim_side_effects', $scenario ) );
		usleep( 500000 );
		if ( ! $store->complete(
			$claim,
			array(
				'status'   => 'success',
				'scenario' => $scenario,
			),
			86400
		) ) {
			fwrite( STDERR, "failed to publish result\n" );
			exit( 3 );
		}
	}

	echo $decision->type;
	exit( 0 );
}

/**
 * Run eight actual PHP processes against one alias and verify one effect.
 *
 * @param string $scenario Scenario name.
 *
 * @throws RuntimeException When process or exclusion proof fails.
 */
function aculect_claims_prove_scenario( string $scenario ): void {
	global $wpdb;
	$start_at  = microtime( true ) + 1.0;
	$processes = array();
	for ( $worker = 0; $worker < 8; ++$worker ) {
		$command = array( PHP_BINARY, __FILE__, 'worker', $scenario, (string) $start_at );
		$pipes   = array();
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'Could not start worker.' );
		}
		$processes[] = array( $process, $pipes );
	}

	$outcomes = array();
	foreach ( $processes as list( $process, $pipes ) ) {
		$outcome = trim( (string) stream_get_contents( $pipes[1] ) );
		$error   = trim( (string) stream_get_contents( $pipes[2] ) );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		if ( 0 !== $status ) {
			throw new RuntimeException( 'Worker failed: ' . $error );
		}
		$outcomes[] = $outcome;
	}

	$effect_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scenario = %s', 'wp_aculect_claim_side_effects', $scenario ) );
	if ( 1 !== $effect_count || 1 !== count( array_filter( $outcomes, static fn ( string $value ): bool => ExecutionClaimDecision::ACQUIRED === $value ) ) ) {
		throw new RuntimeException( 'Competing workers did not produce exactly one owner and one effect.' );
	}
	foreach ( $outcomes as $outcome ) {
		if ( ! in_array( $outcome, array( ExecutionClaimDecision::ACQUIRED, ExecutionClaimDecision::IN_PROGRESS, ExecutionClaimDecision::REPLAY ), true ) ) {
				throw new RuntimeException( 'Worker returned an unsafe decision: ' . $outcome );
		}
	}
}

if ( 'worker' === ( $argv[1] ?? '' ) ) {
	aculect_claims_worker( (string) ( $argv[2] ?? '' ), (float) ( $argv[3] ?? 0 ) );
}

$wpdb->query( 'DROP TABLE IF EXISTS `wp_aculect_ai_companion_execution_claims`' );
$wpdb->query( 'DROP TABLE IF EXISTS `wp_aculect_claim_side_effects`' );
if ( ! Installer::install( true ) ) {
	throw new RuntimeException( 'Exact production installer failed.' );
}
$wpdb->query( 'CREATE TABLE `wp_aculect_claim_side_effects` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, scenario VARCHAR(32) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB' );

aculect_claims_prove_scenario( 'confirmation' );
aculect_claims_prove_scenario( 'idempotency' );

$expired_scenario = 'expired-idempotency';
$expired_result   = wp_json_encode( array( 'status' => 'success' ) );
$expired_now      = time();
$wpdb->insert(
	Installer::table_name(),
	array(
		'confirmation_key_hash' => null,
		'idempotency_key_hash'  => aculect_claims_hash( $expired_scenario, 'idempotency' ),
		'payload_hash'          => aculect_claims_hash( $expired_scenario, 'payload' ),
		'tool_hash'             => aculect_claims_hash( $expired_scenario, 'tool' ),
		'identity_hash'         => aculect_claims_hash( $expired_scenario, 'identity' ),
		'owner_hash'            => null,
		'fence'                 => 1,
		'state'                 => 'completed',
		'result_json'           => $expired_result,
		'result_hash'           => hash( 'sha256', (string) $expired_result ),
		'completed_at'          => gmdate( 'Y-m-d H:i:s', $expired_now - 20 ),
		'retain_until'          => gmdate( 'Y-m-d H:i:s', $expired_now - 10 ),
		'created_at'            => gmdate( 'Y-m-d H:i:s', $expired_now - 20 ),
		'updated_at'            => gmdate( 'Y-m-d H:i:s', $expired_now - 20 ),
	)
);
aculect_claims_prove_scenario( $expired_scenario );

$engine = (string) $wpdb->get_var( 'SELECT VERSION()' );
echo "PASS real competing-worker proof on {$engine}\n";

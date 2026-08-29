<?php
/**
 * SQLite-backed wpdb double for durable workflow run tests.
 *
 * @package Aculect\AICompanion\Tests\Support
 */

declare(strict_types=1);

// phpcs:disable Generic.Commenting.DocComment.MissingShort, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingParamComment, Squiz.Commenting.FunctionComment.IncorrectTypeHint -- Test adapter mirrors a narrow wpdb surface.

namespace Aculect\AICompanion\Tests\Support;

use PDO;

/**
 * Implements only the wpdb surface used by RunInstaller and WorkflowRunStore.
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test infrastructure is intentionally kept with its focused adapter.
final class WorkflowRunSqliteWpdb {

	public string $prefix     = 'wp_';
	public string $last_error = '';
	public int $insert_id     = 0;

	private PDO $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec(
			'CREATE TABLE wp_aculect_ai_workflow_runs (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				run_id TEXT NOT NULL UNIQUE,
				workflow_id TEXT NOT NULL,
				workflow_version INTEGER NOT NULL,
				definition_checksum TEXT NOT NULL,
				plan_hash TEXT NOT NULL,
				input_hash TEXT NOT NULL,
				input_ciphertext TEXT NOT NULL,
				state TEXT NOT NULL,
				state_version INTEGER NOT NULL DEFAULT 1,
				outcome_code TEXT NOT NULL DEFAULT \'\',
				waiting_expires_at TEXT NULL,
				created_by INTEGER NOT NULL,
				updated_by INTEGER NOT NULL,
				created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE wp_aculect_ai_workflow_run_steps (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				run_pk INTEGER NOT NULL,
				step_id TEXT NOT NULL,
				step_position INTEGER NOT NULL,
				adapter_id TEXT NOT NULL,
				adapter_version INTEGER NOT NULL,
				ability_id TEXT NOT NULL,
				kind TEXT NOT NULL,
				state TEXT NOT NULL DEFAULT \'pending\',
				attempt INTEGER NOT NULL DEFAULT 0,
				fence INTEGER NOT NULL DEFAULT 1,
				result_code TEXT NOT NULL DEFAULT \'\',
				output_ciphertext TEXT NOT NULL DEFAULT \'\',
				output_hash TEXT NOT NULL DEFAULT \'\',
				error_code TEXT NOT NULL DEFAULT \'\',
				lease_expires_at TEXT NULL,
				started_at TEXT NULL,
				completed_at TEXT NULL,
				created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
				UNIQUE (run_pk, step_id)
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
					if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $identifier ) ) {
						throw new \InvalidArgumentException( 'Unsafe SQL identifier.' );
					}

					return '"' . $identifier . '"';
				}

				return '%d' === $match[0] ? (string) (int) $value : $this->pdo->quote( (string) $value );
			},
			$query
		);
		if ( ! is_string( $prepared ) || count( $args ) !== $position ) {
			throw new \RuntimeException( 'Could not prepare SQLite test query.' );
		}

		return $prepared;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_charset_collate(): string {
		return '';
	}

	public function query( string $query ): int|false {
		try {
			$query = match ( strtoupper( trim( $query ) ) ) {
				'START TRANSACTION' => 'BEGIN',
				default             => $query,
			};
			return $this->pdo->exec( $query );
		} catch ( \Throwable $exception ) {
			$this->last_error = $exception->getMessage();

			return false;
		}
	}

	public function get_var( string $query ): int|string|null {
		if ( false !== stripos( $query, 'SHOW TABLES LIKE' ) ) {
			preg_match( '/LIKE\s+["\']([^"\']+)["\']/i', $query, $matches );
			$table_name = str_replace( array( '\\_', '\\%', '\\\\' ), array( '_', '%', '\\' ), $matches[1] ?? '' );
			$statement  = $this->pdo->prepare( 'SELECT name FROM sqlite_master WHERE type = \'table\' AND name = :name' );
			$statement->execute( array( ':name' => $table_name ) );
			$value = $statement->fetchColumn();

			return false === $value ? '' : (string) $value;
		}

		$statement = $this->pdo->query( $query );
		if ( false === $statement ) {
			return null;
		}
		$value = $statement->fetchColumn();

		return false === $value ? null : $value;
	}

	/**
	 * @param string $query  Prepared query.
	 * @param string $output Requested shape.
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, string $output ): array|null {
		unset( $output );
		$statement = $this->pdo->query( $query );
		if ( false === $statement ) {
			return null;
		}
		$row = $statement->fetch( PDO::FETCH_ASSOC );

		return false === $row ? null : $row;
	}

	/**
	 * @param string $query  Prepared query.
	 * @param string $output Requested shape.
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );
		$statement = $this->pdo->query( $query );

		return false === $statement ? array() : $statement->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Insert one row into the isolated table.
	 *
	 * @param string               $table   Table name.
	 * @param array<string, mixed> $data
	 * @param list<string>         $formats WordPress format tokens.
	 */
	public function insert( string $table, array $data, array $formats ): int|false {
		unset( $formats );
		$columns      = array_keys( $data );
		$placeholders = array_map( static fn ( string $column ): string => ':' . $column, $columns );
		$sql          = sprintf( 'INSERT INTO "%s" ("%s") VALUES (%s)', $table, implode( '", "', $columns ), implode( ', ', $placeholders ) );
		$statement    = $this->pdo->prepare( $sql );
		if ( false === $statement || ! $statement->execute( $data ) ) {
			return false;
		}

		$this->insert_id = (int) $this->pdo->lastInsertId();

		return $statement->rowCount();
	}

	/**
	 * Update rows matching equality predicates.
	 *
	 * @param string               $table        Table name.
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 * @param list<string>         $formats      WordPress format tokens.
	 * @param list<string>         $where_format WordPress where-format tokens.
	 */
	public function update( string $table, array $data, array $where, array $formats, array $where_format ): int|false {
		unset( $formats, $where_format );
		$set   = array_map( static fn ( string $column ): string => '"' . $column . '" = :set_' . $column, array_keys( $data ) );
		$match = array_map( static fn ( string $column ): string => '"' . $column . '" = :where_' . $column, array_keys( $where ) );
		$sql   = sprintf( 'UPDATE "%s" SET %s WHERE %s', $table, implode( ', ', $set ), implode( ' AND ', $match ) );
		$args  = array();
		foreach ( $data as $column => $value ) {
			$args[ ':set_' . $column ] = $value;
		}
		foreach ( $where as $column => $value ) {
			$args[ ':where_' . $column ] = $value;
		}
		$statement = $this->pdo->prepare( $sql );

		return false === $statement || ! $statement->execute( $args ) ? false : $statement->rowCount();
	}

	/**
	 * @param string $query SQL query.
	 */
	public function scalar( string $query ): mixed {
		$statement = $this->pdo->query( $query );

		return false === $statement ? null : $statement->fetchColumn();
	}
}

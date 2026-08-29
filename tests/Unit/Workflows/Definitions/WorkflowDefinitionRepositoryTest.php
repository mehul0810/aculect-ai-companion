<?php
/**
 * Tests for durable custom workflow definition storage.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Definitions;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRecord;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRepository;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRepositoryException;
use PDO;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Repository tests use an isolated real SQLite store.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused test replaces wpdb with an isolated adapter.

/**
 * Verifies immutable versions, stable IDs, disabled visibility, and storage
 * validation through the repository boundary.
 */
final class WorkflowDefinitionRepositoryTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			self::markTestSkipped( 'pdo_sqlite is required for workflow repository persistence tests.' );
		}

		$this->original_wpdb                          = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']                              = new WorkflowDefinitionSqliteWpdb();
		$GLOBALS['aculect_ai_companion_test_options'] = array(
			'aculect_ai_companion_workflows_db_version' => '2026.08.19.1',
		);
	}

	protected function tearDown(): void {
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}

		parent::tearDown();
	}

	public function test_create_read_and_list_return_a_validated_latest_record(): void {
		$repository = new WorkflowDefinitionRepository();
		$record     = $repository->create( WorkflowDefinition::from_array( $this->definition() ), 'content_audit', 3 );

		self::assertSame( 1, $record->id() );
		self::assertSame( 'sample_workflow', $record->workflow_id() );
		self::assertSame( 'draft', $record->status() );
		self::assertSame( 1, $record->latest_version() );
		self::assertSame( 0, $record->published_version() );
		self::assertSame( 'content_audit', $record->template_id() );
		self::assertSame( 3, $record->template_version() );
		self::assertSame( $record->definition()->checksum(), $record->definition()->checksum() );

		$read = $repository->get( 'sample_workflow' );
		self::assertNotNull( $read );
		self::assertSame( $record->definition()->canonical_json(), $read->definition()->canonical_json() );
		self::assertCount( 1, $repository->list() );
	}

	public function test_update_of_a_published_workflow_appends_an_immutable_version(): void {
		$repository = new WorkflowDefinitionRepository();
		$repository->create( WorkflowDefinition::from_array( $this->definition() ) );

		$next                     = $this->definition();
		$next['workflow_version'] = 2;
		$next['name']             = 'Published sample workflow';
		$next['status']           = 'published';
		$next['updated_by']       = 8;
		$updated                  = $repository->update( WorkflowDefinition::from_array( $next ), 1 );

		self::assertSame( 2, $updated->latest_version() );
		self::assertSame( 2, $updated->published_version() );
		self::assertSame( 'published', $updated->status() );
		self::assertSame( 'sample_workflow', $updated->workflow_id() );
		$first_version  = $repository->get( 'sample_workflow', 1, true );
		$second_version = $repository->get( 'sample_workflow', 2 );
		self::assertInstanceOf( WorkflowDefinitionRecord::class, $first_version );
		self::assertInstanceOf( WorkflowDefinitionRecord::class, $second_version );
		self::assertSame( 'Sample workflow', $first_version->definition()->to_array()['name'] );
		self::assertSame( 'Published sample workflow', $second_version->definition()->to_array()['name'] );
		self::assertSame( 2, $this->wpdb()->scalar( 'SELECT COUNT(*) FROM wp_aculect_ai_workflow_versions' ) );
	}

	public function test_disable_hides_catalog_by_default_without_mutating_versions(): void {
		$repository = new WorkflowDefinitionRepository();
		$created    = $repository->create( WorkflowDefinition::from_array( $this->definition() ) );
		$disabled   = $repository->disable( 'sample_workflow', 9, $created->latest_version() );

		self::assertNotNull( $disabled );
		self::assertSame( 'disabled', $disabled->status() );
		self::assertNull( $repository->get( 'sample_workflow' ) );
		$version = $repository->get( 'sample_workflow', 1, true );
		self::assertInstanceOf( WorkflowDefinitionRecord::class, $version );
		self::assertSame( 'Sample workflow', $version->definition()->to_array()['name'] );
		self::assertSame( 1, $this->wpdb()->scalar( 'SELECT COUNT(*) FROM wp_aculect_ai_workflow_versions' ) );
	}

	public function test_repository_rejects_non_initial_versions_and_stale_writers(): void {
		$repository                  = new WorkflowDefinitionRepository();
		$initial                     = $this->definition();
		$initial['workflow_version'] = 2;

		try {
			$repository->create( WorkflowDefinition::from_array( $initial ) );
			self::fail( 'A first repository version must be version one.' );
		} catch ( WorkflowDefinitionRepositoryException $exception ) {
			self::assertSame( 'initial_version_must_be_one', $exception->error_code() );
		}

		$repository->create( WorkflowDefinition::from_array( $this->definition() ) );
		$next                     = $this->definition();
		$next['workflow_version'] = 2;
		try {
			$repository->update( WorkflowDefinition::from_array( $next ), 4 );
			self::fail( 'A stale expected version must fail closed.' );
		} catch ( WorkflowDefinitionRepositoryException $exception ) {
			self::assertSame( 'workflow_version_conflict', $exception->error_code() );
		}
	}

	public function test_repository_rejects_creator_changes_and_invalid_template_metadata(): void {
		$repository = new WorkflowDefinitionRepository();
		try {
			$repository->create( WorkflowDefinition::from_array( $this->definition() ), 'Bad Template', 1 );
			self::fail( 'Template identifiers must use the bounded identifier vocabulary.' );
		} catch ( WorkflowDefinitionRepositoryException $exception ) {
			self::assertSame( 'invalid_template_id', $exception->error_code() );
		}

		$repository->create( WorkflowDefinition::from_array( $this->definition() ) );
		$next                     = $this->definition();
		$next['workflow_version'] = 2;
		$next['created_by']       = 11;
		try {
			$repository->update( WorkflowDefinition::from_array( $next ), 1 );
			self::fail( 'The catalog creator must remain stable across versions.' );
		} catch ( WorkflowDefinitionRepositoryException $exception ) {
			self::assertSame( 'creator_is_immutable', $exception->error_code() );
		}
	}

	public function test_disabled_status_filter_can_be_requested_explicitly(): void {
		$repository = new WorkflowDefinitionRepository();
		$repository->create( WorkflowDefinition::from_array( $this->definition() ) );
		$repository->disable( 'sample_workflow', 9 );

		self::assertCount( 1, $repository->list( array( 'include_disabled' => true ) ) );
		self::assertCount(
			1,
			$repository->list(
				array(
					'status'           => 'disabled',
					'include_disabled' => true,
				)
			)
		);
		self::assertCount(
			0,
			$repository->list(
				array(
					'status'           => 'published',
					'include_disabled' => true,
				)
			)
		);
	}

	/**
	 * Return the smallest valid read-only workflow definition.
	 *
	 * @return array<string, mixed>
	 */
	private function definition(): array {
		return array(
			'definition_schema_version' => 1,
			'workflow_id'               => 'sample_workflow',
			'workflow_version'          => 1,
			'name'                      => 'Sample workflow',
			'description'               => 'Read site content and return a structured proposal.',
			'content_target'            => array(
				'mode'       => 'either',
				'post_types' => array( 'post' ),
			),
			'input_schema'              => array( 'type' => 'object' ),
			'steps'                     => array(
				array(
					'step_id'         => 'read_context',
					'adapter_id'      => 'wordpress',
					'adapter_version' => 1,
					'ability_id'      => 'content/get-item',
					'kind'            => 'read',
					'arguments'       => array(),
					'depends_on'      => array(),
				),
			),
			'allowed_abilities'         => array( 'content/get-item' ),
			'write_policy'              => array( 'mode' => 'proposal_only' ),
			'approval_gates'            => array(),
			'output_contract'           => array( 'type' => 'object' ),
			'validation_rules'          => array(),
			'status'                    => 'draft',
			'created_by'                => 7,
			'updated_by'                => 7,
			'compatibility'             => array(
				'input_contract_version'  => 1,
				'output_contract_version' => 1,
			),
		);
	}

	/** Return the isolated database adapter. */
	private function wpdb(): WorkflowDefinitionSqliteWpdb {
		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- This is a static-analysis type assertion for the test double.
		/* @var WorkflowDefinitionSqliteWpdb $wpdb */
		$wpdb = $GLOBALS['wpdb'];

		return $wpdb;
	}
}

/**
 * Minimal wpdb-compatible adapter backed by an isolated SQLite database.
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The adapter is local test infrastructure.
final class WorkflowDefinitionSqliteWpdb {

	public string $prefix     = 'wp_';
	public string $last_error = '';
	public int $insert_id     = 0;

	private PDO $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec(
			'CREATE TABLE wp_aculect_ai_workflows (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				workflow_id TEXT NOT NULL UNIQUE,
				status TEXT NOT NULL DEFAULT \'draft\',
				latest_version INTEGER NOT NULL DEFAULT 0,
				published_version INTEGER NOT NULL DEFAULT 0,
				template_id TEXT NOT NULL DEFAULT \'\',
				template_version INTEGER NOT NULL DEFAULT 0,
				created_by INTEGER NOT NULL,
				updated_by INTEGER NOT NULL,
				lock_version INTEGER NOT NULL DEFAULT 1,
				created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
			)'
		);
		$this->pdo->exec(
			'CREATE TABLE wp_aculect_ai_workflow_versions (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				workflow_pk INTEGER NOT NULL,
				workflow_version INTEGER NOT NULL,
				definition_schema_version INTEGER NOT NULL,
				definition_checksum TEXT NOT NULL,
				definition_status TEXT NOT NULL,
				input_contract_version INTEGER NOT NULL,
				output_contract_version INTEGER NOT NULL,
				definition_json TEXT NOT NULL,
				migrated_from_version INTEGER NOT NULL DEFAULT 0,
				migration_id TEXT NOT NULL DEFAULT \'\',
				created_by INTEGER NOT NULL,
				created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
				UNIQUE (workflow_pk, workflow_version)
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

	public function query( string $query ): int|false {
		try {
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
			if ( false === $statement ) {
				return '';
			}
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
	 * Return one associative row.
	 *
	 * @param string $query  Prepared SQL query.
	 * @param string $output Requested output shape.
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
	 * Return associative rows.
	 *
	 * @param string $query  Prepared SQL query.
	 * @param string $output Requested output shape.
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );
		$statement = $this->pdo->query( $query );

		return false === $statement ? array() : $statement->fetchAll( PDO::FETCH_ASSOC );
	}

	/**
	 * Insert one row.
	 *
	 * @param string               $table   Table name.
	 * @param array<string, mixed> $data    Row values.
	 * @param array                $formats WordPress formats.
	 * @phpstan-param list<string> $formats WordPress formats.
	 */
	public function insert( string $table, array $data, array $formats ): int|false {
		unset( $formats );
		$columns      = array_keys( $data );
		$placeholders = array_map( static fn ( string $column ): string => ':' . $column, $columns );
		$sql          = sprintf( 'INSERT INTO "%s" ("%s") VALUES (%s)', $table, implode( '", "', $columns ), implode( ', ', $placeholders ) );
		$statement    = $this->pdo->prepare( $sql );
		if ( false === $statement ) {
			return false;
		}
		if ( ! $statement->execute( $data ) ) {
			return false;
		}

		$this->insert_id = (int) $this->pdo->lastInsertId();

		return $statement->rowCount();
	}

	/**
	 * Update matching rows.
	 *
	 * @param string               $table        Table name.
	 * @param array<string, mixed> $data         Updated values.
	 * @param array<string, mixed> $where        Equality predicates.
	 * @param array                $formats      WordPress formats.
	 * @param array                $where_format WordPress where formats.
	 * @phpstan-param list<string> $formats      WordPress formats.
	 * @phpstan-param list<string> $where_format WordPress where formats.
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
		if ( false === $statement ) {
			return false;
		}

		return $statement->execute( $args ) ? $statement->rowCount() : false;
	}

	public function scalar( string $query ): int {
		$statement = $this->pdo->query( $query );

		return false === $statement ? 0 : (int) $statement->fetchColumn();
	}
}

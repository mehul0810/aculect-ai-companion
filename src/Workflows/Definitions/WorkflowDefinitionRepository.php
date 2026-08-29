<?php
/**
 * Durable workflow definition repository.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use Aculect\AICompanion\Workflows\Database\Installer;
use Throwable;

/**
 * Stores versioned custom workflow definitions without coupling the pure
 * definition contract to WordPress, MCP, admin, or execution code.
 */
final class WorkflowDefinitionRepository {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Workflow definitions use plugin-owned operational tables and must reflect current state.

	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT     = 100;

	/**
	 * Create the first immutable version for a stable workflow ID.
	 *
	 * @param WorkflowDefinition $definition      Validated v1 definition.
	 * @param string             $template_id      Optional built-in template ID.
	 * @param int                $template_version Optional template revision.
	 * @throws WorkflowDefinitionRepositoryException When storage or definition state is invalid.
	 * @throws WorkflowDefinitionRepositoryException When storage or the definition state is invalid.
	 */
	public function create( WorkflowDefinition $definition, string $template_id = '', int $template_version = 0 ): WorkflowDefinitionRecord {
		$this->ensure_storage();
		$this->validate_template( $template_id, $template_version );
		$value = $definition->to_array();
		if ( 1 !== (int) $value['workflow_version'] ) {
			throw new WorkflowDefinitionRepositoryException( 'initial_version_must_be_one' );
		}

		global $wpdb;
		$tables = Installer::table_names();
		if ( null !== $this->catalog_row( (string) $value['workflow_id'], true ) ) {
			throw new WorkflowDefinitionRepositoryException( 'workflow_already_exists' );
		}

		$this->begin_transaction();
		try {
			$now  = gmdate( 'Y-m-d H:i:s' );
			$data = array(
				'workflow_id'       => (string) $value['workflow_id'],
				'status'            => (string) $value['status'],
				'latest_version'    => 1,
				'published_version' => 'published' === $value['status'] ? 1 : 0,
				'template_id'       => $template_id,
				'template_version'  => $template_version,
				'created_by'        => (int) $value['created_by'],
				'updated_by'        => (int) $value['updated_by'],
				'lock_version'      => 1,
				'created_at'        => $now,
				'updated_at'        => $now,
			);
			if ( false === $wpdb->insert( $tables['catalog'], $data, $this->catalog_formats() ) ) {
				throw new WorkflowDefinitionRepositoryException( 'catalog_create_failed' );
			}

			$catalog_id = (int) ( $wpdb->insert_id ?? 0 );
			if ( $catalog_id < 1 ) {
				throw new WorkflowDefinitionRepositoryException( 'catalog_identity_missing' );
			}
			$this->insert_version( $catalog_id, $definition );
			$this->commit_transaction();
		} catch ( WorkflowDefinitionRepositoryException $exception ) {
			$this->rollback_transaction();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception code is bounded by the repository exception constructor.
			throw new WorkflowDefinitionRepositoryException( $exception->error_code() );
		} catch ( Throwable ) {
			$this->rollback_transaction();

			throw new WorkflowDefinitionRepositoryException( 'workflow_create_failed' );
		}

		$record = $this->get( (string) $value['workflow_id'], 1, true );
		if ( null === $record ) {
			throw new WorkflowDefinitionRepositoryException( 'workflow_create_unreadable' );
		}

		return $record;
	}

	/**
	 * Read one immutable version, defaulting to the catalog's latest version.
	 *
	 * Disabled workflows are excluded unless explicitly requested.
	 *
	 * @param string   $workflow_id      Stable workflow identifier.
	 * @param int|null $version        Immutable version, or null for latest.
	 * @param bool     $include_disabled Whether disabled catalog rows are visible.
	 * @throws WorkflowDefinitionRepositoryException When storage or a stored definition is invalid.
	 * @throws WorkflowDefinitionRepositoryException When storage or a stored definition is invalid.
	 */
	public function get( string $workflow_id, ?int $version = null, bool $include_disabled = false ): ?WorkflowDefinitionRecord {
		$this->ensure_storage();
		$catalog = $this->catalog_row( $workflow_id, $include_disabled );
		if ( null === $catalog ) {
			return null;
		}

		$version = null === $version ? (int) $catalog['latest_version'] : $version;
		if ( $version < 1 ) {
			return null;
		}

		global $wpdb;
		$tables = Installer::table_names();
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE workflow_pk = %d AND workflow_version = %d LIMIT 1',
				$tables['versions'],
				(int) $catalog['id'],
				$version
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->record_from_rows( $catalog, $row );
	}

	/**
	 * Read the currently published immutable version.
	 *
	 * A catalog may contain a newer draft while assistants must continue to
	 * discover the last published snapshot. Keeping this lookup here prevents
	 * connector and admin callers from accidentally exposing the draft row.
	 *
	 * @param string $workflow_id Stable workflow identifier.
	 * @return WorkflowDefinitionRecord|null Published record, when present.
	 */
	public function get_published( string $workflow_id ): ?WorkflowDefinitionRecord {
		$this->ensure_storage();
		$catalog = $this->catalog_row( $workflow_id, false );
		if ( null === $catalog || (int) $catalog['published_version'] < 1 ) {
			return null;
		}

		return $this->get( $workflow_id, (int) $catalog['published_version'], false );
	}

	/**
	 * List published snapshots, excluding newer unpublished drafts.
	 *
	 * @param array<string, mixed> $filters List filters: page and per_page.
	 * @return list<WorkflowDefinitionRecord>
	 */
	public function list_published( array $filters = array() ): array {
		$this->ensure_storage();
		global $wpdb;

		$page     = max( 1, absint( $filters['page'] ?? 1 ) );
		$per_page = min( self::MAX_LIMIT, max( 1, absint( $filters['per_page'] ?? self::DEFAULT_LIMIT ) ) );
		$tables   = Installer::table_names();
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, v.definition_json, v.definition_status, v.definition_checksum,
				v.definition_schema_version, v.input_contract_version, v.output_contract_version,
				v.workflow_version AS stored_workflow_version
				FROM %i c INNER JOIN %i v ON v.workflow_pk = c.id AND v.workflow_version = c.published_version
				WHERE c.status <> 'disabled' AND c.published_version > 0 AND v.definition_status = 'published'
				ORDER BY c.updated_at DESC, c.id DESC LIMIT %d OFFSET %d",
				$tables['catalog'],
				$tables['versions'],
				$per_page,
				( $page - 1 ) * $per_page
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$records = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$records[] = $this->record_from_rows( $row, $row );
			}
		}

		return $records;
	}

	/**
	 * List latest records using bounded, deterministic pagination.
	 *
	 * @param array<string, mixed> $filters List filters: status, template_id, page, per_page, include_disabled.
	 * @return list<WorkflowDefinitionRecord>
	 */
	public function list( array $filters = array() ): array {
		$this->ensure_storage();
		global $wpdb;

		$page     = max( 1, absint( $filters['page'] ?? 1 ) );
		$per_page = min( self::MAX_LIMIT, max( 1, absint( $filters['per_page'] ?? self::DEFAULT_LIMIT ) ) );
		$where    = $this->where_clause( $filters );
		$tables   = Installer::table_names();
		$sql      = "SELECT c.*, v.definition_json, v.definition_status, v.definition_checksum,
			v.definition_schema_version, v.input_contract_version, v.output_contract_version,
			v.workflow_version AS stored_workflow_version
			FROM %i c INNER JOIN %i v ON v.workflow_pk = c.id AND v.workflow_version = c.latest_version
			{$where['sql']} ORDER BY c.updated_at DESC, c.id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- SQL contains fixed fragments and all dynamic values are placeholders.
			$wpdb->prepare( $sql, ...array_merge( array_values( $tables ), $where['values'], array( $per_page, ( $page - 1 ) * $per_page ) ) ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$records = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$records[] = $this->record_from_rows( $row, $row );
		}

		return $records;
	}

	/**
	 * Append one immutable version and advance the catalog atomically.
	 *
	 * The definition must use the next version number. The expected version
	 * prevents two writers from silently publishing competing revisions.
	 *
	 * @param WorkflowDefinition $definition      Next validated definition.
	 * @param int                $expected_version Current latest version.
	 * @param string|null        $template_id      Template override, or null to retain the origin.
	 * @param int|null           $template_version Template revision override.
	 * @throws WorkflowDefinitionRepositoryException When the version or storage state is invalid.
	 * @throws WorkflowDefinitionRepositoryException When the version or storage state is invalid.
	 */
	public function update( WorkflowDefinition $definition, int $expected_version, ?string $template_id = null, ?int $template_version = null ): WorkflowDefinitionRecord {
		$this->ensure_storage();
		$value       = $definition->to_array();
		$workflow_id = (string) $value['workflow_id'];
		$catalog     = $this->catalog_row( $workflow_id, true );
		if ( null === $catalog ) {
			throw new WorkflowDefinitionRepositoryException( 'workflow_not_found' );
		}
		if ( 'disabled' === (string) $catalog['status'] ) {
			throw new WorkflowDefinitionRepositoryException( 'workflow_disabled' );
		}
		if ( $expected_version < 1 || $expected_version !== (int) $catalog['latest_version'] ) {
			throw new WorkflowDefinitionRepositoryException( 'workflow_version_conflict' );
		}
		if ( $expected_version + 1 !== (int) $value['workflow_version'] ) {
			throw new WorkflowDefinitionRepositoryException( 'version_must_advance' );
		}
		if ( (int) $catalog['created_by'] !== (int) $value['created_by'] ) {
			throw new WorkflowDefinitionRepositoryException( 'creator_is_immutable' );
		}

		$resolved_template_id      = null === $template_id ? (string) $catalog['template_id'] : $template_id;
		$resolved_template_version = null === $template_version ? (int) $catalog['template_version'] : $template_version;
		$this->validate_template( $resolved_template_id, $resolved_template_version );

		global $wpdb;
		$tables = Installer::table_names();
		$this->begin_transaction();
		try {
			$this->insert_version( (int) $catalog['id'], $definition );
			$published_version = 'published' === $value['status']
				? (int) $value['workflow_version']
				: (int) $catalog['published_version'];
			$updated           = $wpdb->update(
				$tables['catalog'],
				array(
					'status'            => (string) $value['status'],
					'latest_version'    => (int) $value['workflow_version'],
					'published_version' => $published_version,
					'template_id'       => $resolved_template_id,
					'template_version'  => $resolved_template_version,
					'updated_by'        => (int) $value['updated_by'],
					'lock_version'      => (int) $catalog['lock_version'] + 1,
					'updated_at'        => gmdate( 'Y-m-d H:i:s' ),
				),
				array(
					'id'           => (int) $catalog['id'],
					'lock_version' => (int) $catalog['lock_version'],
				),
				array( '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s' ),
				array( '%d', '%d' )
			);
			if ( 1 !== (int) $updated ) {
				throw new WorkflowDefinitionRepositoryException( 'workflow_version_conflict' );
			}
			$this->commit_transaction();
		} catch ( WorkflowDefinitionRepositoryException $exception ) {
			$this->rollback_transaction();
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception code is bounded by the repository exception constructor.
			throw new WorkflowDefinitionRepositoryException( $exception->error_code() );
		} catch ( Throwable ) {
			$this->rollback_transaction();

			throw new WorkflowDefinitionRepositoryException( 'workflow_update_failed' );
		}

		$record = $this->get( $workflow_id, (int) $value['workflow_version'], true );
		if ( null === $record ) {
			throw new WorkflowDefinitionRepositoryException( 'workflow_update_unreadable' );
		}

		return $record;
	}

	/**
	 * Disable a workflow without mutating any immutable version row.
	 *
	 * @param string   $workflow_id      Stable workflow identifier.
	 * @param int      $actor_id         User performing the disable action.
	 * @param int|null $expected_version Optional optimistic version check.
	 * @throws WorkflowDefinitionRepositoryException When the actor or concurrency state is invalid.
	 * @throws WorkflowDefinitionRepositoryException When the actor or concurrency state is invalid.
	 */
	public function disable( string $workflow_id, int $actor_id, ?int $expected_version = null ): ?WorkflowDefinitionRecord {
		$this->ensure_storage();
		if ( $actor_id < 1 ) {
			throw new WorkflowDefinitionRepositoryException( 'invalid_actor' );
		}
		$catalog = $this->catalog_row( $workflow_id, true );
		if ( null === $catalog ) {
			return null;
		}
		if ( null !== $expected_version && $expected_version !== (int) $catalog['latest_version'] ) {
			throw new WorkflowDefinitionRepositoryException( 'workflow_version_conflict' );
		}
		if ( 'disabled' === (string) $catalog['status'] ) {
			return $this->get( $workflow_id, null, true );
		}

		global $wpdb;
		$updated = $wpdb->update(
			Installer::table_names()['catalog'],
			array(
				'status'       => 'disabled',
				'updated_by'   => $actor_id,
				'lock_version' => (int) $catalog['lock_version'] + 1,
				'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'           => (int) $catalog['id'],
				'lock_version' => (int) $catalog['lock_version'],
			),
			array( '%s', '%d', '%d', '%s' ),
			array( '%d', '%d' )
		);
		if ( 1 !== (int) $updated ) {
			throw new WorkflowDefinitionRepositoryException( 'workflow_version_conflict' );
		}

		return $this->get( $workflow_id, null, true );
	}

	/**
	 * Ensure the plugin-owned tables are available before storage access.
	 *
	 * @throws WorkflowDefinitionRepositoryException When the tables cannot be verified.
	 */
	private function ensure_storage(): void {
		if ( ! Installer::install() ) {
			throw new WorkflowDefinitionRepositoryException( 'storage_unavailable' );
		}
	}

	/**
	 * Return one catalog row.
	 *
	 * @param string $workflow_id      Stable workflow identifier.
	 * @param bool   $include_disabled Whether disabled rows are visible.
	 * @return array<string, mixed>|null
	 */
	private function catalog_row( string $workflow_id, bool $include_disabled ): ?array {
		global $wpdb;
		$sql = $include_disabled
			? 'SELECT * FROM %i WHERE workflow_id = %s LIMIT 1'
			: "SELECT * FROM %i WHERE workflow_id = %s AND status <> 'disabled' LIMIT 1";
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is selected from fixed literals and all values use placeholders.
				$sql,
				Installer::table_names()['catalog'],
				$workflow_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Build the bounded list filter clause.
	 *
	 * @param array<string, mixed> $filters List filters.
	 * @return array{sql: string, values: list<mixed>}
	 */
	private function where_clause( array $filters ): array {
		$where  = array();
		$values = array();
		if ( ! (bool) ( $filters['include_disabled'] ?? false ) ) {
			$where[] = "c.status <> 'disabled'";
		}
		$status = isset( $filters['status'] ) ? sanitize_key( (string) $filters['status'] ) : '';
		if ( in_array( $status, array( 'draft', 'published', 'disabled' ), true ) ) {
			$where[]  = 'c.status = %s';
			$values[] = $status;
		}
		$template_id = isset( $filters['template_id'] ) ? sanitize_key( (string) $filters['template_id'] ) : '';
		if ( '' !== $template_id ) {
			$where[]  = 'c.template_id = %s';
			$values[] = $template_id;
		}

		return array(
			'sql'    => array() === $where ? '' : 'WHERE ' . implode( ' AND ', $where ),
			'values' => $values,
		);
	}

	/**
	 * Convert catalog/version rows into a validated detached record.
	 *
	 * @param array<string, mixed> $catalog Catalog row.
	 * @param array<string, mixed> $version Version row.
	 * @throws WorkflowDefinitionRepositoryException When the stored snapshot fails validation.
	 */
	private function record_from_rows( array $catalog, array $version ): WorkflowDefinitionRecord {
		$json = $version['definition_json'] ?? null;
		if ( ! is_string( $json ) ) {
			throw new WorkflowDefinitionRepositoryException( 'stored_definition_invalid' );
		}
		try {
			$definition = WorkflowDefinition::from_json( $json );
		} catch ( WorkflowDefinitionValidationException ) {
			throw new WorkflowDefinitionRepositoryException( 'stored_definition_invalid' );
		}

		$value          = $definition->to_array();
		$stored_version = $version['workflow_version'] ?? $version['stored_workflow_version'] ?? 0;
		if ( (string) $catalog['workflow_id'] !== (string) $value['workflow_id']
			|| (int) $stored_version !== (int) $value['workflow_version']
			|| (string) $version['definition_checksum'] !== $definition->checksum()
			|| (string) $version['definition_status'] !== (string) $value['status'] ) {
			throw new WorkflowDefinitionRepositoryException( 'stored_definition_mismatch' );
		}

		return new WorkflowDefinitionRecord(
			(int) $catalog['id'],
			(string) $catalog['workflow_id'],
			(string) $catalog['status'],
			(int) $catalog['latest_version'],
			(int) $catalog['published_version'],
			(string) $catalog['template_id'],
			(int) $catalog['template_version'],
			(int) $catalog['created_by'],
			(int) $catalog['updated_by'],
			(int) $catalog['lock_version'],
			(string) $catalog['created_at'],
			(string) $catalog['updated_at'],
			$definition
		);
	}

	/**
	 * Insert one immutable definition version.
	 *
	 * @param int                $catalog_id Catalog primary key.
	 * @param WorkflowDefinition $definition Validated definition snapshot.
	 * @throws WorkflowDefinitionRepositoryException When the version cannot be stored.
	 */
	private function insert_version( int $catalog_id, WorkflowDefinition $definition ): void {
		global $wpdb;
		$value    = $definition->to_array();
		$metadata = ( new WorkflowDefinitionCompatibilityMetadata() )->for_definition( $definition );
		$inserted = $wpdb->insert(
			Installer::table_names()['versions'],
			array(
				'workflow_pk'               => $catalog_id,
				'workflow_version'          => (int) $value['workflow_version'],
				'definition_schema_version' => (int) $metadata['definition_schema_version'],
				'definition_checksum'       => $definition->checksum(),
				'definition_status'         => (string) $value['status'],
				'input_contract_version'    => (int) $metadata['input_contract_version'],
				'output_contract_version'   => (int) $metadata['output_contract_version'],
				'definition_json'           => $definition->canonical_json(),
				'migrated_from_version'     => 0,
				'migration_id'              => '',
				'created_by'                => (int) $value['updated_by'],
				'created_at'                => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%s' )
		);
		if ( false === $inserted ) {
			throw new WorkflowDefinitionRepositoryException( 'version_create_failed' );
		}
	}

	/**
	 * Validate template-origin metadata before it reaches storage.
	 *
	 * @param string $template_id      Template identifier.
	 * @param int    $template_version Template revision.
	 * @throws WorkflowDefinitionRepositoryException When metadata is invalid.
	 */
	private function validate_template( string $template_id, int $template_version ): void {
		if ( '' !== $template_id && 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{2,63}$/', $template_id ) ) {
			throw new WorkflowDefinitionRepositoryException( 'invalid_template_id' );
		}
		if ( $template_version < 0 || $template_version > 4294967295 ) {
			throw new WorkflowDefinitionRepositoryException( 'invalid_template_version' );
		}
	}

	/**
	 * Return catalog insert/update formats.
	 *
	 * @return list<string>
	 */
	private function catalog_formats(): array {
		return array( '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s' );
	}

	/** Start a storage transaction. */
	private function begin_transaction(): void {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	/** Commit a storage transaction. */
	private function commit_transaction(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	/** Roll back a storage transaction after a failed write. */
	private function rollback_transaction(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Activity;

use Aculect\AICompanion\Activity\Database\Installer;
use Aculect\AICompanion\Diagnostics\LogSanitizer;

/**
 * Persists connected AI activity log entries.
 */
final class ActivityRepository {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Activity logs use a plugin-owned operational table and must reflect current activity state.

	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT     = 100;

	/**
	 * Persist one activity entry.
	 *
	 * @param array<string, mixed> $entry Activity entry data.
	 */
	public function insert( array $entry ): bool {
		global $wpdb;

		$prepared = $this->prepare_entry( $entry );

		$result = $wpdb->insert(
			Installer::table_name(),
			$prepared['data'],
			$prepared['formats']
		);

		return false !== $result;
	}

	/**
	 * Return activity rows for the admin UI.
	 *
	 * @param array<string, mixed> $filters Activity filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( array $filters = array() ): array {
		global $wpdb;

		$page     = max( 1, absint( $filters['page'] ?? 1 ) );
		$per_page = min( self::MAX_LIMIT, max( 1, absint( $filters['per_page'] ?? self::DEFAULT_LIMIT ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$where    = $this->where_clause( $filters );
		$table    = Installer::table_name();
		$rows     = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic filters add a variable number of placeholder values via argument unpacking.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE clause is built from fixed fragments and placeholder values in where_clause().
				"SELECT * FROM %i {$where['sql']} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				...array_merge( array( $table ), $where['values'], array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'public_row' ), $rows ) : array();
	}

	/**
	 * Count stored activity rows.
	 *
	 * @param array<string, mixed> $filters Activity filters.
	 */
	public function count( array $filters = array() ): int {
		global $wpdb;

		$where = $this->where_clause( $filters );
		$table = Installer::table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE clause is built from fixed fragments and placeholder values in where_clause().
				"SELECT COUNT(*) FROM %i {$where['sql']}",
				...array_merge( array( $table ), $where['values'] )
			)
		);
	}

	/**
	 * Prune expired activity rows.
	 *
	 * @param int $retention_days Retention window.
	 */
	public function prune( int $retention_days ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $retention_days ) * 86400 ) );
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				Installer::table_name(),
				$cutoff
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Normalize an entry into database data and formats.
	 *
	 * @param array<string, mixed> $entry Activity entry data.
	 * @return array{data: array<string, mixed>, formats: string[]}
	 */
	private function prepare_entry( array $entry ): array {
		$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? ( new LogSanitizer() )->sanitize_context( $entry['context'] ) : array();
		$json    = wp_json_encode( $context );

		$data = array(
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			'provider'    => $this->nullable_key( $entry['provider'] ?? null, 40 ),
			'client_id'   => $this->nullable_text( $entry['client_id'] ?? null, 100 ),
			'client_name' => $this->nullable_text( $entry['client_name'] ?? null, 255 ),
			'user_id'     => isset( $entry['user_id'] ) ? absint( $entry['user_id'] ) : null,
			'action'      => $this->action( (string) ( $entry['action'] ?? 'ai.activity' ) ),
			'target_type' => $this->nullable_key( $entry['target_type'] ?? null, 60 ),
			'target_id'   => isset( $entry['target_id'] ) ? absint( $entry['target_id'] ) : null,
			'status'      => $this->status( (string) ( $entry['status'] ?? 'success' ) ),
			'error_code'  => $this->nullable_key( $entry['error_code'] ?? null, 100 ),
			'message'     => $this->nullable_text( $entry['message'] ?? null, 1000 ),
			'context'     => false === $json ? '{}' : $json,
		);

		return array(
			'data'    => $data,
			'formats' => array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ),
		);
	}

	/**
	 * Convert a stored row into admin-safe data.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function public_row( array $row ): array {
		$context = json_decode( (string) ( $row['context'] ?? '{}' ), true );

		return array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'created_at'  => (string) ( $row['created_at'] ?? '' ),
			'provider'    => (string) ( $row['provider'] ?? '' ),
			'client_id'   => (string) ( $row['client_id'] ?? '' ),
			'client_name' => (string) ( $row['client_name'] ?? '' ),
			'user_id'     => null === $row['user_id'] ? null : (int) $row['user_id'],
			'action'      => (string) ( $row['action'] ?? '' ),
			'target_type' => (string) ( $row['target_type'] ?? '' ),
			'target_id'   => null === $row['target_id'] ? null : (int) $row['target_id'],
			'status'      => (string) ( $row['status'] ?? 'success' ),
			'error_code'  => (string) ( $row['error_code'] ?? '' ),
			'message'     => (string) ( $row['message'] ?? '' ),
			'context'     => is_array( $context ) ? $context : array(),
		);
	}

	/**
	 * Build a safe SQL WHERE clause for admin filters.
	 *
	 * @param array<string, mixed> $filters Raw filters.
	 * @return array{sql: string, values: array<int, mixed>}
	 */
	private function where_clause( array $filters ): array {
		global $wpdb;

		$clauses = array();
		$values  = array();

		$action = $this->action_filter( $filters['action'] ?? '' );
		if ( '' !== $action ) {
			$clauses[] = 'action = %s';
			$values[]  = $action;
		}

		$status = $this->status_filter( $filters['status'] ?? '' );
		if ( '' !== $status ) {
			$clauses[] = 'status = %s';
			$values[]  = $status;
		}

		$user_id = absint( $filters['user_id'] ?? 0 );
		if ( $user_id > 0 ) {
			$clauses[] = 'user_id = %d';
			$values[]  = $user_id;
		}

		$assistant = $this->nullable_text( $filters['assistant'] ?? null, 100 );
		if ( null !== $assistant ) {
			$clauses[] = '(client_id LIKE %s OR client_name LIKE %s OR provider LIKE %s)';
			$like      = '%' . $wpdb->esc_like( $assistant ) . '%';
			$values[]  = $like;
			$values[]  = $like;
			$values[]  = $like;
		}

		return array(
			'sql'    => array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * Normalize action names.
	 *
	 * @param string $action Raw action.
	 */
	private function action( string $action ): string {
		$action = strtolower( preg_replace( '/[^a-zA-Z0-9_.-]/', '', $action ) ?? '' );

		return '' === $action ? 'ai.activity' : substr( $action, 0, 100 );
	}

	/**
	 * Normalize an action filter without applying a default.
	 *
	 * @param mixed $action Raw action.
	 */
	private function action_filter( mixed $action ): string {
		if ( ! is_scalar( $action ) ) {
			return '';
		}

		return strtolower( preg_replace( '/[^a-zA-Z0-9_.-]/', '', (string) $action ) ?? '' );
	}

	/**
	 * Normalize activity status.
	 *
	 * @param string $status Raw status.
	 */
	private function status( string $status ): string {
		$status = sanitize_key( strtolower( $status ) );

		return in_array( $status, array( 'success', 'error' ), true ) ? $status : 'success';
	}

	/**
	 * Normalize a status filter without applying a default.
	 *
	 * @param mixed $status Raw status.
	 */
	private function status_filter( mixed $status ): string {
		$status = is_scalar( $status ) ? sanitize_key( strtolower( (string) $status ) ) : '';

		return in_array( $status, array( 'success', 'error' ), true ) ? $status : '';
	}

	/**
	 * Normalize provider/error/target key values.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Maximum length.
	 */
	private function nullable_key( mixed $value, int $limit ): ?string {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

		return '' === $value ? null : substr( $value, 0, $limit );
	}

	/**
	 * Normalize nullable text values.
	 *
	 * @param mixed $value Raw text.
	 * @param int   $limit Maximum length.
	 */
	private function nullable_text( mixed $value, int $limit ): ?string {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		return '' === $value ? null : substr( $value, 0, $limit );
	}
}

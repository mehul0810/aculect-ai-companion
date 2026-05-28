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
	 * Return aggregate activity counts for the admin dashboard.
	 *
	 * @param array<string, mixed> $filters Activity filters.
	 * @return array<string, int>
	 */
	public function summary( array $filters = array() ): array {
		global $wpdb;

		$where                  = $this->where_clause( $filters );
		$table                  = Installer::table_name();
		$high_risk_patterns     = array(
			'%"risk_level":"publish"%',
			'%"risk_level":"destructive"%',
			'%"risk_level":"system"%',
		);
		$activity_type_patterns = array(
			'content.%',
			'taxonomy.%',
			'comment.%',
			'media.%',
		);
		$row                    = $wpdb->get_row(
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- WHERE clause is built from fixed fragments and placeholder values in where_clause().
			$wpdb->prepare(
				"SELECT COUNT(*) AS total,
					SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS successes,
					SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS failures,
					COUNT(DISTINCT NULLIF(COALESCE(NULLIF(client_name, ''), NULLIF(client_id, ''), provider, ''), '')) AS assistants,
					SUM(CASE WHEN context LIKE %s OR context LIKE %s OR context LIKE %s THEN 1 ELSE 0 END) AS high_risk,
					SUM(CASE WHEN target_type IN ('post', 'page', 'term', 'taxonomy') OR action LIKE %s OR action LIKE %s THEN 1 ELSE 0 END) AS content_actions,
					SUM(CASE WHEN target_type = 'comment' OR action LIKE %s THEN 1 ELSE 0 END) AS comment_actions,
					SUM(CASE WHEN target_type = 'attachment' OR action LIKE %s THEN 1 ELSE 0 END) AS media_actions
				FROM %i {$where['sql']}",
				...array_merge( $high_risk_patterns, $activity_type_patterns, array( $table ), $where['values'] )
			),
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();

		return array(
			'total'      => (int) ( $row['total'] ?? 0 ),
			'successes'  => (int) ( $row['successes'] ?? 0 ),
			'failures'   => (int) ( $row['failures'] ?? 0 ),
			'assistants' => (int) ( $row['assistants'] ?? 0 ),
			'highRisk'   => (int) ( $row['high_risk'] ?? 0 ),
			'content'    => (int) ( $row['content_actions'] ?? 0 ),
			'comments'   => (int) ( $row['comment_actions'] ?? 0 ),
			'media'      => (int) ( $row['media_actions'] ?? 0 ),
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
		$user_id = null === $row['user_id'] ? null : (int) $row['user_id'];
		$user    = null !== $user_id ? get_user_by( 'id', $user_id ) : false;

		return array(
			'id'          => (int) ( $row['id'] ?? 0 ),
			'created_at'  => (string) ( $row['created_at'] ?? '' ),
			'provider'    => (string) ( $row['provider'] ?? '' ),
			'client_id'   => (string) ( $row['client_id'] ?? '' ),
			'client_name' => (string) ( $row['client_name'] ?? '' ),
			'user_id'     => $user_id,
			'user'        => $user ? $user->display_name : '',
			'action'      => (string) ( $row['action'] ?? '' ),
			'target_type' => (string) ( $row['target_type'] ?? '' ),
			'target_id'   => null === $row['target_id'] ? null : (int) $row['target_id'],
			'status'      => (string) ( $row['status'] ?? 'success' ),
			'error_code'  => (string) ( $row['error_code'] ?? '' ),
			'message'     => (string) ( $row['message'] ?? '' ),
			'context'     => is_array( $context ) ? $context : array(),
			'risk_level'  => is_array( $context ) && isset( $context['risk_level'] ) && is_scalar( $context['risk_level'] ) ? sanitize_key( (string) $context['risk_level'] ) : '',
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

		$search = $this->nullable_text( $filters['search'] ?? null, 100 );
		if ( null !== $search ) {
			$clauses[] = '(action LIKE %s OR target_type LIKE %s OR error_code LIKE %s OR message LIKE %s OR client_name LIKE %s OR client_id LIKE %s OR provider LIKE %s)';
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$values[]  = $like;
			$values[]  = $like;
			$values[]  = $like;
			$values[]  = $like;
			$values[]  = $like;
			$values[]  = $like;
			$values[]  = $like;
		}

		$since = $this->range_cutoff( $filters['range'] ?? '' );
		if ( '' !== $since ) {
			$clauses[] = 'created_at >= %s';
			$values[]  = $since;
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
	 * Convert an activity range filter into a UTC cutoff.
	 *
	 * @param mixed $range Raw range.
	 */
	private function range_cutoff( mixed $range ): string {
		$range   = is_scalar( $range ) ? sanitize_key( strtolower( (string) $range ) ) : '';
		$seconds = match ( $range ) {
			'24h' => 86400,
			'7d'  => 7 * 86400,
			'30d' => 30 * 86400,
			'90d' => 90 * 86400,
			default => 0,
		};

		return $seconds > 0 ? gmdate( 'Y-m-d H:i:s', time() - $seconds ) : '';
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

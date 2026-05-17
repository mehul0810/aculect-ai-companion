<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

use Aculect\AICompanion\Diagnostics\Database\Installer;

/**
 * Persists diagnostic connection logs in the plugin-owned log table.
 */
final class LogRepository implements LogSinkInterface {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostic logs use a plugin-owned operational table and must reflect current connection state.

	private const DEFAULT_LIMIT = 50;
	private const MAX_LIMIT     = 100;

	/**
	 * Persist one diagnostic log entry.
	 *
	 * @param array<string, mixed> $entry Log entry data.
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
	 * Return diagnostic log rows for the admin UI.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Rows per page.
	 * @return array<int, array<string, mixed>>
	 */
	public function list( int $page = 1, int $per_page = self::DEFAULT_LIMIT ): array {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = min( self::MAX_LIMIT, max( 1, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = Installer::table_name();
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
				$table,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_map( array( $this, 'public_row' ), $rows ) : array();
	}

	/**
	 * Count stored diagnostic log rows.
	 */
	public function count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Installer::table_name() ) );
	}

	/**
	 * Clear all diagnostic log rows.
	 */
	public function clear(): int {
		global $wpdb;

		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Installer::table_name() ) );

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Prune expired diagnostic log entries.
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
	 * @param array<string, mixed> $entry Log entry data.
	 * @return array{data: array<string, mixed>, formats: string[]}
	 */
	private function prepare_entry( array $entry ): array {
		$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? ( new LogSanitizer() )->sanitize_context( $entry['context'] ) : array();
		$json    = wp_json_encode( $context );

		$data = array(
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			'level'          => $this->level( (string) ( $entry['level'] ?? 'info' ) ),
			'event'          => $this->event( (string) ( $entry['event'] ?? 'connection.event' ) ),
			'provider'       => $this->nullable_key( $entry['provider'] ?? null ),
			'request_method' => $this->request_method( $entry['request_method'] ?? null ),
			'request_route'  => $this->nullable_text( $entry['request_route'] ?? null, 255 ),
			'http_status'    => isset( $entry['http_status'] ) ? absint( $entry['http_status'] ) : null,
			'error_code'     => $this->nullable_key( $entry['error_code'] ?? null ),
			'message'        => $this->nullable_text( $entry['message'] ?? null, 1000 ),
			'context'        => false === $json ? '{}' : $json,
		);

		return array(
			'data'    => $data,
			'formats' => array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ),
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
			'id'             => (int) ( $row['id'] ?? 0 ),
			'created_at'     => (string) ( $row['created_at'] ?? '' ),
			'level'          => (string) ( $row['level'] ?? 'info' ),
			'event'          => (string) ( $row['event'] ?? '' ),
			'provider'       => (string) ( $row['provider'] ?? '' ),
			'request_method' => (string) ( $row['request_method'] ?? '' ),
			'request_route'  => (string) ( $row['request_route'] ?? '' ),
			'http_status'    => null === $row['http_status'] ? null : (int) $row['http_status'],
			'error_code'     => (string) ( $row['error_code'] ?? '' ),
			'message'        => (string) ( $row['message'] ?? '' ),
			'context'        => is_array( $context ) ? $context : array(),
		);
	}

	/**
	 * Normalize log level.
	 *
	 * @param string $level Raw level.
	 */
	private function level( string $level ): string {
		$level = sanitize_key( strtolower( $level ) );

		return in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info';
	}

	/**
	 * Normalize event names.
	 *
	 * @param string $event Raw event name.
	 */
	private function event( string $event ): string {
		$event = strtolower( preg_replace( '/[^a-zA-Z0-9_.-]/', '', $event ) ?? '' );

		return '' === $event ? 'connection.event' : substr( $event, 0, 80 );
	}

	/**
	 * Normalize provider/error key values.
	 *
	 * @param mixed $value Raw value.
	 */
	private function nullable_key( mixed $value ): ?string {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

		return '' === $value ? null : substr( $value, 0, 100 );
	}

	/**
	 * Normalize request method values.
	 *
	 * @param mixed $value Raw method.
	 */
	private function request_method( mixed $value ): ?string {
		$value = is_scalar( $value ) ? strtoupper( preg_replace( '/[^A-Z]/', '', strtoupper( (string) $value ) ) ?? '' ) : '';

		return '' === $value ? null : substr( $value, 0, 10 );
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

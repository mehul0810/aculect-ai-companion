<?php

declare(strict_types=1);

/**
 * Stateful wpdb double for pending MCP approval store tests.
 *
 * @package Aculect\AICompanion\Tests\Support
 */
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Commenting.DocComment.MissingShort, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.IncorrectTypeHint -- Focused wpdb fixture.
final class McpApprovalWpdb {
	public string $prefix     = 'wp_';
	public int $insert_id     = 0;
	public string $last_error = '';
	public bool $table_exists = false;
	/** @var array<int,array<string,mixed>> */
	public array $rows = array();
	/** @var list<array<string,mixed>> */
	public array $activity_rows = array();
	public string $last_query   = '';
	/** @var list<mixed> */
	public array $last_args = array();
	private int $next_id    = 1;

	public function prepare( string $query, mixed ...$args ): string {
		$this->last_query = $query;
		$this->last_args  = array_values( $args );
		return $query;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	public function get_var( string $query ): string|null {
		unset( $query );
		if ( str_starts_with( $this->last_query, 'SELECT COUNT(*)' ) ) {
			[ , $blog, $user, $pending, $approved, $now ] = $this->last_args;
			$count                                        = count(
				array_filter(
					$this->rows,
					static fn ( array $row ): bool => (int) $row['blog_id'] === (int) $blog
						&& (int) $row['user_id'] === (int) $user
						&& in_array( $row['status'], array( $pending, $approved ), true )
						&& $row['expires_at'] > $now
				)
			);
			return (string) $count;
		}
		return $this->table_exists ? stripcslashes( (string) ( $this->last_args[0] ?? '' ) ) : null;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	/**
	 * Insert a row into the fake table.
	 *
	 * @param string              $table   Table name.
	 * @param array<string,mixed> $data    Row data.
	 * @param list<string>        $formats Value formats.
	 */
	public function insert( string $table, array $data, array $formats = array() ): int|false {
		unset( $formats );
		if ( $table !== $this->prefix . 'aculect_ai_companion_pending_approvals' ) {
			if ( $table === $this->prefix . 'aculect_ai_companion_activity' ) {
				$this->activity_rows[] = $data;
			}
			return 1;
		}
		if ( ! $this->table_exists ) {
			return false;
		}
		$id                = $this->next_id++;
		$this->rows[ $id ] = array_merge( array( 'id' => $id ), $data );
		$this->insert_id   = $id;
		return 1;
	}

	/**
	 * Apply a post-table update to the WordPress post fixture.
	 *
	 * @param string              $table Table name.
	 * @param array<string,mixed> $data Data to update.
	 * @param array<string,mixed> $where Update selectors.
	 * @param list<string>        $formats Data formats.
	 * @param list<string>        $where_formats Selector formats.
	 */
	public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): int|false {
		unset( $formats, $where_formats );
		if ( $table !== $this->prefix . 'posts' ) {
			return 1;
		}
		$post_id = (int) ( $where['ID'] ?? 0 );
		$post    = $GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] ?? null;
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		foreach ( $data as $field => $value ) {
			if ( property_exists( $post, $field ) ) {
				$post->{$field} = $value;
			}
		}
		return 1;
	}

	/** @return list<array<string,mixed>>|null */
	public function get_results( string $query, mixed $output = null ): ?array {
		unset( $query, $output );
		if ( ! $this->table_exists ) {
			return null;
		}
		[ , $blog, $user, $status, $now, $limit ] = $this->last_args;
		$rows                                     = array_values(
			array_filter(
				$this->rows,
				static fn ( array $row ): bool => (int) $row['blog_id'] === (int) $blog
					&& (int) $row['user_id'] === (int) $user
					&& $row['status'] === $status
					&& $row['expires_at'] > $now
			)
		);
		usort( $rows, static fn ( array $a, array $b ): int => strcmp( $b['created_at'], $a['created_at'] ) );
		return array_slice( $rows, 0, (int) $limit );
	}

	/** @return array<string,mixed>|null */
	public function get_row( string $query, mixed $output = null ): ?array {
		unset( $query, $output );
		if ( ! $this->table_exists ) {
			return null;
		}
		if ( str_contains( $this->last_query, 'operation_fingerprint = %s' ) ) {
			[ , $blog, $user, $client, $session, $fingerprint, $pending, $approved, $declined, $consumed, $now ] = $this->last_args;
			foreach ( array_reverse( $this->rows, true ) as $row ) {
				if ( (int) $row['blog_id'] === (int) $blog && (int) $row['user_id'] === (int) $user && $row['client_hash'] === $client && $row['session_hash'] === $session && $row['operation_fingerprint'] === $fingerprint && in_array( $row['status'], array( $pending, $approved, $declined, $consumed ), true ) && $row['expires_at'] > $now ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $this->last_query, 'client_hash = %s' ) ) {
			[ , $id, $blog, $user, $client, $session, $status, $now ] = $this->last_args;
			$row = $this->rows[ (int) $id ] ?? null;
			return is_array( $row )
				&& (int) $row['blog_id'] === (int) $blog
				&& (int) $row['user_id'] === (int) $user
				&& $row['client_hash'] === $client
				&& $row['session_hash'] === $session
				&& $row['status'] === $status
				&& $row['expires_at'] > $now
				? $row
				: null;
		}
		[ , $id, $blog, $user, $status, $now ] = $this->last_args;
		$row                                   = $this->rows[ (int) $id ] ?? null;
		return is_array( $row )
			&& (int) $row['blog_id'] === (int) $blog
			&& (int) $row['user_id'] === (int) $user
			&& $row['status'] === $status
			&& $row['expires_at'] > $now
			? $row
			: null;
	}

	public function query( string $query ): int|false {
		$args        = $this->last_args;
		$id_argument = str_contains( $query, 'SET status = %s, decided_at = %s WHERE id = %d' ) ? 3 : 4;
		if ( str_starts_with( $query, 'UPDATE ' ) && isset( $this->rows[ (int) ( $args[ $id_argument ] ?? 0 ) ] ) ) {
			if ( str_contains( $query, 'session_hash = %s' ) ) {
				if ( str_contains( $query, 'SET status = %s, decided_at = %s WHERE id = %d' ) ) {
					[ , $decision, $now, $id, $blog, $user, $client, $session, $fingerprint, $status, $expires ] = $args;
					$row = &$this->rows[ (int) $id ];
					if ( (int) $row['blog_id'] !== (int) $blog || (int) $row['user_id'] !== (int) $user || $row['client_hash'] !== $client || $row['session_hash'] !== $session || $row['operation_fingerprint'] !== $fingerprint || $row['status'] !== $status || $row['expires_at'] <= $expires ) {
						return 0;
					}
					$row['status']     = $decision;
					$row['decided_at'] = $now;
					return 1;
				}
			}
			[ , $decision, $now, $actor, $id, $blog, $user, $client, $fingerprint, $status, $expires ] = $args;
			$row = &$this->rows[ (int) $id ];
			if ( (int) $row['blog_id'] !== (int) $blog || (int) $row['user_id'] !== (int) $user || (int) $user !== (int) $actor || $row['client_hash'] !== $client || $row['operation_fingerprint'] !== $fingerprint || $row['status'] !== $status || $row['expires_at'] <= $expires ) {
				return 0;
			}
			$row['status']     = $decision;
			$row['decided_at'] = $now;
			$row['decided_by'] = $actor;
			return 1;
		}
		if ( str_starts_with( $query, 'DELETE ' ) ) {
			[ , $now, $limit ] = array_pad( $args, 3, 0 );
			$removed           = 0;
			foreach ( $this->rows as $id => $row ) {
				if ( $removed >= (int) $limit ) {
					break;
				}
				if ( $row['expires_at'] <= $now ) {
					unset( $this->rows[ $id ] );
					++$removed;
				}
			}
			return $removed;
		}
		return false;
	}
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- The conditional WordPress salt stub belongs to this focused fixture.
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return (string) ( $GLOBALS['mcp_approval_test_salt'] ?? 'unit-test-only-stable-salt-' . $scheme );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/** Sanitize plain-text test summaries while retaining line breaks. */
	function sanitize_textarea_field( string $text ): string {
		return trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace( array( "\r\n", "\r" ), "\n", wp_strip_all_tags( $text ) ) ) ?? '' );
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	/**
	 * Register a scheduled test event.
	 *
	 * @param int     $timestamp Schedule time.
	 * @param string  $recurrence Recurrence name.
	 * @param string  $hook Event hook.
	 * @param mixed[] $args Event arguments.
	 * @param bool    $wp_error Whether to return errors.
	 * @phpstan-param array<mixed> $args
	 */
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array(), bool $wp_error = false ): bool {
		unset( $recurrence, $args, $wp_error );
		if ( ! empty( $GLOBALS['mcp_approval_schedule_failure'] ) ) {
			return false;
		}
		$GLOBALS['aculect_ai_companion_test_scheduled_events'][ $hook ] = $timestamp;
		return true;
	}
}
// phpcs:enable

// phpcs:enable

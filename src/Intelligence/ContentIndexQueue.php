<?php
/**
 * Durable per-object queue for deferred content indexing.
 *
 * @package Aculect\AICompanion\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence;

/**
 * Stores one non-autoloaded queue row and lease per pending object.
 */
final class ContentIndexQueue {

	private const LEGACY_OPTION     = 'aculect_ai_companion_pending_index_ids';
	private const QUEUE_PREFIX      = 'aculect_ai_companion_pending_index_';
	private const LOCK_PREFIX       = 'aculect_ai_companion_index_lock_';
	private const CURSOR_OPTION     = 'aculect_ai_companion_index_queue_cursor';
	private const DEFAULT_LEASE_TTL = 300;
	private const MAX_CLAIM_LIMIT   = 100;
	private const MAX_RETRY_DELAY   = 3600;

	/**
	 * Add or refresh one pending object without a shared read/modify/write list.
	 *
	 * @param int $object_id WordPress object ID.
	 */
	public function enqueue( int $object_id ): bool {
		return '' !== $this->enqueue_generation( $object_id );
	}

	/**
	 * Add or refresh one pending object and return its generation token.
	 *
	 * @param int $object_id WordPress object ID.
	 */
	public function enqueue_generation( int $object_id ): string {
		$object_id = absint( $object_id );
		if ( 0 >= $object_id ) {
			return '';
		}

		$this->migrate_legacy_option();
		$generation = $this->queue_state();
		if ( ! $this->replace_queue_generation( $object_id, $generation ) ) {
			return '';
		}

		return $generation;
	}

	/**
	 * Claim a bounded batch with per-object expiring leases.
	 *
	 * @param int $limit Maximum rows to claim.
	 * @return list<array{object_id: int, queue_token: string, lock_token: string, action: string}>
	 */
	public function claim( int $limit ): array {
		$this->migrate_legacy_option();

		$limit   = max( 1, min( self::MAX_CLAIM_LIMIT, $limit ) );
		$claimed = array();
		foreach ( $this->pending_object_ids( $limit ) as $object_id ) {
			$queue_token = $this->read_queue_generation( $object_id );
			if ( '' === $queue_token ) {
				continue;
			}
			$state = $this->parse_queue_state( $queue_token );
			if ( $state['available_at'] > time() ) {
				continue;
			}

			$lock_token = $this->claim_lock( $object_id );
			if ( '' === $lock_token ) {
				continue;
			}

			$claimed[] = array(
				'object_id'   => $object_id,
				'queue_token' => $queue_token,
				'lock_token'  => $lock_token,
				'action'      => $state['action'],
			);
		}

		return $claimed;
	}

	/**
	 * Acknowledge one successful generation without deleting a newer enqueue.
	 *
	 * @param int    $object_id   WordPress object ID.
	 * @param string $queue_token Claimed queue generation.
	 * @param string $lock_token  Claimed lease token.
	 */
	public function acknowledge( int $object_id, string $queue_token, string $lock_token ): bool {
		$deleted = $this->clear_generation( $object_id, $queue_token );
		$this->delete_option_if_value( $this->lock_key( $object_id ), $lock_token );

		return $deleted;
	}

	/**
	 * Remove exactly one unclaimed queue generation.
	 *
	 * @param int    $object_id   WordPress object ID.
	 * @param string $queue_token Expected queue generation.
	 */
	public function clear_generation( int $object_id, string $queue_token ): bool {
		return '' !== $queue_token
			&& $this->delete_option_if_value( $this->queue_key( $object_id ), $queue_token );
	}

	/**
	 * Release a failed item with bounded exponential backoff.
	 *
	 * @param int    $object_id   WordPress object ID.
	 * @param string $queue_token Claimed queue generation.
	 * @param string $lock_token  Claimed lease token.
	 */
	public function retry( int $object_id, string $queue_token, string $lock_token ): void {
		$state    = $this->parse_queue_state( $queue_token );
		$attempts = $state['attempts'] + 1;
		$delay    = min( self::MAX_RETRY_DELAY, 30 * ( 2 ** min( 6, $attempts - 1 ) ) );
		$this->update_option_if_value(
			$this->queue_key( $object_id ),
			$queue_token,
			$this->queue_state( $attempts, time() + $delay, $state['action'] )
		);
		$this->delete_option_if_value( $this->lock_key( $object_id ), $lock_token );
	}

	/**
	 * Release an unstarted claim while preserving its queued generation.
	 *
	 * @param int    $object_id  WordPress object ID.
	 * @param string $lock_token Claimed lease token.
	 */
	public function release_claim( int $object_id, string $lock_token ): void {
		$this->delete_option_if_value( $this->lock_key( $object_id ), $lock_token );
	}

	/**
	 * Atomically replace pending work with a durable deletion tombstone.
	 *
	 * @param int $object_id WordPress object ID.
	 */
	public function invalidate_for_delete( int $object_id ): string {
		$object_id = absint( $object_id );
		if ( 0 >= $object_id ) {
			return '';
		}

		$tombstone = $this->queue_state( 0, 0, 'delete' );
		if ( ! $this->replace_queue_generation( $object_id, $tombstone ) ) {
			return '';
		}

		return $tombstone;
	}

	/**
	 * Return the current queue generation and action for one object.
	 *
	 * @param int $object_id WordPress object ID.
	 * @return array{queue_token: string, action: string}
	 */
	public function current_generation( int $object_id ): array {
		$queue_token = $this->read_queue_generation( $object_id );
		$state       = $this->parse_queue_state( $queue_token );

		return array(
			'queue_token' => $queue_token,
			'action'      => $state['action'],
		);
	}

	/**
	 * Return the durable pending row count.
	 */
	public function pending_count(): int {
		$this->migrate_legacy_option();

		if ( $this->uses_test_options() ) {
			return count( $this->test_option_names( self::QUEUE_PREFIX ) );
		}

		global $wpdb;

		$like = $wpdb->esc_like( self::QUEUE_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded count over non-autoloaded plugin-owned queue options.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);
	}

	/**
	 * Delete all queue and lease rows during uninstall.
	 */
	public static function delete_all(): void {
		if ( isset( $GLOBALS['aculect_ai_companion_test_options'] ) && is_array( $GLOBALS['aculect_ai_companion_test_options'] ) ) {
			foreach ( array_keys( $GLOBALS['aculect_ai_companion_test_options'] ) as $option_name ) {
				if ( self::is_owned_option_name( (string) $option_name ) ) {
					unset( $GLOBALS['aculect_ai_companion_test_options'][ $option_name ] );
				}
			}
			return;
		}

		global $wpdb;

		$queue_like = $wpdb->esc_like( self::QUEUE_PREFIX ) . '%';
		$lock_like  = $wpdb->esc_like( self::LOCK_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Capture plugin-owned keys so persistent option-cache entries can be invalidated after uninstall.
		$owned_option_names = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name IN (%s, %s) OR option_name LIKE %s OR option_name LIKE %s",
				self::LEGACY_OPTION,
				self::CURSOR_OPTION,
				$queue_like,
				$lock_like
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes only plugin-owned queue options.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s) OR option_name LIKE %s OR option_name LIKE %s",
				self::LEGACY_OPTION,
				self::CURSOR_OPTION,
				$queue_like,
				$lock_like
			)
		);
		if ( function_exists( 'wp_cache_delete' ) ) {
			foreach ( $owned_option_names as $option_name ) {
				wp_cache_delete( (string) $option_name, 'options' );
			}
		}
	}

	/**
	 * Return bounded pending object IDs in stable option order.
	 *
	 * @param int $limit Maximum IDs.
	 * @return list<int>
	 */
	private function pending_object_ids( int $limit ): array {
		$cursor = (string) get_option( self::CURSOR_OPTION, '' );
		$names  = $this->option_names_after( $cursor, $limit );
		if ( count( $names ) < $limit && '' !== $cursor ) {
			$names = array_values(
				array_unique(
					array_merge(
						$names,
						$this->option_names_after( '', $limit - count( $names ), $cursor )
					)
				)
			);
		}

		if ( array() !== $names ) {
			update_option( self::CURSOR_OPTION, (string) end( $names ), false );
		}

		$ids = array_map(
			static fn ( string $name ): int => absint( substr( $name, strlen( self::QUEUE_PREFIX ) ) ),
			array_map( 'strval', $names )
		);

		return array_values( array_filter( $ids ) );
	}

	/**
	 * Return queue option names after one stable cursor.
	 *
	 * @param string $cursor       Last visited option name.
	 * @param int    $limit        Maximum names.
	 * @param string $upper_bound  Optional inclusive wrap boundary.
	 * @return list<string>
	 */
	private function option_names_after( string $cursor, int $limit, string $upper_bound = '' ): array {
		if ( $this->uses_test_options() ) {
			return array_slice(
				array_values(
					array_filter(
						$this->test_option_names( self::QUEUE_PREFIX ),
						static fn ( string $name ): bool => $name > $cursor && ( '' === $upper_bound || $name <= $upper_bound )
					)
				),
				0,
				$limit
			);
		}

		global $wpdb;

		$like = $wpdb->esc_like( self::QUEUE_PREFIX ) . '%';
		if ( '' === $upper_bound ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core options table is trusted; values are prepared and keyset pagination is bounded.
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name ASC LIMIT %d",
					$like,
					$cursor,
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core options table is trusted; values are prepared and keyset pagination is bounded.
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s AND option_name <= %s ORDER BY option_name ASC LIMIT %d",
					$like,
					$cursor,
					$upper_bound,
					$limit
				)
			);
		}

		return array_values( array_map( 'strval', (array) $names ) );
	}

	/**
	 * Acquire or recover one expiring lease.
	 *
	 * @param int $object_id WordPress object ID.
	 */
	private function claim_lock( int $object_id ): string {
		$key        = $this->lock_key( $object_id );
		$lock_token = $this->token() . ':' . ( time() + self::DEFAULT_LEASE_TTL );
		if ( $this->add_lock_option( $key, $lock_token ) ) {
			return $lock_token;
		}

		$current = (string) get_option( $key, '' );
		$parts   = explode( ':', $current );
		$expires = absint( end( $parts ) );
		if ( 0 < $expires && $expires >= time() ) {
			return '';
		}

		return $this->update_option_if_value( $key, $current, $lock_token ) ? $lock_token : '';
	}

	/**
	 * Add one non-autoloaded lock row if it does not already exist.
	 *
	 * @param string $option_name Lock option name.
	 * @param string $lock_token  Unique expiring lock token.
	 */
	private function add_lock_option( string $option_name, string $lock_token ): bool {
		return add_option( $option_name, $lock_token, '', false );
	}

	/**
	 * Delete an option only if its generation value still matches.
	 *
	 * @param string $option_name Option name.
	 * @param string $value       Expected stored value.
	 */
	private function delete_option_if_value( string $option_name, string $value ): bool {
		if ( $this->uses_test_options() ) {
			$current = (string) get_option( $option_name, '' );
			if ( ! hash_equals( $value, $current ) ) {
				return false;
			}

			return delete_option( $option_name );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete acknowledges only the claimed queue generation.
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $option_name,
				'option_value' => $value,
			),
			array( '%s', '%s' )
		);

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option_name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}

		return 1 === (int) $deleted;
	}

	/**
	 * Replace an option only if its claimed generation still matches.
	 *
	 * @param string $option_name Option name.
	 * @param string $old_value   Expected stored value.
	 * @param string $new_value   Replacement value.
	 */
	private function update_option_if_value( string $option_name, string $old_value, string $new_value ): bool {
		if ( $this->uses_test_options() ) {
			$current = (string) get_option( $option_name, '' );
			if ( ! hash_equals( $old_value, $current ) ) {
				return false;
			}

			return update_option( $option_name, $new_value, false );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Conditional update preserves newer queue generations.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_value,
				$option_name,
				$old_value
			)
		);

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option_name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}

		return 1 === (int) $updated;
	}

	/**
	 * Migrate the former shared pending-ID list without dropping queued work.
	 */
	private function migrate_legacy_option(): void {
		$legacy = get_option( self::LEGACY_OPTION, array() );
		if ( ! is_array( $legacy ) || array() === $legacy ) {
			return;
		}

		$migrated = true;
		foreach ( array_values( array_unique( array_filter( array_map( 'absint', $legacy ) ) ) ) as $object_id ) {
			$migrated = $this->replace_queue_generation( $object_id, $this->queue_state() ) && $migrated;
		}

		if ( $migrated ) {
			delete_option( self::LEGACY_OPTION );
		}
	}

	/**
	 * Check whether the PHPUnit option store is active.
	 */
	private function uses_test_options(): bool {
		return isset( $GLOBALS['aculect_ai_companion_test_options'] ) && is_array( $GLOBALS['aculect_ai_companion_test_options'] );
	}

	/**
	 * Read the authoritative generation without allowing a stale cache republish.
	 *
	 * @param int $object_id WordPress object ID.
	 */
	private function read_queue_generation( int $object_id ): string {
		$option_name = $this->queue_key( $object_id );
		if ( $this->uses_test_options() ) {
			return (string) get_option( $option_name, '' );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Queue correctness requires the current database generation; claims are bounded to five rows per sweep.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Atomically replace one generation without publishing a stale cache value.
	 *
	 * @param int    $object_id WordPress object ID.
	 * @param string $generation New queue generation.
	 */
	private function replace_queue_generation( int $object_id, string $generation ): bool {
		$option_name = $this->queue_key( $object_id );
		if ( $this->uses_test_options() ) {
			return update_option( $option_name, $generation, false );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One atomic upsert makes the last queue generation authoritative; caches are invalidated below.
		$replaced = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
				$option_name,
				$generation
			)
		);
		if ( false === $replaced ) {
			return false;
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option_name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}

		return true;
	}

	/**
	 * Return sorted test option names with one prefix.
	 *
	 * @param string $prefix Option prefix.
	 * @return list<string>
	 */
	private function test_option_names( string $prefix ): array {
		$names = array_values(
			array_filter(
				array_map( 'strval', array_keys( $GLOBALS['aculect_ai_companion_test_options'] ?? array() ) ),
				static fn ( string $name ): bool => str_starts_with( $name, $prefix )
			)
		);
		sort( $names );

		return $names;
	}

	/**
	 * Return a queue option key for one object.
	 *
	 * @param int $object_id WordPress object ID.
	 */
	private function queue_key( int $object_id ): string {
		return self::QUEUE_PREFIX . absint( $object_id );
	}

	/**
	 * Return a lease option key for one object.
	 *
	 * @param int $object_id WordPress object ID.
	 */
	private function lock_key( int $object_id ): string {
		return self::LOCK_PREFIX . absint( $object_id );
	}

	/**
	 * Return a unique generation token.
	 */
	private function token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Build one client-invisible queue state value.
	 *
	 * @param int    $attempts     Consecutive failures.
	 * @param int    $available_at Earliest retry timestamp.
	 * @param string $action       Queue action: index or delete.
	 */
	private function queue_state( int $attempts = 0, int $available_at = 0, string $action = 'index' ): string {
		$state = wp_json_encode(
			array(
				'token'        => $this->token(),
				'attempts'     => max( 0, $attempts ),
				'available_at' => max( 0, $available_at ),
				'action'       => 'delete' === $action ? 'delete' : 'index',
			)
		);

		return false === $state ? $this->token() : $state;
	}

	/**
	 * Parse one queue state, including legacy raw tokens.
	 *
	 * @param string $value Stored queue value.
	 * @return array{attempts: int, available_at: int, action: string}
	 */
	private function parse_queue_state( string $value ): array {
		$state = json_decode( $value, true );

		return array(
			'attempts'     => is_array( $state ) ? absint( $state['attempts'] ?? 0 ) : 0,
			'available_at' => is_array( $state ) ? absint( $state['available_at'] ?? 0 ) : 0,
			'action'       => is_array( $state ) && 'delete' === ( $state['action'] ?? '' ) ? 'delete' : 'index',
		);
	}

	/**
	 * Check whether an option belongs to the queue.
	 *
	 * @param string $option_name Option name.
	 */
	private static function is_owned_option_name( string $option_name ): bool {
		return self::LEGACY_OPTION === $option_name
			|| self::CURSOR_OPTION === $option_name
			|| str_starts_with( $option_name, self::QUEUE_PREFIX )
			|| str_starts_with( $option_name, self::LOCK_PREFIX );
	}
}

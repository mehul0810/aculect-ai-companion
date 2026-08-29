<?php
/**
 * Database schema for durable custom workflow runs.
 *
 * @package Aculect\AICompanion\Workflows\Database
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Database;

/**
 * Owns site-scoped run and step state for internal custom workflows.
 *
 * Definition snapshots remain owned by Installer. This class only creates the
 * execution tables, keeping lifecycle state separate from the catalog.
 */
final class RunInstaller {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned workflow run tables require controlled schema changes.

	private const DB_VERSION                           = '2026.08.29.2';
	private const OPTION_DB_VERSION                    = 'aculect_ai_companion_workflow_runs_db_version';
	private const OPTION_ENGINE_REPAIR_LOCK            = 'aculect_ai_companion_workflow_runs_engine_repair_lock';
	private const OPTION_ENGINE_REPAIR_RETRY_AFTER     = 'aculect_ai_companion_workflow_runs_engine_repair_retry_after';
	private const ENGINE_REPAIR_LOCK_TTL               = 5 * 60;
	private const ENGINE_REPAIR_FAILURE_RETRY_INTERVAL = 5 * 60;

	/**
	 * Create or repair the workflow run tables.
	 *
	 * @return bool Whether both tables are available.
	 */
	public static function install(): bool {
		$missing = self::missing_table_keys();
		$stored  = (string) get_option( self::OPTION_DB_VERSION, '0' );
		if ( array() !== $missing || version_compare( $stored, self::DB_VERSION, '<' ) ) {
			try {
				self::create_tables();
			} catch ( \Throwable ) {
				return false;
			}

			$missing = self::missing_table_keys();
		}

		if ( array() !== $missing ) {
			return false;
		}

		if ( ! self::ensure_transactional_tables() ) {
			return false;
		}

		if ( self::DB_VERSION !== $stored ) {
			$updated = update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
			if ( ! $updated && self::DB_VERSION !== (string) get_option( self::OPTION_DB_VERSION, '0' ) ) {
				return false;
			}
		}

		return true;
	}

	/** Create tables during plugin activation. */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Remove workflow run storage and its schema option.
	 *
	 * The plugin uninstall entry point calls this only after the site owner has
	 * explicitly enabled full data removal.
	 */
	public static function uninstall(): void {
		global $wpdb;

		$tables = self::table_names();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $tables['steps'] ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $tables['runs'] ) );

		delete_option( self::OPTION_DB_VERSION );
		delete_option( self::OPTION_ENGINE_REPAIR_LOCK );
		delete_option( self::OPTION_ENGINE_REPAIR_RETRY_AFTER );
	}

	/**
	 * Return site-scoped run table names.
	 *
	 * @return array{runs:string,steps:string}
	 */
	public static function table_names(): array {
		global $wpdb;

		return array(
			'runs'  => $wpdb->prefix . 'aculect_ai_workflow_runs',
			'steps' => $wpdb->prefix . 'aculect_ai_workflow_run_steps',
		);
	}

	/**
	 * Return logical tables that are not present.
	 *
	 * @return list<string>
	 */
	public static function missing_table_keys(): array {
		global $wpdb;

		$missing = array();
		foreach ( self::table_names() as $key => $table ) {
			if ( $table !== (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Return the exact dbDelta-compatible schema.
	 *
	 * @param array{runs:string,steps:string} $tables  Table names.
	 * @param string                          $charset Charset declaration.
	 */
	public static function schema_sql( array $tables, string $charset ): string {
		$runs  = $tables['runs'];
		$steps = $tables['steps'];

		return implode(
			"\n",
			array(
				"CREATE TABLE {$runs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(64) NOT NULL,
            workflow_id varchar(64) NOT NULL,
            workflow_version int(10) unsigned NOT NULL,
            definition_checksum char(64) NOT NULL,
            plan_hash char(64) NOT NULL,
            input_hash char(64) NOT NULL,
            input_ciphertext longtext NOT NULL,
            state varchar(32) NOT NULL,
            state_version bigint(20) unsigned NOT NULL DEFAULT 1,
            outcome_code varchar(64) NOT NULL DEFAULT '',
            waiting_expires_at datetime NULL,
            approval_reference_hash char(64) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NOT NULL,
            updated_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY run_id (run_id),
            KEY workflow_state (workflow_id, state, updated_at, id),
            KEY state_updated (state, updated_at, id)
        ) ENGINE=InnoDB {$charset};",
				"CREATE TABLE {$steps} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_pk bigint(20) unsigned NOT NULL,
            step_id varchar(64) NOT NULL,
            step_position int(10) unsigned NOT NULL,
            adapter_id varchar(64) NOT NULL,
            adapter_version int(10) unsigned NOT NULL,
            ability_id varchar(128) NOT NULL,
            kind varchar(16) NOT NULL,
            state varchar(20) NOT NULL DEFAULT 'pending',
            attempt int(10) unsigned NOT NULL DEFAULT 0,
            fence bigint(20) unsigned NOT NULL DEFAULT 1,
            result_code varchar(64) NOT NULL DEFAULT '',
            output_ciphertext longtext NOT NULL,
            output_hash char(64) NOT NULL DEFAULT '',
            error_code varchar(64) NOT NULL DEFAULT '',
            lease_expires_at datetime NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY run_step (run_pk, step_id),
            KEY run_state_position (run_pk, state, step_position, id),
            KEY lease_state (state, lease_expires_at, id)
        ) ENGINE=InnoDB {$charset};",
			)
		);
	}

	/** Create tables through dbDelta(). */
	private static function create_tables(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::schema_sql( self::table_names(), $wpdb->get_charset_collate() ) );
	}

	/**
	 * Require InnoDB for the run and step tables, repairing legacy engines.
	 *
	 * Retention deletes parent and child rows in one transaction. Treating an
	 * unknown or non-transactional engine as healthy would make that contract
	 * false on older sites where MyISAM is still the default.
	 *
	 * @return bool Whether every table is authoritatively transactional.
	 */
	private static function ensure_transactional_tables(): bool {
		global $wpdb;
		if ( self::is_sqlite_backend() ) {
			// SQLite provides transactional DDL/DML semantics without MySQL table engines.
			delete_option( self::OPTION_ENGINE_REPAIR_RETRY_AFTER );
			return true;
		}
		if ( ! self::is_known_mysql_backend() ) {
			// A false or missing wpdb::is_mysql value is not proof of SQLite. An
			// unknown adapter must not be allowed to claim transactional semantics.
			return false;
		}

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'query' ) ) {
			return false;
		}

		$engines = array();
		foreach ( self::table_names() as $table ) {
			$engine = self::table_engine( $table );
			if ( '' === $engine ) {
				return false;
			}
			$engines[ $table ] = $engine;
		}

		$needs_repair = array_filter(
			$engines,
			static fn ( string $engine ): bool => 'INNODB' !== $engine
		);
		if ( array() === $needs_repair ) {
			delete_option( self::OPTION_ENGINE_REPAIR_RETRY_AFTER );
			return true;
		}

		$now          = time();
		$current_lock = self::read_repair_lock();
		if ( self::repair_lock_is_active( $current_lock, $now ) ) {
			return false;
		}

		$retry_after = get_option( self::OPTION_ENGINE_REPAIR_RETRY_AFTER, null );
		if ( null !== $retry_after ) {
			if ( self::repair_retry_is_active( $retry_after, $now ) ) {
				return false;
			}
			if ( ! self::delete_repair_retry_if_value( $retry_after ) ) {
				// A concurrent writer may have replaced the invalid value with a
				// valid backoff while this request was cleaning it up. Re-read the
				// authoritative option before deciding whether repair is allowed.
				$current_retry = get_option( self::OPTION_ENGINE_REPAIR_RETRY_AFTER, null );
				if ( null !== $current_retry ) {
					if ( self::repair_retry_is_active( $current_retry, $now ) ) {
						return false;
					}

					// An invalid value that cannot be atomically replaced is not
					// evidence that the engine repair is safe to attempt.
					return false;
				}
			}
		}

		$lock_token = self::acquire_repair_lock( $now );
		if ( '' === $lock_token ) {
			return false;
		}
		$owned_token = $lock_token;
		try {
			foreach ( $needs_repair as $table => $engine ) {
				unset( $engine );
				try {
					$altered = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
				} catch ( \Throwable ) {
					$owned_token = self::publish_repair_failure( $lock_token, $now );
					return false;
				}
				if ( false === $altered || 'INNODB' !== self::table_engine( $table ) ) {
					$owned_token = self::publish_repair_failure( $lock_token, $now );
					return false;
				}
			}

			delete_option( self::OPTION_ENGINE_REPAIR_RETRY_AFTER );
			return true;
		} finally {
			self::delete_repair_lock_if_value( $owned_token );
		}
	}

	/**
	 * Detect the supported SQLite WordPress database adapters.
	 *
	 * SQLite has no InnoDB table engine to inspect, but its transaction boundary
	 * is authoritative for the parent/child retention delete. Unknown adapters
	 * remain on the fail-closed MySQL verification path.
	 */
	private static function is_sqlite_backend(): bool {
		foreach ( array( 'DB_ENGINE', 'DATABASE_TYPE' ) as $constant ) {
			if ( defined( $constant ) && 'SQLITE' === strtoupper( (string) constant( $constant ) ) ) {
				return true;
			}
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		if ( class_exists( 'WP_SQLite_DB', false ) && $wpdb instanceof \WP_SQLite_DB ) {
			return true;
		}

		return false;
	}

	/** Return whether wpdb explicitly identifies the supported MySQL backend. */
	private static function is_known_mysql_backend(): bool {
		global $wpdb;

		return isset( $wpdb ) && is_object( $wpdb ) && property_exists( $wpdb, 'is_mysql' ) && true === $wpdb->is_mysql;
	}

	/**
	 * Acquire an expiring engine-repair lease.
	 *
	 * @param int $now Current Unix timestamp.
	 */
	private static function acquire_repair_lock( int $now ): string {
		$lock_token = self::repair_lock_token( $now + self::ENGINE_REPAIR_LOCK_TTL );
		if ( self::add_repair_lock( $lock_token ) ) {
			return $lock_token;
		}

		$current = self::read_repair_lock();
		if ( '' === $current ) {
			return '';
		}
		if ( self::repair_lock_is_active( $current, $now ) ) {
			return '';
		}

		return self::update_repair_lock_if_value( $current, $lock_token ) ? $lock_token : '';
	}

	/**
	 * Publish a bounded retry delay while retaining exact lease ownership.
	 *
	 * @param string $lock_token Exact lease owner.
	 * @param int    $now        Current Unix timestamp.
	 */
	private static function publish_repair_failure( string $lock_token, int $now ): string {
		$retry_after = $now + self::ENGINE_REPAIR_FAILURE_RETRY_INTERVAL;
		$outcome     = 'failure:' . self::repair_lock_token( $retry_after );
		if ( ! self::update_repair_lock_if_value( $lock_token, $outcome ) ) {
			return $lock_token;
		}
		update_option( self::OPTION_ENGINE_REPAIR_RETRY_AFTER, $retry_after, false );

		return $outcome;
	}

	/**
	 * Add a unique engine-repair lease row.
	 *
	 * @param string $lock_token Unique expiring lease.
	 */
	private static function add_repair_lock( string $lock_token ): bool {
		if ( self::uses_test_options() ) {
			return add_option( self::OPTION_ENGINE_REPAIR_LOCK, $lock_token, '', false );
		}

		global $wpdb;
		$added = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::OPTION_ENGINE_REPAIR_LOCK,
				$lock_token
			)
		);

		return 1 === (int) $added;
	}

	/** Read the authoritative current engine-repair lease. */
	private static function read_repair_lock(): string {
		if ( self::uses_test_options() ) {
			return (string) get_option( self::OPTION_ENGINE_REPAIR_LOCK, '' );
		}

		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION_ENGINE_REPAIR_LOCK
			)
		);

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Replace a lease only when the exact previous owner still holds it.
	 *
	 * @param string $old_value Expected owner token.
	 * @param string $new_value Replacement owner token.
	 */
	private static function update_repair_lock_if_value( string $old_value, string $new_value ): bool {
		if ( self::uses_test_options() ) {
			$current = (string) get_option( self::OPTION_ENGINE_REPAIR_LOCK, '' );
			if ( ! hash_equals( $old_value, $current ) ) {
				return false;
			}

			return update_option( self::OPTION_ENGINE_REPAIR_LOCK, $new_value, false );
		}

		global $wpdb;
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_value,
				self::OPTION_ENGINE_REPAIR_LOCK,
				$old_value
			)
		);

		return 1 === (int) $updated;
	}

	/**
	 * Release a lease only when the exact owner still holds it.
	 *
	 * @param string $lock_token Expected owner token.
	 */
	private static function delete_repair_lock_if_value( string $lock_token ): bool {
		if ( self::uses_test_options() ) {
			$current = (string) get_option( self::OPTION_ENGINE_REPAIR_LOCK, '' );
			if ( '' === $lock_token || ! hash_equals( $lock_token, $current ) ) {
				return false;
			}

			return delete_option( self::OPTION_ENGINE_REPAIR_LOCK );
		}

		global $wpdb;
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::OPTION_ENGINE_REPAIR_LOCK,
				'option_value' => $lock_token,
			),
			array( '%s', '%s' )
		);

		return 1 === (int) $deleted;
	}

	/**
	 * Remove a malformed or expired retry option only when its value is unchanged.
	 *
	 * @param mixed $retry_after Exact value observed before cleanup.
	 */
	private static function delete_repair_retry_if_value( mixed $retry_after ): bool {
		if ( self::uses_test_options() ) {
			$current = get_option( self::OPTION_ENGINE_REPAIR_RETRY_AFTER, null );
			if ( ! self::same_option_value( $retry_after, $current ) ) {
				return false;
			}

			return delete_option( self::OPTION_ENGINE_REPAIR_RETRY_AFTER );
		}

		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION_ENGINE_REPAIR_RETRY_AFTER,
				self::option_storage_value( $retry_after )
			)
		);

		return 1 === (int) $deleted;
	}

	/**
	 * Return an expiring lease token suffix, including failure outcomes.
	 *
	 * @param string $lock_token Lease token.
	 */
	private static function repair_lock_expires_at( string $lock_token ): ?int {
		$parts = explode( ':', $lock_token );
		if ( 2 === count( $parts ) ) {
			$valid = 1 === preg_match( '/^[a-f0-9]{32}$/D', $parts[0] );
			$last  = $parts[1];
		} elseif ( 3 === count( $parts ) ) {
			$valid = 'failure' === $parts[0] && 1 === preg_match( '/^[a-f0-9]{32}$/D', $parts[1] );
			$last  = $parts[2];
		} else {
			return null;
		}

		if ( ! $valid || ! is_string( $last ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $last ) ) {
			return null;
		}

		$expires_at = (int) $last;
		if ( (string) $expires_at !== $last ) {
			return null;
		}

		return $expires_at;
	}

	/**
	 * Validate a cached lease before trusting its expiry.
	 *
	 * @param string $lock_token Cached lease token.
	 * @param int    $now        Current Unix timestamp.
	 */
	private static function repair_lock_is_active( string $lock_token, int $now ): bool {
		$expires_at = self::repair_lock_expires_at( $lock_token );

		return null !== $expires_at && $expires_at >= $now && $expires_at <= $now + self::ENGINE_REPAIR_LOCK_TTL;
	}

	/**
	 * Validate a retry timestamp against the same bounded five-minute window.
	 *
	 * @param mixed $value Candidate option value.
	 * @param int   $now   Current Unix timestamp.
	 */
	private static function repair_retry_is_active( mixed $value, int $now ): bool {
		if ( is_int( $value ) ) {
			$raw = (string) $value;
		} elseif ( is_string( $value ) ) {
			$raw = $value;
		} else {
			return false;
		}

		if ( 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $raw ) ) {
			return false;
		}

		$expires_at = (int) $raw;
		if ( (string) $expires_at !== $raw ) {
			return false;
		}

		return $expires_at >= $now && $expires_at <= $now + self::ENGINE_REPAIR_FAILURE_RETRY_INTERVAL;
	}

	/**
	 * Compare test-option values without coercing malformed structures.
	 *
	 * @param mixed $left  First option value.
	 * @param mixed $right Second option value.
	 */
	private static function same_option_value( mixed $left, mixed $right ): bool {
		if ( gettype( $left ) !== gettype( $right ) ) {
			return false;
		}

		return $left === $right;
	}

	/**
	 * Convert an option value to the value stored in wp_options for CAS cleanup.
	 *
	 * @param mixed $value Option value.
	 */
	private static function option_storage_value( mixed $value ): string {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}
		if ( function_exists( 'maybe_serialize' ) ) {
			return (string) maybe_serialize( $value );
		}

		return serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fallback is only for an impossible partial WordPress runtime.
	}

	/**
	 * Create a unique expiring lease token.
	 *
	 * @param int $expires_at Unix expiry timestamp.
	 */
	private static function repair_lock_token( int $expires_at ): string {
		return bin2hex( random_bytes( 16 ) ) . ':' . $expires_at;
	}

	/** Check whether the lightweight PHPUnit option store is active. */
	private static function uses_test_options(): bool {
		return isset( $GLOBALS['aculect_ai_companion_test_options'] ) && is_array( $GLOBALS['aculect_ai_companion_test_options'] );
	}

	/**
	 * Read one table engine from the authoritative MySQL metadata surface.
	 *
	 * @param string $table Site-scoped table name.
	 */
	private static function table_engine( string $table ): string {
		global $wpdb;
		try {
			$value = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);
		} catch ( \Throwable ) {
			return '';
		}

		return strtoupper( trim( (string) $value ) );
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\ExecutionClaims;

use Closure;

/**
 * Transactional WordPress database execution-claim authority.
 */
final class WordPressExecutionClaimStore implements ExecutionClaimStoreInterface {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Correctness requires authoritative uncached claim reads and exact CAS writes.

	private const CLAIM_LEASE_SECONDS = 30;
	private const MAX_RESULT_BYTES    = 1048576;
	private const MAX_CLAIM_ATTEMPTS  = 8;

	/**
	 * Controlled UTC timestamp source.
	 *
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * Cryptographically random request-local owner token source.
	 *
	 * @var Closure(): string
	 */
	private Closure $owner_token_factory;

	/**
	 * Construct the authoritative claim store.
	 *
	 * @param Closure(): int|null    $clock               Controlled UTC timestamp source.
	 * @param Closure(): string|null $owner_token_factory Controlled 32-byte token source.
	 */
	public function __construct( ?Closure $clock = null, ?Closure $owner_token_factory = null ) {
		$this->clock               = $clock ?? static fn (): int => time();
		$this->owner_token_factory = $owner_token_factory ?? static fn (): string => random_bytes( 32 );
	}

	public function claim(
		string $payload_hash,
		string $tool_hash,
		string $identity_hash,
		?string $confirmation_key_hash,
		?string $idempotency_key_hash,
		bool $allow_create,
		?array $legacy_result,
		bool $legacy_key_reuse,
		int $completed_retention
	): ExecutionClaimDecision {
		if ( ! self::valid_hash( $payload_hash ) || ! self::valid_hash( $tool_hash ) || ! self::valid_hash( $identity_hash ) ) {
			return ExecutionClaimDecision::uncertain();
		}
		if ( null !== $confirmation_key_hash && ! self::valid_hash( $confirmation_key_hash ) ) {
			return ExecutionClaimDecision::uncertain();
		}
		if ( null !== $idempotency_key_hash && ! self::valid_hash( $idempotency_key_hash ) ) {
			return ExecutionClaimDecision::uncertain();
		}
		if ( null === $confirmation_key_hash && null === $idempotency_key_hash ) {
			return ExecutionClaimDecision::missing();
		}

		for ( $attempt = 0; $attempt < self::MAX_CLAIM_ATTEMPTS; ++$attempt ) {
			$owner_token = ( $this->owner_token_factory )();
			if ( 32 !== strlen( $owner_token ) || ! $this->begin_transaction() ) {
				return ExecutionClaimDecision::uncertain();
			}

			$rows = $this->matching_rows( $confirmation_key_hash, $idempotency_key_hash );
			if ( false === $rows ) {
				$retryable = $this->query_retryable();
				$this->rollback();
				if ( $retryable && $attempt + 1 < self::MAX_CLAIM_ATTEMPTS ) {
					$this->pause_before_retry( $attempt );
					continue;
				}
				return ExecutionClaimDecision::uncertain();
			}
			if ( 1 < count( $rows ) ) {
				$this->rollback();
				return ExecutionClaimDecision::uncertain();
			}

			if ( 1 === count( $rows ) ) {
				$expired = $this->delete_expired_completed_row( $rows[0] );
				if ( false === $expired ) {
					$this->rollback();
					return ExecutionClaimDecision::uncertain();
				}
				if ( null === $expired ) {
					$decision = $this->decision_for_row(
						$rows[0],
						$payload_hash,
						$tool_hash,
						$identity_hash,
						$confirmation_key_hash,
						$idempotency_key_hash,
						$owner_token
					);

					return $this->commit() ? $decision : ExecutionClaimDecision::uncertain();
				}
			}

			if ( null !== $legacy_result ) {
				$encoded = self::encode_result( $legacy_result );
				if ( null === $encoded ) {
					$this->rollback();
					return ExecutionClaimDecision::uncertain();
				}

				$id = $this->insert_completed(
					$payload_hash,
					$tool_hash,
					$identity_hash,
					$confirmation_key_hash,
					$idempotency_key_hash,
					$encoded,
					$completed_retention
				);
				if ( 0 < $id && $this->commit() ) {
					return ExecutionClaimDecision::replay( $legacy_result );
				}

				$this->rollback();
				$this->pause_before_retry( $attempt );
				continue;
			}

			if ( $legacy_key_reuse ) {
				$this->rollback();
				return ExecutionClaimDecision::key_reuse();
			}
			if ( ! $allow_create ) {
				$this->rollback();
				return ExecutionClaimDecision::missing();
			}

			$id = $this->insert_claimed(
				$payload_hash,
				$tool_hash,
				$identity_hash,
				$confirmation_key_hash,
				$idempotency_key_hash,
				$owner_token
			);
			if ( 0 < $id && $this->commit() ) {
				return ExecutionClaimDecision::acquired( new ExecutionClaim( $id, $owner_token, 1 ) );
			}

			$this->rollback();
			$this->pause_before_retry( $attempt );
		}

		return ExecutionClaimDecision::uncertain();
	}

	public function mark_running( ExecutionClaim $claim ): bool {
		global $wpdb;

		$now = $this->now();
		$sql = $wpdb->prepare(
			'UPDATE %i SET state = %s, started_at = %s, lease_expires_at = NULL, updated_at = %s WHERE id = %d AND state = %s AND owner_hash = %s AND fence = %d AND lease_expires_at > %s',
			Installer::table_name(),
			'running',
			$now,
			$now,
			$claim->id(),
			'claimed',
			$claim->owner_hash(),
			$claim->fence(),
			$now
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with a validated table identifier.
		return 1 === (int) $wpdb->query( $sql );
	}

	public function complete( ExecutionClaim $claim, array $result, int $completed_retention ): bool {
		global $wpdb;

		$encoded = self::encode_result( $result );
		if ( null === $encoded ) {
			return false;
		}

		$now          = ( $this->clock )();
		$now_sql      = gmdate( 'Y-m-d H:i:s', $now );
		$retain_until = gmdate( 'Y-m-d H:i:s', $now + max( 1, $completed_retention ) );
		$sql          = $wpdb->prepare(
			'UPDATE %i SET state = %s, result_json = %s, result_hash = %s, owner_hash = NULL, completed_at = %s, retain_until = %s, updated_at = %s WHERE id = %d AND state = %s AND owner_hash = %s AND fence = %d',
			Installer::table_name(),
			'completed',
			$encoded,
			hash( 'sha256', $encoded ),
			$now_sql,
			$retain_until,
			$now_sql,
			$claim->id(),
			'running',
			$claim->owner_hash(),
			$claim->fence()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with a validated table identifier.
		return 1 === (int) $wpdb->query( $sql );
	}

	public function release( ExecutionClaim $claim ): bool {
		global $wpdb;

		$sql = $wpdb->prepare(
			'DELETE FROM %i WHERE id = %d AND state = %s AND owner_hash = %s AND fence = %d',
			Installer::table_name(),
			$claim->id(),
			'running',
			$claim->owner_hash(),
			$claim->fence()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with a validated table identifier.
		return 1 === (int) $wpdb->query( $sql );
	}

	public function mark_uncertain( ExecutionClaim $claim ): bool {
		global $wpdb;

		$sql = $wpdb->prepare(
			'UPDATE %i SET state = %s, owner_hash = NULL, lease_expires_at = NULL, updated_at = %s WHERE id = %d AND state = %s AND owner_hash = %s AND fence = %d',
			Installer::table_name(),
			'uncertain',
			$this->now(),
			$claim->id(),
			'running',
			$claim->owner_hash(),
			$claim->fence()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with a validated table identifier.
		return 1 === (int) $wpdb->query( $sql );
	}

	public function prune_completed( int $limit = 500 ): int|false {
		global $wpdb;

		$limit = min( 500, max( 1, $limit ) );
		$this->clear_query_error();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE state = %s AND retain_until IS NOT NULL AND retain_until <= %s ORDER BY id ASC LIMIT %d',
				Installer::table_name(),
				'completed',
				$this->now(),
				$limit
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || $this->query_failed() ) {
			return false;
		}

		$ids = array_values( array_filter( array_map( static fn ( array $row ): int => absint( $row['id'] ?? 0 ), $rows ) ) );
		if ( array() === $ids ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$query        = sprintf( 'DELETE FROM %%i WHERE id IN ( %s ) AND state = %%s AND retain_until IS NOT NULL AND retain_until <= %%s', $placeholders );
		$args         = array_merge( array( Installer::table_name() ), $ids, array( 'completed', $this->now() ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query shape is fixed; placeholders are generated from bounded integer IDs.
		$sql = $wpdb->prepare( $query, ...$args );
		$this->clear_query_error();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above with a validated table identifier and integer IDs.
		$deleted = $wpdb->query( $sql );

		return false === $deleted ? false : (int) $deleted;
	}

	/**
	 * Resolve one locked database row into a closed claim decision.
	 *
	 * @param array<string, mixed> $row Locked database row.
	 * @param string               $payload_hash Exact call payload hash.
	 * @param string               $tool_hash Internal tool hash.
	 * @param string               $identity_hash Authenticated identity hash.
	 * @param string|null          $confirmation_key_hash Confirmation-token hash.
	 * @param string|null          $idempotency_key_hash Identity-bound idempotency hash.
	 * @param string               $owner_token Request-local owner token.
	 */
	private function decision_for_row(
		array $row,
		string $payload_hash,
		string $tool_hash,
		string $identity_hash,
		?string $confirmation_key_hash,
		?string $idempotency_key_hash,
		string $owner_token
	): ExecutionClaimDecision {
		$confirmation_matches = null === $confirmation_key_hash || hash_equals( $confirmation_key_hash, (string) ( $row['confirmation_key_hash'] ?? '' ) );
		$idempotency_matches  = null === $idempotency_key_hash || hash_equals( $idempotency_key_hash, (string) ( $row['idempotency_key_hash'] ?? '' ) );
		if ( ! $confirmation_matches || ! $idempotency_matches ) {
			return ExecutionClaimDecision::uncertain();
		}

		$idempotency_matches = null !== $idempotency_key_hash;
		$identity_matches    = hash_equals( $payload_hash, (string) ( $row['payload_hash'] ?? '' ) )
			&& hash_equals( $tool_hash, (string) ( $row['tool_hash'] ?? '' ) )
			&& hash_equals( $identity_hash, (string) ( $row['identity_hash'] ?? '' ) );
		if ( ! $identity_matches ) {
			return $idempotency_matches ? ExecutionClaimDecision::key_reuse() : ExecutionClaimDecision::invalid();
		}

		$state = (string) ( $row['state'] ?? '' );
		if ( 'completed' === $state ) {
			$result_json = (string) ( $row['result_json'] ?? '' );
			$result_hash = (string) ( $row['result_hash'] ?? '' );
			if ( '' === $result_json || self::MAX_RESULT_BYTES < strlen( $result_json ) || ! hash_equals( hash( 'sha256', $result_json ), $result_hash ) ) {
				return ExecutionClaimDecision::uncertain();
			}

			$result = json_decode( $result_json, true );
			return is_array( $result ) ? ExecutionClaimDecision::replay( $result ) : ExecutionClaimDecision::uncertain();
		}
		if ( 'running' === $state ) {
			return ExecutionClaimDecision::in_progress( 5 );
		}
		if ( 'uncertain' === $state ) {
			return ExecutionClaimDecision::uncertain();
		}
		if ( 'claimed' !== $state ) {
			return ExecutionClaimDecision::uncertain();
		}

		$now       = ( $this->clock )();
		$expires   = strtotime( (string) ( $row['lease_expires_at'] ?? '' ) );
		$expires   = false === $expires ? 0 : $expires;
		$old_owner = (string) ( $row['owner_hash'] ?? '' );
		$id        = absint( $row['id'] ?? 0 );
		$fence     = absint( $row['fence'] ?? 0 );
		if ( $expires > $now ) {
			return ExecutionClaimDecision::in_progress( $expires - $now );
		}
		if ( 0 >= $id || 0 >= $fence || ! self::valid_hash( $old_owner ) ) {
			return ExecutionClaimDecision::uncertain();
		}

		global $wpdb;
		$new_fence = $fence + 1;
		$updated   = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET owner_hash = %s, fence = %d, lease_expires_at = %s, updated_at = %s WHERE id = %d AND state = %s AND owner_hash = %s AND fence = %d AND lease_expires_at <= %s',
				Installer::table_name(),
				hash( 'sha256', $owner_token ),
				$new_fence,
				gmdate( 'Y-m-d H:i:s', $now + self::CLAIM_LEASE_SECONDS ),
				gmdate( 'Y-m-d H:i:s', $now ),
				$id,
				'claimed',
				$old_owner,
				$fence,
				gmdate( 'Y-m-d H:i:s', $now )
			)
		);

		return 1 === (int) $updated
			? ExecutionClaimDecision::acquired( new ExecutionClaim( $id, $owner_token, $new_fence ) )
			: ExecutionClaimDecision::uncertain();
	}

	/**
	 * Delete one expired completed row while its alias row is transaction-locked.
	 *
	 * @param array<string, mixed> $row Locked database row.
	 * @return bool|null True when deleted, false when malformed or stale, null when not completed or still retained.
	 */
	private function delete_expired_completed_row( array $row ): ?bool {
		if ( 'completed' !== (string) ( $row['state'] ?? '' ) ) {
			return null;
		}

		$retain_until = $row['retain_until'] ?? null;
		if ( ! is_string( $retain_until ) ) {
			return false;
		}
		$retain_timestamp = self::parse_db_timestamp( $retain_until );
		if ( null === $retain_timestamp ) {
			return false;
		}
		if ( $retain_timestamp > ( $this->clock )() ) {
			return null;
		}

		$id = absint( $row['id'] ?? 0 );
		if ( 0 >= $id ) {
			return false;
		}

		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE id = %d AND state = %s AND retain_until = %s',
				Installer::table_name(),
				$id,
				'completed',
				$retain_until
			)
		);

		return 1 === (int) $deleted;
	}

	/**
	 * Find rows matching either execution alias while holding a transaction lock.
	 *
	 * @param string|null $confirmation_key_hash Confirmation-token hash.
	 * @param string|null $idempotency_key_hash  Identity-bound idempotency hash.
	 *
	 * @return list<array<string, mixed>>|false
	 */
	private function matching_rows( ?string $confirmation_key_hash, ?string $idempotency_key_hash ): array|false {
		global $wpdb;

		if ( null !== $confirmation_key_hash && null !== $idempotency_key_hash ) {
			$sql = $wpdb->prepare(
				'SELECT * FROM %i WHERE confirmation_key_hash = %s OR idempotency_key_hash = %s ORDER BY id ASC FOR UPDATE',
				Installer::table_name(),
				$confirmation_key_hash,
				$idempotency_key_hash
			);
		} elseif ( null !== $confirmation_key_hash ) {
			$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE confirmation_key_hash = %s ORDER BY id ASC FOR UPDATE', Installer::table_name(), $confirmation_key_hash );
		} else {
			$sql = $wpdb->prepare( 'SELECT * FROM %i WHERE idempotency_key_hash = %s ORDER BY id ASC FOR UPDATE', Installer::table_name(), $idempotency_key_hash );
		}

		$this->clear_query_error();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every branch prepares the query above with a validated table identifier.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) && ! $this->query_failed() ? $rows : false;
	}

	private function insert_claimed(
		string $payload_hash,
		string $tool_hash,
		string $identity_hash,
		?string $confirmation_key_hash,
		?string $idempotency_key_hash,
		string $owner_token
	): int {
		$now = ( $this->clock )();
		return $this->insert_row(
			array(
				'confirmation_key_hash' => $confirmation_key_hash,
				'idempotency_key_hash'  => $idempotency_key_hash,
				'payload_hash'          => $payload_hash,
				'tool_hash'             => $tool_hash,
				'identity_hash'         => $identity_hash,
				'owner_hash'            => hash( 'sha256', $owner_token ),
				'fence'                 => 1,
				'state'                 => 'claimed',
				'lease_expires_at'      => gmdate( 'Y-m-d H:i:s', $now + self::CLAIM_LEASE_SECONDS ),
				'created_at'            => gmdate( 'Y-m-d H:i:s', $now ),
				'updated_at'            => gmdate( 'Y-m-d H:i:s', $now ),
			)
		);
	}

	private function insert_completed(
		string $payload_hash,
		string $tool_hash,
		string $identity_hash,
		?string $confirmation_key_hash,
		?string $idempotency_key_hash,
		string $encoded,
		int $completed_retention
	): int {
		$now = ( $this->clock )();
		return $this->insert_row(
			array(
				'confirmation_key_hash' => $confirmation_key_hash,
				'idempotency_key_hash'  => $idempotency_key_hash,
				'payload_hash'          => $payload_hash,
				'tool_hash'             => $tool_hash,
				'identity_hash'         => $identity_hash,
				'fence'                 => 1,
				'state'                 => 'completed',
				'result_json'           => $encoded,
				'result_hash'           => hash( 'sha256', $encoded ),
				'completed_at'          => gmdate( 'Y-m-d H:i:s', $now ),
				'retain_until'          => gmdate( 'Y-m-d H:i:s', $now + max( 1, $completed_retention ) ),
				'created_at'            => gmdate( 'Y-m-d H:i:s', $now ),
				'updated_at'            => gmdate( 'Y-m-d H:i:s', $now ),
			)
		);
	}

	/**
	 * Insert one normalized claim row.
	 *
	 * @param array<string, mixed> $data Row data.
	 */
	private function insert_row( array $data ): int {
		global $wpdb;

		$this->clear_query_error();
		$inserted = $wpdb->insert( Installer::table_name(), $data );
		if ( 1 !== (int) $inserted || $this->query_failed() ) {
			return 0;
		}

		return absint( $wpdb->insert_id ?? 0 );
	}

	private function begin_transaction(): bool {
		global $wpdb;

		$this->clear_query_error();
		return false !== $wpdb->query( 'START TRANSACTION' ) && ! $this->query_failed();
	}

	private function commit(): bool {
		global $wpdb;

		$this->clear_query_error();
		return false !== $wpdb->query( 'COMMIT' ) && ! $this->query_failed();
	}

	private function rollback(): void {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );
	}

	private function now(): string {
		return gmdate( 'Y-m-d H:i:s', ( $this->clock )() );
	}

	private function clear_query_error(): void {
		global $wpdb;

		$wpdb->last_error = '';
	}

	private function query_failed(): bool {
		global $wpdb;

		return '' !== trim( (string) ( $wpdb->last_error ?? '' ) );
	}

	private function query_retryable(): bool {
		global $wpdb;

		$error = strtolower( (string) ( $wpdb->last_error ?? '' ) );
		return str_contains( $error, 'deadlock' )
			|| str_contains( $error, 'lock wait timeout' )
			|| str_contains( $error, 'database is locked' );
	}

	private function pause_before_retry( int $attempt ): void {
		if ( $attempt + 1 >= self::MAX_CLAIM_ATTEMPTS ) {
			return;
		}

		usleep( random_int( 1000, 4000 ) * ( $attempt + 1 ) );
	}

	private static function valid_hash( string $hash ): bool {
		return 1 === preg_match( '/\A[a-f0-9]{64}\z/', $hash );
	}

	/**
	 * Parse one exact UTC database timestamp within the portable DATETIME range.
	 *
	 * @param string $value Canonical UTC database timestamp.
	 */
	private static function parse_db_timestamp( string $value ): ?int {
		if ( 1 !== preg_match( '/\A(?:19[7-9]\d|[2-9]\d{3})-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01]) (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d\z/', $value ) ) {
			return null;
		}

		$timestamp = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		$errors    = \DateTimeImmutable::getLastErrors();
		if ( false === $timestamp || ( is_array( $errors ) && ( 0 < $errors['warning_count'] || 0 < $errors['error_count'] ) ) || $timestamp->format( 'Y-m-d H:i:s' ) !== $value ) {
			return null;
		}

		$unix = $timestamp->getTimestamp();
		return 0 <= $unix && 253402300799 >= $unix ? $unix : null;
	}

	/**
	 * Encode and bound one successful tool result.
	 *
	 * @param array<string, mixed> $result Successful tool result.
	 */
	private static function encode_result( array $result ): ?string {
		$encoded = wp_json_encode( $result );
		return is_string( $encoded ) && self::MAX_RESULT_BYTES >= strlen( $encoded ) ? $encoded : null;
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Closure;

/**
 * Stores exact, short-lived MCP operation requests pending human review.
 *
 * Approval is only a human decision record. This class never executes tools.
 */
final class PendingOperationApprovalStore {

	public const TTL_SECONDS               = 600;
	public const MAX_ARGUMENTS             = 16384;
	public const MAX_SUMMARY               = 8192;
	public const MAX_QUEUE_ITEMS           = 50;
	public const MAX_OUTSTANDING_PER_ACTOR = 25;
	public const CLEANUP_HOOK              = 'aculect_mcp_pending_approval_cleanup';
	private const MAX_JSON_DEPTH           = 12;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads and atomic compare-and-set writes must use authoritative storage.

	/**
	 * UTC timestamp provider for deterministic expiry tests.
	 *
	 * @var Closure():int
	 */
	private Closure $clock;
	private bool $cleanup_registered = false;

	/**
	 * Construct the store.
	 *
	 * @param Closure():int|null $clock Controlled UTC clock.
	 */
	public function __construct( ?Closure $clock = null ) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Build a bounded, opaque audit correlation value unique to one proposal row.
	 *
	 * @param array<string,mixed> $request Decoded request metadata.
	 */
	public static function audit_reference( array $request ): string {
		$id          = absint( $request['id'] ?? 0 );
		$fingerprint = is_string( $request['operation_fingerprint'] ?? null ) ? $request['operation_fingerprint'] : '';
		if ( 0 >= $id || 1 !== preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) {
			return '';
		}
		$site_actor = implode( "\0", array( (string) get_current_blog_id(), (string) get_current_user_id() ) );
		return substr( hash_hmac( 'sha256', "aculect.approval-audit.v1\0" . $site_actor . "\0" . $id . "\0" . $fingerprint, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Create an exact operation review request for the authenticated MCP actor.
	 *
	 * @param string              $tool_name Public MCP tool name.
	 * @param array<string,mixed> $arguments Exact normalized arguments, fingerprinted but never persisted.
	 * @param array<string,mixed> $review_summary Bounded server-authored operation/target/change summary.
	 * @param string              $client_id Authenticated OAuth client identifier.
	 * @param string              $capability Required current WordPress capability.
	 * @param array<int,int>      $capability_args Numeric capability arguments.
	 * @phpstan-param list<int>   $capability_args
	 * @param string              $session_id Authenticated OAuth access-token session identifier.
	 * @param string              $policy_fingerprint Current policy version digest.
	 * @param string              $expected_target_fingerprint Target digest captured before the server dry-run.
	 * @return int|\WP_Error Pending row ID or a fail-closed error.
	 */
	public function create( string $tool_name, array $arguments, array $review_summary, string $client_id, string $capability, array $capability_args = array(), string $session_id = '', string $policy_fingerprint = '', string $expected_target_fingerprint = '' ): int|\WP_Error {
		$user_id = get_current_user_id();
		$blog_id = get_current_blog_id();
		if ( 0 >= $user_id || 0 >= $blog_id || ! $this->valid_tool( $tool_name ) || ! $this->valid_client_id( $client_id ) || ! $this->valid_session_id( $session_id ) || ! $this->valid_fingerprint( $policy_fingerprint ) || ! $this->valid_capability( $capability, $capability_args ) || ! current_user_can( $capability, ...$capability_args ) ) {
			return new \WP_Error( 'approval_request_denied', 'The operation cannot be queued for approval.' );
		}

		try {
			$canonical_arguments = ( new PendingApprovalCanonicalizer() )->canonicalize( $arguments );
		} catch ( \UnexpectedValueException ) {
			return new \WP_Error( 'approval_payload_invalid', 'The operation arguments cannot be stored safely.' );
		}
		$arguments_json = $this->encode( $canonical_arguments );
		if ( null === $arguments_json || self::MAX_ARGUMENTS < strlen( $arguments_json ) ) {
			return new \WP_Error( 'approval_payload_invalid', 'The operation is too large or cannot be stored safely.' );
		}
		$review_summary = $this->normalize_summary( $review_summary );
		if ( null !== $review_summary ) {
			$current_target_fingerprint = $this->target_state_fingerprint( $review_summary['target_id'] );
			if ( '' !== $expected_target_fingerprint && ( null === $current_target_fingerprint || ! hash_equals( $expected_target_fingerprint, $current_target_fingerprint ) ) ) {
				return new \WP_Error( 'approval_target_changed', 'The target changed while its review summary was being prepared.' );
			}
			$review_summary['target_fingerprint'] = $current_target_fingerprint ?? '';
			if ( ! preg_match( '/^[a-f0-9]{64}$/', $review_summary['target_fingerprint'] ) ) {
				return new \WP_Error( 'approval_target_unavailable', 'The target state cannot be verified safely for review.' );
			}
		}
		$summary_json = null === $review_summary ? null : $this->encode( $review_summary );
		if ( null === $summary_json || self::MAX_SUMMARY < strlen( $summary_json ) ) {
			return new \WP_Error( 'approval_summary_invalid', 'A bounded operation summary is required for human review.' );
		}
		$session_hash          = $this->session_hash( $session_id );
		$operation_fingerprint = $this->operation_fingerprint( $tool_name, $arguments_json, $capability, $capability_args, $policy_fingerprint );
		$client_hash           = $this->client_hash( $client_id );
		$request_key           = $this->request_key( $blog_id, $user_id, $client_hash, $session_hash, $operation_fingerprint );
		$payload               = array(
			'capability'         => $capability,
			'capability_args'    => array_values( $capability_args ),
			'client_hash'        => $client_hash,
			'session_hash'       => $session_hash,
			'operation_hash'     => $operation_fingerprint,
			'policy_fingerprint' => $policy_fingerprint,
			'review_summary'     => $review_summary,
			'schema'             => 'aculect.pending-approval.v2',
			'tool'               => $tool_name,
		);
		$payload_json          = $this->encode( $payload );
		$ciphertext            = null === $payload_json ? null : $this->encrypt( $payload_json, $this->aad( $blog_id, $user_id, $payload['client_hash'], $session_hash, $tool_name, $operation_fingerprint ) );
		if ( null === $ciphertext || ! PendingApprovalInstaller::install() || ! $this->register_cleanup() ) {
			return new \WP_Error( 'approval_storage_unavailable', 'The operation could not be queued safely.' );
		}

		global $wpdb;
		$now     = ( $this->clock )();
		$created = gmdate( 'Y-m-d H:i:s', $now );
		$expires = gmdate( 'Y-m-d H:i:s', $now + self::TTL_SECONDS );
		$this->prune_expired( 25 );
		$outstanding = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE blog_id = %d AND user_id = %d AND status IN (%s, %s) AND expires_at > %s',
				PendingApprovalInstaller::table_name(),
				$blog_id,
				$user_id,
				'pending',
				'approved',
				$created
			)
		);
		if ( ! is_numeric( $outstanding ) || self::MAX_OUTSTANDING_PER_ACTOR <= (int) $outstanding ) {
			return new \WP_Error( 'approval_queue_full', 'Too many operations are awaiting review. Finish or wait for them to expire.' );
		}
		$inserted = $wpdb->insert(
			PendingApprovalInstaller::table_name(),
			array(
				'blog_id'               => $blog_id,
				'user_id'               => $user_id,
				'client_hash'           => $payload['client_hash'],
				'session_hash'          => $session_hash,
				'request_key'           => $request_key,
				'tool_name'             => $tool_name,
				'operation_fingerprint' => $operation_fingerprint,
				'payload_ciphertext'    => $ciphertext,
				'status'                => 'pending',
				'created_at'            => $created,
				'expires_at'            => $expires,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( 1 !== $inserted || 0 >= (int) $wpdb->insert_id ) {
			return new \WP_Error( 'approval_storage_unavailable', 'The operation could not be queued safely.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * List pending, unexpired requests for the current WordPress actor and site.
	 *
	 * @return list<array<string,mixed>>|\WP_Error
	 */
	public function pending_for_current_actor(): array|\WP_Error {
		if ( 0 >= get_current_user_id() || 0 >= get_current_blog_id() || ! PendingApprovalInstaller::install() ) {
			return new \WP_Error( 'approval_storage_unavailable', 'Pending operations are unavailable.' );
		}
		$this->prune_expired( 25 );
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE blog_id = %d AND user_id = %d AND status = %s AND expires_at > %s ORDER BY created_at DESC LIMIT %d',
				PendingApprovalInstaller::table_name(),
				get_current_blog_id(),
				get_current_user_id(),
				'pending',
				gmdate( 'Y-m-d H:i:s', ( $this->clock )() ),
				self::MAX_QUEUE_ITEMS
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'approval_storage_unavailable', 'Pending operations are unavailable.' );
		}

		$requests = array();
		foreach ( $rows as $row ) {
			$request = $this->decode_row( $row );
			if ( null !== $request && $this->target_is_current( $request ) && current_user_can( $request['capability'], ...$request['capability_args'] ) ) {
				$requests[] = $request;
			}
		}

		return $requests;
	}

	/**
	 * Load one pending request for the current actor, without trusting its ID.
	 *
	 * @param int $id Pending request ID.
	 * @return array<string,mixed>|null
	 */
	public function find_pending_for_current_actor( int $id ): ?array {
		if ( 0 >= $id || 0 >= get_current_user_id() || 0 >= get_current_blog_id() || ! PendingApprovalInstaller::install() ) {
			return null;
		}
		$this->prune_expired( 25 );
		global $wpdb;
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND blog_id = %d AND user_id = %d AND status = %s AND expires_at > %s LIMIT 1',
				PendingApprovalInstaller::table_name(),
				$id,
				get_current_blog_id(),
				get_current_user_id(),
				'pending',
				gmdate( 'Y-m-d H:i:s', ( $this->clock )() )
			),
			ARRAY_A
		);
		$request = is_array( $row ) ? $this->decode_row( $row ) : null;
		return null !== $request && $this->target_is_current( $request ) && current_user_can( $request['capability'], ...$request['capability_args'] ) ? $request : null;
	}

	/**
	 * Atomically record a single explicit decision after current capability checks.
	 *
	 * @param int    $id Pending request ID.
	 * @param string $decision `approved` or `declined`.
	 * @return bool True only when this request changed pending state exactly once.
	 */
	public function decide_for_current_actor( int $id, string $decision ): bool {
		if ( ! in_array( $decision, array( 'approved', 'declined' ), true ) ) {
			return false;
		}
		$request = $this->find_pending_for_current_actor( $id );
		if ( null === $request || ! current_user_can( $request['capability'], ...$request['capability_args'] ) ) {
			return false;
		}
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s', ( $this->clock )() );
		$sql = $wpdb->prepare(
			'UPDATE %i SET status = %s, decided_at = %s, decided_by = %d WHERE id = %d AND blog_id = %d AND user_id = %d AND client_hash = %s AND operation_fingerprint = %s AND status = %s AND expires_at > %s',
			PendingApprovalInstaller::table_name(),
			$decision,
			$now,
			get_current_user_id(),
			$id,
			get_current_blog_id(),
			get_current_user_id(),
			$request['client_hash'],
			$request['operation_fingerprint'],
			'pending',
			$now
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; table name comes from the fixed plugin prefix and suffix.
		$consumed = 1 === (int) $wpdb->query( $sql );
		// A drift discovered after the one-use CAS burns the approval and fails closed.
		return $consumed && $this->target_is_current( $request );
	}

	/**
	 * Compare a later client-resubmitted call with its approved operation fingerprint.
	 *
	 * This comparison is not execution authorization or atomic consumption.
	 *
	 * @param int                 $id Pending request ID.
	 * @param string              $tool_name Public MCP tool name.
	 * @param array<string,mixed> $arguments Resubmitted tool arguments.
	 * @param string              $client_id Authenticated OAuth client ID.
	 * @param string              $session_id Authenticated OAuth access-token session identifier.
	 * @param string              $policy_fingerprint Current policy version digest.
	 */
	public function matches_approved_operation( int $id, string $tool_name, array $arguments, string $client_id, string $session_id, string $policy_fingerprint = '' ): bool {
		if ( ! $this->valid_tool( $tool_name ) || ! $this->valid_client_id( $client_id ) || ! $this->valid_session_id( $session_id ) || ! $this->valid_fingerprint( $policy_fingerprint ) || 0 >= $id || 0 >= get_current_user_id() || 0 >= get_current_blog_id() || ! PendingApprovalInstaller::install() ) {
			return false;
		}
		$this->prune_expired( 25 );
		try {
			$canonical_arguments = ( new PendingApprovalCanonicalizer() )->canonicalize( $arguments );
		} catch ( \UnexpectedValueException ) {
			return false;
		}
		$arguments_json = $this->encode( $canonical_arguments );
		if ( null === $arguments_json || self::MAX_ARGUMENTS < strlen( $arguments_json ) ) {
			return false;
		}
		global $wpdb;
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND blog_id = %d AND user_id = %d AND client_hash = %s AND session_hash = %s AND status = %s AND expires_at > %s LIMIT 1',
				PendingApprovalInstaller::table_name(),
				$id,
				get_current_blog_id(),
				get_current_user_id(),
				$this->client_hash( $client_id ),
				$this->session_hash( $session_id ),
				'approved',
				gmdate( 'Y-m-d H:i:s', ( $this->clock )() )
			),
			ARRAY_A
		);
		$request = is_array( $row ) ? $this->decode_row( $row ) : null;
		if ( null === $request || $tool_name !== $request['tool_name'] || ! current_user_can( $request['capability'], ...$request['capability_args'] ) ) {
			return false;
		}
		$fingerprint = $this->operation_fingerprint( $tool_name, $arguments_json, $request['capability'], $request['capability_args'], $policy_fingerprint );
		return hash_equals( $request['operation_fingerprint'], $fingerprint );
	}

	/**
	 * Return the state for this exact authenticated operation, if one exists.
	 *
	 * @param string              $tool_name Internal tool name.
	 * @param array<string,mixed> $arguments Normalized operation arguments.
	 * @param string              $client_id OAuth client identifier.
	 * @param string              $session_id OAuth access-token session identifier.
	 * @param string              $capability Current required WordPress capability.
	 * @param array<int,int>      $capability_args Numeric capability arguments.
	 * @phpstan-param list<int>    $capability_args
	 * @param string              $policy_fingerprint Current policy version digest.
	 */
	public function exact_operation_status( string $tool_name, array $arguments, string $client_id, string $session_id, string $capability, array $capability_args, string $policy_fingerprint = '' ): ?string {
		$request = $this->exact_operation_request( $tool_name, $arguments, $client_id, $session_id, $capability, $capability_args, $policy_fingerprint );
		return null === $request ? null : (string) $request['status'];
	}

	/**
	 * Return a matching request and mark pending approvals stale on target drift.
	 *
	 * @param string              $tool_name Public MCP tool name.
	 * @param array<string,mixed> $arguments Normalized operation arguments.
	 * @param string              $client_id OAuth client identifier.
	 * @param string              $session_id OAuth access-token session identifier.
	 * @param string              $capability Current required WordPress capability.
	 * @param array<int,int>      $capability_args Numeric capability arguments.
	 * @phpstan-param list<int>   $capability_args
	 * @param string              $policy_fingerprint Current policy version digest.
	 * @return array<string,mixed>|null
	 */
	public function exact_operation_request( string $tool_name, array $arguments, string $client_id, string $session_id, string $capability, array $capability_args, string $policy_fingerprint = '' ): ?array {
		$request = $this->find_exact_operation( $tool_name, $arguments, $client_id, $session_id, $capability, $capability_args, $policy_fingerprint );
		if ( null !== $request && in_array( $request['status'], array( 'pending', 'approved' ), true ) && ! $this->target_is_current( $request ) ) {
			$request['status'] = 'stale';
		}
		return $request;
	}

	/**
	 * Return an internal, non-reusable execution-claim alias for an exact call.
	 *
	 * @param string              $tool_name Public MCP tool name.
	 * @param array<string,mixed> $arguments Normalized operation arguments.
	 * @param string              $client_id OAuth client identifier.
	 * @param string              $session_id OAuth access-token session identifier.
	 * @param string              $capability Current required WordPress capability.
	 * @param array<int,int>      $capability_args Numeric capability arguments.
	 * @phpstan-param list<int>    $capability_args
	 * @param string              $policy_fingerprint Current policy version digest.
	 */
	public function execution_alias( string $tool_name, array $arguments, string $client_id, string $session_id, string $capability, array $capability_args, string $policy_fingerprint = '' ): ?string {
		$fingerprint = $this->fingerprint_for_arguments( $tool_name, $arguments, $capability, $capability_args, $policy_fingerprint );
		if ( null === $fingerprint || ! $this->valid_client_id( $client_id ) || ! $this->valid_session_id( $session_id ) ) {
			return null;
		}
		$binding = implode( "\0", array( (string) get_current_blog_id(), (string) get_current_user_id(), $this->client_hash( $client_id ), $this->session_hash( $session_id ), $fingerprint ) );
		return hash_hmac( 'sha256', "aculect.pending-approval-execution.v1\0" . $binding, wp_salt( 'auth' ) );
	}

	/**
	 * Atomically consume an approved operation exactly once before dispatch.
	 *
	 * @param string              $tool_name Public MCP tool name.
	 * @param array<string,mixed> $arguments Normalized operation arguments.
	 * @param string              $client_id OAuth client identifier.
	 * @param string              $session_id OAuth access-token session identifier.
	 * @param string              $capability Current required WordPress capability.
	 * @param array<int,int>      $capability_args Numeric capability arguments.
	 * @phpstan-param list<int>    $capability_args
	 * @param string              $policy_fingerprint Current policy version digest.
	 */
	public function consume_approved_operation( string $tool_name, array $arguments, string $client_id, string $session_id, string $capability, array $capability_args, string $policy_fingerprint = '' ): bool {
		$request = $this->exact_operation_request( $tool_name, $arguments, $client_id, $session_id, $capability, $capability_args, $policy_fingerprint );
		if ( null === $request || 'approved' !== $request['status'] || ! current_user_can( $capability, ...$capability_args ) ) {
			return false;
		}
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s', ( $this->clock )() );
		$sql = $wpdb->prepare(
			'UPDATE %i SET status = %s, decided_at = %s WHERE id = %d AND blog_id = %d AND user_id = %d AND client_hash = %s AND session_hash = %s AND operation_fingerprint = %s AND status = %s AND expires_at > %s',
			PendingApprovalInstaller::table_name(),
			'consumed',
			$now,
			$request['id'],
			get_current_blog_id(),
			get_current_user_id(),
			$request['client_hash'],
			$request['session_hash'],
			$request['operation_fingerprint'],
			'approved',
			$now
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; table name comes from the fixed plugin prefix and suffix.
		return 1 === (int) $wpdb->query( $sql );
	}

	/**
	 * Resolve one exact actor/site/client/session operation row.
	 *
	 * @param string              $tool_name Public MCP tool name.
	 * @param array<string,mixed> $arguments Normalized operation arguments.
	 * @param string              $client_id OAuth client identifier.
	 * @param string              $session_id OAuth access-token session identifier.
	 * @param string              $capability Current required WordPress capability.
	 * @param array<int,int>      $capability_args Numeric capability arguments.
	 * @phpstan-param list<int>    $capability_args
	 * @param string              $policy_fingerprint Current policy version digest.
	 * @return array<string,mixed>|null
	 */
	private function find_exact_operation( string $tool_name, array $arguments, string $client_id, string $session_id, string $capability, array $capability_args, string $policy_fingerprint ): ?array {
		if ( ! $this->valid_tool( $tool_name ) || ! $this->valid_client_id( $client_id ) || ! $this->valid_session_id( $session_id ) || ! $this->valid_fingerprint( $policy_fingerprint ) || ! $this->valid_capability( $capability, $capability_args ) || 0 >= get_current_user_id() || 0 >= get_current_blog_id() || ! PendingApprovalInstaller::install() || ! current_user_can( $capability, ...$capability_args ) ) {
			return null;
		}
		$fingerprint = $this->fingerprint_for_arguments( $tool_name, $arguments, $capability, $capability_args, $policy_fingerprint );
		if ( null === $fingerprint ) {
			return null;
		}
		$this->prune_expired( 25 );
		global $wpdb;
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE blog_id = %d AND user_id = %d AND client_hash = %s AND session_hash = %s AND operation_fingerprint = %s AND status IN (%s, %s, %s, %s) AND expires_at > %s ORDER BY id DESC LIMIT 1',
				PendingApprovalInstaller::table_name(),
				get_current_blog_id(),
				get_current_user_id(),
				$this->client_hash( $client_id ),
				$this->session_hash( $session_id ),
				$fingerprint,
				'pending',
				'approved',
				'declined',
				'consumed',
				gmdate( 'Y-m-d H:i:s', ( $this->clock )() )
			),
			ARRAY_A
		);
		$request = is_array( $row ) ? $this->decode_row( $row ) : null;
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- These are authenticated row fields compared to current operation inputs.
		return null !== $request && $request['tool_name'] === $tool_name && $request['capability'] === $capability && $request['capability_args'] === array_values( $capability_args ) && $request['policy_fingerprint'] === $policy_fingerprint ? $request : null;
	}

	/**
	 * Ensure the object reviewed is still the current object version.
	 *
	 * @param array<string,mixed> $request Decrypted review request.
	 */
	private function target_is_current( array $request ): bool {
		$summary     = $request['review_summary'];
		$post        = function_exists( 'get_post' ) ? get_post( (int) $summary['target_id'] ) : null;
		$fingerprint = $post instanceof \WP_Post ? $this->target_state_fingerprint( (int) $post->ID ) : null;
		return $post instanceof \WP_Post
			&& (string) $post->post_modified_gmt === (string) $summary['target_version']
			&& is_string( $fingerprint )
			&& isset( $summary['target_fingerprint'] )
			&& hash_equals( (string) $summary['target_fingerprint'], $fingerprint );
	}

	/**
	 * Compute a keyed-independent digest of versioned post fields and write targets.
	 *
	 * @param int $post_id WordPress post ID.
	 */
	private function target_state_fingerprint( int $post_id ): ?string {
		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || $post_id !== (int) $post->ID ) {
			return null;
		}
		$state = array();
		foreach ( array( 'ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type', 'post_mime_type' ) as $field ) {
			$value = $post->{$field} ?? null;
			if ( null !== $value && ! is_scalar( $value ) ) {
				return null;
			}
			$state[ $field ] = $value;
		}
		if ( function_exists( 'get_object_taxonomies' ) && function_exists( 'wp_get_object_terms' ) ) {
			$taxonomies = get_object_taxonomies( (string) $post->post_type, 'names' );
			if ( ! is_array( $taxonomies ) ) {
				return null;
			}
			sort( $taxonomies );
			$state['taxonomies'] = array();
			foreach ( $taxonomies as $taxonomy ) {
				$term_ids = wp_get_object_terms( $post_id, (string) $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $term_ids ) || ! is_array( $term_ids ) ) {
					return null;
				}
				$term_ids = array_map( 'intval', $term_ids );
				sort( $term_ids );
				$state['taxonomies'][ (string) $taxonomy ] = $term_ids;
			}
		}
		if ( function_exists( 'get_post_thumbnail_id' ) ) {
			$state['featured_media'] = (int) get_post_thumbnail_id( $post_id );
		}
		$json = $this->encode( $state );
		return null === $json ? null : hash( 'sha256', $json );
	}

	/**
	 * Compute an exact fingerprint while retaining only the keyed digest.
	 *
	 * @param string              $tool_name Public MCP tool name.
	 * @param array<string,mixed> $arguments Normalized operation arguments.
	 * @param string              $capability Current required WordPress capability.
	 * @param array<int,int>      $capability_args Numeric capability arguments.
	 * @phpstan-param list<int>   $capability_args
	 * @param string              $policy_fingerprint Current policy version digest.
	 * @return string|null
	 */
	private function fingerprint_for_arguments( string $tool_name, array $arguments, string $capability, array $capability_args, string $policy_fingerprint ): ?string {
		try {
			$canonical = ( new PendingApprovalCanonicalizer() )->canonicalize( $arguments );
		} catch ( \UnexpectedValueException ) {
			return null;
		}
		$json = $this->encode( $canonical );
		return null === $json || self::MAX_ARGUMENTS < strlen( $json ) ? null : $this->operation_fingerprint( $tool_name, $json, $capability, $capability_args, $policy_fingerprint );
	}

	/** Register bounded, recurring cleanup before the first request is stored. */
	public function register_cleanup(): bool {
		if ( ! $this->cleanup_registered ) {
			add_action( self::CLEANUP_HOOK, array( $this, 'prune_expired' ) );
			$this->cleanup_registered = true;
		}
		if ( false !== wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			return true;
		}
		wp_schedule_event( time() + 3600, 'hourly', self::CLEANUP_HOOK );
		return true;
	}

	/**
	 * Capture a target digest before building a human-review summary.
	 *
	 * @param int $post_id WordPress post ID.
	 */
	public function target_state_fingerprint_for( int $post_id ): ?string {
		return $this->target_state_fingerprint( $post_id );
	}

	/**
	 * Delete a bounded number of expired rows to limit sensitive payload retention.
	 *
	 * @param int $limit Maximum rows to delete in this call.
	 */
	public function prune_expired( int $limit = 100 ): int {
		if ( ! PendingApprovalInstaller::install() ) {
			return 0;
		}
		global $wpdb;
		$limit = max( 1, min( 1000, $limit ) );
		$sql   = $wpdb->prepare(
			'DELETE FROM %i WHERE expires_at <= %s LIMIT %d',
			PendingApprovalInstaller::table_name(),
			gmdate( 'Y-m-d H:i:s', ( $this->clock )() ),
			$limit
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; bounded row count.
		$result = $wpdb->query( $sql );
		return false === $result ? 0 : max( 0, (int) $result );
	}

	/**
	 * Validate a row, decrypt it, and recompute its operation binding.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>|null
	 */
	private function decode_row( array $row ): ?array {
		$blog_id = (int) ( $row['blog_id'] ?? 0 );
		$user_id = (int) ( $row['user_id'] ?? 0 );
		$tool    = is_string( $row['tool_name'] ?? null ) ? $row['tool_name'] : '';
		$client  = is_string( $row['client_hash'] ?? null ) ? $row['client_hash'] : '';
		$session = is_string( $row['session_hash'] ?? null ) ? $row['session_hash'] : '';
		$hash    = is_string( $row['operation_fingerprint'] ?? null ) ? $row['operation_fingerprint'] : '';
		$sealed  = is_string( $row['payload_ciphertext'] ?? null ) ? $row['payload_ciphertext'] : '';
		if ( 0 >= $blog_id || 0 >= $user_id || ! $this->valid_tool( $tool ) || ! preg_match( '/^[a-f0-9]{64}$/', $client ) || ! preg_match( '/^[a-f0-9]{64}$/', $session ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return null;
		}
		$json    = $this->decrypt( $sealed, $this->aad( $blog_id, $user_id, $client, $session, $tool, $hash ) );
		$payload = is_string( $json ) ? json_decode( $json, true, self::MAX_JSON_DEPTH, JSON_BIGINT_AS_STRING ) : null;
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Authenticated row fields are compared with each other after decryption, not against fixed literals.
		if ( ! is_array( $payload ) || 'aculect.pending-approval.v2' !== ( $payload['schema'] ?? null ) || $tool !== ( $payload['tool'] ?? null ) || $client !== ( $payload['client_hash'] ?? null ) || $session !== ( $payload['session_hash'] ?? null ) || $hash !== ( $payload['operation_hash'] ?? null ) || ! $this->valid_fingerprint( (string) ( $payload['policy_fingerprint'] ?? '' ) ) || ! is_array( $payload['review_summary'] ?? null ) || null === $this->normalize_summary( $payload['review_summary'] ) || ! is_string( $payload['capability'] ?? null ) || ! is_array( $payload['capability_args'] ?? null ) ) {
			return null;
		}
		if ( ! $this->valid_capability( $payload['capability'], $payload['capability_args'] ) ) {
			return null;
		}

		return array(
			'id'                    => (int) ( $row['id'] ?? 0 ),
			'blog_id'               => $blog_id,
			'user_id'               => $user_id,
			'client_hash'           => $client,
			'session_hash'          => $session,
			'tool_name'             => $tool,
			'review_summary'        => $payload['review_summary'],
			'capability'            => $payload['capability'],
			'capability_args'       => array_values( $payload['capability_args'] ),
			'operation_fingerprint' => $hash,
			'policy_fingerprint'    => (string) $payload['policy_fingerprint'],
			'status'                => (string) ( $row['status'] ?? '' ),
			'created_at'            => (string) ( $row['created_at'] ?? '' ),
			'expires_at'            => (string) ( $row['expires_at'] ?? '' ),
		);
	}

	private function client_hash( string $client_id ): string {
		return hash_hmac( 'sha256', $client_id, wp_salt( 'auth' ) );
	}

	private function session_hash( string $session_id ): string {
		return hash_hmac( 'sha256', "aculect.pending-approval-session.v1\0" . $session_id, wp_salt( 'auth' ) );
	}

	private function request_key( int $blog_id, int $user_id, string $client_hash, string $session_hash, string $fingerprint ): string {
		$binding = implode( "\0", array( (string) $blog_id, (string) $user_id, $client_hash, $session_hash, $fingerprint ) );
		return hash_hmac( 'sha256', "aculect.pending-approval-request.v1\0" . $binding, wp_salt( 'auth' ) );
	}

	/**
	 * Compute the keyed digest of the exact operation and policy binding.
	 *
	 * @param string         $tool_name Public MCP tool name.
	 * @param string         $arguments_json Canonical arguments JSON.
	 * @param string         $capability Current WordPress capability.
	 * @param array<int,int> $capability_args Numeric capability arguments.
	 * @phpstan-param list<int> $capability_args
	 * @param string         $policy_fingerprint Current policy version digest.
	 */
	private function operation_fingerprint( string $tool_name, string $arguments_json, string $capability, array $capability_args, string $policy_fingerprint ): string {
		$key    = hash_hmac( 'sha256', 'aculect-pending-approval-operation-v1', wp_salt( 'auth' ), true );
		$policy = $this->encode(
			array(
				'capability'         => $capability,
				'capability_args'    => array_values( $capability_args ),
				'policy_fingerprint' => $policy_fingerprint,
			)
		);
		return hash_hmac( 'sha256', $tool_name . "\0" . ( $policy ?? '' ) . "\0" . $arguments_json, $key );
	}

	private function aad( int $blog_id, int $user_id, string $client_hash, string $session_hash, string $tool, string $fingerprint ): string {
		return implode( "\0", array( 'aculect.pending-approval.v2', (string) $blog_id, (string) $user_id, $client_hash, $session_hash, $tool, $fingerprint ) );
	}

	private function encrypt( string $plain, string $aad ): ?string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return null;
		}
		try {
			$iv         = random_bytes( 12 );
			$key        = hash_hmac( 'sha256', 'aculect-pending-approval-encryption-v1', wp_salt( 'auth' ), true );
			$tag        = '';
			$ciphertext = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16 );
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! is_string( $ciphertext ) || 16 !== strlen( $tag ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Base64 only transports the authenticated encryption IV, tag and ciphertext as printable database text.
		return 'v1:' . base64_encode( $iv . $tag . $ciphertext );
	}

	private function decrypt( string $sealed, string $aad ): ?string {
		if ( ! function_exists( 'openssl_decrypt' ) || ! str_starts_with( $sealed, 'v1:' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the authenticated encryption envelope written by encrypt().
		$binary = base64_decode( substr( $sealed, 3 ), true );
		if ( ! is_string( $binary ) || 28 >= strlen( $binary ) ) {
			return null;
		}
		try {
			$key   = hash_hmac( 'sha256', 'aculect-pending-approval-encryption-v1', wp_salt( 'auth' ), true );
			$plain = openssl_decrypt( substr( $binary, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $binary, 0, 12 ), substr( $binary, 12, 16 ), $aad );
		} catch ( \Throwable ) {
			return null;
		}
		return is_string( $plain ) ? $plain : null;
	}

	private function valid_tool( string $tool ): bool {
		return 1 <= strlen( $tool ) && 64 >= strlen( $tool ) && 1 === preg_match( '/^[A-Za-z0-9_.-]+$/', $tool );
	}

	private function valid_client_id( string $client_id ): bool {
		return 1 <= strlen( $client_id ) && 191 >= strlen( $client_id ) && 1 === preg_match( '/^[A-Za-z0-9._:-]+$/', $client_id );
	}

	private function valid_session_id( string $session_id ): bool {
		return 1 <= strlen( $session_id ) && 191 >= strlen( $session_id ) && 1 === preg_match( '/^[A-Za-z0-9._:-]+$/', $session_id );
	}

	private function valid_fingerprint( string $fingerprint ): bool {
		return '' === $fingerprint || 1 === preg_match( '/^[a-f0-9]{64}$/', $fingerprint );
	}

	/**
	 * Validate a capability name and its bounded numeric arguments.
	 *
	 * @param string         $capability Capability name.
	 * @param array<int,int> $arguments Capability arguments.
	 * @phpstan-param list<int> $arguments
	 */
	private function valid_capability( string $capability, array $arguments ): bool {
		if ( 1 > strlen( $capability ) || 128 < strlen( $capability ) || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $capability ) || 8 < count( $arguments ) ) {
			return false;
		}
		foreach ( $arguments as $argument ) {
			if ( ! is_int( $argument ) || 0 >= $argument ) {
				return false;
			}
		}
		return array_is_list( $arguments );
	}

	/**
	 * Validate and bound the server-authored human review summary.
	 *
	 * @param array<string,mixed> $summary Trusted operation summary projection.
	 * @return array{operation:string,target:string,target_id:int,target_version:string,target_fingerprint?:string,risk_level:string,risk_categories:list<string>,changes:list<array{label:string,before:string,after:string}>}|null
	 */
	private function normalize_summary( array $summary ): ?array {
		if ( array_diff( array_keys( $summary ), array( 'operation', 'target', 'target_id', 'target_version', 'target_fingerprint', 'risk_level', 'risk_categories', 'changes' ) ) || ! is_string( $summary['operation'] ?? null ) || ! is_string( $summary['target'] ?? null ) || ! is_int( $summary['target_id'] ?? null ) || 0 >= $summary['target_id'] || ! is_string( $summary['target_version'] ?? null ) || 19 !== strlen( $summary['target_version'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $summary['target_version'] ) || ! is_array( $summary['changes'] ?? null ) || ! array_is_list( $summary['changes'] ) || 0 === count( $summary['changes'] ) || 20 < count( $summary['changes'] ) || ( isset( $summary['target_fingerprint'] ) && ( ! is_string( $summary['target_fingerprint'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $summary['target_fingerprint'] ) ) ) ) {
			return null;
		}
		$risk_level      = is_string( $summary['risk_level'] ?? null ) ? sanitize_key( $summary['risk_level'] ) : 'update';
		$risk_categories = is_array( $summary['risk_categories'] ?? null ) ? $summary['risk_categories'] : array();
		if ( ! in_array( $risk_level, array( 'read', 'draft', 'update', 'approve', 'publish', 'destructive', 'system' ), true ) || ! array_is_list( $risk_categories ) || 8 < count( $risk_categories ) ) {
			return null;
		}
		foreach ( $risk_categories as $category ) {
			if ( ! is_string( $category ) || ! in_array( $category, array( 'content', 'publication', 'taxonomy', 'media', 'authorship', 'destructive', 'reversible', 'read_only' ), true ) ) {
				return null;
			}
		}
		$operation = $summary['operation'];
		$target    = $summary['target'];
		if ( '' === trim( $operation ) || '' === trim( $target ) || 256 < strlen( $operation ) || 512 < strlen( $target ) ) {
			return null;
		}
		$changes = array();
		foreach ( $summary['changes'] as $change ) {
			if ( ! is_array( $change ) || array_diff( array_keys( $change ), array( 'label', 'before', 'after' ) ) || ! is_string( $change['label'] ?? null ) || '' === trim( $change['label'] ) || 128 < strlen( $change['label'] ) || ! is_string( $change['before'] ?? null ) || ! is_string( $change['after'] ?? null ) || 2048 < strlen( $change['before'] ) || 2048 < strlen( $change['after'] ) ) {
				return null;
			}
			$changes[] = array(
				'label'  => $change['label'],
				'before' => $change['before'],
				'after'  => $change['after'],
			);
		}
		return array(
			'operation'          => $operation,
			'target'             => $target,
			'target_id'          => $summary['target_id'],
			'target_version'     => $summary['target_version'],
			'target_fingerprint' => is_string( $summary['target_fingerprint'] ?? null ) ? $summary['target_fingerprint'] : '',
			'risk_level'         => $risk_level,
			'risk_categories'    => array_values( array_unique( $risk_categories ) ),
			'changes'            => $changes,
		);
	}

	/**
	 * Encode canonical JSON without lossy invalid-UTF-8 substitution.
	 *
	 * @param mixed $value JSON value.
	 */
	private function encode( mixed $value ): ?string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Reject invalid UTF-8 instead of WordPress's lossy repair because this serialization defines an exact operation fingerprint.
			$json = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR, self::MAX_JSON_DEPTH + 2 );
		} catch ( \JsonException ) {
			return null;
		}
		return $json;
	}
}

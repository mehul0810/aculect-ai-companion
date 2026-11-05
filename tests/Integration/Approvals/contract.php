<?php
/**
 * Native database gateway/approval proof; run only on disposable loopback WordPress.
 *
 * Usage: wp --user=<fixture-admin> eval-file contract.php disposable-loopback <zip-sha256|source-checkout>
 * This supplies an already-authorized context and already-negotiated Apps flag.
 * It does not exercise OAuth, HTTP transport, browser/admin routing, or real hosts.
 * Synthetic posts, approvals, claims and audit rows remain for inspection. The fake
 * connection lookup rows are deleted and the Apps preference restored on exit.
 *
 * @package AculectAICompanion
 */

// WP-CLI eval-file prepends code before evaluation, so strict_types is unsupported here.

use Aculect\AICompanion\Activity\Database\Installer as ActivityInstaller;
use Aculect\AICompanion\Admin\McpApprovalQueue;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionRequest;
use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\PendingApprovalInstaller;
use Aculect\AICompanion\Connectors\MCP\PendingOperationApprovalStore;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use Aculect\AICompanion\Connectors\OAuth\Database\Installer as ConnectionInstaller;
use Aculect\AICompanion\Connectors\OAuth\IssuerBinding;
use Aculect\AICompanion\Settings\McpAppsSettings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Native persistence is the subject of this disposable integration proof.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI JSON contains only bounded proof metadata.

/** Exercise production services against native WordPress storage. */
final class AculectNativeApprovalProof {

	private array $checks = array();
	private array $updates = array();
	private array $posts = array();
	private array $last_result = array();
	private string $client = '';
	private string $session = '';
	private int $audit_start = 0;

	/** Run guarded synthetic cases and emit a sanitized receipt even on failure. */
	public function run( array $arguments ): void {
		global $wpdb, $wp_version;
		$old_apps = get_option( McpAppsSettings::OPTION_ENABLED, null );
		$failed   = true;
		$failure  = '';
		try {
			$this->guard( $arguments );
			$this->seed_connection();
			McpAppsSettings::set_enabled( true );
			$this->check( McpAppsNegotiation::enabled(), 'apps_opt_in' );
			add_action( 'post_updated', array( $this, 'observe_update' ), 10, 1 );
			$this->approved_replay( true );
			$this->approved_replay( false );
			$this->declined();
			$this->changed_arguments();
			$this->stale_target();
			$this->invalid_nonce();
			$this->audit();
			$failed = false;
		} catch ( Throwable $error ) {
			// Never emit exception messages, SQL, request arguments, or credentials.
			$failure = $error instanceof LogicException ? $error->getMessage() : get_class( $error );
		} finally {
			remove_action( 'post_updated', array( $this, 'observe_update' ), 10 );
			if ( '' !== $this->client ) {
				$tables = ConnectionInstaller::table_names();
				$wpdb->delete( $tables['access_tokens'], array( 'client_id' => $this->client ) );
				$wpdb->delete( $tables['clients'], array( 'client_id' => $this->client ) );
				if ( null === $old_apps ) {
					delete_option( McpAppsSettings::OPTION_ENABLED );
				} else {
					update_option( McpAppsSettings::OPTION_ENABLED, $old_apps, false );
				}
			}
		}
		$gateway_file = ( new ReflectionClass( AbilityExecutionGateway::class ) )->getFileName();
		$queue_file   = ( new ReflectionClass( McpApprovalQueue::class ) )->getFileName();
		echo wp_json_encode(
			array(
				'proof'               => 'native-wordpress-gateway-approval',
				'result'              => $failed ? 'failed' : 'passed',
				'failure'             => $failure,
				'checks'              => $this->checks,
				'last_gateway_result' => $this->last_result,
				'wordpress'           => $wp_version,
				'php'                 => PHP_VERSION,
				'database'            => $wpdb->db_version(),
				'package_sha256'      => 'source-checkout' === ( $arguments[1] ?? '' ) ? null : ( $arguments[1] ?? '' ),
				'artifact_kind'       => 'source-checkout' === ( $arguments[1] ?? '' ) ? 'source-checkout' : 'installed-package',
				'gateway_sha256'      => hash_file( 'sha256', $gateway_file ),
				'queue_sha256'        => hash_file( 'sha256', $queue_file ),
				'synthetic_post_ids'  => $this->posts,
				'boundaries'          => array( 'seeded already-authorized context', 'already-negotiated Apps context', 'native post_updated hook counts target updates; revisions are separate', 'no OAuth endpoints', 'no HTTP transport or negotiation proof', 'no browser or real-host proof', 'single-process sequential calls; no concurrency proof' ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n";
		if ( $failed ) {
			WP_CLI::halt( 1 );
		}
	}

	/** Require explicit disposable-site opt-in and an existing administrator. */
	private function guard( array $arguments ): void {
		$this->check( defined( 'WP_CLI' ) && WP_CLI, 'wp_cli_required' );
		$this->check( 'disposable-loopback' === ( $arguments[0] ?? '' ), 'disposable_opt_in_required' );
		$this->check( 'source-checkout' === ( $arguments[1] ?? '' ) || 1 === preg_match( '/^[a-f0-9]{64}$/', $arguments[1] ?? '' ), 'artifact_identity_required' );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$harness = 'wordpress' === $host && defined( 'WP_CODEX_CLOUD_DEV' ) && true === WP_CODEX_CLOUD_DEV;
		$this->check( $harness || in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ), 'loopback_or_isolated_harness_required' );
		$this->check( 'local' === wp_get_environment_type(), 'local_environment_required' );
		$this->check( current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' ), 'fixture_admin_required' );
		$this->check( array() === ConnectionInstaller::missing_table_keys(), 'installed_connection_tables' );
		$this->check( PendingApprovalInstaller::install(), 'native_approval_table' );
	}

	/** Insert lookup fixtures directly; never register, authorize, issue or refresh. */
	private function seed_connection(): void {
		global $wpdb;
		$this->client  = 'approval-proof-' . wp_generate_uuid4();
		$this->session = 'fake-lookup-' . wp_generate_uuid4();
		$tables        = ConnectionInstaller::table_names();
		$this->check(
			1 === $wpdb->insert(
				$tables['clients'],
				array(
					'client_id'     => $this->client,
					'client_name'   => 'Disposable approval proof',
					'provider'      => 'chatgpt',
					'redirect_uris' => '[]',
					'issuer_hash'   => IssuerBinding::hash(),
					'user_id'       => get_current_user_id(),
					'revoked'       => 0,
				)
			),
			'synthetic_client_lookup_inserted'
		);
		$this->check(
			1 === $wpdb->insert(
				$tables['access_tokens'],
				array(
					'token_hash' => hash( 'sha256', $this->session ),
					'client_id'  => $this->client,
					'user_id'    => get_current_user_id(),
					'scopes'     => '["content:read","content:draft"]',
					'resource'   => Helpers::mcp_resource(),
					'revoked'    => 0,
					'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 1800 ),
				)
			),
			'synthetic_hashed_lookup_inserted'
		);
		$this->audit_start = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', ActivityInstaller::table_name() ) );
	}

	/** Observe only target post updates, excluding inserted posts and revisions. */
	public function observe_update( int $post_id ): void {
		if ( isset( $this->updates[ $post_id ] ) ) {
			++$this->updates[ $post_id ];
		}
	}

	/** Make a unique synthetic draft with an independently readable native row. */
	private function post(): int {
		$id = wp_insert_post( array( 'post_title' => 'Approval proof original', 'post_content' => 'Synthetic proof content.', 'post_status' => 'draft', 'post_author' => get_current_user_id() ), true );
		$this->check( is_int( $id ) && 0 < $id, 'synthetic_post_created' );
		$this->posts[]        = $id;
		$this->updates[ $id ] = 0;
		return $id;
	}

	/** Invoke a fresh production gateway with only a supplied authenticated context. */
	private function call( array $arguments ): array {
		$request = new AbilityExecutionRequest(
			array( 'name' => 'content_workflow_update_post', 'arguments' => $arguments ),
			array(
				'user_id'      => get_current_user_id(),
				'client_id'    => $this->client,
				'token_id'     => $this->session,
				'provider'     => 'chatgpt',
				'scopes'       => array( 'content:read', 'content:draft' ),
				'profile'      => 'full_access',
				'access_level' => ConnectionAccessLevel::READ,
			)
		);
		$outcome = McpAppsNegotiation::with_request_enabled( true, static fn () => ( new AbilityExecutionGateway() )->execute( $request ) );
		$result  = $outcome->data['result'] ?? array();
		$this->last_result = array_intersect_key( $result, array_flip( array( 'status', 'error', 'outcome', 'no_write', 'replayed' ) ) );
		$this->last_result['gateway_type'] = $outcome->type;
		return $result;
	}

	/** Find only this run's newest native approval row without reading its payload. */
	private function latest(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i WHERE client_hash = %s', PendingApprovalInstaller::table_name(), hash_hmac( 'sha256', $this->client, wp_salt( 'auth' ) ) ) );
	}

	/** Read persisted state directly, bypassing post and approval caches. */
	private function status( int $id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', PendingApprovalInstaller::table_name(), $id ) );
	}

	/** Assert native target data and exact target update count. */
	private function unchanged( int $id, string $title, int $updates, string $label ): void {
		global $wpdb;
		$actual = $wpdb->get_var( $wpdb->prepare( 'SELECT post_title FROM %i WHERE ID = %d', $wpdb->posts, $id ) );
		$this->check( $title === $actual && $updates === $this->updates[ $id ], $label );
	}

	/** Use the actual admin processor, native nonce and native authority recheck. */
	private function decide( int $id, string $decision, bool $valid_nonce = true ): string {
		$pending = ( new PendingOperationApprovalStore() )->find_pending_for_current_actor( $id );
		$this->check( is_array( $pending ), 'pending_visible_to_native_admin' );
		$nonce = $valid_nonce ? wp_create_nonce( 'aculect_mcp_approval_' . $id . '_' . $pending['operation_fingerprint'] ) : 'invalid-proof-nonce';
		return ( new McpApprovalQueue() )->process_decision( array( 'request_id' => (string) $id, 'decision' => $decision, '_wpnonce' => $nonce ) );
	}

	/** Approval is inert; exact execution occurs once and replay preserves human edits. */
	private function approved_replay( bool $with_key ): void {
		$id   = $this->post();
		$args = array( 'id' => $id, 'title' => 'Approval proof accepted' );
		$case = $with_key ? 'keyed' : 'unkeyed';
		if ( $with_key ) {
			$args['idempotency_key'] = 'approval-proof-' . $id;
		}
		$this->check( 'approval_pending' === ( $this->call( $args )['status'] ?? '' ), $case . '_pending' );
		$approval = $this->latest();
		$this->unchanged( $id, 'Approval proof original', 0, $case . '_pending_no_write' );
		$this->check( 'approved' === $this->decide( $approval, 'approved' ) && 'approved' === $this->status( $approval ), $case . '_approval_persisted' );
		$this->unchanged( $id, 'Approval proof original', 0, $case . '_decision_no_write' );
		$this->check( 'success' === ( $this->call( $args )['outcome'] ?? '' ), $case . '_exact_resubmit_success' );
		$this->unchanged( $id, 'Approval proof accepted', 1, $case . '_exactly_one_target_update' );
		$this->check( 'consumed' === $this->status( $approval ), $case . '_approval_consumed' );
		$this->check( $id === wp_update_post( array( 'ID' => $id, 'post_title' => 'Approval proof later human edit' ), true ), $case . '_native_human_edit' );
		$replay = $this->call( $args );
		$this->check( 'success' === ( $replay['outcome'] ?? '' ) && true === ( $replay['replayed'] ?? false ), $case . '_replayed_receipt' );
		$this->unchanged( $id, 'Approval proof later human edit', 2, $case . '_replay_preserves_human_edit_without_update' );
	}

	/** A declined exact request stays blocked on resubmission. */
	private function declined(): void {
		$id   = $this->post();
		$args = array( 'id' => $id, 'title' => 'Approval proof declined' );
		$this->check( 'approval_pending' === ( $this->call( $args )['status'] ?? '' ), 'decline_pending' );
		$approval = $this->latest();
		$this->check( 'declined' === $this->decide( $approval, 'declined' ), 'decline_decision' );
		$this->unchanged( $id, 'Approval proof original', 0, 'decline_decision_no_write' );
		$this->check( 'approval_declined' === ( $this->call( $args )['error'] ?? '' ) && 'declined' === $this->status( $approval ), 'declined_resubmit_blocked' );
		$this->unchanged( $id, 'Approval proof original', 0, 'declined_resubmit_no_write' );
	}

	/** Changed arguments cannot consume a prior approval. */
	private function changed_arguments(): void {
		$id   = $this->post();
		$args = array( 'id' => $id, 'title' => 'Approval proof original proposal' );
		$this->check( 'approval_pending' === ( $this->call( $args )['status'] ?? '' ), 'changed_args_pending' );
		$approval = $this->latest();
		$this->check( 'approved' === $this->decide( $approval, 'approved' ), 'changed_args_original_approved' );
		$args['title'] = 'Approval proof changed proposal';
		$this->check( 'approval_pending' === ( $this->call( $args )['status'] ?? '' ), 'changed_args_need_new_approval' );
		$this->check( $approval !== $this->latest() && 'approved' === $this->status( $approval ), 'changed_args_do_not_consume_original' );
		$this->unchanged( $id, 'Approval proof original', 0, 'changed_args_no_write' );
	}

	/** A human edit after approval makes the exact approved operation stale. */
	private function stale_target(): void {
		$id   = $this->post();
		$args = array( 'id' => $id, 'title' => 'Approval proof stale proposal' );
		$this->check( 'approval_pending' === ( $this->call( $args )['status'] ?? '' ), 'stale_pending' );
		$approval = $this->latest();
		$this->check( 'approved' === $this->decide( $approval, 'approved' ), 'stale_original_approved' );
		$this->check( $id === wp_update_post( array( 'ID' => $id, 'post_title' => 'Approval proof newer human edit' ), true ), 'stale_native_human_edit' );
		$this->check( 'approval_stale' === ( $this->call( $args )['error'] ?? '' ), 'stale_target_rejected' );
		$this->check( 'approved' === $this->status( $approval ), 'stale_approval_not_consumed' );
		$this->unchanged( $id, 'Approval proof newer human edit', 1, 'stale_resubmit_preserves_human_edit' );
	}

	/** A forged nonce cannot record consent or write the post. */
	private function invalid_nonce(): void {
		$id   = $this->post();
		$args = array( 'id' => $id, 'title' => 'Approval proof nonce proposal' );
		$this->check( 'approval_pending' === ( $this->call( $args )['status'] ?? '' ), 'nonce_pending' );
		$approval = $this->latest();
		$this->check( 'invalid' === $this->decide( $approval, 'approved', false ) && 'pending' === $this->status( $approval ), 'invalid_nonce_no_decision' );
		$this->unchanged( $id, 'Approval proof original', 0, 'invalid_nonce_no_write' );
	}

	/** Require correlated native audit events without proposal or lookup values. */
	private function audit(): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT action, context FROM %i WHERE id > %d ORDER BY id ASC LIMIT 200', ActivityInstaller::table_name(), $this->audit_start ), ARRAY_A );
		$refs = array();
		foreach ( $rows as $row ) {
			$context = (string) $row['context'];
			$this->check( ! str_contains( $context, 'Approval proof' ) && ! str_contains( $context, $this->session ), 'audit_contains_no_proposal_or_lookup' );
			$data = json_decode( $context, true );
			if ( isset( $data['approval_ref'] ) ) {
				$refs[ $data['approval_ref'] ][] = $row['action'];
			}
		}
		$correlated = 0;
		foreach ( $refs as $events ) {
			if ( array() === array_diff( array( 'mcp.timeline.approval_decision', 'mcp.timeline.approval_consumed', 'mcp.timeline.approval_execution' ), $events ) ) {
				++$correlated;
			}
		}
		$this->check( 2 === $correlated, 'both_executions_have_correlated_native_audit' );
	}

	/** Fail with a fixed assertion name, never fixture or exception values. */
	private function check( bool $passed, string $name ): void {
		$this->checks[] = array( 'check' => $name, 'passed' => $passed );
		if ( ! $passed ) {
			throw new LogicException( $name );
		}
	}
}

( new AculectNativeApprovalProof() )->run( $args ?? array() );

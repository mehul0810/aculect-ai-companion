<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Activity\ActivityLogger;
use Aculect\AICompanion\Connectors\MCP\PendingOperationApprovalStore;
use Closure;

/**
 * Authenticated WordPress queue for reviewing pending exact MCP operations.
 *
 * Registering this class is intentionally separate from plugin bootstrap. Its
 * decisions are inert records and do not authorize or execute MCP operations.
 */
final class McpApprovalQueue {

	public const PAGE_SLUG          = 'aculect-mcp-approvals';
	private const ADMIN_POST_ACTION = 'aculect_mcp_approval_decide';
	private const NOTICE_TRANSIENT  = 'aculect_mcp_approval_notice_';

	private PendingOperationApprovalStore $store;

	/**
	 * Timeline recorder for sanitized WordPress admin decisions.
	 *
	 * @var Closure(string, array<string, mixed>, array<string, mixed>): void
	 */
	private Closure $timeline_recorder;

	/**
	 * Create the authenticated admin approval queue.
	 *
	 * @param PendingOperationApprovalStore|null $store Encrypted approval store.
	 * @param Closure|null                       $timeline_recorder Sanitized event callback.
	 * @phpstan-param Closure(string, array<string, mixed>, array<string, mixed>): void|null $timeline_recorder
	 */
	public function __construct( ?PendingOperationApprovalStore $store = null, ?Closure $timeline_recorder = null ) {
		$this->store             = $store ?? new PendingOperationApprovalStore();
		$this->timeline_recorder = $timeline_recorder ?? static function ( string $event, array $metadata, array $auth ): void {
			( new ActivityLogger() )->record_timeline_event( $event, $metadata, $auth );
		};
	}

	/** Register the admin queue and logged-in POST action. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_' . self::ADMIN_POST_ACTION, array( $this, 'handle_decision' ) );
	}

	/** Register the hidden-from-public, authenticated review screen. */
	public function register_page(): void {
		add_options_page(
			__( 'Pending MCP approvals', 'aculect-ai-companion' ),
			__( 'Pending MCP approvals', 'aculect-ai-companion' ),
			'read',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/** Render only the current actor's site-bound pending queue. */
	public function render(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You cannot review pending operations.', 'aculect-ai-companion' ), '', array( 'response' => 403 ) );
		}
		$requests = $this->store->pending_for_current_actor();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Pending MCP approvals', 'aculect-ai-companion' ); ?></h1>
			<p><?php echo esc_html__( 'Review each exact operation below. Approving or declining records only your decision; it does not run the operation.', 'aculect-ai-companion' ); ?></p>
			<?php $this->render_notice(); ?>
			<?php if ( is_wp_error( $requests ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $requests->get_error_message() ); ?></p></div>
			<?php elseif ( array() === $requests ) : ?>
				<p><?php echo esc_html__( 'There are no pending operations for this WordPress user on this site.', 'aculect-ai-companion' ); ?></p>
			<?php else : ?>
				<?php foreach ( $requests as $request ) : ?>
					<?php $this->render_request( $request ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Process a submitted decision without performing redirects or tool execution.
	 *
	 * @param array<string,mixed> $input Uns lashed POST payload.
	 * @return string Bounded result code safe for an admin status notice.
	 */
	public function process_decision( array $input ): string {
		$id = isset( $input['request_id'] ) && is_scalar( $input['request_id'] ) ? absint( (string) $input['request_id'] ) : 0;
		if ( 0 >= $id ) {
			return 'invalid';
		}
		$request = $this->store->find_pending_for_current_actor( $id );
		if ( null === $request ) {
			return 'unavailable';
		}
		$nonce = is_string( $input['_wpnonce'] ?? null ) ? $input['_wpnonce'] : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $this->nonce_action( $request ) ) ) {
			return 'invalid';
		}
		$decision = is_string( $input['decision'] ?? null ) ? $input['decision'] : '';
		if ( ! in_array( $decision, array( 'approved', 'declined' ), true ) ) {
			return 'invalid';
		}

		if ( ! $this->store->decide_for_current_actor( $id, $decision ) ) {
			return 'unavailable';
		}
		try {
			( $this->timeline_recorder )(
				'approval_decision',
				array(
					'method'              => 'admin_decision',
					'tool'                => (string) $request['tool_name'],
					'status'              => 'approved' === $decision ? 'validated' : 'blocked',
					'result_class'        => $decision,
					'confirmation_policy' => 'wordpress_human_approval',
					'approval_ref'        => PendingOperationApprovalStore::audit_reference( $request ),
				),
				array(
					'provider' => 'wordpress_admin',
					'user_id'  => get_current_user_id(),
				)
			);
		} catch ( \Throwable $throwable ) {
			// Audit storage is best effort and must not change the human decision.
			unset( $throwable );
		}
		return $decision;
	}

	/** Handle the logged-in POST-only decision endpoint. */
	public function handle_decision(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'A logged-in WordPress session is required to decide this request.', 'aculect-ai-companion' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- process_decision checks the request-specific nonce before using the posted decision.
		$input  = wp_unslash( $_POST );
		$status = $this->process_decision( $input );
		set_transient( self::NOTICE_TRANSIENT . get_current_user_id(), $status, 60 );
		wp_safe_redirect(
			admin_url( 'options-general.php?page=' . self::PAGE_SLUG ),
			303
		);
		exit;
	}

	/** Show and consume a short-lived, actor-scoped decision receipt. */
	private function render_notice(): void {
		$status = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
		$messages = array(
			'approved'    => __( 'Approval recorded. This did not execute the operation; the MCP client must resubmit it.', 'aculect-ai-companion' ),
			'declined'    => __( 'The operation was declined. No operation was executed.', 'aculect-ai-companion' ),
			'invalid'     => __( 'The decision could not be verified. No decision was recorded.', 'aculect-ai-companion' ),
			'unavailable' => __( 'The request is unavailable or has expired. No operation was executed.', 'aculect-ai-companion' ),
		);
		if ( ! is_string( $status ) || ! isset( $messages[ $status ] ) ) {
			return;
		}
		$notice_class = in_array( $status, array( 'approved', 'declined' ), true ) ? 'notice-success' : 'notice-error';
		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible"><p><?php echo esc_html( $messages[ $status ] ); ?></p></div>
		<?php
	}

	/**
	 * Render a bounded, escaped exact operation with POST-only choices.
	 *
	 * @param array<string,mixed> $request Decrypted, actor-scoped pending request.
	 */
	private function render_request( array $request ): void {
		$summary = $request['review_summary'];
		?>
		<section class="card" aria-labelledby="mcp-approval-<?php echo esc_attr( (string) $request['id'] ); ?>">
			<h2 id="mcp-approval-<?php echo esc_attr( (string) $request['id'] ); ?>"><?php echo esc_html( $request['tool_name'] ); ?></h2>
			<p><strong><?php echo esc_html__( 'Operation:', 'aculect-ai-companion' ); ?></strong> <?php echo esc_html( $summary['operation'] ); ?></p>
			<p><strong><?php echo esc_html__( 'Target:', 'aculect-ai-companion' ); ?></strong> <?php echo esc_html( $summary['target'] ); ?></p>
			<p><strong><?php echo esc_html__( 'Risk level:', 'aculect-ai-companion' ); ?></strong> <?php echo esc_html( ucfirst( $summary['risk_level'] ) ); ?></p>
			<p><strong><?php echo esc_html__( 'Risk categories:', 'aculect-ai-companion' ); ?></strong> <?php echo esc_html( '' === implode( ', ', $summary['risk_categories'] ) ? __( 'Content update', 'aculect-ai-companion' ) : implode( ', ', $summary['risk_categories'] ) ); ?></p>
			<table class="widefat striped">
				<thead><tr><th><?php echo esc_html__( 'Change', 'aculect-ai-companion' ); ?></th><th><?php echo esc_html__( 'Before', 'aculect-ai-companion' ); ?></th><th><?php echo esc_html__( 'Proposed', 'aculect-ai-companion' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $summary['changes'] as $change ) : ?>
						<tr><th scope="row"><?php echo esc_html( $change['label'] ); ?></th><td><pre><?php echo esc_html( $change['before'] ); ?></pre></td><td><pre><?php echo esc_html( $change['after'] ); ?></pre></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><?php echo esc_html__( 'This request expires at:', 'aculect-ai-companion' ); ?> <time><?php echo esc_html( $request['expires_at'] ); ?> UTC</time></p>
			<p><?php echo esc_html__( 'Your decision records consent only. The MCP client must resubmit this exact operation, and WordPress will recheck access, policy, and target version before execution.', 'aculect-ai-companion' ); ?></p>
			<?php
			foreach ( array(
				'approved' => __( 'Approve this operation', 'aculect-ai-companion' ),
				'declined' => __( 'Decline this operation', 'aculect-ai-companion' ),
			) as $decision => $label ) :
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_POST_ACTION ); ?>">
					<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $request['id'] ); ?>">
					<input type="hidden" name="decision" value="<?php echo esc_attr( $decision ); ?>">
					<?php wp_nonce_field( $this->nonce_action( $request ) ); ?>
					<button type="submit" class="button"><?php echo esc_html( $label ); ?></button>
				</form>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * Bind each nonce to one row and its immutable operation fingerprint.
	 *
	 * @param array<string,mixed> $request Decoded server-side request.
	 */
	private function nonce_action( array $request ): string {
		return 'aculect_mcp_approval_' . (int) $request['id'] . '_' . (string) $request['operation_fingerprint'];
	}
}

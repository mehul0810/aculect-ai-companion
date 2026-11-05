<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

/** Route and navigation for actor-scoped approvals inside the Aculect admin page. */
final class ApprovalTab {

	public const TAB = 'approvals';

	/** The queue shares the plugin page, not a second Settings page. */
	public static function url(): string {
		return admin_url( 'options-general.php?page=aculect-ai-companion&tab=' . self::TAB );
	}

	/** Read-only routing value; settings data is never loaded for this tab. */
	public static function is_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab routing.
		return isset( $_GET['tab'] ) && self::TAB === sanitize_key( wp_unslash( (string) $_GET['tab'] ) );
	}

	/** Readers see only their queue; managers see it when the tab is selected. */
	private static function is_visible(): bool {
		return self::is_requested() || ! current_user_can( 'manage_options' );
	}

	/** Render the actor-only view when it owns this admin request. */
	public static function render_if_needed(): bool {
		if ( ! self::is_visible() ) {
			return false;
		}
		( new McpApprovalQueue() )->render();
		return true;
	}

	/** Enqueue styles only for the approval view. */
	public static function enqueue_if_needed(): bool {
		if ( ! self::is_visible() ) {
			return false;
		}
		self::enqueue_style();
		return true;
	}

	/** Load only styling, never settings scripts or localized settings data. */
	private static function enqueue_style(): void {
		if ( file_exists( ACULECT_AI_COMPANION_PLUGIN_DIR . 'build/style-index.css' ) ) {
			wp_enqueue_style( 'aculect-ai-companion-settings-style', ACULECT_AI_COMPANION_PLUGIN_URL . 'build/style-index.css', array( 'wp-components' ), ACULECT_AI_COMPANION_VERSION );
		}
		wp_enqueue_style( 'aculect-ai-companion-approval-queue', ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/css/approval-queue.css', array(), ACULECT_AI_COMPANION_VERSION );
	}

	/** Render queue-only navigation, with settings return reserved for managers. */
	public static function render_navigation(): void {
		?>
		<div class="aculect-ai-companion-tabs-shell"><nav class="aculect-ai-companion-tabs" aria-label="<?php echo esc_attr( __( 'Aculect AI Companion', 'aculect-ai-companion' ) ); ?>">
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a class="aculect-ai-companion-tab" href="<?php echo esc_url( admin_url( 'options-general.php?page=aculect-ai-companion' ) ); ?>"><?php echo esc_html__( 'Overview', 'aculect-ai-companion' ); ?></a>
			<?php endif; ?>
			<a class="aculect-ai-companion-tab is-active" aria-current="page" href="<?php echo esc_url( self::url() ); ?>"><?php echo esc_html__( 'Pending MCP approvals', 'aculect-ai-companion' ); ?></a>
		</nav></div>
		<?php
	}
}

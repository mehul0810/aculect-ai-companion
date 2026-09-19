<?php
/**
 * Standalone private form: no AI bridge, external scripts or value-bearing redirects.
 *
 * @package Aculect\AICompanion\Admin
 */

declare(strict_types=1);
namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Settings\PrivateSettingRequests;
use Aculect\AICompanion\Settings\PrivateSettingTargets;
use Aculect\AICompanion\Settings\PrivateSettingAudit;

/** Authenticated native browser POST owns all user input. */
final class PrivateSettingForm {
	public function register(): void {
		add_action( 'admin_post_aculect_private_setting', array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_aculect_private_setting', array( $this, 'login' ) );
	}

	public function login(): void {
		if ( ! is_ssl() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Sign in and request a fresh form.', 'aculect-ai-companion' ), '', array( 'response' => 403 ) );
		}
		auth_redirect();
	}

	public function handle(): void {
		nocache_headers();
		header( "Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'" );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		if ( ! is_ssl() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'An HTTPS administrator session is required.', 'aculect-ai-companion' ), '', array( 'response' => 403 ) );
		}
		$method = $_SERVER['REQUEST_METHOD'] ?? '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- GET only identifies a user-bound request; POST nonce is checked before input use below.
		$input    = 'POST' === $method ? $_POST : $_GET;
		$id       = is_string( $input['request_id'] ?? null ) ? wp_unslash( $input['request_id'] ) : '';
		$requests = new PrivateSettingRequests();
		$record   = $requests->lookup( $id );
		if ( array() === $record || ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
			wp_die( esc_html__( 'This request expired or belongs to another account. Request a fresh form.', 'aculect-ai-companion' ), '', array( 'response' => 403 ) );
		}
		if ( 'POST' === $method ) {
			$nonce = is_string( $input['_wpnonce'] ?? null ) ? wp_unslash( $input['_wpnonce'] ) : '';
			if ( ! wp_verify_nonce( $nonce, 'aculect_private_setting_' . $id ) ) {
				wp_die( esc_html__( 'The form could not be verified. Request a fresh form.', 'aculect-ai-companion' ), '', array( 'response' => 403 ) );
			}
			$value  = is_string( $input['private_value'] ?? null ) ? wp_unslash( $input['private_value'] ) : null;
			$result = $requests->submit( $id, $value );
			unset( $value, $input, $_POST['private_value'], $_REQUEST['private_value'] );
			( new PrivateSettingAudit() )->record( $record['target'], (string) ( $result['status'] ?? 'error' ) );
			wp_safe_redirect(
				add_query_arg(
					array(
						'action'     => 'aculect_private_setting',
						'request_id' => $id,
					),
					admin_url( 'admin-post.php', 'https' )
				),
				303
			);
			exit;
		}
		$this->render( $record );
		exit;
	}

	/**
	 * Render only fixed labels and sanitized operation status. Inputs are always blank.
	 *
	 * @param array<string,mixed> $record Request metadata.
	 */
	private function render( array $record ): void {
		$target = ( new PrivateSettingTargets() )->get( $record['target'] );
		if ( null === $target ) {
			return;
		}
		$numeric    = in_array( $record['target'], array( 'posts_per_page', 'default_category' ), true );
		$short_text = in_array( $record['target'], array( 'site_title', 'tagline' ), true );
		header( 'Content-Type: text/html; charset=UTF-8' );
		wp_enqueue_style( 'aculect-private-settings', plugins_url( 'assets/css/private-settings.css', ACULECT_AI_COMPANION_PLUGIN_FILE ), array(), '0.8.0' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?php esc_html_e( 'Aculect private settings', 'aculect-ai-companion' ); ?></title><?php wp_print_styles( array( 'aculect-private-settings' ) ); ?></head>
<body><main>
<h1><?php esc_html_e( 'Update a WordPress setting privately', 'aculect-ai-companion' ); ?></h1>
<p><?php echo esc_html( (string) wp_parse_url( admin_url(), PHP_URL_HOST ) ); ?> — <?php echo esc_html( $target['group'] . ': ' . $target['label'] ); ?></p>
<aside role="note"><p id="private-safety"><?php esc_html_e( 'Enter the value here, not in your AI chat. Aculect sends only the outcome back to the assistant. This form expires after ten minutes.', 'aculect-ai-companion' ); ?></p></aside>
		<?php if ( 'pending' === $record['status'] ) : ?>
			<?php if ( $target['secret'] ) : ?>
<p><?php esc_html_e( 'The key will be checked using the WordPress AI provider SDK, which may contact its provider, and stored using WordPress Connectors storage. Hosting and other installed plugins remain part of the trusted environment.', 'aculect-ai-companion' ); ?></p>
	<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php', 'https' ) ); ?>" autocomplete="off">
<input type="hidden" name="action" value="aculect_private_setting">
<input type="hidden" name="request_id" value="<?php echo esc_attr( $record['id'] ); ?>">
			<?php wp_nonce_field( 'aculect_private_setting_' . $record['id'], '_wpnonce', false ); ?>
<label for="private_value"><?php echo esc_html( $target['label'] ); ?></label>
<input id="private_value" name="private_value" type="<?php echo $target['secret'] ? 'password' : ( $numeric ? 'number' : 'text' ); ?>" autocomplete="off" maxlength="<?php echo $short_text ? '300' : '4096'; ?>" spellcheck="false" aria-describedby="private-safety private-submit private-format" <?php echo 'tagline' === $record['target'] ? '' : 'required'; ?>
			<?php
			if ( $numeric ) :
				?>
	inputmode="numeric" min="1" step="1" max="<?php echo 'posts_per_page' === $record['target'] ? '100' : '999999999'; ?>"<?php endif; ?>>
<p id="private-format"><?php echo esc_html( $short_text ? __( 'Maximum 300 UTF-8 bytes; some characters use multiple bytes.', 'aculect-ai-companion' ) : __( 'Use the format shown in the field label. Required fields cannot be blank.', 'aculect-ai-companion' ) ); ?></p>
<p id="private-submit"><?php esc_html_e( 'Submitting replaces this setting. Invalid input leaves the existing value unchanged and requires a fresh form. To cancel, close this page.', 'aculect-ai-companion' ); ?></p>
<button type="submit"><?php esc_html_e( 'Confirm and update', 'aculect-ai-companion' ); ?></button>
</form>
		<?php else : ?>
<p role="status"><?php echo esc_html( 'updated' === $record['status'] ? __( 'Setting updated. You can return to your assistant.', 'aculect-ai-companion' ) : __( 'This request is no longer pending. If the update did not complete, request a new form. No input value is shown.', 'aculect-ai-companion' ) ); ?></p>
		<?php endif; ?>
</main></body></html>
		<?php
	}
}

<?php
/**
 * Install disposable OAuth contract-test hooks in the WordPress fixture.
 *
 * This file is invoked with `wp eval-file` by the OAuth browser workflow. It
 * writes a small MU plugin so the competitor rewrite and lifecycle probe are
 * present on subsequent HTTP requests without changing the product package.
 *
 * @package Aculect_AI_Companion
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( '1' !== getenv( 'ACULECT_OAUTH_FIXTURE_OPT_IN' ) ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::error( 'OAuth contract fixture requires explicit disposable opt-in.' );
	}
	exit;
}

$site_url  = wp_parse_url( home_url( '/' ) );
$site_host = is_array( $site_url ) ? trim( strtolower( (string) ( $site_url['host'] ?? '' ) ), '[]' ) : '';
if (
	! is_array( $site_url )
	|| 'http' !== strtolower( (string) ( $site_url['scheme'] ?? '' ) )
	|| ! in_array( $site_host, array( 'localhost', '127.0.0.1', '::1' ), true )
) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::error( 'OAuth contract fixture requires an HTTP loopback WordPress site.' );
	}
	exit;
}

$mu_plugin_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
if ( ! wp_mkdir_p( $mu_plugin_dir ) ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::error( 'OAuth contract fixture directory could not be created.' );
	}
	exit;
}

$fixture = <<<'PHP'
<?php
/**
 * Disposable OAuth contract-test fixture. Never ship this file.
 */

declare(strict_types=1);

add_filter(
	'query_vars',
	static function ( array $vars ): array {
		$vars[] = 'aculect_oauth_contract_competitor';

		return $vars;
	}
);

if ( ! function_exists( 'aculect_oauth_contract_register_competitor_rewrite' ) ) {
	function aculect_oauth_contract_register_competitor_rewrite(): void {
		// Registered late so this intentionally wins over Aculect's generic alias.
		add_rewrite_rule(
			'^oauth/authorize/?$',
			'index.php?aculect_oauth_contract_competitor=1',
			'top'
		);
	}
}

add_action( 'init', 'aculect_oauth_contract_register_competitor_rewrite', 999 );

add_action(
	'template_redirect',
	static function (): void {
		if ( '1' !== (string) get_query_var( 'aculect_oauth_contract_competitor' ) ) {
			return;
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'oauth-competitor-fixture';
		exit;
	},
	0
);

add_action(
	'wp_ajax_aculect_oauth_fixture_nonce',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		wp_send_json_success(
			array( 'nonce' => wp_create_nonce( 'aculect_oauth_fixture_lifecycle' ) )
		);
	}
);

add_action(
	'wp_ajax_aculect_oauth_fixture_lifecycle',
	static function (): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		check_ajax_referer( 'aculect_oauth_fixture_lifecycle' );

		$operation = isset( $_POST['operation'] ) && is_scalar( $_POST['operation'] )
			? sanitize_key( wp_unslash( (string) $_POST['operation'] ) )
			: '';
		if ( 'cycle' !== $operation ) {
			wp_send_json_error( 'unsupported_operation', 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin = 'aculect-ai-companion/aculect-ai-companion.php';
		deactivate_plugins( $plugin );
		$activation = activate_plugin( $plugin );
		if ( is_wp_error( $activation ) ) {
			wp_send_json_error( 'activation_failed', 500 );
		}

		// Activation registers the plugin alias again after init. Restore the
		// intentionally competing fixture rule before persisting the collision.
		aculect_oauth_contract_register_competitor_rewrite();
		flush_rewrite_rules( true );
		wp_send_json_success( array( 'status' => 'cycle_complete' ) );
	}
);
PHP;

$fixture_path = trailingslashit( $mu_plugin_dir ) . 'aculect-oauth-contract-fixture.php';
if ( false === file_put_contents( $fixture_path, $fixture, LOCK_EX ) ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::error( 'OAuth contract fixture could not be installed.' );
	}
	exit;
}

require_once $fixture_path;

// wp eval-file runs before a normal HTTP init. Register the fixture's native
// rewrite callback in this process before flushing the disposable site.
aculect_oauth_contract_register_competitor_rewrite();
flush_rewrite_rules( true );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::success( 'OAuth contract fixture installed.' );
}

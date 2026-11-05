<?php
/**
 * Native WordPress WebMCP proof, restricted to an opted-in disposable local site.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

// Native request fixtures intentionally replace WordPress query globals and parse script markup.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited,WordPress.WP.EnqueuedResources.NonEnqueuedScript

if ( '1' !== getenv( 'ACULECT_WEBMCP_FIXTURE' ) || 'cli' !== PHP_SAPI ) {
	throw new RuntimeException( 'An explicitly opted-in disposable CLI fixture is required.' );
}
$root = getenv( 'ACULECT_WEBMCP_WP_ROOT' );
if ( ! is_string( $root ) || ! is_file( $root . '/wp-load.php' ) ) {
	throw new RuntimeException( 'A disposable WordPress root is required.' );
}
require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), array( 'localhost', '127.0.0.1' ), true ) ) {
	throw new RuntimeException( 'Only a local loopback fixture is permitted.' );
}

$checks = array();
$verify = static function ( bool $condition, string $name ) use ( &$checks ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Failed native WebMCP clause: ' . esc_html( $name ) );
	}
	$checks[] = $name;
};
$assets = new \Aculect\AICompanion\WebMCP\WebMcpAssets();
$posts  = array();

try {
	add_filter( 'pre_wp_mail', '__return_true' );
	foreach ( array( 'publish', 'draft', 'private' ) as $status ) {
		$posts[ $status ] = wp_insert_post(
			array(
				'post_title'  => 'Disposable WebMCP ' . $status,
				'post_status' => $status,
				'post_type'   => 'page',
			)
		);
	}
	$posts['password'] = wp_insert_post(
		array(
			'post_title'    => 'Disposable protected page',
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_password' => 'synthetic-proof',
		)
	);
	foreach ( $posts as $kind => $id ) {
		wp_set_current_user( 0 );
		$GLOBALS['wp_query'] = new WP_Query(
			array(
				'page_id'     => $id,
				'post_status' => 'any',
			)
		);
		$GLOBALS['post']     = $GLOBALS['wp_query']->post;
		wp_dequeue_script( 'aculect-ai-companion-webmcp' );
		$assets->enqueue();
		$verify( wp_script_is( 'aculect-ai-companion-webmcp', 'enqueued' ) === ( 'publish' === $kind ), 'request-' . $kind );
	}
	$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $posts['publish'] ) );
	$GLOBALS['post']     = $GLOBALS['wp_query']->post;
	wp_set_current_user( 1 );
	wp_dequeue_script( 'aculect-ai-companion-webmcp' );
	$assets->enqueue();
	$verify( ! wp_script_is( 'aculect-ai-companion-webmcp', 'enqueued' ), 'logged-in-denied' );
	wp_set_current_user( 0 );
	add_filter( 'aculect_ai_companion_webmcp_enabled', '__return_false' );
	$assets->enqueue();
	$verify( ! wp_script_is( 'aculect-ai-companion-webmcp', 'enqueued' ), 'site-filter-disabled' );
	remove_filter( 'aculect_ai_companion_webmcp_enabled', '__return_false' );
	$diagnostics = new \Aculect\AICompanion\Diagnostics\WebMcpReadiness();
	$asset       = ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/js/webmcp.js';
	foreach ( array(
		'valid'                => array( '<script defer src="' . esc_url( $asset ) . '?ver=0.9.0"></script>', true ),
		'wrong-origin'         => array( '<script src="https://elsewhere.invalid' . wp_parse_url( $asset, PHP_URL_PATH ) . '"></script>', false ),
		'inert-json'           => array( '<script type="application/json" src="' . esc_url( $asset ) . '"></script>', false ),
		'comment'              => array( '<!-- <script src="' . esc_url( $asset ) . '"></script> -->', false ),
		'template'             => array( '<template><script src="' . esc_url( $asset ) . '"></script></template>', false ),
		'noscript'             => array( '<noscript><script src="' . esc_url( $asset ) . '"></script></noscript>', false ),
		'double-encoded-query' => array( '<script src="' . esc_url( $asset ) . '&amp;#x3f;other"></script>', false ),
		'after-template'       => array( '<template>Inert</template><script src="' . esc_url( $asset ) . '"></script>', true ),
		'after-noscript'       => array( '<noscript>Inert</noscript><script src="' . esc_url( $asset ) . '"></script>', true ),
	) as $name => $case ) {
		$item = $diagnostics->item(
			array(
				'status'       => 200,
				'content_type' => 'text/html',
				'body'         => $case[0],
			)
		);
		$verify( $item['evidence']['publicScriptObserved'] === $case[1], 'native-html-parser-' . $name );
		$verify( false === $item['evidence']['nativeBrowserRegistrationVerified'], 'no-browser-claim-' . $name );
	}
	echo wp_json_encode(
		array(
			'status'    => 'passed',
			'wordpress' => get_bloginfo( 'version' ),
			'checks'    => $checks,
		)
	) . PHP_EOL;
} finally {
	remove_filter( 'aculect_ai_companion_webmcp_enabled', '__return_false' );
	wp_set_current_user( 0 );
	foreach ( $posts as $id ) {
		wp_delete_post( $id, true );
	}
}

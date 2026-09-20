<?php
/**
 * Process-isolated doubles for bounded site operations.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax, Universal.Namespaces.DisallowDeclarationWithoutName, Universal.Namespaces.OneDeclarationPerFile -- Isolated native function_exists guard needs one global shim alongside namespaced doubles.

namespace {
	if ( ! function_exists( 'get_plugins' ) ) {
		function get_plugins(): array {
			return $GLOBALS['bounded_site_plugins'] ?? array();
		}
	}
}

namespace Aculect\AICompanion\Connectors\MCP {
	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.NamingConventions.ValidFunctionName, WordPress.NamingConventions.ValidVariableName, Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment, Universal.Files.SeparateFunctionsFromOO.Mixed
	class BoundedSiteFixturePost extends \WP_Post {
		public string $post_password = '';
	}
	function current_user_can( string $capability, mixed ...$args ): bool {
		$GLOBALS['bounded_site_cap_checks'][] = array( $capability, $args );
		return ! in_array( $capability, $GLOBALS['bounded_site_denied'], true );
	}
	function get_post( int $id ): ?\WP_Post {
		return $GLOBALS['bounded_site_posts'][ $id ] ?? null;
	}
	function is_post_publicly_viewable( \WP_Post $post ): bool {
		return 'publish' === $post->post_status;
	}
	function get_permalink( \WP_Post $post ): string {
		return $GLOBALS['bounded_site_permalink'] . '?p=' . $post->ID;
	}
	function home_url( string $path = '' ): string {
		return 'https://example.org' . $path;
	}
	function wp_safe_remote_get( string $url, array $args ): array|\WP_Error {
		$GLOBALS['bounded_site_requests'][] = array(
			'url'  => $url,
			'args' => $args,
		);
		return $GLOBALS['bounded_site_response'];
	}
	function wp_remote_retrieve_header( array $response, string $header ): mixed {
		return $response['headers'][ $header ] ?? '';
	}
	function esc_url_raw( string $url, array $protocols = array( 'http', 'https' ) ): string {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$clean  = filter_var( $url, FILTER_SANITIZE_URL );
		return is_string( $scheme ) && ! in_array( strtolower( $scheme ), $protocols, true ) ? '' : ( is_string( $clean ) ? $clean : '' );
	}
	function is_multisite(): bool {
		return $GLOBALS['bounded_site_multisite'];
	}
	function is_super_admin(): bool {
		return $GLOBALS['bounded_site_super_admin'];
	}
	function get_locale(): string {
		return $GLOBALS['bounded_site_locale'];
	}
	function get_plugins(): array {
		return $GLOBALS['bounded_site_plugins'];
	}
	function wp_suspend_cache_invalidation( bool $suspend = true ): bool {
		$previous                                  = (bool) ( $GLOBALS['_wp_suspend_cache_invalidation'] ?? false );
		$GLOBALS['_wp_suspend_cache_invalidation'] = $suspend; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reproduce the native setter in an isolated test process.
		return $previous;
	}
	function clean_post_cache( \WP_Post $post ): void {
		if ( ! empty( $GLOBALS['_wp_suspend_cache_invalidation'] ) ) {
			return;
		}
		$GLOBALS['bounded_site_cleaned'][] = $post->ID;
	}
	function did_action( string $action ): int {
		return 'wp_loaded' === $action && $GLOBALS['bounded_site_loaded'] ? 1 : 0;
	}
	function flush_rewrite_rules( bool $hard ): void {
		$GLOBALS['bounded_site_flushes'][] = $hard;
	}
	function get_current_blog_id(): int {
		return 7;
	}
}

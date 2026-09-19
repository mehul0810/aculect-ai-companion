<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Runs explicit, site-local maintenance through native WordPress APIs.
 */
final class TargetedMaintenanceAbilities extends AbstractAbilityService {

	/**
	 * Invalidate one content object's native caches, not a shared cache backend.
	 *
	 * @param array<string,mixed> $args Tool arguments.
	 * @return array<string,mixed>
	 */
	public function clean_post_cache( array $args ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error( 'forbidden', 'Site administration permission is required.' );
		}
		$id = $args['post_id'] ?? null;
		if ( ! is_int( $id ) || $id < 1 ) {
			return $this->error( 'invalid_post_id', 'Provide a positive content item ID.' );
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $id ) ) {
			return $this->error( 'unavailable', 'The content item is unavailable or cannot be managed.' );
		}
		// The native function is a setter even without arguments; inspect its flag without changing it.
		if ( ! empty( $GLOBALS['_wp_suspend_cache_invalidation'] ) ) {
			return $this->error( 'cache_invalidation_suspended', 'WordPress cache invalidation is suspended; no action was taken.' );
		}
		if ( $this->is_dry_run( $args ) ) {
			return $this->preview_response(
				'maintenance.clean_post_cache',
				$args,
				array( 'post_id' => $id ),
				array( $this->change( 'native_post_cache', 'current', 'invalidated' ) ),
				array( 'This does not purge CDN, page-cache, or other plugins\' caches. Native caches regenerate on demand.' )
			);
		}
		clean_post_cache( $post );
		return array(
			'status'            => 'success',
			'post_id'           => $id,
			'operation'         => 'native_post_cache_invalidation_requested',
			'page_cache_purged' => false,
			'recovery'          => 'Native caches regenerate on the next read. No stored content was deleted.',
		);
	}

	/**
	 * Rebuild this site's rewrite option without modifying server files.
	 *
	 * @param array<string,mixed> $args Tool arguments.
	 * @return array<string,mixed>
	 */
	public function flush_rewrite_rules( array $args ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error( 'forbidden', 'Site administration permission is required.' );
		}
		if ( ! did_action( 'wp_loaded' ) ) {
			return $this->error( 'runtime_not_ready', 'Rewrite rules can only be rebuilt after WordPress has loaded.' );
		}
		if ( $this->is_dry_run( $args ) ) {
			return $this->preview_response(
				'maintenance.flush_rewrite_rules',
				$args,
				array( 'site_id' => get_current_blog_id() ),
				array( $this->change( 'rewrite_rules', 'current', 'rebuilt_from_registered_routes' ) ),
				array( 'Soft flush only: no .htaccess or web.config changes, permalink setting changes, or network-wide flush.' )
			);
		}
		flush_rewrite_rules( false );
		return array(
			'status'               => 'success',
			'site_id'              => get_current_blog_id(),
			'operation'            => 'soft_rewrite_flush_requested',
			'server_files_changed' => false,
			'recovery'             => 'After correcting any route registration problem, save Settings > Permalinks to rebuild rules again.',
		);
	}
}

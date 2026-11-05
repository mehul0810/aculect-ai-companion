<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Recheck operation-specific native authority without retaining content bodies.
 */
final class PendingApprovalNativeAuthority {

	private const FIELDS = array( 'id', 'status', 'date', 'author', 'featured_media', 'clear_featured_media', 'taxonomies', 'expected_modified_gmt' );

	/**
	 * Capture only metadata needed by current native authorization and validation.
	 *
	 * @param array<string, mixed> $arguments Exact normalized operation arguments.
	 * @param int                  $post_id Reviewed target post.
	 * @return array<string, mixed>
	 */
	public static function arguments( array $arguments, int $post_id ): array {
		return array_merge( array_intersect_key( $arguments, array_flip( self::FIELDS ) ), array( 'id' => $post_id ) );
	}

	/**
	 * Reuse the native write service's non-mutating checks for the reviewed action.
	 *
	 * This covers mapped publishing/deletion, author, media and taxonomy authority
	 * as well as current target validity. No workflow callback or write executes.
	 *
	 * @param array<string, mixed> $request Authenticated encrypted request metadata.
	 */
	public static function allows( array $request ): bool {
		$args = $request['authorization_args'] ?? null;
		if ( ! is_array( $args ) || array_diff_key( $args, array_flip( self::FIELDS ) ) || (int) ( $args['id'] ?? 0 ) !== (int) $request['review_summary']['target_id'] ) {
			return false;
		}
		$result = ( new ContentAbilities() )->update_item( array_merge( $args, array( 'dry_run' => true ) ) );
		return ! isset( $result['error'] ) && true === ( $result['dry_run'] ?? false );
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Adds the negotiated post-update view without changing the workflow service contract.
 */
final class McpAppsContentUpdate {

	/**
	 * Execute the established update workflow and append its optional UI payload.
	 *
	 * @param array<string, mixed> $args Workflow arguments.
	 * @return array<string, mixed>
	 */
	public function update_post( array $args ): array {
		$post_id = absint( $args['id'] ?? 0 );
		$builder = new McpAppsPostUpdateResult();
		$before  = $builder->capture_before( $post_id );
		if ( is_array( $before ) && $before['post'] instanceof \WP_Post && ! array_key_exists( 'expected_modified_gmt', $args ) ) {
			$args['expected_modified_gmt'] = (string) $before['post']->post_modified_gmt;
		}
		$result = ( new ContentWorkflowAbilities() )->update_post( $args );
		$atomic = is_array( $result['fields'] ?? null ) ? $result['fields'] : $result;

		return $builder->append( $result, $post_id, $atomic, $before );
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Preview state-only operations whose callbacks do not implement dry runs.
 */
final class MetadataWritePreview {

	private const TOOLS = array(
		'workflow_session.start',
		'workflow_session.update',
		'workflow_loop.create',
		'workflow_loop.run_next',
		'workflow_loop.run_batch',
		'workflow_loop.pause',
		'workflow_loop.cancel',
		'content_internal_link.suggestions_create',
		'content_internal_link.suggestion_review',
	);

	/**
	 * Return a non-mutating preview, or null for native dry-run implementations.
	 *
	 * Exact arguments remain bound by the gateway's confirmation token. Callback
	 * validation still runs on confirmed execution; this preview claims no diff.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<string, mixed> $args Normalized tool arguments.
	 * @return array<string, mixed>|null
	 */
	public static function for_call( string $tool, array $args ): ?array {
		if ( empty( $args['dry_run'] ) || ! in_array( $tool, self::TOOLS, true ) ) {
			return null;
		}
		if ( 'content_internal_link.suggestion_review' === $tool ) {
			$suggestion = ( new \Aculect\AICompanion\Intelligence\InternalLinkSuggestionRepository() )->find( sanitize_key( (string) ( $args['id'] ?? $args['suggestion_id'] ?? '' ) ) );
			if ( array() === $suggestion ) {
				return array(
					'error'   => 'suggestion_not_found',
					'message' => 'No internal-link suggestion was found for that ID.',
				);
			}
			if ( ! current_user_can( 'edit_post', (int) ( $suggestion['source_post']['id'] ?? 0 ) ) ) {
				return array(
					'error'   => 'forbidden',
					'message' => 'You do not have permission to review suggestions for that source content item.',
				);
			}
		}
		return array(
			'dry_run'  => true,
			'status'   => 'preview',
			'action'   => $tool,
			'changes'  => array(),
			'warnings' => array( 'This operation has no native change preview. Its callback has not run. Confirmation authorizes only the exact submitted arguments; current permissions and callback validation still apply.' ),
		);
	}
}

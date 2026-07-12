<?php
/**
 * Derive bounded MCP learning suggestions from sanitized activity logs.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Activity\ActivityRepository;

/**
 * Reviews recent MCP activity patterns without storing new learning directly.
 */
final class ActivityLearningInsights {

	private const MAX_ROWS = 50;

	/**
	 * Inspect recent activity for repeatable MCP workflow improvements.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function inspect( array $args ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array(
				'status'       => 'unavailable',
				'error'        => 'activity_runtime_unavailable',
				'message'      => 'The MCP activity store is unavailable in this runtime.',
				'items'        => array(),
				'insights'     => array(),
				'next_actions' => array( 'Retry after Aculect activity tables are available in WordPress.' ),
			);
		}

		$status  = $this->status( $args['status'] ?? 'error' );
		$filters = array(
			'per_page' => min( self::MAX_ROWS, max( 1, absint( $args['per_page'] ?? 25 ) ) ),
		);
		if ( 'all' !== $status ) {
			$filters['status'] = $status;
		}
		if ( isset( $args['range'] ) && is_scalar( $args['range'] ) ) {
			$filters['range'] = sanitize_key( (string) $args['range'] );
		}

		$items    = ( new ActivityRepository() )->list( $filters );
		$insights = $this->insights( $items );

		return array(
			'status'       => 'success',
			'items'        => $this->compact_items( $items ),
			'insights'     => $insights,
			'total'        => count( $items ),
			'filters'      => $filters,
			'next_actions' => array() === $insights
				? array( 'No repeatable MCP learning signal was detected in the inspected activity rows.' )
				: array( 'Review insights and submit durable guidance with intelligence_feedback_submit when the suggested update matches the site owner intent.' ),
		);
	}

	/**
	 * Build learning insights from activity rows.
	 *
	 * @param list<array<string, mixed>> $items Activity rows.
	 * @return list<array<string, mixed>>
	 */
	private function insights( array $items ): array {
		$counts = array();
		foreach ( $items as $item ) {
			$error_code = sanitize_key( (string) ( $item['error_code'] ?? '' ) );
			if ( '' === $error_code ) {
				$error_code = $this->error_from_message( (string) ( $item['message'] ?? '' ) );
			}
			if ( '' === $error_code ) {
				continue;
			}

			$counts[ $error_code ] = ( $counts[ $error_code ] ?? 0 ) + 1;
		}

		$insights = array();
		foreach ( $counts as $error_code => $count ) {
			$insight = $this->insight_for_error( (string) $error_code, (int) $count );
			if ( array() !== $insight ) {
				$insights[] = $insight;
			}
		}

		return $insights;
	}

	/**
	 * Build one insight for an error code.
	 *
	 * @param string $error_code Activity error code.
	 * @param int    $count Number of occurrences.
	 * @return array<string, mixed>
	 */
	private function insight_for_error( string $error_code, int $count ): array {
		return match ( $error_code ) {
			'tool_disabled' => array(
				'type'             => 'ability_routing',
				'count'            => $count,
				'learning_domain'  => 'developer',
				'issue'            => 'Assistant attempted tools that are disabled by current Aculect ability policy.',
				'suggested_update' => 'Before retrying a disabled tool, call workflow_route_request or intelligence_capabilities_get_directory and explain the blocked_by reason to the user.',
			),
			'insufficient_scope' => array(
				'type'             => 'oauth_scope',
				'count'            => $count,
				'learning_domain'  => 'developer',
				'issue'            => 'Assistant attempted tools outside the current OAuth token scopes.',
				'suggested_update' => 'When a write tool requires missing scopes, ask the user to reconnect with the required scope before retrying.',
			),
			'invalid_block_content' => array(
				'type'             => 'block_authoring',
				'count'            => $count,
				'learning_domain'  => 'content',
				'issue'            => 'Assistant produced block content that failed validation.',
				'suggested_update' => 'For content writes, call intelligence_blocks_list_available and content_workflow_prepare_post, then validate serialized blocks before writing.',
			),
			'unknown_tool' => array(
				'type'             => 'tool_discovery',
				'count'            => $count,
				'learning_domain'  => 'developer',
				'issue'            => 'Assistant attempted a tool name that is not exposed by this MCP server.',
				'suggested_update' => 'Use tools/list, workflow_route_request, and the capability directory instead of guessing tool names.',
			),
			default => array(),
		};
	}

	/**
	 * Return compact rows safe for MCP responses.
	 *
	 * @param list<array<string, mixed>> $items Activity rows.
	 * @return list<array<string, mixed>>
	 */
	private function compact_items( array $items ): array {
		return array_values(
			array_map(
				static fn ( array $item ): array => array(
					'created_at' => (string) ( $item['created_at'] ?? '' ),
					'provider'   => (string) ( $item['provider'] ?? '' ),
					'action'     => (string) ( $item['action'] ?? '' ),
					'status'     => (string) ( $item['status'] ?? '' ),
					'error_code' => (string) ( $item['error_code'] ?? '' ),
					'message'    => (string) ( $item['message'] ?? '' ),
				),
				$items
			)
		);
	}

	/**
	 * Infer common error codes from legacy messages.
	 *
	 * @param string $message Activity message.
	 */
	private function error_from_message( string $message ): string {
		$message = strtolower( $message );
		if ( str_contains( $message, 'disabled in aculect' ) ) {
			return 'tool_disabled';
		}
		if ( str_contains( $message, 'scope' ) ) {
			return 'insufficient_scope';
		}
		if ( str_contains( $message, 'block content' ) ) {
			return 'invalid_block_content';
		}
		if ( str_contains( $message, 'unknown tool' ) ) {
			return 'unknown_tool';
		}

		return '';
	}

	/**
	 * Normalize requested activity status.
	 *
	 * @param mixed $status Raw status.
	 */
	private function status( mixed $status ): string {
		$status = sanitize_key( is_scalar( $status ) ? (string) $status : 'error' );

		return in_array( $status, array( 'error', 'success', 'all' ), true ) ? $status : 'error';
	}
}

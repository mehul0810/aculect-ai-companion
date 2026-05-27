<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Activity;

use Aculect\AICompanion\Connectors\MCP\ToolSafety;

/**
 * Records connected AI actions with sanitized metadata only.
 */
final class ActivityLogger {

	private const OPTION_LAST_PRUNED_AT = 'aculect_ai_companion_activity_last_pruned_at';
	private const PRUNE_INTERVAL        = 3600;
	private const DEFAULT_RETENTION     = 90;

	private ActivityRepository $repository;

	public function __construct( ?ActivityRepository $repository = null ) {
		$this->repository = $repository ?? new ActivityRepository();
	}

	/**
	 * Record one write-capable MCP tool call.
	 *
	 * @param string               $action Tool action.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @param array<string, mixed> $result Tool result.
	 * @param array<string, mixed> $auth   OAuth authentication context.
	 */
	public function record_tool_call( string $action, array $args, array $result, array $auth ): bool {
		$target = $this->target( $action, $args, $result );
		$status = isset( $result['error'] ) ? 'error' : 'success';
		$risk   = ( new ToolSafety() )->risk_level( $action, $args );

		$inserted = $this->repository->insert(
			array(
				'provider'    => (string) ( $auth['provider'] ?? 'mcp' ),
				'client_id'   => (string) ( $auth['client_id'] ?? '' ),
				'client_name' => (string) ( $auth['client_name'] ?? '' ),
				'user_id'     => (int) ( $auth['user_id'] ?? 0 ),
				'action'      => $action,
				'target_type' => $target['type'],
				'target_id'   => $target['id'],
				'status'      => $status,
				'error_code'  => 'error' === $status ? (string) ( $result['error'] ?? 'tool_error' ) : '',
				'message'     => 'error' === $status ? (string) ( $result['message'] ?? 'AI action failed.' ) : 'AI action completed.',
				'context'     => $this->context( $action, $args, $result, $risk ),
			)
		);

		if ( $inserted ) {
			$this->maybe_prune();
		}

		return $inserted;
	}

	/**
	 * Build sanitized activity context without storing request payload values.
	 *
	 * @param string               $action Tool action.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @param array<string, mixed> $result Tool result.
	 * @param string               $risk   Tool risk level.
	 * @return array<string, mixed>
	 */
	private function context( string $action, array $args, array $result, string $risk ): array {
		return array(
			'argument_keys' => $this->argument_keys( $args ),
			'risk_level'    => $risk,
			'metadata'      => $this->safe_argument_metadata( $action, $args ),
			'result'        => $this->result_metadata( $result ),
		);
	}

	/**
	 * Record an administrator lifecycle action for user-level AI access.
	 *
	 * @param string               $action         Admin access action.
	 * @param int                  $target_user_id Affected WordPress user ID.
	 * @param int                  $actor_user_id  Administrator user ID.
	 * @param string               $message        Admin-safe activity message.
	 * @param array<string, mixed> $metadata       Sanitized extra metadata.
	 */
	public function record_user_access_event( string $action, int $target_user_id, int $actor_user_id, string $message, array $metadata = array() ): bool {
		$metadata = array_merge(
			array(
				'target_user_id' => max( 0, $target_user_id ),
				'actor_user_id'  => max( 0, $actor_user_id ),
			),
			$this->safe_event_metadata( $metadata )
		);

		$inserted = $this->repository->insert(
			array(
				'provider'    => 'admin',
				'client_id'   => '',
				'client_name' => 'WordPress Admin',
				'user_id'     => $actor_user_id,
				'action'      => $action,
				'target_type' => 'user',
				'target_id'   => $target_user_id,
				'status'      => 'success',
				'error_code'  => '',
				'message'     => $message,
				'context'     => array(
					'metadata' => $metadata,
				),
			)
		);

		if ( $inserted ) {
			$this->maybe_prune();
		}

		return $inserted;
	}

	/**
	 * Extract target metadata from the request and result.
	 *
	 * @param string               $action Tool action.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @param array<string, mixed> $result Tool result.
	 * @return array{type: string, id: int|null}
	 */
	private function target( string $action, array $args, array $result ): array {
		return match ( $action ) {
			'content.create_item', 'content.update_item' => array(
				'type' => sanitize_key( (string) ( $result['type'] ?? $args['post_type'] ?? 'content' ) ),
				'id'   => $this->first_id( $result, $args, array( 'id' ) ),
			),
			'taxonomy.create_term', 'taxonomy.update_term' => array(
				'type' => sanitize_key( (string) ( $result['taxonomy'] ?? $args['taxonomy'] ?? 'term' ) ),
				'id'   => $this->first_id( $result, $args, array( 'id', 'term_id' ) ),
			),
			'media.upload_item' => array(
				'type' => 'attachment',
				'id'   => $this->first_id( $result, $args, array( 'id', 'post_id' ) ),
			),
			'comments.create_item', 'comments.update_item' => array(
				'type' => 'comment',
				'id'   => $this->first_id( $result, $args, array( 'id' ) ),
			),
			'wp_abilities.run' => array(
				'type' => 'wp_ability',
				'id'   => null,
			),
			default => array(
				'type' => 'mcp_tool',
				'id'   => null,
			),
		};
	}

	/**
	 * Return the first positive ID from result or arguments.
	 *
	 * @param array<string, mixed> $result Result data.
	 * @param array<string, mixed> $args   Argument data.
	 * @param string[]             $keys   Candidate keys.
	 */
	private function first_id( array $result, array $args, array $keys ): ?int {
		foreach ( $keys as $key ) {
			$id = absint( $result[ $key ] ?? $args[ $key ] ?? 0 );
			if ( $id > 0 ) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * Return sanitized argument keys without argument values.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return string[]
	 */
	private function argument_keys( array $args ): array {
		return array_values(
			array_filter(
				array_map(
					static fn( string $key ): string => sanitize_key( $key ),
					array_keys( $args )
				)
			)
		);
	}

	/**
	 * Build a safe metadata summary from arguments without storing content bodies.
	 *
	 * @param string               $action Tool action.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @return array<string, mixed>
	 */
	private function safe_argument_metadata( string $action, array $args ): array {
		$metadata = array(
			'action' => $action,
		);

		foreach ( array( 'post_type', 'status', 'taxonomy', 'id', 'term_id', 'post_id' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
				$metadata[ $key ] = is_numeric( $args[ $key ] ) ? absint( $args[ $key ] ) : sanitize_text_field( (string) $args[ $key ] );
			}
		}

		if ( isset( $args['url'] ) && is_scalar( $args['url'] ) ) {
			$host = (string) wp_parse_url( (string) $args['url'], PHP_URL_HOST );
			if ( '' !== $host ) {
				$metadata['source_host'] = strtolower( sanitize_text_field( $host ) );
			}
		}

		if ( 'wp_abilities.run' === $action && isset( $args['id'] ) && is_scalar( $args['id'] ) ) {
			$metadata['wp_ability_id'] = sanitize_text_field( (string) $args['id'] );
		}

		return $metadata;
	}

	/**
	 * Build a safe result summary.
	 *
	 * @param array<string, mixed> $result Tool result.
	 * @return array<string, mixed>
	 */
	private function result_metadata( array $result ): array {
		$metadata = array();

		foreach ( array( 'id', 'type', 'status', 'taxonomy', 'mime_type' ) as $key ) {
			if ( isset( $result[ $key ] ) && is_scalar( $result[ $key ] ) ) {
				$metadata[ $key ] = is_numeric( $result[ $key ] ) ? absint( $result[ $key ] ) : sanitize_text_field( (string) $result[ $key ] );
			}
		}

		if ( isset( $result['error'] ) && is_scalar( $result['error'] ) ) {
			$metadata['error'] = sanitize_key( (string) $result['error'] );
		}

		return $metadata;
	}

	/**
	 * Sanitize scalar admin-event metadata.
	 *
	 * @param array<string, mixed> $metadata Raw metadata.
	 * @return array<string, mixed>
	 */
	private function safe_event_metadata( array $metadata ): array {
		$safe = array();

		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_scalar( $value ) ) {
				continue;
			}

			$safe[ $key ] = is_numeric( $value ) ? absint( $value ) : sanitize_text_field( (string) $value );
		}

		return $safe;
	}

	/**
	 * Prune activity rows at most hourly.
	 */
	private function maybe_prune(): void {
		$last_pruned_at = absint( get_option( self::OPTION_LAST_PRUNED_AT, 0 ) );
		if ( time() - $last_pruned_at < self::PRUNE_INTERVAL ) {
			return;
		}

		$retention_days = absint( apply_filters( 'aculect_ai_companion_activity_retention_days', self::DEFAULT_RETENTION ) );
		$this->repository->prune( max( 1, $retention_days ) );
		update_option( self::OPTION_LAST_PRUNED_AT, time(), false );
	}
}

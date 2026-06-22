<?php
/**
 * Admin-only MCP self-management abilities.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Diagnostics\Logger;
use Aculect\AICompanion\Intelligence\LearningSuggestionRepository;

/**
 * Exposes tightly gated Aculect policy and review queue management through MCP.
 */
final class AdminSelfManagementAbilities {

	private const CONFIRMATION_TEXT = 'I understand this changes Aculect AI Companion admin policy.';

	private Logger $logger;

	public function __construct( ?Logger $logger = null ) {
		$this->logger = $logger ?? new Logger();
	}

	/**
	 * Inspect current self-management state without exposing raw options.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function inspect( array $args = array() ): array {
		unset( $args );

		if ( ! $this->can_manage() ) {
			return $this->forbidden();
		}

		$registry        = new AbilitiesRegistry();
		$availability    = new McpToolAvailability();
		$wp_policy       = new WordPressAbilitiesPolicy();
		$learning        = new LearningSuggestionRepository();
		$policy          = $availability->ability_policy_for_current_user( $registry );
		$wp_definitions  = $wp_policy->public_definitions();
		$learning_status = $learning->admin_payload();

		return $this->status(
			'inspected',
			array(
				'policy'              => array(
					'user_policy_state'         => (string) ( $policy['user_policy_state'] ?? '' ),
					'exposed_ability_count'     => absint( $policy['exposed_ability_count'] ?? 0 ),
					'global_enabled_count'      => absint( $policy['global_enabled_count'] ?? 0 ),
					'role_allowed_count'        => absint( $policy['role_allowed_count'] ?? 0 ),
					'blocked_by_global_count'   => count( (array) ( $policy['blocked_by_global_ids'] ?? array() ) ),
					'blocked_by_role_count'     => count( (array) ( $policy['blocked_by_role_ids'] ?? array() ) ),
					'default_read_only_policy'  => true === ( $policy['default_read_only_policy'] ?? false ),
					'scope_aware'               => true === ( $policy['scope_aware'] ?? false ),
					'granted_scopes'            => array_values( array_map( 'strval', (array) ( $policy['granted_scopes'] ?? array() ) ) ),
					'operation_tool_names'      => array_values( array_map( 'strval', (array) ( $policy['operation_tool_names'] ?? array() ) ) ),
					'global_enabled_tool_names' => array_values( array_map( 'strval', (array) ( $policy['global_enabled_tool_names'] ?? array() ) ) ),
				),
				'wordpress_abilities' => array(
					'available'     => function_exists( 'wp_get_abilities' ),
					'public_count'  => count( $wp_definitions ),
					'allowed_count' => count( $wp_policy->allowed_ids() ),
					'allowed_ids'   => $wp_policy->allowed_ids(),
				),
				'learning_queue'      => array(
					'summary'       => is_array( $learning_status['summary'] ?? null ) ? $learning_status['summary'] : array(),
					'pending_items' => $this->pending_learning_items( $learning_status ),
				),
				'warnings'            => array( 'Raw option values, secrets, tokens, and arbitrary wp_options are intentionally not returned.' ),
				'next_actions'        => array(
					'Use admin_self_management_update_enabled_abilities with dry_run=true before changing Aculect ability policy.',
					'Use admin_self_management_update_wp_abilities with dry_run=true before allowing public WordPress Abilities API registrations.',
					'Use admin_self_management_review_learning with dry_run=true before approving or rejecting learning suggestions.',
				),
			)
		);
	}

	/**
	 * Preview or update enabled Aculect ability IDs.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function update_enabled_abilities( array $args ): array {
		if ( ! $this->can_manage() ) {
			return $this->forbidden();
		}

		$registry  = new AbilitiesRegistry();
		$requested = $this->sanitize_ability_ids( (array) ( $args['enabled_ids'] ?? array() ), $registry );
		$current   = $registry->enabled_ids();
		$changes   = $this->changes( $current, $requested );
		$dry_run   = ! empty( $args['dry_run'] );

		if ( ! $dry_run && ! $this->is_confirmed( $args ) ) {
			return $this->confirmation_required( 'admin_self_management.update_enabled_abilities', $changes );
		}

		if ( ! $dry_run ) {
			$registry->save_enabled_ids( $requested );
			$this->log_mutation( 'admin_self_management.enabled_abilities_updated', 'Aculect enabled abilities policy was updated through MCP self-management.', $changes );
		}

		return $this->status(
			$dry_run ? 'preview' : 'updated',
			array(
				'dry_run'      => $dry_run,
				'target'       => array( 'type' => 'aculect_enabled_abilities' ),
				'changes'      => $changes,
				'warnings'     => $this->always_on_warnings( (array) ( $args['enabled_ids'] ?? array() ), $registry ),
				'next_actions' => $dry_run ? array( 'Repeat with dry_run=false and confirmation_text to persist the policy update.' ) : array( 'Refresh MCP tools/list metadata in connected clients.' ),
			)
		);
	}

	/**
	 * Preview or update allowed public WordPress Ability IDs.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function update_wordpress_abilities( array $args ): array {
		if ( ! $this->can_manage() ) {
			return $this->forbidden();
		}

		$policy    = new WordPressAbilitiesPolicy();
		$current   = $policy->allowed_ids();
		$requested = $this->sanitize_public_wp_ability_ids( (array) ( $args['allowed_ids'] ?? array() ), $policy );
		$changes   = $this->changes( $current, $requested );
		$dry_run   = ! empty( $args['dry_run'] );

		if ( ! $dry_run && ! $this->is_confirmed( $args ) ) {
			return $this->confirmation_required( 'admin_self_management.update_wp_abilities', $changes );
		}

		if ( ! $dry_run ) {
			$policy->save_allowed_ids( $requested );
			$this->log_mutation( 'admin_self_management.wp_abilities_updated', 'Public WordPress Abilities policy was updated through MCP self-management.', $changes );
		}

		return $this->status(
			$dry_run ? 'preview' : 'updated',
			array(
				'dry_run'      => $dry_run,
				'target'       => array( 'type' => 'wordpress_abilities_policy' ),
				'changes'      => $changes,
				'warnings'     => function_exists( 'wp_get_abilities' ) ? array() : array( 'The WordPress Abilities API runtime is unavailable, so only sanitized requested IDs can be previewed.' ),
				'next_actions' => $dry_run ? array( 'Repeat with dry_run=false and confirmation_text to persist the public abilities policy.' ) : array( 'Review connected clients before asking them to run newly allowed public abilities.' ),
			)
		);
	}

	/**
	 * List or review learning suggestions.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function review_learning( array $args ): array {
		if ( ! $this->can_manage() ) {
			return $this->forbidden();
		}

		$repository = new LearningSuggestionRepository();
		$action     = sanitize_key( (string) ( $args['action'] ?? 'list' ) );
		$payload    = $repository->admin_payload();
		if ( 'list' === $action ) {
			return $this->status(
				'listed',
				array(
					'dry_run'      => true,
					'changes'      => array(),
					'items'        => $this->pending_learning_items( $payload ),
					'summary'      => is_array( $payload['summary'] ?? null ) ? $payload['summary'] : array(),
					'warnings'     => array(),
					'next_actions' => array( 'Use action=approve or action=reject with dry_run=true to preview a review decision.' ),
				)
			);
		}

		if ( ! in_array( $action, array( 'approve', 'reject' ), true ) ) {
			return $this->error( 'invalid_action', 'Action must be list, approve, or reject.' );
		}

		$id      = sanitize_text_field( (string) ( $args['id'] ?? '' ) );
		$item    = $this->learning_item( $payload, $id );
		$dry_run = ! empty( $args['dry_run'] );
		if ( array() === $item ) {
			return $this->error( 'suggestion_not_found', 'No learning suggestion matched that ID.' );
		}

		$changes = array(
			'id'     => $id,
			'before' => (string) ( $item['status'] ?? 'pending' ),
			'after'  => 'approve' === $action ? 'approved' : 'dismissed',
		);

		if ( ! $dry_run && ! $this->is_confirmed( $args ) ) {
			return $this->confirmation_required( 'admin_self_management.review_learning', $changes );
		}

		$updated = true;
		if ( ! $dry_run ) {
			$updated = $repository->review( $id, 'approve' === $action ? 'approve' : 'dismiss', sanitize_text_field( (string) ( $args['review_note'] ?? '' ) ) );
			if ( $updated ) {
				$this->log_mutation( 'admin_self_management.learning_reviewed', 'Aculect learning suggestion was reviewed through MCP self-management.', $changes );
			}
		}

		return $this->status(
			$dry_run ? 'preview' : ( $updated ? 'updated' : 'not_updated' ),
			array(
				'dry_run'      => $dry_run,
				'target'       => array(
					'type' => 'learning_suggestion',
					'id'   => $id,
				),
				'changes'      => $changes,
				'warnings'     => 'approve' === $action ? array( 'Approved suggestions sync into durable Aculect memory without exposing raw option values.' ) : array(),
				'next_actions' => $dry_run ? array( 'Repeat with dry_run=false and confirmation_text to persist this review decision.' ) : array( 'Inspect the learning queue again to verify the updated status.' ),
			)
		);
	}

	/**
	 * Check admin capability.
	 */
	private function can_manage(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	/**
	 * Return a standard forbidden result.
	 *
	 * @return array<string, mixed>
	 */
	private function forbidden(): array {
		return $this->error( 'forbidden', 'This Aculect self-management ability requires manage_options.' );
	}

	/**
	 * Return a standard error result.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return array<string, mixed>
	 */
	private function error( string $code, string $message ): array {
		return array(
			'status'       => 'error',
			'error'        => $code,
			'message'      => $message,
			'changes'      => array(),
			'warnings'     => array(),
			'next_actions' => array(),
		);
	}

	/**
	 * Return a standard success/status result.
	 *
	 * @param string               $status Result status.
	 * @param array<string, mixed> $extra Extra payload fields.
	 * @return array<string, mixed>
	 */
	private function status( string $status, array $extra ): array {
		return array_merge(
			array(
				'status'       => $status,
				'changes'      => array(),
				'warnings'     => array(),
				'next_actions' => array(),
			),
			$extra
		);
	}

	/**
	 * Return a confirmation-required result.
	 *
	 * @param string $action  Action name.
	 * @param mixed  $changes Previewed changes.
	 * @return array<string, mixed>
	 */
	private function confirmation_required( string $action, mixed $changes ): array {
		return array(
			'status'                => 'confirmation_required',
			'action'                => $action,
			'confirmation_required' => true,
			'confirmation_text'     => self::CONFIRMATION_TEXT,
			'changes'               => $changes,
			'warnings'              => array( 'Admin policy changes require explicit confirmation.' ),
			'next_actions'          => array( 'Run a dry run first, then repeat with dry_run=false and the exact confirmation_text.' ),
		);
	}

	/**
	 * Check explicit confirmation text.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function is_confirmed( array $args ): bool {
		return hash_equals( self::CONFIRMATION_TEXT, (string) ( $args['confirmation_text'] ?? '' ) );
	}

	/**
	 * Write a support-safe diagnostic event for confirmed self-management writes.
	 *
	 * @param string               $event   Diagnostic event name.
	 * @param string               $message Event message.
	 * @param array<string, mixed> $changes Sanitized change summary.
	 */
	private function log_mutation( string $event, string $message, array $changes ): void {
		$this->logger->info(
			$event,
			$message,
			array(
				'tool_group' => 'aculect_admin',
				'changes'    => $changes,
			)
		);
	}

	/**
	 * Return added/removed IDs.
	 *
	 * @param array $current Current IDs.
	 * @param array $next    Requested IDs.
	 * @phpstan-param list<string> $current
	 * @phpstan-param list<string> $next
	 * @return array<string, list<string>|int>
	 */
	private function changes( array $current, array $next ): array {
		return array(
			'added'          => array_values( array_diff( $next, $current ) ),
			'removed'        => array_values( array_diff( $current, $next ) ),
			'previous_count' => count( $current ),
			'next_count'     => count( $next ),
		);
	}

	/**
	 * Sanitize configurable Aculect ability IDs.
	 *
	 * @param array<mixed>      $ids      Raw IDs.
	 * @param AbilitiesRegistry $registry Ability registry.
	 * @return list<string>
	 */
	private function sanitize_ability_ids( array $ids, AbilitiesRegistry $registry ): array {
		$known = $registry->configurable_definitions();
		$out   = array();
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$id = $registry->internal_id( sanitize_text_field( (string) $id ) );
			if ( array_key_exists( $id, $known ) ) {
				$out[] = $id;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Warn when callers try to toggle always-on tools.
	 *
	 * @param array<mixed>      $ids      Raw IDs.
	 * @param AbilitiesRegistry $registry Ability registry.
	 * @return list<string>
	 */
	private function always_on_warnings( array $ids, AbilitiesRegistry $registry ): array {
		$warnings = array();
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$id = $registry->internal_id( sanitize_text_field( (string) $id ) );
			if ( $registry->is_always_on_read_intelligence( $id ) || $registry->is_always_on_write_intelligence( $id ) || $registry->is_derived_workflow( $id ) ) {
				$warnings[] = $id . ' is first-party workflow or intelligence infrastructure and is not stored in configurable ability policy.';
			}
		}

		return array_values( array_unique( $warnings ) );
	}

	/**
	 * Sanitize public WordPress Ability IDs.
	 *
	 * @param array<mixed>             $ids    Raw IDs.
	 * @param WordPressAbilitiesPolicy $policy Policy object.
	 * @return list<string>
	 */
	private function sanitize_public_wp_ability_ids( array $ids, WordPressAbilitiesPolicy $policy ): array {
		$public = array_column( $policy->public_definitions(), 'id' );
		$out    = array();
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$id = sanitize_text_field( (string) $id );
			if ( array() === $public || in_array( $id, $public, true ) ) {
				$out[] = $id;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Return pending learning items with bounded fields.
	 *
	 * @param array<string, mixed> $payload Learning admin payload.
	 * @return list<array<string, mixed>>
	 */
	private function pending_learning_items( array $payload ): array {
		$items = array();
		foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || 'pending' !== (string) ( $item['status'] ?? '' ) ) {
				continue;
			}

			$items[] = array(
				'id'               => (string) ( $item['id'] ?? '' ),
				'domain'           => (string) ( $item['domain'] ?? '' ),
				'issue'            => (string) ( $item['issue'] ?? '' ),
				'suggested_update' => (string) ( $item['suggested_update'] ?? '' ),
				'confidence'       => (string) ( $item['confidence'] ?? '' ),
				'created_at'       => (string) ( $item['created_at'] ?? '' ),
			);
		}

		return array_values( array_slice( $items, 0, 20 ) );
	}

	/**
	 * Find one learning item by ID.
	 *
	 * @param array<string, mixed> $payload Learning admin payload.
	 * @param string               $id      Learning suggestion ID.
	 * @return array<string, mixed>
	 */
	private function learning_item( array $payload, string $id ): array {
		foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
			if ( is_array( $item ) && hash_equals( (string) ( $item['id'] ?? '' ), $id ) ) {
				return $item;
			}
		}

		return array();
	}
}

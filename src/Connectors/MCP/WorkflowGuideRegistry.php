<?php
/**
 * Bounded workflow guide registry for assistant clients.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Provides compact, policy-aware workflow guides without requiring model memory.
 */
final class WorkflowGuideRegistry {

	private const MAX_GUIDES = 20;

	/**
	 * List workflow guides with current operation availability.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_guides( array $args ): array {
		$detail         = $this->detail( $args );
		$category       = sanitize_key( (string) ( $args['category'] ?? '' ) );
		$available_only = true === ( $args['available_only'] ?? false );
		$manifest       = ( new McpToolAvailability() )->operations_manifest_for_current_user();
		$items          = array();

		foreach ( $this->definitions() as $guide ) {
			if ( '' !== $category && $category !== (string) $guide['task_category'] ) {
				continue;
			}

			$item = $this->guide_response( $guide, $manifest, $detail );
			if ( $available_only && true !== $item['available'] ) {
				continue;
			}

			$items[] = $item;
			if ( count( $items ) >= self::MAX_GUIDES ) {
				break;
			}
		}

		return array(
			'items'        => $items,
			'total'        => count( $items ),
			'context'      => $detail,
			'bounded'      => true,
			'max_guides'   => self::MAX_GUIDES,
			'next_actions' => array(
				'Call workflow_guides_get with an id for the compact step sequence before starting multi-tool work.',
				'Use only operations marked available; inspect missing_required_operations before assuming WordPress access is unavailable.',
			),
		);
	}

	/**
	 * Return one workflow guide with current operation availability.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_guide( array $args ): array {
		$id = sanitize_key( (string) ( $args['id'] ?? '' ) );
		if ( '' === $id ) {
			return $this->error( 'invalid_guide_id', 'Provide a workflow guide ID returned by workflow_guides_list.' );
		}

		foreach ( $this->definitions() as $guide ) {
			if ( $id === (string) $guide['id'] ) {
				return $this->guide_response( $guide, ( new McpToolAvailability() )->operations_manifest_for_current_user(), 'full' );
			}
		}

		return $this->error( 'guide_not_found', 'No workflow guide exists for that ID.' );
	}

	/**
	 * Return compact first-party workflow guide definitions.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function definitions(): array {
		return array(
			array(
				'id'                      => 'content_long_form_draft',
				'title'                   => 'Create a long-form draft',
				'summary'                 => 'Plan, validate, and create a WordPress draft with serialized block content and SEO metadata.',
				'task_category'           => 'content',
				'risk_level'              => 'draft_write',
				'estimated_response_size' => 'medium',
				'required_operations'     => array( 'workflows.prepare_post', 'workflows.create_draft' ),
				'optional_operations'     => array( 'workflows.update_rankmath_seo', 'intelligence_index.search_items', 'intelligence_index.internal_links', 'media.list' ),
				'steps'                   => array(
					'Call intelligence_content_get_context, then workflow_guides_get when a compact procedure is needed.',
					'Call content_workflow_prepare_post with the brief, audience, SEO intent, target post type, and desired word count.',
					'Call content_find_internal_links when available and weave selected internal links into the draft plan before generating prose.',
					'Keep generated prose outside WordPress until validation passes; generate serialized WordPress block markup using registered semantic blocks and never use core/html.',
					'Validate block content before writing, then call content_workflow_create_draft with draft status.',
					'Apply Rank Math fields through seo_workflow_update_rankmath when available, and return the post ID, edit URL, warnings, and next actions.',
					'If content_workflow_prepare_post or content_workflow_create_draft is unavailable, stop before writing and return the missing_required_operations with a read-only outline, internal-link plan, and SEO field draft for manual review.',
				),
			),
			array(
				'id'                      => 'content_existing_post_update',
				'title'                   => 'Update an existing post safely',
				'summary'                 => 'Prepare and apply a full-document or section-based update while preserving block-editor compatibility.',
				'task_category'           => 'content',
				'risk_level'              => 'content_update',
				'estimated_response_size' => 'medium',
				'required_operations'     => array( 'workflows.prepare_post', 'workflows.update_post' ),
				'optional_operations'     => array( 'intelligence_index.search_chunks', 'intelligence_index.find_related', 'intelligence_index.internal_links' ),
				'steps'                   => array(
					'Read content intelligence and fetch the existing item or indexed sections before drafting changes.',
					'Call content_workflow_prepare_post with existing_post_id and the update brief.',
					'Call content_find_internal_links when available and add only relevant internal links that fit the existing article context.',
					'Keep rewritten prose outside WordPress until validation passes; use section_map for targeted long-form edits when stable heading sections exist, otherwise replace the full block document.',
					'Run dry_run for risky replacements, review warnings, then repeat with confirmation when required.',
					'If content_workflow_prepare_post or content_workflow_update_post is unavailable, stop before writing and return the missing_required_operations with a read-only update brief, section map, and internal-link recommendations.',
				),
			),
			array(
				'id'                      => 'site_readiness_audit',
				'title'                   => 'Audit site readiness before changes',
				'summary'                 => 'Inspect connector, REST, HTTPS, cron, update, and active-theme readiness before planning site management work.',
				'task_category'           => 'site',
				'risk_level'              => 'read_only',
				'estimated_response_size' => 'small',
				'required_operations'     => array( 'workflows.site_audit' ),
				'optional_operations'     => array( 'site_information.get_health', 'site_information.list_plugins', 'site_information.list_themes' ),
				'steps'                   => array(
					'Call intelligence_site_get_context for site and connector context.',
					'Call site_workflow_audit before recommending maintenance, update, cache, permalink, or cron actions.',
					'Report findings, severity, blocked operations, and next actions without making changes.',
				),
			),
			array(
				'id'                      => 'site_management_planning',
				'title'                   => 'Plan safe site management work',
				'summary'                 => 'Build a progressive site-management plan from site context, health signals, operation availability, and administrator review points.',
				'task_category'           => 'site',
				'risk_level'              => 'planning',
				'estimated_response_size' => 'medium',
				'required_operations'     => array( 'workflows.site_audit' ),
				'optional_operations'     => array( 'site_information.get_health', 'site_information.list_plugins', 'site_information.list_themes', 'admin_menu.get_context', 'admin_menu.get_navigation_target', 'workflow_guides.session_start' ),
				'steps'                   => array(
					'Call intelligence_site_get_context, then site_workflow_audit to separate environment readiness from connector and policy state.',
					'Review required_operations and optional_operations; use only operations marked available and include blocked_by or missing_scopes for skipped follow-ups.',
					'Use site health, plugin, theme, and admin-menu context for recommendations; do not request filesystem, database-console, or server-side code execution.',
					'Classify findings as environment issue, plugin authorization issue, policy block, or administrator decision before proposing action.',
					'Stop and ask the administrator before update installs, setting writes, cache purges, deletion, or any operation not listed as available.',
				),
			),
			array(
				'id'                      => 'connector_troubleshooting',
				'title'                   => 'Troubleshoot connector setup',
				'summary'                 => 'Diagnose OAuth setup, stale metadata, client-side tool cache, and blocked tool discovery without assuming WordPress access is broken.',
				'task_category'           => 'site',
				'risk_level'              => 'read_only',
				'estimated_response_size' => 'medium',
				'required_operations'     => array( 'workflow_guides.list' ),
				'optional_operations'     => array( 'workflow_guides.get', 'actions.discover', 'actions.inspect', 'site_editor.refresh_context', 'admin_menu.refresh_context', 'intelligence_index.activity_learning' ),
				'steps'                   => array(
					'Call intelligence_site_get_context and inspect the operations manifest before retrying setup or discovery.',
					'For OAuth failures, check missing scopes, expired authorization, and user-policy blockers before treating the site environment as unavailable.',
					'For stale metadata, use available refresh-context operations for plugin-owned snapshots, then ask the client to reconnect if its cached tool list remains stale.',
					'For blocked tool discovery, compare available operations, blocked_by reasons, and WordPress Abilities diagnostics; report policy blocks separately from environment failures.',
					'For stale client cache, ask the administrator to remove and re-add the connector only after the exported or listed tool metadata includes the expected tools.',
				),
			),
			array(
				'id'                      => 'site_editor_intelligence_review',
				'title'                   => 'Review Site Editor capabilities',
				'summary'                 => 'Inspect the active theme, Site Editor availability, templates, template parts, global styles, blocks, and patterns before Appearance > Editor work.',
				'task_category'           => 'site',
				'risk_level'              => 'read_only',
				'estimated_response_size' => 'medium',
				'required_operations'     => array( 'site_editor.get_context' ),
				'optional_operations'     => array( 'site_editor.list_templates', 'site_editor.list_template_parts', 'site_editor.refresh_context' ),
				'steps'                   => array(
					'Call site_editor_get_context before planning Appearance > Editor changes.',
					'If the task targets a template or global region, call site_editor_list_templates or site_editor_list_template_parts.',
					'Use registered blocks, patterns, and global settings for recommendations; never request filesystem or theme-file writes.',
					'Call site_editor_refresh_context after theme or Site Editor changes so future MCP work has a fresh snapshot.',
				),
			),
			array(
				'id'                      => 'admin_menu_settings_review',
				'title'                   => 'Find admin settings surfaces',
				'summary'                 => 'Inspect visible WordPress admin menus, registered settings metadata, and the best admin page for a core, plugin, or theme settings task.',
				'task_category'           => 'site',
				'risk_level'              => 'read_only',
				'estimated_response_size' => 'small',
				'required_operations'     => array( 'admin_menu.get_context' ),
				'optional_operations'     => array( 'admin_menu.get_navigation_target', 'admin_menu.list_pages', 'admin_menu.list_settings', 'admin_menu.refresh_context' ),
				'steps'                   => array(
					'Call admin_menu_get_context before planning WordPress core, plugin, or theme settings work.',
					'Call admin_menu_get_navigation_target with the task to find the likely admin page and capability.',
					'Use admin_menu_list_settings only for registered setting metadata; do not request raw option values or secrets.',
					'Use future typed abilities for setting writes; do not edit files or arbitrary wp_options.',
				),
			),
			array(
				'id'                      => 'seo_rankmath_metadata_update',
				'title'                   => 'Update Rank Math metadata',
				'summary'                 => 'Update SEO title, meta description, and focus keywords through the focused Rank Math workflow.',
				'task_category'           => 'seo',
				'risk_level'              => 'metadata_write',
				'estimated_response_size' => 'small',
				'required_operations'     => array( 'workflows.update_rankmath_seo' ),
				'optional_operations'     => array( 'content.get_item', 'intelligence_index.search_items' ),
				'steps'                   => array(
					'Confirm the target post ID and current SEO intent.',
					'Call seo_workflow_update_rankmath with meta_title, meta_description, and focus_keywords.',
					'Return normalized applied fields and warnings; do not rewrite content unless explicitly requested.',
					'If seo_workflow_update_rankmath is unavailable, keep the SEO title, meta description, and focus keywords outside WordPress and return them as a manual-review fallback with missing_required_operations.',
				),
			),
		);
	}

	/**
	 * Build a guide response enriched with operation availability.
	 *
	 * @param array<string, mixed> $guide    Guide definition.
	 * @param array<string, mixed> $manifest Operations manifest.
	 * @param string               $detail   summary or full.
	 * @return array<string, mixed>
	 */
	private function guide_response( array $guide, array $manifest, string $detail ): array {
		$required = $this->operation_statuses( (array) $guide['required_operations'], $manifest );
		$optional = $this->operation_statuses( (array) $guide['optional_operations'], $manifest );
		$missing  = array_values(
			array_filter(
				$required,
				static fn ( array $operation ): bool => true !== $operation['available']
			)
		);

		$response = array(
			'id'                          => (string) $guide['id'],
			'title'                       => (string) $guide['title'],
			'summary'                     => (string) $guide['summary'],
			'task_category'               => (string) $guide['task_category'],
			'risk_level'                  => (string) $guide['risk_level'],
			'estimated_response_size'     => (string) $guide['estimated_response_size'],
			'available'                   => array() === $missing,
			'required_operations'         => $required,
			'optional_operations'         => $optional,
			'missing_required_operations' => $missing,
		);

		if ( 'full' === $detail ) {
			$response['steps']        = array_values( (array) $guide['steps'] );
			$response['next_actions'] = array() === $missing
				? array( 'Follow the steps using available tools and keep write operations in draft or dry_run until reviewed.' )
				: array( 'Resolve missing_required_operations before attempting this guide, or choose a different available guide.' );
		}

		return $response;
	}

	/**
	 * Return availability for operation references.
	 *
	 * @param array<int, mixed>    $references Operation references.
	 * @param array<string, mixed> $manifest   Operations manifest.
	 * @return list<array<string, mixed>>
	 */
	private function operation_statuses( array $references, array $manifest ): array {
		$operations = array();
		foreach ( $references as $reference ) {
			if ( ! is_scalar( $reference ) ) {
				continue;
			}

			$operations[] = $this->operation_status( (string) $reference, $manifest );
		}

		return $operations;
	}

	/**
	 * Return one operation availability entry.
	 *
	 * @param string               $reference Operation reference such as workflows.prepare_post.
	 * @param array<string, mixed> $manifest  Operations manifest.
	 * @return array<string, mixed>
	 */
	private function operation_status( string $reference, array $manifest ): array {
		$parts = explode( '.', $reference, 2 );
		$group = (string) ( $parts[0] ?? '' );
		$key   = (string) ( $parts[1] ?? '' );
		$entry = isset( $manifest[ $group ][ $key ] ) && is_array( $manifest[ $group ][ $key ] ) ? $manifest[ $group ][ $key ] : array();

		return array(
			'ref'             => $reference,
			'group'           => $group,
			'key'             => $key,
			'tool'            => (string) ( $entry['tool'] ?? '' ),
			'available'       => true === ( $entry['available'] ?? false ),
			'blocked_by'      => (string) ( $entry['blocked_by'] ?? ( array() === $entry ? 'operation_not_found' : '' ) ),
			'read_only'       => true === ( $entry['read_only'] ?? false ),
			'required_scopes' => array_values( (array) ( $entry['required_scopes'] ?? array() ) ),
			'missing_scopes'  => array_values( (array) ( $entry['missing_scopes'] ?? array() ) ),
		);
	}

	/**
	 * Normalize detail level.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function detail( array $args ): string {
		return 'full' === sanitize_key( (string) ( $args['detail'] ?? 'summary' ) ) ? 'full' : 'summary';
	}

	/**
	 * Return a standard error payload.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return array<string, mixed>
	 */
	private function error( string $code, string $message ): array {
		return array(
			'status'  => 'error',
			'error'   => $code,
			'message' => $message,
		);
	}
}

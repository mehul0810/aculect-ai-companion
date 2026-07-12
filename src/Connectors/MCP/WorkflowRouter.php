<?php
/**
 * Request router for smarter MCP workflow selection.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Classifies assistant requests into deterministic Aculect workflow paths.
 */
final class WorkflowRouter {

	/**
	 * Route a user request to the next MCP workflow step.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function route( array $args ): array {
		$request = $this->request_text( $args );
		if ( '' === $request ) {
			return array(
				'status'  => 'error',
				'error'   => 'invalid_request',
				'message' => 'Provide request, brief, or user_goal so Aculect can route the workflow.',
			);
		}

		$intent       = $this->intent( $request, $args );
		$content_mode = $this->content_mode( $request, $args );
		$post_type    = $this->post_type( $request, $args, $content_mode );
		$route        = $this->route_for_intent( $intent, $content_mode );
		$operations   = ( new McpToolAvailability() )->operations_manifest_for_current_user();
		$guide        = '' === $route['guide_id']
			? array()
			: ( new WorkflowGuideRegistry() )->get_guide( array( 'id' => $route['guide_id'] ) );

		return array(
			'status'                => 'ready',
			'workflow'              => 'workflow_route_request',
			'intent'                => $intent,
			'content_mode'          => $content_mode,
			'post_type'             => $post_type,
			'confidence'            => $route['confidence'],
			'risk_level'            => $route['risk_level'],
			'workflow_guide_id'     => $route['guide_id'],
			'workflow_guide'        => $guide,
			'next_tool'             => $route['next_tool'],
			'next_tool_arguments'   => $this->next_tool_arguments( $route['next_tool'], $request, $post_type, $content_mode, $args ),
			'recommended_sequence'  => $route['sequence'],
			'required_operations'   => $this->operation_entries( $route['operations'], $operations ),
			'blocked_operations'    => $this->blocked_operations( $route['operations'], $operations ),
			'workflow_session_plan' => array(
				'start_tool'      => ( new AbilitiesRegistry() )->tool_name( 'workflow_session.start' ),
				'start_arguments' => array(
					'workflow'     => '' === $route['guide_id'] ? $intent : $route['guide_id'],
					'state'        => 'routed',
					'brief'        => $request,
					'content_mode' => $content_mode,
					'intent'       => $intent,
					'post_type'    => $post_type,
				),
			),
			'next_actions'          => array(
				'Call workflow_session_start when the task will span multiple tools or may need to resume.',
				'Call the next_tool with next_tool_arguments, then follow recommended_sequence using only available operations.',
				'When an operation is blocked, inspect blocked_operations before assuming WordPress data is unavailable.',
			),
		);
	}

	/**
	 * Build operation entries for route references.
	 *
	 * @param string[]             $references Operation refs.
	 * @param array<string, mixed> $manifest   Operations manifest.
	 * @return list<array<string, mixed>>
	 * @phpstan-param list<string> $references
	 */
	private function operation_entries( array $references, array $manifest ): array {
		$entries = array();
		foreach ( $references as $reference ) {
			$entries[] = $this->operation_entry( $reference, $manifest );
		}

		return $entries;
	}

	/**
	 * Return blocked operation entries.
	 *
	 * @param string[]             $references Operation refs.
	 * @param array<string, mixed> $manifest   Operations manifest.
	 * @return list<array<string, mixed>>
	 * @phpstan-param list<string> $references
	 */
	private function blocked_operations( array $references, array $manifest ): array {
		return array_values(
			array_filter(
				$this->operation_entries( $references, $manifest ),
				static fn ( array $entry ): bool => true !== $entry['available']
			)
		);
	}

	/**
	 * Return one operation entry from the manifest.
	 *
	 * @param string               $reference Operation ref.
	 * @param array<string, mixed> $manifest  Operations manifest.
	 * @return array<string, mixed>
	 */
	private function operation_entry( string $reference, array $manifest ): array {
		$parts = explode( '.', $reference, 2 );
		$group = (string) ( $parts[0] ?? '' );
		$key   = (string) ( $parts[1] ?? '' );
		$entry = isset( $manifest[ $group ][ $key ] ) && is_array( $manifest[ $group ][ $key ] ) ? $manifest[ $group ][ $key ] : array();

		return array(
			'ref'             => $reference,
			'tool'            => (string) ( $entry['tool'] ?? '' ),
			'available'       => true === ( $entry['available'] ?? false ),
			'blocked_by'      => (string) ( $entry['blocked_by'] ?? ( array() === $entry ? 'operation_not_found' : '' ) ),
			'read_only'       => true === ( $entry['read_only'] ?? false ),
			'required_scopes' => array_values( (array) ( $entry['required_scopes'] ?? array() ) ),
			'missing_scopes'  => array_values( (array) ( $entry['missing_scopes'] ?? array() ) ),
		);
	}

	/**
	 * Return the route definition for an intent.
	 *
	 * @param string $intent Request intent.
	 * @param string $content_mode Content mode.
	 * @return array{guide_id:string,next_tool:string,sequence:list<string>,operations:list<string>,risk_level:string,confidence:string}
	 */
	private function route_for_intent( string $intent, string $content_mode ): array {
		$registry = new AbilitiesRegistry();

		return match ( $intent ) {
			'capability_discovery' => array(
				'guide_id'   => '',
				'next_tool'  => $registry->tool_name( 'intelligence.capabilities.get_directory' ),
				'sequence'   => array( 'intelligence_capabilities_get_directory' ),
				'operations' => array( 'workflow_guides.list' ),
				'risk_level' => 'read_only',
				'confidence' => 'high',
			),
			'site_audit' => array(
				'guide_id'   => 'site_readiness_audit',
				'next_tool'  => $registry->tool_name( 'intelligence.site.get_context' ),
				'sequence'   => array( 'intelligence_site_get_context', 'workflow_guides_get', 'site_workflow_audit' ),
				'operations' => array( 'workflows.site_audit' ),
				'risk_level' => 'read_only',
				'confidence' => 'high',
			),
			'site_editor' => array(
				'guide_id'   => 'site_editor_intelligence_review',
				'next_tool'  => $registry->tool_name( 'site_editor.get_context' ),
				'sequence'   => array( 'site_editor_get_context', 'workflow_guides_get', 'site_editor_list_templates', 'site_editor_list_template_parts' ),
				'operations' => array( 'site_editor.get_context', 'site_editor.list_templates', 'site_editor.list_template_parts' ),
				'risk_level' => 'read_only',
				'confidence' => 'high',
			),
			'admin_menu' => array(
				'guide_id'   => 'admin_menu_settings_review',
				'next_tool'  => $registry->tool_name( 'admin_menu.get_context' ),
				'sequence'   => array( 'admin_menu_get_context', 'workflow_guides_get', 'admin_menu_get_navigation_target', 'admin_menu_list_settings' ),
				'operations' => array( 'admin_menu.get_context', 'admin_menu.get_navigation_target', 'admin_menu.list_settings' ),
				'risk_level' => 'read_only',
				'confidence' => 'high',
			),
			'seo_update' => array(
				'guide_id'   => 'seo_rankmath_metadata_update',
				'next_tool'  => $registry->tool_name( 'intelligence.content.get_context' ),
				'sequence'   => array( 'intelligence_content_get_context', 'workflow_guides_get', 'seo_workflow_update_rankmath' ),
				'operations' => array( 'workflows.update_rankmath_seo' ),
				'risk_level' => 'metadata_write',
				'confidence' => 'high',
			),
			'content_update' => array(
				'guide_id'   => 'content_existing_post_update',
				'next_tool'  => $registry->tool_name( 'intelligence.content.get_context' ),
				'sequence'   => array( 'intelligence_content_get_context', 'workflow_guides_get', 'content_workflow_prepare_post', 'content_search_chunks', 'content_workflow_update_post' ),
				'operations' => array( 'workflows.prepare_post', 'workflows.update_post', 'intelligence_index.search_chunks' ),
				'risk_level' => 'content_update',
				'confidence' => 'high',
			),
			'internal_links' => array(
				'guide_id'   => '',
				'next_tool'  => $registry->tool_name( 'content_find.internal_links' ),
				'sequence'   => array( 'intelligence_content_get_context', 'content_search_items', 'content_find_internal_links' ),
				'operations' => array( 'intelligence_index.internal_links' ),
				'risk_level' => 'read_only',
				'confidence' => 'high',
			),
			default => array(
				'guide_id'   => 'content_long_form_draft',
				'next_tool'  => $registry->tool_name( 'intelligence.content.get_context' ),
				'sequence'   => 'article' === $content_mode
					? array( 'intelligence_content_get_context', 'workflow_guides_get', 'content_workflow_prepare_post', 'content_find_internal_links', 'content_workflow_create_draft' )
					: array( 'intelligence_content_get_context', 'intelligence_blocks_list_available', 'intelligence_patterns_list_available', 'workflow_guides_get', 'content_workflow_prepare_post', 'content_workflow_create_draft' ),
				'operations' => array( 'workflows.prepare_post', 'workflows.create_draft', 'intelligence_index.internal_links' ),
				'risk_level' => 'draft_write',
				'confidence' => 'medium',
			),
		};
	}

	/**
	 * Build arguments for the suggested next tool.
	 *
	 * @param string               $tool Suggested tool name.
	 * @param string               $request User request.
	 * @param string               $post_type Target post type.
	 * @param string               $content_mode Content mode.
	 * @param array<string, mixed> $args Original arguments.
	 * @return array<string, mixed>
	 */
	private function next_tool_arguments( string $tool, string $request, string $post_type, string $content_mode, array $args ): array {
		if ( 'content_workflow_prepare_post' === $tool ) {
			return array(
				'brief'        => $request,
				'post_type'    => $post_type,
				'content_mode' => $content_mode,
			);
		}

		if ( str_starts_with( $tool, 'intelligence_' ) ) {
			return array();
		}

		if ( 'site_editor_get_context' === $tool || 'admin_menu_get_context' === $tool ) {
			return array();
		}

		if ( 'admin_menu_get_navigation_target' === $tool ) {
			return array(
				'query' => $request,
			);
		}

		if ( 'content_find_internal_links' === $tool ) {
			return array(
				'query' => $request,
			);
		}

		return array_filter(
			array(
				'brief'            => $request,
				'post_type'        => $post_type,
				'content_mode'     => $content_mode,
				'existing_post_id' => absint( $args['existing_post_id'] ?? $args['post_id'] ?? 0 ),
			)
		);
	}

	/**
	 * Infer request intent.
	 *
	 * @param string               $request User request.
	 * @param array<string, mixed> $args Original arguments.
	 */
	private function intent( string $request, array $args ): string {
		$explicit = sanitize_key( (string) ( $args['intent'] ?? '' ) );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		$text = strtolower( $request );
		if ( $this->contains_any( $text, array( 'what can you do', 'available abilities', 'available tools', 'possibilities', 'help directory', 'detect available' ) ) ) {
			return 'capability_discovery';
		}
		if ( $this->contains_any( $text, array( 'audit', 'health', 'readiness', 'maintenance', 'site management', 'diagnostic' ) ) ) {
			return 'site_audit';
		}
		if ( $this->contains_any( $text, array( 'appearance editor', 'appearance > editor', 'site editor', 'global styles', 'template part', 'template parts', 'header template', 'footer template', 'theme style', 'theme styles' ) ) ) {
			return 'site_editor';
		}
		if ( $this->contains_any( $text, array( 'admin menu', 'admin page', 'settings page', 'plugin setting', 'plugin settings', 'theme setting', 'theme settings', 'wordpress setting', 'wordpress settings', 'navigate admin' ) ) ) {
			return 'admin_menu';
		}
		if ( $this->contains_any( $text, array( 'rank math', 'meta title', 'meta description', 'focus keyword', 'seo title', 'seo metadata' ) ) ) {
			return 'seo_update';
		}
		if ( $this->contains_any( $text, array( 'internal link', 'related content', 'link opportunity' ) ) ) {
			return 'internal_links';
		}
		if ( $this->contains_any( $text, array( 'update', 'rewrite', 'revise', 'refresh', 'replace', 'edit existing' ) ) || absint( $args['existing_post_id'] ?? $args['post_id'] ?? 0 ) > 0 ) {
			return 'content_update';
		}

		return 'content_create';
	}

	/**
	 * Infer content mode.
	 *
	 * @param string               $request User request.
	 * @param array<string, mixed> $args Original arguments.
	 */
	private function content_mode( string $request, array $args ): string {
		$explicit = sanitize_key( (string) ( $args['content_mode'] ?? '' ) );
		if ( in_array( $explicit, array( 'article', 'page', 'landing_page', 'visual_layout', 'service_page', 'product_page', 'case_study' ), true ) ) {
			return $explicit;
		}

		$text = strtolower( $request . ' ' . (string) ( $args['layout_intent'] ?? '' ) . ' ' . (string) ( $args['visual_reference_summary'] ?? '' ) );
		if ( $this->contains_any( $text, array( 'screenshot', 'image attached', 'visual reference', 'grid', 'columns', 'cards', 'hero', 'section layout' ) ) ) {
			return 'visual_layout';
		}
		if ( str_contains( $text, 'landing' ) ) {
			return 'landing_page';
		}
		if ( str_contains( $text, 'service' ) ) {
			return 'service_page';
		}
		if ( str_contains( $text, 'product' ) ) {
			return 'product_page';
		}
		if ( str_contains( $text, 'case stud' ) ) {
			return 'case_study';
		}
		if ( str_contains( $text, 'page' ) ) {
			return 'page';
		}

		return 'article';
	}

	/**
	 * Infer post type.
	 *
	 * @param string               $request User request.
	 * @param array<string, mixed> $args Original arguments.
	 * @param string               $content_mode Content mode.
	 */
	private function post_type( string $request, array $args, string $content_mode ): string {
		$explicit = sanitize_key( (string) ( $args['post_type'] ?? '' ) );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		return in_array( $content_mode, array( 'page', 'landing_page', 'visual_layout', 'service_page', 'product_page' ), true ) || str_contains( strtolower( $request ), 'page' )
			? 'page'
			: 'post';
	}

	/**
	 * Return request text.
	 *
	 * @param array<string, mixed> $args Original arguments.
	 */
	private function request_text( array $args ): string {
		foreach ( array( 'request', 'brief', 'user_goal', 'prompt' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
				$value = trim( sanitize_text_field( (string) $args[ $key ] ) );
				if ( '' !== $value ) {
					return substr( $value, 0, 2000 );
				}
			}
		}

		return '';
	}

	/**
	 * Check if text contains any needle.
	 *
	 * @param string   $text Text to search.
	 * @param string[] $needles Needles.
	 * @phpstan-param list<string> $needles
	 */
	private function contains_any( string $text, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( str_contains( $text, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}

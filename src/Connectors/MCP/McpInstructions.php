<?php
/**
 * Server guidance presented during MCP initialization.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Keeps the long-form client guidance separate from transport handling.
 *
 * @internal
 */
final class McpInstructions {

	/**
	 * Return the bounded workflow and safety guidance for MCP clients.
	 *
	 * @return string Initialization guidance.
	 */
	public static function text(): string {
		return implode(
			' ',
			array(
				'Aculect AI Companion is a WordPress MCP server with read-only Aculect Intelligence context tools and separately governed operational tools.',
				'For ambiguous or multi-step work, call workflow_route_request first; when the workflow spans multiple tools, call workflow_session_start and pass workflow_session_id through workflow calls.',
				'Clients that support MCP resources can call resources/list and resources/read for compact Aculect context such as capability directory, site summary, content model, workflow guides, brand profile, and approved memory.',
				'When the user asks what is possible, what can be managed, or which abilities/workflows are available, call intelligence_capabilities_get_directory first.',
				'When the task needs a repeatable multi-tool procedure, call workflow_guides_list and then workflow_guides_get for the chosen guide.',
				'Before planning site, content, brand, or developer work, call the relevant context tool: intelligence_site_get_context, intelligence_content_get_context, intelligence_developer_get_context, or intelligence_brand_get_context.',
				'Use the returned operations manifest to choose only available operational tools; unavailable operations explain global ability, role policy, or OAuth scope blockers.',
				'Before planning WordPress core management, route/schema-dependent content work, or Site Editor compatibility, call core_schema_discover for bounded REST route, post type, taxonomy, status, revision, autosave, and capability discovery.',
				'For ChatGPT company knowledge, deep research, and citation-oriented retrieval, call search first and then fetch with a returned ID before quoting or citing WordPress content.',
				'For fast content discovery, prefer content_search_items, content_search_chunks, content_find_related, and content_find_internal_links before reading full posts; refresh stale index rows with content_index_refresh_batch when available.',
				'For internal-link work, inspect content_internal_link_policy first, audit existing signals with content_audit_internal_links when the source or target state is unclear, then use content_find_internal_links and the reviewable suggestion flow before requesting any write.',
				'For "do all" style collection work such as thin-page cleanup, create a workflow_loop after discovery, then use workflow_loop_run_next or workflow_loop_run_batch to process bounded items and report completion without resending the full item list.',
				'Use memory_list for durable Aculect Intelligence guidance; do not require ChatGPT or Claude saved memory to understand the site. If memory_list is empty or missing obvious site guidance, call memory_bootstrap or memory_save with no key/value to prepare initial memory for admin review. Submit new durable guidance with intelligence_feedback_submit for admin review unless the user explicitly authorizes memory_save.',
				'If the plugin, MCP connection, or an assistant workflow fails, call plugin_incident_report to store a local sanitized incident report with report_id and correlation_id, prepare a public GitHub issue draft, then create it through your own GitHub or browser tools when available.',
				'When repeated MCP errors or poor tool choices happen, call mcp_learning_inspect_activity and submit a bounded learning suggestion with intelligence_feedback_submit when the owner should review durable guidance.',
				'For site management planning or maintenance posture questions, call site_workflow_audit before recommending changes.',
				'For Appearance > Editor, full-site editing, template, template part, navigation, global style, or theme design-token work, call site_editor_get_context first, then inspect templates or template parts as needed. Site Editor work is admin-level only; do not request filesystem or theme-file changes.',
				'For classic menus, registered menu locations, wp_navigation entities, or Navigation block inventory, call navigation_get_context first and then use navigation_list_menus, navigation_list_locations, or navigation_list_items. This slice is read-only and does not implement menu writes or raw serialized block edits.',
				'For WordPress core, plugin, or theme admin settings work, call admin_menu_get_context or admin_menu_get_navigation_target first. Use registered settings metadata for discovery only; do not read or write arbitrary wp_options.',
				'For normal WordPress content creation or editing, call content_workflow_prepare_post first, then prefer content_workflow_create_draft, content_workflow_update_post, or seo_workflow_update_rankmath when available.',
				'For published custom content workflows, call content_workflow_list and content_workflow_get first, then use content_workflow_prepare, content_workflow_dry_run, and content_workflow_execute; resume or cancel only with the returned run_id and exact input/approval evidence.',
				'When an internal-link apply or other write preview returns confirmation_required, ask the user for confirmation and repeat the same tool call with confirmation_token before it expires.',
				'For image workflows, use content_media_apply_image to import an existing attachment, public URL, externally generated image URL, base64/data URL, or Openverse CC0 result, then set featured media or insert core image/gallery/cover/media-text blocks without hand-stitching raw media and content calls.',
				'When the user provides an image, screenshot, visual reference, grid, columns, cards, hero, landing page, service page, product page, or other page-layout direction, summarize the visual/layout requirements, discover layout blocks and patterns, and pass content_mode plus layout_intent to content_workflow_prepare_post before drafting.',
				'Use atomic content, taxonomy, media, and SEO tools only when a workflow tool is unavailable or the user asks for a narrow direct operation.',
				'If intelligence is incomplete, stale, or causes poor results, call intelligence_feedback_submit with a bounded learning suggestion for admin review.',
				'Never use raw Custom HTML blocks or core/html; use registered WordPress blocks and patterns, and validate block content before write operations.',
				'Pass a unique idempotency_key on create and update tool calls so network retries replay the stored result instead of duplicating work.',
			)
		);
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing, WordPress.Arrays.MultipleStatementAlignment, Generic.Commenting.DocComment.MissingShort, Squiz.Commenting.FunctionComment -- Schemas intentionally retain their frozen compact declaration form.

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\ContentMediaWorkflowAbilities;
use Aculect\AICompanion\Connectors\MCP\ContentWriteSchemas;
use Aculect\AICompanion\Connectors\MCP\ContentWorkflowAbilities;
use Aculect\AICompanion\Connectors\MCP\SiteWorkflowAbilities;
use Aculect\AICompanion\Connectors\MCP\WorkflowGuideRegistry;
use Aculect\AICompanion\Connectors\MCP\WorkflowLoopStore;
use Aculect\AICompanion\Connectors\MCP\WorkflowRouter;
use Aculect\AICompanion\Connectors\MCP\WorkflowSessionStore;
use RuntimeException;

/**
 * Owns the fixed MCP workflow module declarations.
 */
final class FixedWorkflowAbilityModules {

	private const MIN_LONG_FORM_WORDS          = 3000;
	private const MAX_LONG_FORM_WORDS          = 5000;
	private const MAX_SERIALIZED_CONTENT_BYTES = 300000;
	private const CONTENT_MODES                = array( 'article', 'page', 'landing_page', 'visual_layout', 'service_page', 'product_page', 'case_study' );
	private const BLOCK_FAMILIES               = array( 'text', 'media', 'layout', 'navigation', 'data', 'embed', 'design', 'widget' );

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Return the fixed modules in their historical registry order.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	public function all(): array {
		$modules = array(
			$this->factory->create( 'workflow.route_request', 'Route MCP Workflow Request', 'Use this first for ambiguous or multi-step WordPress MCP work. It classifies the request, selects the best workflow guide, lists blocked operations, and returns the next tool with arguments.', 'Workflow Router', 'content:read', true, $this->workflow_route_schema(), static fn ( array $args ): array => ( new WorkflowRouter() )->route( $args ) ),
			$this->factory->create( 'workflow_session.start', 'Start MCP Workflow Session', 'Start a bounded Aculect workflow session so ChatGPT, Claude, Codex, or another MCP client can resume multi-tool content, SEO, or site-management work without relying on chat memory.', 'Workflow Sessions', 'content:draft', false, $this->workflow_session_start_schema(), static fn ( array $args ): array => ( new WorkflowSessionStore() )->start( $args ) ),
			$this->factory->create( 'workflow_session.get', 'Get MCP Workflow Session', 'Read bounded Aculect workflow progress for a previous multi-tool MCP workflow.', 'Workflow Sessions', 'content:read', true, $this->workflow_session_get_schema(), static fn ( array $args ): array => ( new WorkflowSessionStore() )->get( $args ) ),
			$this->factory->create( 'workflow_session.update', 'Update MCP Workflow Session', 'Advance bounded Aculect workflow progress after a planning, validation, draft, update, SEO, or review step.', 'Workflow Sessions', 'content:draft', false, $this->workflow_session_update_schema(), static fn ( array $args ): array => ( new WorkflowSessionStore() )->update( $args ) ),
			$this->factory->create( 'workflow_loop.create', 'Create MCP Workflow Loop', 'Create a bounded item-aware workflow loop from thin-page candidates or explicit content items so assistants can store guidance once and resume item-by-item.', 'Workflow Loops', 'content:draft', false, $this->workflow_loop_create_schema(), static fn ( array $args ): array => ( new WorkflowLoopStore() )->create( $args ) ),
			$this->factory->create( 'workflow_loop.get', 'Get MCP Workflow Loop', 'Read compact workflow loop progress, item statuses, recent events, current item, and next actions.', 'Workflow Loops', 'content:read', true, $this->workflow_loop_get_schema(), static fn ( array $args ): array => ( new WorkflowLoopStore() )->get( $args ) ),
			$this->factory->create( 'workflow_loop.run_next', 'Run Next Workflow Loop Item', 'Mark optional prior item completion and return the next bounded item with the workflow tool arguments the assistant should use.', 'Workflow Loops', 'content:draft', false, $this->workflow_loop_run_next_schema(), static fn ( array $args ): array => ( new WorkflowLoopStore() )->run_next( $args ) ),
			$this->factory->create( 'workflow_loop.run_batch', 'Run Workflow Loop Batch', 'Mark optional completed item results and return a bounded batch of pending loop items without repeating already completed items.', 'Workflow Loops', 'content:draft', false, $this->workflow_loop_run_batch_schema(), static fn ( array $args ): array => ( new WorkflowLoopStore() )->run_batch( $args ) ),
			$this->factory->create( 'workflow_loop.pause', 'Pause MCP Workflow Loop', 'Pause a workflow loop without discarding stored item progress.', 'Workflow Loops', 'content:draft', false, $this->workflow_loop_pause_schema(), static fn ( array $args ): array => ( new WorkflowLoopStore() )->pause( $args ) ),
			$this->factory->create( 'workflow_loop.cancel', 'Cancel MCP Workflow Loop', 'Cancel a workflow loop and prevent future run calls from starting pending items.', 'Workflow Loops', 'content:draft', false, $this->workflow_loop_pause_schema(), static fn ( array $args ): array => ( new WorkflowLoopStore() )->cancel( $args ) ),
			$this->factory->create( 'workflow_guides.list', 'List MCP Workflow Guides', 'List compact, policy-aware workflow guides so assistants can choose the right multi-tool path without loading large instructions upfront.', 'Workflow Guides', 'content:read', true, $this->workflow_guides_list_schema(), static fn ( array $args ): array => ( new WorkflowGuideRegistry() )->list_guides( $args ) ),
			$this->factory->create( 'workflow_guides.get', 'Get MCP Workflow Guide', 'Read one compact workflow guide with required operations, optional operations, missing blockers, and safe step order.', 'Workflow Guides', 'content:read', true, $this->workflow_guides_get_schema(), static fn ( array $args ): array => ( new WorkflowGuideRegistry() )->get_guide( $args ) ),
			$this->factory->create( 'content_workflow.prepare_post', 'Prepare Long-Form Content Workflow', 'Use this when a user asks to create, rewrite, or plan WordPress long-form content. It returns a block-safe outline, section plan, SEO recommendations, and available workflow operations before any write.', 'Content Workflows', 'content:read', true, $this->workflow_prepare_post_schema(), static fn ( array $args ): array => ( new ContentWorkflowAbilities() )->prepare_post( $args ) ),
			$this->factory->create( 'content_workflow.create_draft', 'Create Draft From Block Workflow', 'Use this when a user wants to create a WordPress draft from validated serialized block content, including long-form posts of 3000 to 5000 words. Do not use raw HTML or core/html.', 'Content Workflows', 'content:draft', false, $this->workflow_create_draft_schema(), static fn ( array $args ): array => ( new ContentWorkflowAbilities() )->create_draft( $args ) ),
			$this->factory->create( 'content_workflow.update_post', 'Update Post From Block Workflow', 'Use this when a user wants to update an existing WordPress post from validated serialized block content or a section map. Prefer this for long-form content updates instead of low-level content_update_item.', 'Content Workflows', 'content:draft', false, $this->workflow_update_post_schema(), static fn ( array $args ): array => ( new ContentWorkflowAbilities() )->update_post( $args ) ),
			$this->factory->create( 'content_media.search_cc0_images', 'Search CC0 Image Candidates', 'Search Openverse for CC0 image candidates that can be reviewed before import into the WordPress media library.', 'Content Media Workflows', 'content:read', true, $this->content_media_search_schema(), static fn ( array $args ): array => ( new ContentMediaWorkflowAbilities() )->search_cc0_images( $args ) ),
			$this->factory->create( 'content_media.apply_image', 'Apply Image to Content', 'Resolve an image from an existing attachment, URL, generated image URL, base64/data URL, or CC0 search result, then set it as featured media or insert a safe core media block into an existing post.', 'Content Media Workflows', 'content:draft', false, $this->content_media_apply_image_schema(), static fn ( array $args ): array => ( new ContentMediaWorkflowAbilities() )->apply_image( $args ) ),
			$this->factory->create( 'seo_workflow.update_rankmath', 'Update Rank Math SEO Workflow', 'Use this when a user specifically wants to update Rank Math SEO title, meta description, or focus keywords for a WordPress content item.', 'SEO Workflows', 'content:draft', false, $this->workflow_rankmath_schema(), static fn ( array $args ): array => ( new ContentWorkflowAbilities() )->update_rankmath_seo( $args ) ),
			$this->factory->create( 'site_workflow.audit', 'Audit Site Management Readiness', 'Use this read-only workflow when a user asks to audit site health, maintenance posture, connector readiness, update signals, permalinks, HTTPS, REST API, cron, or active theme state before planning site management work.', 'Site Workflows', 'content:read', true, $this->empty_schema(), static fn ( array $args ): array => ( new SiteWorkflowAbilities() )->audit( $args ) ),
		);

		return $this->key_by_id( $modules );
	}

	/**
	 * Key modules without permitting silent duplicate replacement.
	 *
	 * @param list<AbilityModuleInterface> $modules Modules to index.
	 * @return array<string, AbilityModuleInterface>
	 * @throws RuntimeException When a duplicate internal ID is present.
	 */
	public function key_by_id( array $modules ): array {
		$keyed = array();
		foreach ( $modules as $module ) {
			if ( isset( $keyed[ $module->id() ] ) ) {
				throw new RuntimeException( 'Duplicate fixed workflow ability ID.' );
			}
			$keyed[ $module->id() ] = $module;
		}

		return $keyed;
	}

	/**
	 * Build a closed object schema.
	 *
	 * @param array<string, mixed> $properties Schema properties.
	 * @param list<string>         $required   Required keys.
	 * @return array<string, mixed>
	 */
	private function object_schema( array $properties, array $required = array() ): array {
		$schema = array( 'type' => 'object', 'properties' => $properties, 'additionalProperties' => false );
		if ( array() !== $required ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/** @return array<string, mixed> */
	private function empty_schema(): array {
		return array( 'type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false );
	}

	/** @return array<string, mixed> */
	private function workflow_guides_list_schema(): array {
		return $this->object_schema(
			array(
				'category' => array( 'type' => 'string', 'enum' => array( 'content', 'seo', 'site' ), 'description' => 'Optional guide category filter.' ),
				'available_only' => array( 'type' => 'boolean', 'description' => 'When true, return only guides whose required operations are currently available.' ),
				'detail' => array( 'type' => 'string', 'enum' => array( 'summary', 'full' ), 'description' => 'Use summary for discovery and full for guide steps.' ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function workflow_route_schema(): array {
		return $this->object_schema(
			array(
				'request' => array( 'type' => 'string', 'description' => 'User request to classify and route.' ),
				'brief' => array( 'type' => 'string', 'description' => 'Alias for request when the client already has a content brief.' ),
				'user_goal' => array( 'type' => 'string', 'description' => 'Alias for request when the client has a goal statement.' ),
				'prompt' => array( 'type' => 'string', 'description' => 'Alias for request for clients that pass prompt-shaped arguments.' ),
				'intent' => array( 'type' => 'string', 'enum' => array( 'capability_discovery', 'site_audit', 'site_editor', 'admin_menu', 'seo_update', 'internal_links', 'content_update', 'content_create' ), 'description' => 'Optional explicit intent override.' ),
				'post_type' => array( 'type' => 'string', 'description' => 'Target WordPress post type when known.' ),
				'content_mode' => $this->content_mode_schema(),
				'layout_intent' => array( 'type' => 'string', 'description' => 'Layout direction such as hero, columns, cards, grid, comparison, or CTA sections.' ),
				'visual_reference_summary' => array( 'type' => 'string', 'description' => 'Concise summary of any image/screenshot/design reference the assistant inspected.' ),
				'existing_post_id' => array( 'type' => 'integer', 'description' => 'Existing post ID when the request is an update.' ),
				'post_id' => array( 'type' => 'integer', 'description' => 'Alias for existing_post_id.' ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function workflow_session_start_schema(): array {
		return $this->object_schema(
			array(
				'workflow' => array( 'type' => 'string', 'description' => 'Workflow or workflow guide ID.' ),
				'workflow_id' => array( 'type' => 'string', 'description' => 'Alias for workflow.' ),
				'state' => $this->workflow_session_state_schema(),
				'brief' => array( 'type' => 'string' ), 'request' => array( 'type' => 'string' ), 'provider' => array( 'type' => 'string' ), 'intent' => array( 'type' => 'string' ),
				'content_mode' => $this->content_mode_schema(), 'post_type' => array( 'type' => 'string' ), 'target_type' => array( 'type' => 'string' ), 'target_id' => array( 'type' => 'integer' ), 'post_id' => array( 'type' => 'integer' ), 'title' => array( 'type' => 'string' ), 'operation' => array( 'type' => 'string' ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function workflow_session_get_schema(): array {
		return $this->object_schema(
			array(
				'workflow_session_id' => array( 'type' => 'string', 'description' => 'Workflow session ID returned by workflow_session_start or a workflow response.' ),
				'id' => array( 'type' => 'string', 'description' => 'Alias for workflow_session_id.' ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function workflow_session_update_schema(): array {
		return $this->object_schema(
			array(
				'workflow_session_id' => array( 'type' => 'string', 'description' => 'Workflow session ID returned by workflow_session_start or a workflow response.' ),
				'id' => array( 'type' => 'string', 'description' => 'Alias for workflow_session_id.' ),
				'state' => $this->workflow_session_state_schema(),
				'message' => array( 'type' => 'string', 'description' => 'Short progress note. Do not include secrets or long content bodies.' ),
				'tool' => array( 'type' => 'string', 'description' => 'Tool that just completed.' ),
				'post_id' => array( 'type' => 'integer' ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function workflow_loop_create_schema(): array {
		return $this->object_schema(
			array(
				'source' => array( 'type' => 'string', 'enum' => array( 'thin_pages', 'provided_items' ), 'description' => 'Collection source. Defaults to thin_pages.' ),
				'workflow' => array( 'type' => 'string', 'description' => 'Workflow or workflow guide ID. Defaults to thin_page_cleanup.' ),
				'workflow_id' => array( 'type' => 'string', 'description' => 'Alias for workflow.' ),
				'workflow_session_id' => $this->workflow_session_id_schema(),
				'objective' => array( 'type' => 'string', 'description' => 'Short loop objective.' ),
				'brief' => array( 'type' => 'string', 'description' => 'Alias for objective.' ),
				'guidance' => array( 'type' => 'string', 'maxLength' => 1200, 'description' => 'User guidance to apply to every loop item.' ),
				'query' => array( 'type' => 'string', 'description' => 'Optional search term for thin-page discovery.' ),
				'post_type' => array( 'type' => 'string', 'description' => 'Post type for thin-page discovery. Defaults to page.' ),
				'status' => array( 'type' => 'string', 'description' => 'Post status for thin-page discovery. Defaults to publish.' ),
				'max_word_count' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'description' => 'Maximum indexed word count for thin-page candidates. Defaults to 300.' ),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Maximum items to store in the loop.' ),
				'batch_size' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Default batch size for workflow_loop_run_batch.' ),
				'items' => array( 'type' => 'array', 'description' => 'Explicit content items when source=provided_items.', 'maxItems' => 50, 'items' => $this->workflow_loop_item_schema() ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function workflow_loop_get_schema(): array {
		return $this->object_schema( array( 'workflow_loop_id' => $this->workflow_loop_id_schema(), 'loop_id' => $this->workflow_loop_id_schema(), 'id' => $this->workflow_loop_id_schema() ) );
	}

	/** @return array<string, mixed> */
	private function workflow_loop_run_next_schema(): array {
		return $this->object_schema(
			array(
				'workflow_loop_id' => $this->workflow_loop_id_schema(), 'loop_id' => $this->workflow_loop_id_schema(), 'id' => $this->workflow_loop_id_schema(),
				'completed_item_id' => array( 'type' => 'integer', 'description' => 'Optional item ID that the assistant just completed.' ),
				'completed_status' => $this->workflow_loop_completion_status_schema(),
				'completed_message' => array( 'type' => 'string', 'description' => 'Short completion note. Do not include long content bodies.' ),
				'resume' => array( 'type' => 'boolean', 'description' => 'Set true to resume a paused loop before selecting the next item.' ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function workflow_loop_run_batch_schema(): array {
		return $this->object_schema(
			array(
				'workflow_loop_id' => $this->workflow_loop_id_schema(), 'loop_id' => $this->workflow_loop_id_schema(), 'id' => $this->workflow_loop_id_schema(),
				'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => 'Maximum pending items to start in this call.' ),
				'completed_items' => array(
					'type' => 'array', 'description' => 'Optional completed item results to store before starting more items.', 'maxItems' => 10,
					'items' => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'item_id' => array( 'type' => 'integer' ), 'status' => $this->workflow_loop_completion_status_schema(), 'message' => array( 'type' => 'string' ) ), 'additionalProperties' => false ),
				),
				'resume' => array( 'type' => 'boolean', 'description' => 'Set true to resume a paused loop before selecting the next batch.' ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function workflow_loop_pause_schema(): array {
		return $this->object_schema(
			array(
				'workflow_loop_id' => $this->workflow_loop_id_schema(), 'loop_id' => $this->workflow_loop_id_schema(), 'id' => $this->workflow_loop_id_schema(),
				'message' => array( 'type' => 'string', 'description' => 'Short reason for the pause or cancellation.' ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function workflow_loop_item_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array( 'id' => array( 'type' => 'integer' ), 'post_id' => array( 'type' => 'integer' ), 'type' => array( 'type' => 'string' ), 'post_type' => array( 'type' => 'string' ), 'status' => array( 'type' => 'string' ), 'post_status' => array( 'type' => 'string' ), 'title' => array( 'type' => 'string' ), 'post_title' => array( 'type' => 'string' ), 'permalink' => array( 'type' => 'string' ), 'url' => array( 'type' => 'string' ), 'word_count' => array( 'type' => 'integer' ), 'stale' => array( 'type' => 'boolean' ) ),
			'additionalProperties' => false,
		);
	}

	/** @return array<string, string> */
	private function workflow_loop_id_schema(): array {
		return array( 'type' => 'string', 'description' => 'Workflow loop ID returned by workflow_loop_create.' );
	}

	/** @return array<string, mixed> */
	private function workflow_loop_completion_status_schema(): array {
		return array( 'type' => 'string', 'enum' => array( 'succeeded', 'failed', 'skipped', 'blocked', 'cancelled' ), 'description' => 'Final status for an item that has already been processed outside the loop store.' );
	}

	/** @return array<string, mixed> */
	private function workflow_guides_get_schema(): array {
		return $this->object_schema( array( 'id' => array( 'type' => 'string', 'description' => 'Workflow guide ID returned by workflow_guides_list.' ) ), array( 'id' ) );
	}

	/** @return array<string, mixed> */
	private function workflow_prepare_post_schema(): array {
		return $this->object_schema(
			array(
				'brief' => array( 'type' => 'string', 'description' => 'Content brief or user request to plan against.' ),
				'post_type' => array( 'type' => 'string', 'description' => 'Target WordPress post type. Defaults to post.' ),
				'audience' => array( 'type' => 'string', 'description' => 'Intended reader or customer segment.' ),
				'seo_intent' => array( 'type' => 'string', 'description' => 'Search intent, target query, or SEO goal.' ),
				'desired_word_count' => array( 'type' => 'integer', 'minimum' => self::MIN_LONG_FORM_WORDS, 'maximum' => self::MAX_LONG_FORM_WORDS, 'description' => 'Target word count for long-form content. Values are clamped to 3000-5000 words.' ),
				'content_mode' => $this->content_mode_schema(),
				'content_type' => array( 'type' => 'string', 'description' => 'Optional natural-language content type alias such as blog post, landing page, service page, product page, case study, or visual layout. content_mode is preferred when known.' ),
				'layout_intent' => array( 'type' => 'string', 'description' => 'Layout direction such as hero plus three-column cards, media/text sections, pricing grid, comparison columns, or FSE-style page composition.' ),
				'visual_reference_summary' => array( 'type' => 'string', 'description' => 'Concise non-sensitive summary of an attached image/screenshot/design reference. The MCP server does not inspect images directly; the assistant should translate the image into layout requirements here.' ),
				'section_requirements' => $this->string_list_schema( 'Requested page/article sections, for example hero, feature grid, testimonials, FAQ, comparison, or CTA.', 12 ),
				'preferred_block_families' => $this->block_family_list_schema( 'Preferred block families such as layout, media, or text.' ),
				'preferred_blocks' => $this->string_list_schema( 'Preferred registered block names, for example core/columns, core/group, core/cover, or core/media-text.', 20 ),
				'preferred_patterns' => $this->string_list_schema( 'Preferred registered pattern names when known.', 12 ),
				'existing_post_id' => array( 'type' => 'integer', 'description' => 'Existing post ID when planning an update workflow.' ),
				'workflow_session_id' => $this->workflow_session_id_schema(),
			),
			array( 'brief' )
		);
	}

	/** @return array<string, mixed> */
	private function workflow_create_draft_schema(): array {
		return $this->object_schema(
			array_merge(
				$this->workflow_content_fields(),
				$this->rankmath_fields(),
				array( 'post_type' => array( 'type' => 'string', 'description' => 'Target WordPress post type. Defaults to post.' ), 'workflow_session_id' => $this->workflow_session_id_schema() )
			),
			array( 'title', 'content' )
		);
	}

	/** @return array<string, mixed> */
	private function workflow_update_post_schema(): array {
		return $this->object_schema(
			array_merge(
				array(
					'id' => array( 'type' => 'integer', 'description' => 'Existing WordPress content item ID.' ),
					'update_mode' => array( 'type' => 'string', 'enum' => array( 'replace', 'sections' ), 'description' => 'Use replace for a full block document or sections when section_map contains the updated serialized section content.' ),
					'section_map' => array(
						'type' => 'object',
						'description' => 'Map stable section IDs to updated serialized block section objects. The workflow combines sections into a full block document before validation.',
						'additionalProperties' => array(
							'type' => 'object',
							'properties' => array(
								'content' => array( 'type' => 'string', 'maxLength' => self::MAX_SERIALIZED_CONTENT_BYTES, 'description' => 'Serialized WordPress block markup for this section. Never use raw HTML or core/html.' ),
								'id' => array( 'type' => 'string', 'description' => 'Optional stable section ID. The map key is used when omitted.' ),
								'section_id' => array( 'type' => 'string', 'description' => 'Optional alias for id.' ),
								'anchor' => array( 'type' => 'string', 'description' => 'Optional heading anchor for the section.' ),
								'heading' => array( 'type' => 'string', 'description' => 'Optional heading text used to resolve the section ID.' ),
							),
							'required' => array( 'content' ),
							'additionalProperties' => false,
						),
					),
					'status' => $this->content_status_schema(),
					'workflow_session_id' => $this->workflow_session_id_schema(),
				),
				$this->workflow_content_fields(),
				$this->rankmath_fields(),
				array( 'expected_modified_gmt' => ContentWriteSchemas::expected_modified_gmt() )
			),
			array( 'id' )
		);
	}

	/** @return array<string, mixed> */
	private function content_media_search_schema(): array {
		return $this->object_schema(
			array(
				'query' => array( 'type' => 'string', 'description' => 'Image search topic. Openverse results are restricted to CC0.' ),
				'topic' => array( 'type' => 'string', 'description' => 'Alias for query.' ),
				'page' => $this->page_schema(),
				'per_page' => $this->per_page_schema( 10, 'Image candidates to return. Defaults to 5 and is capped at 10.' ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function content_media_apply_image_schema(): array {
		return $this->object_schema(
			array(
				'post_id' => array( 'type' => 'integer', 'description' => 'Existing WordPress post, page, or custom content item ID.' ),
				'id' => array( 'type' => 'integer', 'description' => 'Alias for post_id.' ),
				'source_type' => array( 'type' => 'string', 'enum' => array( 'attachment_id', 'url', 'generated_url', 'image_data', 'data_url', 'search_cc0' ), 'description' => 'Image source. Use generated_url for externally generated AI images, image_data/data_url for direct encoded image payloads, and search_cc0 to import from Openverse CC0 results.' ),
				'target' => array( 'type' => 'string', 'enum' => array( 'featured_image', 'insert_block' ), 'description' => 'Whether to set the image as featured media or insert a media block into post content.' ),
				'attachment_id' => array( 'type' => 'integer', 'description' => 'Existing image attachment ID when source_type is attachment_id.' ),
				'media_id' => array( 'type' => 'integer', 'description' => 'Alias for attachment_id.' ),
				'image_id' => array( 'type' => 'integer', 'description' => 'Alias for attachment_id.' ),
				'url' => array( 'type' => 'string', 'format' => 'uri', 'description' => 'Public HTTP or HTTPS image URL for url or generated_url sources.' ),
				'image_url' => array( 'type' => 'string', 'format' => 'uri', 'description' => 'Alias for url.' ),
				'data_url' => array( 'type' => 'string', 'maxLength' => 15000000, 'description' => 'Base64 image data URL for image_data/data_url sources.' ),
				'data_base64' => array( 'type' => 'string', 'maxLength' => 15000000, 'description' => 'Raw base64 image data for image_data sources. Prefer data_url when the client can provide it.' ),
				'image_base64' => array( 'type' => 'string', 'maxLength' => 15000000, 'description' => 'Alias for data_base64.' ),
				'mime_type' => array( 'type' => 'string', 'description' => 'Image MIME type required when raw base64 data is provided.' ),
				'filename' => array( 'type' => 'string', 'description' => 'Preferred filename for encoded image uploads.' ),
				'query' => array( 'type' => 'string', 'description' => 'Search topic when source_type is search_cc0.' ),
				'topic' => array( 'type' => 'string', 'description' => 'Alias for query.' ),
				'selected_result_id' => array( 'type' => 'string', 'description' => 'Openverse result ID to import after reviewing search candidates.' ),
				'candidate_id' => array( 'type' => 'string', 'description' => 'Alias for selected_result_id.' ),
				'selected_index' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 9, 'description' => 'Zero-based candidate index to import. Defaults to 0.' ),
				'block_type' => array( 'type' => 'string', 'enum' => array( 'image', 'gallery', 'cover', 'media_text' ), 'description' => 'Core media block to insert when target is insert_block.' ),
				'placement' => array( 'type' => 'string', 'enum' => array( 'append', 'prepend', 'after_first_paragraph', 'after_heading' ), 'description' => 'Where to insert the media block in existing content.' ),
				'section_id' => array( 'type' => 'string', 'description' => 'Heading anchor or normalized heading text required when placement is after_heading.' ),
				'after_heading' => array( 'type' => 'string', 'description' => 'Alias for section_id.' ),
				'block_text' => array( 'type' => 'string', 'description' => 'Optional paragraph text inside media_text blocks.' ),
				'title' => array( 'type' => 'string' ), 'alt_text' => array( 'type' => 'string' ), 'caption' => array( 'type' => 'string' ), 'description' => array( 'type' => 'string' ),
			)
		);
	}

	/** @return array<string, mixed> */
	private function workflow_rankmath_schema(): array {
		return $this->object_schema( array_merge( array( 'id' => array( 'type' => 'integer', 'description' => 'Existing WordPress content item ID.' ), 'workflow_session_id' => $this->workflow_session_id_schema() ), $this->rankmath_fields() ), array( 'id' ) );
	}

	/** @return array<string, mixed> */
	private function workflow_content_fields(): array {
		return array(
			'title' => array( 'type' => 'string' ),
			'content' => array( 'type' => 'string', 'maxLength' => self::MAX_SERIALIZED_CONTENT_BYTES, 'description' => 'Serialized WordPress block content for the full document. Required for create. For update, provide content or section_map. Never use raw HTML or core/html.' ),
			'excerpt' => array( 'type' => 'string' ), 'slug' => array( 'type' => 'string' ), 'date' => $this->content_date_schema(),
			'featured_media' => array( 'type' => 'integer', 'description' => 'Existing image attachment ID to assign as the featured image.' ),
			'clear_featured_media' => array( 'type' => 'boolean', 'description' => 'Set true to intentionally remove the current featured image.' ),
			'author' => array( 'type' => 'integer', 'description' => 'Existing WordPress user ID to assign as author.' ),
			'taxonomies' => $this->taxonomy_assignment_schema( 'Map taxonomy slugs to existing term IDs or term slugs.' ),
			'content_mode' => $this->content_mode_schema(),
			'layout_intent' => array( 'type' => 'string', 'description' => 'Expected layout direction used for block validation warnings.' ),
			'visual_reference_summary' => array( 'type' => 'string', 'description' => 'Concise non-sensitive summary of the visual reference used to produce this block document.' ),
			'expected_block_families' => $this->block_family_list_schema( 'Block families expected in the serialized content. Use layout for columns/grids/cards/page sections.' ),
			'expected_blocks' => $this->string_list_schema( 'Specific block names expected in the serialized content, such as core/columns or core/media-text.', 20 ),
		);
	}

	/** @return array<string, mixed> */
	private function content_mode_schema(): array {
		return array( 'type' => 'string', 'enum' => self::CONTENT_MODES, 'description' => 'Content shape. Use article for prose-heavy blog posts, page or landing_page for FSE-style pages, visual_layout when an image/screenshot/layout direction should drive block choice, and service_page/product_page/case_study for those structured page types.' );
	}

	/** @return array<string, mixed> */
	private function string_list_schema( string $description, int $max_items = 20 ): array {
		return array( 'type' => 'array', 'description' => $description, 'items' => array( 'type' => 'string' ), 'maxItems' => $max_items );
	}

	/** @return array<string, mixed> */
	private function page_schema(): array {
		return array( 'type' => 'integer', 'minimum' => 1, 'description' => 'One-based page number. Defaults to 1.' );
	}

	/** @return array<string, mixed> */
	private function per_page_schema( int $maximum, string $description ): array {
		return array( 'type' => 'integer', 'minimum' => 1, 'maximum' => $maximum, 'description' => $description );
	}

	/** @return array<string, string> */
	private function workflow_session_id_schema(): array {
		return array( 'type' => 'string', 'description' => 'Optional workflow session ID from workflow_session_start. Pass it through workflow calls so progress is preserved outside client chat memory.' );
	}

	/** @return array<string, mixed> */
	private function workflow_session_state_schema(): array {
		return array( 'type' => 'string', 'enum' => array( 'routed', 'started', 'prepared', 'validated', 'draft_created', 'updated', 'seo_applied', 'needs_review', 'failed', 'complete' ), 'description' => 'Compact workflow state.' );
	}

	/** @return array<string, mixed> */
	private function block_family_list_schema( string $description ): array {
		$schema                  = $this->string_list_schema( $description, 8 );
		$schema['items']['enum'] = self::BLOCK_FAMILIES;
		return $schema;
	}

	/** @return array<string, mixed> */
	private function rankmath_fields(): array {
		return array( 'meta_title' => array( 'type' => 'string', 'description' => 'Rank Math SEO title.' ), 'meta_description' => array( 'type' => 'string', 'description' => 'Rank Math SEO meta description.' ), 'focus_keywords' => $this->string_list_schema( 'Rank Math focus keywords.', 10 ) );
	}

	/** @return array<string, string> */
	private function content_date_schema(): array {
		return array( 'type' => 'string', 'description' => 'Publication date as YYYY-MM-DDTHH:MM:SS in the site timezone, YYYY-MM-DD HH:MM:SS, or ISO 8601 with a timezone offset. Pass date, not date_gmt. Use status future with a future date to schedule.' );
	}

	/** @return array<string, mixed> */
	private function content_status_schema(): array {
		return array( 'type' => 'string', 'enum' => array( 'draft', 'future', 'pending', 'private', 'publish', 'trash' ), 'description' => 'Writable WordPress status. Use future only with a date that resolves to a future time in the WordPress site timezone. Invalid statuses are rejected instead of being converted to draft.' );
	}

	/** @return array<string, mixed> */
	private function taxonomy_assignment_schema( string $description ): array {
		return array( 'type' => 'object', 'description' => $description . ' Each taxonomy value should be an array of existing term IDs.', 'additionalProperties' => array( 'type' => 'array', 'description' => 'Existing term IDs for one taxonomy.', 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'maxItems' => 100 ) );
	}
}
// phpcs:enable

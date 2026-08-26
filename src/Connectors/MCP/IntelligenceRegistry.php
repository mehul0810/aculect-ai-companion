<?php
/**
 * Internal Aculect intelligence registry.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Intelligence\LearningSuggestionRepository;
use Closure;

/**
 * Registry of always-on intelligence tools exposed through MCP.
 */
final class IntelligenceRegistry {
	private const MAX_SERIALIZED_CONTENT_BYTES = 300000;

	/**
	 * Cached intelligence modules.
	 *
	 * @var array<string, AbilityModuleInterface>|null
	 */
	private ?array $modules = null;

	/**
	 * Return internal intelligence modules.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	public function modules(): array {
		if ( null === $this->modules ) {
			$this->modules = $this->build_modules();
			AbilityModuleContract::validate( $this->modules );
		}

		return $this->modules;
	}

	/**
	 * Return one module by internal ID, legacy alias, or public tool name.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function module( string $id ): ?AbilityModuleInterface {
		return $this->modules()[ $this->internal_id( $id ) ] ?? null;
	}

	/**
	 * Check whether an intelligence tool exists.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function is_known( string $id ): bool {
		return array_key_exists( $this->internal_id( $id ), $this->modules() );
	}

	/**
	 * Return OAuth scopes required for an intelligence tool.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 * @return list<string>
	 */
	public function required_scopes( string $id ): array {
		$module = $this->module( $id );

		return null === $module ? array( 'content:read' ) : $module->required_scopes();
	}

	/**
	 * Return the input schema for an intelligence tool.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 * @return array<string, mixed>
	 */
	public function input_schema( string $id ): array {
		$module = $this->module( $id );

		return null === $module ? $this->empty_schema() : $module->input_schema();
	}

	/**
	 * Execute a registered intelligence tool.
	 *
	 * @param string               $id     Internal ID, legacy alias, or public tool name.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @param array<string, mixed> $source Authenticated MCP connection context.
	 * @return array<string, mixed>
	 */
	public function execute( string $id, array $args, array $source = array() ): array {
		$internal_id = $this->internal_id( $id );
		if ( 'intelligence.feedback.submit' === $internal_id ) {
			return ( new LearningSuggestionRepository() )->submit( $args, $source );
		}
		if ( 'plugin.incident.report' === $internal_id ) {
			return ( new PluginIncidentReporter() )->report( $args, $source );
		}
		if ( 'plugin.incident.list' === $internal_id ) {
			return ( new PluginIncidentReporter() )->list_reports( $args );
		}

		$module = $this->module( $internal_id );

		return null === $module ? array( 'error' => 'Unknown tool' ) : $module->execute( $args );
	}

	/**
	 * Check whether an intelligence tool is read-only.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function is_read_only( string $id ): bool {
		$module = $this->module( $id );

		return null === $module || $module->is_read_only();
	}

	/**
	 * Convert a public tool name or legacy alias back to the internal ID.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function internal_id( string $id ): string {
		$id = $this->normalize_alias( $id );
		if ( array_key_exists( $id, $this->modules() ) ) {
			return $id;
		}

		foreach ( array_keys( $this->modules() ) as $module_id ) {
			if ( hash_equals( $this->tool_name( (string) $module_id ), $id ) ) {
				return (string) $module_id;
			}
		}

		return $id;
	}

	/**
	 * Build a client-safe MCP tool name for an internal intelligence ID.
	 *
	 * @param string $id Internal ID or legacy alias.
	 */
	public function tool_name( string $id ): string {
		return ( new AbilitiesRegistry() )->tool_name( $this->normalize_alias( $id ) );
	}

	/**
	 * Normalize older ability names to the current intelligence IDs.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function normalize_alias( string $id ): string {
		$aliases = array(
			'brand.get_profile'       => 'intelligence.brand.get_context',
			'brand_get_profile'       => 'intelligence.brand.get_context',
			'blocks.list_available'   => 'intelligence.blocks.list_available',
			'blocks_list_available'   => 'intelligence.blocks.list_available',
			'blocks.get_info'         => 'intelligence.blocks.get_info',
			'blocks_get_info'         => 'intelligence.blocks.get_info',
			'patterns.list_available' => 'intelligence.patterns.list_available',
			'patterns_list_available' => 'intelligence.patterns.list_available',
			'patterns.get_info'       => 'intelligence.patterns.get_info',
			'patterns_get_info'       => 'intelligence.patterns.get_info',
			'content.validate_blocks' => 'intelligence.content.validate_blocks',
			'content_validate_blocks' => 'intelligence.content.validate_blocks',
			'plugin.issue.report'     => 'plugin.incident.report',
			'plugin_issue_report'     => 'plugin.incident.report',
		);

		return $aliases[ $id ] ?? $id;
	}

	/**
	 * Return internal intelligence modules keyed by ID.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	private function build_modules(): array {
		$context         = new IntelligenceContext();
		$block_knowledge = new BlockKnowledgeAbilities();
		$modules         = array(
			$this->build_module(
				'intelligence.capabilities.get_directory',
				'MCP Capability Directory',
				'Explain what this WordPress MCP connection can do now, what workflows are possible, which intelligence surfaces exist, and why blocked capabilities are unavailable.',
				$this->object_schema(
					array(
						'detail' => array(
							'type'        => 'string',
							'enum'        => array( 'summary', 'full' ),
							'description' => 'Use summary for a concise first-session answer or full to include per-operation entries and scopes. Defaults to summary.',
						),
					)
				),
				static fn ( array $args ): array => $context->capabilities( $args )
			),
			$this->build_module(
				'intelligence.site.get_context',
				'Site Intelligence',
				'Read stable site, theme, locale, and connector context for this WordPress site.',
				$this->empty_schema(),
				static fn (): array => $context->site()
			),
			$this->build_module(
				'intelligence.content.get_context',
				'Content Intelligence',
				'Read content types, taxonomies, block summaries, pattern summaries, and content-generation constraints.',
				$this->empty_schema(),
				static fn (): array => $context->content()
			),
			$this->build_module(
				'intelligence.developer.get_context',
				'Developer Intelligence',
				'Read safe WordPress runtime and implementation context without exposing secrets.',
				$this->empty_schema(),
				static fn (): array => $context->developer()
			),
			$this->build_module(
				'intelligence.brand.get_context',
				'Brand Intelligence',
				'Read saved and detected brand guidance for content, design, and media decisions.',
				$this->empty_schema(),
				static fn (): array => $context->brand()
			),
			$this->build_module(
				'intelligence.blocks.list_available',
				'List Available Blocks',
				'List registered WordPress blocks with usage guidance. Never use the Custom HTML block (core/html).',
				$this->object_schema(
					array(
						'search'    => array( 'type' => 'string' ),
						'namespace' => array(
							'type'        => 'string',
							'description' => 'Optional block namespace such as core, woocommerce, or a plugin namespace.',
						),
						'category'  => array( 'type' => 'string' ),
						'purpose'   => array(
							'type'        => 'string',
							'enum'        => array( 'text', 'media', 'layout', 'navigation', 'data', 'embed', 'design', 'widget' ),
							'description' => 'Optional generation family filter. Use layout for columns, grids, cards, covers, grouped sections, and visual page composition.',
						),
						'inserter'  => array(
							'type'        => 'boolean',
							'description' => 'Filter by whether the block is intended to appear in inserter-style selection flows.',
						),
						'page'      => $this->page_schema(),
						'per_page'  => $this->per_page_schema( 100, 'Blocks per page. Defaults to 50.' ),
						'context'   => $this->context_schema( 'Use compact for browsing or full to include attribute/support keys. Defaults to compact.' ),
					)
				),
				static fn ( array $args ): array => $block_knowledge->list_blocks( $args )
			),
			$this->build_module(
				'intelligence.blocks.get_info',
				'Inspect a Block',
				'Read detailed guidance for one registered WordPress block. Never use the Custom HTML block (core/html).',
				$this->object_schema(
					array(
						'name' => array(
							'type'        => 'string',
							'description' => 'Registered block name such as core/paragraph.',
						),
					),
					array( 'name' )
				),
				static fn ( array $args ): array => $block_knowledge->get_block_info( $args )
			),
			$this->build_module(
				'intelligence.patterns.list_available',
				'List Available Patterns',
				'List registered WordPress block patterns with usage guidance. Avoid patterns that contain Custom HTML blocks.',
				$this->object_schema(
					array(
						'search'     => array( 'type' => 'string' ),
						'category'   => array( 'type' => 'string' ),
						'block_type' => array(
							'type'        => 'string',
							'description' => 'Optional related block type such as core/post-content or core/query.',
						),
						'inserter'   => array(
							'type'        => 'boolean',
							'description' => 'Filter by whether the pattern is intended to appear in inserter-style selection flows.',
						),
						'page'       => $this->page_schema(),
						'per_page'   => $this->per_page_schema( 100, 'Patterns per page. Defaults to 50.' ),
						'context'    => $this->context_schema( 'Use compact for browsing or full to include bounded content previews. Defaults to compact.' ),
					)
				),
				static fn ( array $args ): array => $block_knowledge->list_patterns( $args )
			),
			$this->build_module(
				'intelligence.patterns.get_info',
				'Inspect a Pattern',
				'Read detailed guidance for one registered WordPress block pattern and optionally include bounded block markup.',
				$this->object_schema(
					array(
						'name'            => array(
							'type'        => 'string',
							'description' => 'Registered pattern name such as theme/hero.',
						),
						'include_content' => array(
							'type'        => 'boolean',
							'description' => 'When true, include bounded pattern block markup. Use only when the exact pattern markup is needed.',
						),
					),
					array( 'name' )
				),
				static fn ( array $args ): array => $block_knowledge->get_pattern_info( $args )
			),
			$this->build_module(
				'intelligence.content.validate_blocks',
				'Validate Block Content',
				'Validate serialized block content before writing it and reject Custom HTML block usage.',
				$this->object_schema(
					array(
						'content'                  => array(
							'type'        => 'string',
							'maxLength'   => self::MAX_SERIALIZED_CONTENT_BYTES,
							'description' => 'Serialized WordPress block content to validate before create or update operations.',
						),
						'content_mode'             => array(
							'type'        => 'string',
							'enum'        => array( 'article', 'page', 'landing_page', 'visual_layout', 'service_page', 'product_page', 'case_study' ),
							'description' => 'Expected content shape. Layout-oriented modes return warnings when no layout blocks are present.',
						),
						'layout_intent'            => array(
							'type'        => 'string',
							'description' => 'Expected layout direction, such as hero plus columns, grid cards, media/text, or visual page sections.',
						),
						'visual_reference_summary' => array(
							'type'        => 'string',
							'description' => 'Concise non-sensitive summary of any image/screenshot/design reference used to generate this block content.',
						),
						'expected_block_families'  => array(
							'type'        => 'array',
							'description' => 'Expected block families. Include layout when the document should use grid/columns/page-section blocks.',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'text', 'media', 'layout', 'navigation', 'data', 'embed', 'design', 'widget' ),
							),
						),
						'expected_blocks'          => array(
							'type'        => 'array',
							'description' => 'Specific block names expected in the document, such as core/columns or core/media-text.',
							'items'       => array( 'type' => 'string' ),
						),
					),
					array( 'content' )
				),
				static fn ( array $args ): array => $block_knowledge->validate_block_content( $args )
			),
			$this->build_module(
				'intelligence.feedback.submit',
				'Submit Learning Suggestion',
				'Queue an admin-reviewed suggestion when site, content, developer, or brand intelligence should improve. Do not include secrets, credentials, personal data, or raw tool arguments.',
				$this->object_schema(
					array(
						'domain'           => array(
							'type'        => 'string',
							'enum'        => array( 'site', 'content', 'developer', 'brand' ),
							'description' => 'The intelligence domain that should improve.',
						),
						'issue'            => array(
							'type'        => 'string',
							'description' => 'Short description of what went wrong or what was missing.',
						),
						'evidence'         => array(
							'type'        => 'string',
							'description' => 'Optional bounded, non-sensitive evidence from the interaction.',
						),
						'suggested_update' => array(
							'type'        => 'string',
							'description' => 'Suggested improvement for future site, content, developer, or brand intelligence.',
						),
						'confidence'       => array(
							'type'        => 'string',
							'enum'        => array( 'low', 'medium', 'high' ),
							'description' => 'Confidence that this suggestion should be reviewed.',
						),
					),
					array( 'domain', 'issue', 'suggested_update' )
				),
				static function ( array $args ): array {
					unset( $args );

					return array(
						'status'  => 'unavailable',
						'message' => 'Learning suggestions must be submitted through the registry executor.',
					);
				},
				false
			),
			$this->build_module(
				'plugin.incident.report',
				'Report Aculect Plugin Incident',
				'Store a sanitized local incident report and prepare a public GitHub issue draft when this MCP server, plugin behavior, or an assistant workflow fails. The plugin does not need a GitHub token; use the returned issue draft or URL with your own GitHub/browser tools.',
				$this->object_schema(
					array(
						'title'              => array(
							'type'        => 'string',
							'description' => 'Short public incident title.',
						),
						'summary'            => array(
							'type'        => 'string',
							'description' => 'Concise public summary of what went wrong. Do not include secrets, credentials, raw OAuth tokens, raw tool arguments, or private content.',
						),
						'correlation_id'     => array(
							'type'        => 'string',
							'description' => 'Optional correlation ID from a failed tool response or activity row.',
						),
						'client_name'        => array(
							'type'        => 'string',
							'description' => 'Assistant client where the issue occurred, such as ChatGPT, Claude, Codex, Gemini, or another MCP client.',
						),
						'workflow'           => array(
							'type'        => 'string',
							'description' => 'Workflow or task being attempted, such as long-form draft creation or Rank Math SEO update.',
						),
						'tool_name'          => array(
							'type'        => 'string',
							'description' => 'MCP tool involved, if known.',
						),
						'category'           => array(
							'type'        => 'string',
							'enum'        => array( 'bug', 'compatibility', 'workflow_gap', 'documentation', 'configuration', 'client_behavior', 'usability', 'enhancement' ),
							'description' => 'Incident category. Defaults to bug.',
						),
						'severity'           => array(
							'type'        => 'string',
							'enum'        => array( 'low', 'medium', 'high', 'blocking' ),
							'description' => 'Incident severity. Defaults to medium.',
						),
						'activity_id'        => array(
							'type'        => 'integer',
							'description' => 'Optional local Activity tab row ID related to the incident.',
						),
						'steps_to_reproduce' => array(
							'type'        => 'array',
							'description' => 'Bounded public reproduction steps.',
							'items'       => array( 'type' => 'string' ),
						),
						'recovery_attempts'  => array(
							'type'        => 'array',
							'description' => 'Bounded non-sensitive recovery attempts the assistant already tried.',
							'items'       => array( 'type' => 'string' ),
						),
						'expected_behavior'  => array( 'type' => 'string' ),
						'actual_behavior'    => array( 'type' => 'string' ),
						'evidence'           => array(
							'type'        => 'string',
							'description' => 'Optional bounded, non-sensitive evidence.',
						),
					),
					array( 'title', 'summary' )
				),
				static function ( array $args ): array {
					unset( $args );

					return array(
						'status'  => 'unavailable',
						'message' => 'Plugin incident reports must be submitted through the registry executor.',
					);
				},
				false
			),
			$this->build_module(
				'plugin.incident.list',
				'List Aculect Plugin Incidents',
				'List sanitized local incident reports previously submitted by MCP clients.',
				$this->object_schema(
					array(
						'status'   => array(
							'type'        => 'string',
							'enum'        => array( 'open', 'dismissed', 'submitted' ),
							'description' => 'Optional status filter.',
						),
						'page'     => array( 'type' => 'integer' ),
						'per_page' => array( 'type' => 'integer' ),
					)
				),
				static function ( array $args ): array {
					unset( $args );

					return array(
						'status'  => 'unavailable',
						'message' => 'Plugin incidents must be listed through the registry executor.',
					);
				}
			),
		);

		$keyed = array();
		foreach ( $modules as $module ) {
			$keyed[ $module->id() ] = $module;
		}

		return $keyed;
	}

	/**
	 * Build an intelligence module.
	 *
	 * @param string               $id          Internal intelligence ID.
	 * @param string               $title       Tool title.
	 * @param string               $description Tool description.
	 * @param array<string, mixed> $schema Input schema.
	 * @param Closure              $handler     Execution callback.
	 * @param bool                 $read_only   Whether the module is read-only.
	 */
	private function build_module( string $id, string $title, string $description, array $schema, Closure $handler, bool $read_only = true ): AbilityModuleInterface {
		return new CallbackAbilityModule(
			$id,
			$title,
			$description,
			'Aculect Intelligence',
			array( 'content:read' ),
			$read_only,
			$schema,
			$handler
		);
	}

	/**
	 * Build an object schema.
	 *
	 * @param array<string, mixed> $properties Schema properties.
	 * @param array                $required   Required property names.
	 * @phpstan-param list<string> $required
	 * @return array<string, mixed>
	 */
	private function object_schema( array $properties, array $required = array() ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);

		if ( array() !== $required ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Build an empty object schema.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Build a bounded page-number schema.
	 *
	 * @return array<string, mixed>
	 */
	private function page_schema(): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'One-based page number. Defaults to 1.',
		);
	}

	/**
	 * Build a bounded per-page schema.
	 *
	 * @param int    $maximum     Maximum value accepted by the handler.
	 * @param string $description Schema description.
	 * @return array<string, mixed>
	 */
	private function per_page_schema( int $maximum, string $description ): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'maximum'     => $maximum,
			'description' => $description,
		);
	}

	/**
	 * Build a compact/full response context schema.
	 *
	 * @param string $description Schema description.
	 * @return array<string, mixed>
	 */
	private function context_schema( string $description ): array {
		return array(
			'type'        => 'string',
			'enum'        => array( 'compact', 'full' ),
			'description' => $description,
		);
	}
}

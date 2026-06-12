<?php
/**
 * Internal Aculect intelligence context for MCP clients.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Brand\BrandProfile;
use Aculect\AICompanion\Connectors\Helpers;

/**
 * Builds read-only context payloads that are always available to MCP clients.
 */
final class IntelligenceContext {

	private const CUSTOM_HTML_BLOCK_NAME = 'core/html';

	/**
	 * Return high-level site context for connected assistants.
	 *
	 * @return array<string, mixed>
	 */
	public function site(): array {
		return array(
			'type'              => 'site',
			'label'             => 'Site Intelligence',
			'description'       => 'Stable WordPress site, theme, locale, and connector context.',
			'site'              => $this->site_identity(),
			'wordpress'         => $this->wordpress_context(),
			'theme'             => $this->active_theme(),
			'connector'         => $this->connector_context(),
			'operations'        => $this->operations_manifest(),
			'guidance'          => $this->shared_generation_guidance(),
			'learning_protocol' => $this->learning_protocol(),
		);
	}

	/**
	 * Return content model context for connected assistants.
	 *
	 * @return array<string, mixed>
	 */
	public function content(): array {
		return array(
			'type'              => 'content',
			'label'             => 'Content Intelligence',
			'description'       => 'Readable content types, taxonomies, block guidance, and content-generation constraints.',
			'post_types'        => $this->post_types(),
			'taxonomies'        => $this->taxonomies(),
			'block_summary'     => $this->block_collection_summary(),
			'pattern_summary'   => $this->pattern_collection_summary(),
			'operations'        => $this->operations_manifest(),
			'guidance'          => $this->shared_generation_guidance(),
			'learning_protocol' => $this->learning_protocol(),
		);
	}

	/**
	 * Return developer-oriented site context without secrets.
	 *
	 * @return array<string, mixed>
	 */
	public function developer(): array {
		return array(
			'type'              => 'developer',
			'label'             => 'Developer Intelligence',
			'description'       => 'Safe implementation context for understanding the WordPress runtime and available extension surfaces.',
			'wordpress'         => $this->wordpress_context(),
			'theme'             => $this->active_theme(),
			'features'          => array(
				'rest_api_url'                 => $this->function_exists( 'rest_url' ) ? rest_url() : '',
				'block_registry_available'     => $this->block_registry_available(),
				'pattern_registry_available'   => $this->pattern_registry_available(),
				'wordpress_abilities_api'      => $this->function_exists( 'wp_get_abilities' ),
				'custom_html_block_disallowed' => true,
			),
			'content_contract'  => array(
				'preferred_format' => 'Serialized WordPress block markup using registered blocks and patterns.',
				'never_use'        => array( self::CUSTOM_HTML_BLOCK_NAME ),
				'validation_tool'  => ( new AbilitiesRegistry() )->tool_name( 'intelligence.content.validate_blocks' ),
			),
			'connector'         => $this->connector_context(),
			'operations'        => $this->operations_manifest(),
			'learning_protocol' => $this->learning_protocol(),
		);
	}

	/**
	 * Return brand guidance from saved profile fields and detected defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function brand(): array {
		return array(
			'type'              => 'brand',
			'label'             => 'Brand Intelligence',
			'description'       => 'Safe brand identity, visual, and editorial guidance for future assistant work.',
			'profile'           => ( new BrandProfile() )->public_profile(),
			'guidance'          => array(
				'use_saved_values_first' => true,
				'respect_empty_fields'   => 'When a field is empty, infer conservatively from the existing site instead of inventing a new brand direction.',
				'never_use'              => array( self::CUSTOM_HTML_BLOCK_NAME ),
			),
			'learning_protocol' => $this->learning_protocol(),
		);
	}

	/**
	 * Return a concise capability directory for first-session MCP discovery.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function capabilities( array $args = array() ): array {
		$detail     = 'full' === sanitize_key( (string) ( $args['detail'] ?? 'summary' ) ) ? 'full' : 'summary';
		$operations = $this->operations_manifest();
		$regular    = array(
			$this->capability_group( 'content', 'Content', $operations['content'] ?? array(), $detail ),
			$this->capability_group( 'content_groups', 'Content Groups', $operations['content_groups'] ?? array(), $detail ),
			$this->capability_group( 'media', 'Media', $operations['media'] ?? array(), $detail ),
			$this->capability_group( 'comments', 'Comments', $operations['comments'] ?? array(), $detail ),
			$this->capability_group( 'site_information', 'Site Information', $operations['site_information'] ?? array(), $detail ),
			$this->capability_group( 'wordpress_actions', 'WordPress Actions', $operations['actions'] ?? array(), $detail ),
		);
		$workflows  = $this->capability_group( 'workflows', 'Guided Workflows', $operations['workflows'] ?? array(), $detail );
		$blocked    = $this->blocked_capability_summary( $operations, $detail );

		return array(
			'type'                 => 'capability_directory',
			'label'                => 'MCP Capability Help Directory',
			'description'          => 'Safe startup summary for questions like what can you do, detect available abilities, or what workflows are possible.',
			'detail'               => $detail,
			'summary'              => array(
				'available_regular_tools' => $this->sum_counts( $regular, 'available_count' ),
				'blocked_regular_tools'   => $this->sum_counts( $regular, 'blocked_count' ),
				'available_workflows'     => (int) $workflows['available_count'],
				'blocked_workflows'       => (int) $workflows['blocked_count'],
				'intelligence_surfaces'   => count( $this->intelligence_context_tools() ) + count( $this->intelligence_knowledge_tools() ),
				'blocked_reason_counts'   => $blocked['counts_by_reason'],
			),
			'regular_abilities'    => $regular,
			'workflows'            => $workflows,
			'intelligence'         => array(
				'context_tools'   => $this->intelligence_context_tools(),
				'knowledge_tools' => $this->intelligence_knowledge_tools(),
				'index_tools'     => $this->capability_group( 'intelligence_index', 'Content Intelligence Index', $operations['intelligence_index'] ?? array(), $detail ),
				'learning'        => array(
					'feedback_tool' => ( new AbilitiesRegistry() )->tool_name( 'intelligence.feedback.submit' ),
					'write_policy'  => 'Use intelligence_feedback_submit for reviewed learning suggestions. Durable memory updates require memory_save availability, explicit write permission, and admin review/confirmation.',
				),
			),
			'blocked_capabilities' => $blocked,
			'example_prompts'      => array(
				'What can you do on this site through MCP?',
				'Which content workflows are available right now?',
				'Find relevant internal links for this draft before updating it.',
				'Prepare a long-form draft using only valid WordPress blocks.',
			),
			'next_actions'         => array(
				'For planning, call the relevant intelligence context tool before using write tools.',
				'Use available workflow tools for normal content creation and editing.',
				'If a needed capability is blocked, inspect blocked_by and reconnect or update role/global policy before retrying.',
			),
			'safety'               => array(
				'secrets_included'        => false,
				'raw_settings_included'   => false,
				'raw_content_included'    => false,
				'large_payloads_included' => false,
			),
		);
	}

	/**
	 * Return the review-first learning protocol for MCP clients.
	 *
	 * @return array<string, mixed>
	 */
	private function learning_protocol(): array {
		return array(
			'feedback_tool'         => ( new AbilitiesRegistry() )->tool_name( 'intelligence.feedback.submit' ),
			'status'                => 'suggestion_only',
			'admin_review_required' => true,
			'direct_memory_updates' => false,
			'domains'               => array( 'site', 'content', 'developer', 'brand' ),
			'instruction'           => 'If this intelligence is incomplete or causes poor results, submit a bounded learning suggestion. Suggestions are queued for admin review and never update site, content, developer, or brand memory directly.',
			'do_not_include'        => array( 'secrets', 'credentials', 'personal data', 'raw tool arguments' ),
		);
	}

	/**
	 * Return shared guidance that should apply across every intelligence domain.
	 *
	 * @return array<string, mixed>
	 */
	private function shared_generation_guidance(): array {
		return array(
			'preferred_content_format' => 'Use registered WordPress block markup and available block patterns so content remains editable.',
			'never_use'                => array( self::CUSTOM_HTML_BLOCK_NAME ),
			'custom_html_rule'         => 'Never use the Custom HTML block (core/html). Use semantic core blocks, registered site blocks, or patterns instead.',
			'fallback_rule'            => 'If a requested layout cannot be represented with registered blocks or patterns, ask for an approved block or pattern instead of adding raw HTML.',
			'permission_rule'          => 'Content changes still require the connected WordPress user to have the required permission and OAuth scope.',
		);
	}

	/**
	 * Return exact MCP operation names for assistants that receive intelligence context first.
	 *
	 * @return array<string, mixed>
	 */
	private function operations_manifest(): array {
		return ( new McpToolAvailability() )->operations_manifest_for_current_user();
	}

	/**
	 * Build a capability group summary.
	 *
	 * @param string               $id      Group ID.
	 * @param string               $label   Group label.
	 * @param array<string, mixed> $entries Operation entries.
	 * @param string               $detail  Directory detail level.
	 * @return array<string, mixed>
	 */
	private function capability_group( string $id, string $label, array $entries, string $detail ): array {
		$available = array();
		$blocked   = array();

		foreach ( $entries as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$item = $this->capability_entry( (string) $key, $entry, $detail );
			if ( true === $item['available'] ) {
				$available[] = $item;
			} else {
				$blocked[] = $item;
			}
		}

		$group = array(
			'id'              => $id,
			'label'           => $label,
			'available_count' => count( $available ),
			'blocked_count'   => count( $blocked ),
			'available_tools' => array_values( array_column( $available, 'tool' ) ),
			'blocked_tools'   => array_slice( $blocked, 0, 'full' === $detail ? count( $blocked ) : 5 ),
		);

		if ( 'full' === $detail ) {
			$group['entries'] = array_merge( $available, $blocked );
		}

		return $group;
	}

	/**
	 * Normalize one capability entry.
	 *
	 * @param string               $key    Operation key.
	 * @param array<string, mixed> $entry  Operation manifest entry.
	 * @param string               $detail Directory detail level.
	 * @return array<string, mixed>
	 */
	private function capability_entry( string $key, array $entry, string $detail ): array {
		$item = array(
			'key'        => $key,
			'tool'       => (string) ( $entry['tool'] ?? '' ),
			'available'  => true === ( $entry['available'] ?? false ),
			'read_only'  => true === ( $entry['read_only'] ?? false ),
			'blocked_by' => (string) ( $entry['blocked_by'] ?? '' ),
		);

		if ( 'full' === $detail ) {
			$item['required_scopes'] = array_values( (array) ( $entry['required_scopes'] ?? array() ) );
			$item['missing_scopes']  = array_values( (array) ( $entry['missing_scopes'] ?? array() ) );
		}

		return $item;
	}

	/**
	 * Return always-on context tools.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function intelligence_context_tools(): array {
		$registry = new AbilitiesRegistry();

		return array(
			$this->intelligence_tool( 'capabilities', 'Capability directory and help for first-time discovery.', 'intelligence.capabilities.get_directory', true, $registry ),
			$this->intelligence_tool( 'site', 'Site, theme, locale, connector, and operations context.', 'intelligence.site.get_context', true, $registry ),
			$this->intelligence_tool( 'content', 'Content types, taxonomies, block guidance, patterns, and content constraints.', 'intelligence.content.get_context', true, $registry ),
			$this->intelligence_tool( 'developer', 'Safe WordPress runtime and extension context.', 'intelligence.developer.get_context', true, $registry ),
			$this->intelligence_tool( 'brand', 'Saved and detected brand guidance.', 'intelligence.brand.get_context', true, $registry ),
		);
	}

	/**
	 * Return block, pattern, validation, and feedback tools.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function intelligence_knowledge_tools(): array {
		$registry = new AbilitiesRegistry();

		return array(
			$this->intelligence_tool( 'blocks_list', 'List registered WordPress blocks and avoid core/html.', 'intelligence.blocks.list_available', true, $registry ),
			$this->intelligence_tool( 'blocks_get', 'Inspect one registered block.', 'intelligence.blocks.get_info', true, $registry ),
			$this->intelligence_tool( 'patterns_list', 'List registered block patterns.', 'intelligence.patterns.list_available', true, $registry ),
			$this->intelligence_tool( 'patterns_get', 'Inspect one registered block pattern.', 'intelligence.patterns.get_info', true, $registry ),
			$this->intelligence_tool( 'validate_blocks', 'Validate serialized WordPress block content before writes.', 'intelligence.content.validate_blocks', true, $registry ),
			$this->intelligence_tool( 'feedback_submit', 'Queue bounded learning suggestions for admin review.', 'intelligence.feedback.submit', false, $registry ),
		);
	}

	/**
	 * Build one intelligence tool descriptor for the help directory.
	 *
	 * @param string            $key       Directory key.
	 * @param string            $purpose   Tool purpose.
	 * @param string            $id        Internal intelligence ID.
	 * @param bool              $read_only Whether the tool is read-only.
	 * @param AbilitiesRegistry $registry  Ability registry for tool-name conversion.
	 * @return array<string, mixed>
	 */
	private function intelligence_tool( string $key, string $purpose, string $id, bool $read_only, AbilitiesRegistry $registry ): array {
		return array(
			'key'       => $key,
			'tool'      => $registry->tool_name( $id ),
			'available' => true,
			'read_only' => $read_only,
			'purpose'   => $purpose,
		);
	}

	/**
	 * Build blocked capability counts and sample entries.
	 *
	 * @param array<string, mixed> $operations Operation manifest.
	 * @param string               $detail     Directory detail level.
	 * @return array<string, mixed>
	 */
	private function blocked_capability_summary( array $operations, string $detail ): array {
		$items  = array();
		$counts = array();

		foreach ( array( 'site_information', 'content', 'workflows', 'intelligence_index', 'content_groups', 'media', 'comments', 'actions' ) as $group ) {
			foreach ( (array) ( $operations[ $group ] ?? array() ) as $key => $entry ) {
				if ( ! is_array( $entry ) || true === ( $entry['available'] ?? false ) ) {
					continue;
				}

				$reason            = (string) ( $entry['blocked_by'] ?? 'unavailable' );
				$counts[ $reason ] = (int) ( $counts[ $reason ] ?? 0 ) + 1;
				$items[]           = array(
					'group'      => $group,
					'key'        => (string) $key,
					'tool'       => (string) ( $entry['tool'] ?? '' ),
					'blocked_by' => $reason,
				);
			}
		}

		return array(
			'counts_by_reason' => $counts,
			'items'            => array_slice( $items, 0, 'full' === $detail ? count( $items ) : 10 ),
		);
	}

	/**
	 * Sum one numeric key across capability groups.
	 *
	 * @param array<int, array<string, mixed>> $groups Capability groups.
	 * @param string                           $key    Numeric key.
	 */
	private function sum_counts( array $groups, string $key ): int {
		$total = 0;
		foreach ( $groups as $group ) {
			$total += (int) ( $group[ $key ] ?? 0 );
		}

		return $total;
	}

	/**
	 * Return site identity fields.
	 *
	 * @return array<string, string>
	 */
	private function site_identity(): array {
		return array(
			'name'        => $this->option_text( 'blogname' ),
			'description' => $this->option_text( 'blogdescription' ),
			'home_url'    => $this->function_exists( 'home_url' ) ? home_url( '/' ) : '',
			'site_url'    => $this->function_exists( 'site_url' ) ? site_url( '/' ) : '',
			'locale'      => $this->function_exists( 'get_locale' ) ? (string) get_locale() : '',
			'timezone'    => $this->function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : '',
		);
	}

	/**
	 * Return safe WordPress runtime context.
	 *
	 * @return array<string, mixed>
	 */
	private function wordpress_context(): array {
		return array(
			'version'          => $this->function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			'multisite'        => $this->function_exists( 'is_multisite' ) ? (bool) is_multisite() : false,
			'environment_type' => $this->function_exists( 'wp_get_environment_type' ) ? (string) wp_get_environment_type() : 'production',
			'php_version'      => PHP_VERSION,
		);
	}

	/**
	 * Return active theme metadata.
	 *
	 * @return array<string, string>
	 */
	private function active_theme(): array {
		if ( ! $this->function_exists( 'wp_get_theme' ) ) {
			return array(
				'name'       => '',
				'stylesheet' => '',
				'template'   => '',
				'version'    => '',
			);
		}

		$theme = wp_get_theme();

		return array(
			'name'       => is_object( $theme ) && method_exists( $theme, 'get' ) ? (string) $theme->get( 'Name' ) : '',
			'stylesheet' => is_object( $theme ) && method_exists( $theme, 'get_stylesheet' ) ? (string) $theme->get_stylesheet() : '',
			'template'   => is_object( $theme ) && method_exists( $theme, 'get_template' ) ? (string) $theme->get_template() : '',
			'version'    => is_object( $theme ) && method_exists( $theme, 'get' ) ? (string) $theme->get( 'Version' ) : '',
		);
	}

	/**
	 * Return safe connector metadata.
	 *
	 * @return array<string, mixed>
	 */
	private function connector_context(): array {
		return array(
			'name'                    => 'Aculect AI Companion',
			'version'                 => defined( 'ACULECT_AI_COMPANION_VERSION' ) ? ACULECT_AI_COMPANION_VERSION : '',
			'mcp_endpoint'            => Helpers::mcp_resource(),
			'transport'               => 'streamable-http',
			'auth'                    => 'oauth2.1',
			'managed_as_user_ability' => false,
		);
	}

	/**
	 * Return supported post type metadata when WordPress APIs are available.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function post_types(): array {
		if ( ! $this->function_exists( 'get_post_types' ) ) {
			return array();
		}

		return ( new ContentAbilities() )->list_post_types();
	}

	/**
	 * Return supported taxonomy metadata when WordPress APIs are available.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function taxonomies(): array {
		if ( ! $this->function_exists( 'get_taxonomies' ) ) {
			return array();
		}

		return ( new TaxonomyAbilities() )->list_taxonomies();
	}

	/**
	 * Return a bounded block collection summary.
	 *
	 * @return array<string, mixed>
	 */
	private function block_collection_summary(): array {
		if ( ! $this->block_registry_available() ) {
			return array(
				'total'     => 0,
				'available' => false,
				'items'     => array(),
			);
		}

		$result = ( new BlockKnowledgeAbilities() )->list_blocks(
			array(
				'context'  => 'compact',
				'per_page' => 10,
			)
		);

		return array(
			'total'     => (int) ( $result['total'] ?? 0 ),
			'available' => true,
			'items'     => (array) ( $result['items'] ?? array() ),
		);
	}

	/**
	 * Return a bounded pattern collection summary.
	 *
	 * @return array<string, mixed>
	 */
	private function pattern_collection_summary(): array {
		if ( ! $this->pattern_registry_available() ) {
			return array(
				'total'     => 0,
				'available' => false,
				'items'     => array(),
			);
		}

		$result = ( new BlockKnowledgeAbilities() )->list_patterns(
			array(
				'context'  => 'compact',
				'per_page' => 10,
			)
		);

		return array(
			'total'     => (int) ( $result['total'] ?? 0 ),
			'available' => true,
			'items'     => (array) ( $result['items'] ?? array() ),
		);
	}

	/**
	 * Check whether the WordPress block registry is available.
	 */
	private function block_registry_available(): bool {
		return class_exists( '\WP_Block_Type_Registry' )
			&& method_exists( '\WP_Block_Type_Registry', 'get_instance' )
			&& method_exists( \WP_Block_Type_Registry::get_instance(), 'get_all_registered' );
	}

	/**
	 * Check whether the WordPress pattern registry is available.
	 */
	private function pattern_registry_available(): bool {
		return class_exists( '\WP_Block_Patterns_Registry' )
			&& method_exists( '\WP_Block_Patterns_Registry', 'get_instance' )
			&& method_exists( \WP_Block_Patterns_Registry::get_instance(), 'get_all_registered' );
	}

	/**
	 * Return a sanitized text option.
	 *
	 * @param string $option Option name.
	 */
	private function option_text( string $option ): string {
		if ( ! $this->function_exists( 'get_option' ) ) {
			return '';
		}

		$value = get_option( $option, '' );

		if ( $this->function_exists( 'sanitize_text_field' ) ) {
			return sanitize_text_field( (string) $value );
		}

		if ( $this->function_exists( 'wp_strip_all_tags' ) ) {
			return trim( (string) wp_strip_all_tags( (string) $value ) );
		}

		return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $value ) );
	}

	/**
	 * Check whether a global WordPress function exists.
	 *
	 * @param string $function Function name.
	 */
	private function function_exists( string $function ): bool {
		return function_exists( $function );
	}
}

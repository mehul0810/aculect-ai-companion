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

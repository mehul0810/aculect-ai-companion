<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Registers first-party intelligence with the WordPress Abilities API.
 */
final class WordPressAbilitiesRegistrar {

	private const CATEGORY              = 'aculect-intelligence';
	private const NAMESPACE             = 'aculect-ai-companion';
	private const FIRST_PARTY_WRITE_IDS = array(
		'plugin.incident.report',
	);

	/**
	 * Cached first-party WordPress Ability names.
	 *
	 * @var list<string>|null
	 */
	private ?array $ability_names = null;

	/**
	 * Attach WordPress Abilities API hooks when the API is present.
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Register the Aculect Intelligence category.
	 */
	public function register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		call_user_func(
			'wp_register_ability_category',
			self::CATEGORY,
			array(
				'label'       => __( 'Aculect Intelligence', 'aculect-ai-companion' ),
				'description' => __( 'Site, admin menu, Site Editor, content, brand, block, pattern, search, memory, and incident reporting intelligence exposed by Aculect AI Companion.', 'aculect-ai-companion' ),
			)
		);
	}

	/**
	 * Register Aculect Intelligence abilities.
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $this->first_party_modules() as $module ) {
			call_user_func( 'wp_register_ability', $this->ability_name( $module ), $this->ability_args( $module ) );
		}
	}

	/**
	 * Return first-party WordPress Ability names registered by this class.
	 *
	 * @return list<string>
	 */
	public function ability_names(): array {
		if ( null === $this->ability_names ) {
			$this->ability_names = array_values( $this->module_ability_names() );
		}

		return $this->ability_names;
	}

	/**
	 * Return first-party MCP module IDs mapped to WordPress Ability names.
	 *
	 * @return array<string, string>
	 */
	public function module_ability_names(): array {
		return array_map(
			fn( AbilityModuleInterface $module ): string => $this->ability_name( $module ),
			$this->first_party_modules()
		);
	}

	/**
	 * Check whether an ability name belongs to first-party intelligence.
	 *
	 * @param string $name WordPress Ability name.
	 */
	public function is_first_party_intelligence( string $name ): bool {
		return in_array( sanitize_text_field( $name ), $this->ability_names(), true );
	}

	/**
	 * Check whether an ability name belongs to first-party read intelligence.
	 *
	 * @param string $name WordPress Ability name.
	 */
	public function is_first_party_read_intelligence( string $name ): bool {
		$name    = sanitize_text_field( $name );
		$modules = array_flip( $this->module_ability_names() );
		$module  = $modules[ $name ] ?? '';

		return '' !== $module && ! in_array( $module, self::FIRST_PARTY_WRITE_IDS, true );
	}

	/**
	 * Return the public WordPress Ability name for a first-party module ID or alias.
	 *
	 * @param string $id Internal module ID, MCP tool name, legacy alias, or public ability name.
	 */
	public function ability_name_for_id( string $id ): string {
		$id = sanitize_text_field( $id );
		if ( str_starts_with( $id, self::NAMESPACE . '/' ) ) {
			return $id;
		}

		$module = ( new IntelligenceRegistry() )->module( $id );
		if ( null === $module ) {
			return '';
		}

		$names = $this->module_ability_names();
		return $names[ $module->id() ] ?? '';
	}

	/**
	 * Return intelligence modules that should be mirrored to WordPress Abilities.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	private function first_party_modules(): array {
		$modules = array();

		foreach ( ( new IntelligenceRegistry() )->modules() as $module ) {
			if ( $module->is_read_only() || in_array( $module->id(), self::FIRST_PARTY_WRITE_IDS, true ) ) {
				$modules[ $module->id() ] = $module;
			}
		}

		foreach ( ( new AbilitiesRegistry() )->always_on_read_intelligence_modules() as $module ) {
			if ( $module->is_read_only() ) {
				$modules[ $module->id() ] = $module;
			}
		}

		return $modules;
	}

	/**
	 * Build a WordPress Ability name from an internal module ID.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 */
	private function ability_name( AbilityModuleInterface $module ): string {
		$name = strtolower( preg_replace( '/[^a-zA-Z0-9-]+/', '-', str_replace( '_', '-', $module->id() ) ) ?? '' );
		$name = trim( $name, '-' );

		return self::NAMESPACE . '/' . ( '' === $name ? 'intelligence' : $name );
	}

	/**
	 * Build registration arguments for one ability module.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 * @return array<string, mixed>
	 */
	private function ability_args( AbilityModuleInterface $module ): array {
		return array(
			'label'               => $module->title(),
			'description'         => $module->description(),
			'category'            => self::CATEGORY,
			'input_schema'        => $module->input_schema(),
			'output_schema'       => $this->output_schema_for_module( $module ),
			'execute_callback'    => fn( mixed $input = array() ): array => ( new IntelligenceRegistry() )->execute( $module->id(), is_array( $input ) ? $input : array() ),
			'permission_callback' => $this->permission_callback_for_module( $module ),
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => $module->is_read_only(),
					'destructive' => false,
					'idempotent'  => $module->is_read_only(),
				),
				'mcp'          => array(
					'public' => true,
					'tool'   => ( new AbilitiesRegistry() )->tool_name( $module->id() ),
				),
			),
		);
	}

	/**
	 * Return the WordPress Abilities permission callback for a module.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 * @return callable
	 */
	private function permission_callback_for_module( AbilityModuleInterface $module ): callable {
		$id = $module->id();

		return static function ( mixed $input = null ) use ( $id ): bool {
				unset( $input );

			if ( str_starts_with( $id, 'site_editor.' ) ) {
				return current_user_can( 'edit_theme_options' );
			}

			if ( str_starts_with( $id, 'admin_menu.' ) ) {
				return current_user_can( 'manage_options' );
			}

			return current_user_can( 'read' );
		};
	}

	/**
	 * Return a top-level output schema matching the existing MCP result shapes.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 * @return array<string, mixed>
	 */
	private function output_schema_for_module( AbilityModuleInterface $module ): array {
		if ( 'plugin.incident.list' === $module->id() ) {
			return $this->object_output_schema(
				array(
					'items'    => array( 'type' => 'array' ),
					'total'    => array( 'type' => 'integer' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
					'summary'  => array( 'type' => 'object' ),
					'error'    => array( 'type' => 'string' ),
					'message'  => array( 'type' => 'string' ),
				)
			);
		}

		if ( $this->is_collection_module( $module ) ) {
			return $this->object_output_schema(
				array(
					'items'              => array( 'type' => 'array' ),
					'total'              => array( 'type' => 'integer' ),
					'visible_total'      => array( 'type' => 'integer' ),
					'page'               => array( 'type' => 'integer' ),
					'per_page'           => array( 'type' => 'integer' ),
					'context'            => array( 'type' => 'string' ),
					'index'              => array( 'type' => 'object' ),
					'filtered_by_access' => array( 'type' => 'boolean' ),
					'total_is_estimated' => array( 'type' => 'boolean' ),
					'degraded'           => array( 'type' => 'boolean' ),
					'degraded_reason'    => array( 'type' => 'string' ),
					'quality_summary'    => array( 'type' => 'object' ),
					'error'              => array( 'type' => 'string' ),
					'message'            => array( 'type' => 'string' ),
				)
			);
		}

		if ( 'intelligence.content.validate_blocks' === $module->id() ) {
			return $this->object_output_schema(
				array(
					'valid'          => array( 'type' => 'boolean' ),
					'errors'         => array( 'type' => 'array' ),
					'warnings'       => array( 'type' => 'array' ),
					'blocks'         => array( 'type' => 'array' ),
					'message'        => array( 'type' => 'string' ),
					'uses_core_html' => array( 'type' => 'boolean' ),
				)
			);
		}

		if ( 'content_batch.status' === $module->id() ) {
			return $this->object_output_schema(
				array(
					'status'  => array( 'type' => 'string' ),
					'error'   => array( 'type' => 'string' ),
					'message' => array( 'type' => 'string' ),
					'job'     => array( 'type' => 'object' ),
					'items'   => array( 'type' => 'array' ),
				)
			);
		}

		if ( 'plugin.incident.report' === $module->id() ) {
			return $this->object_output_schema(
				array(
					'status'            => array( 'type' => 'string' ),
					'message'           => array( 'type' => 'string' ),
					'error'             => array( 'type' => 'string' ),
					'report_id'         => array( 'type' => 'string' ),
					'correlation_id'    => array( 'type' => 'string' ),
					'repository'        => array( 'type' => 'string' ),
					'title'             => array( 'type' => 'string' ),
					'body'              => array( 'type' => 'string' ),
					'issue_url'         => array( 'type' => 'string' ),
					'can_create_direct' => array( 'type' => 'boolean' ),
					'incident'          => array( 'type' => 'object' ),
					'next_actions'      => array( 'type' => 'array' ),
				)
			);
		}

		return $this->object_output_schema(
			array(
				'type'                 => array( 'type' => 'string' ),
				'label'                => array( 'type' => 'string' ),
				'description'          => array( 'type' => 'string' ),
				'operations'           => array( 'type' => 'object' ),
				'regular_abilities'    => array( 'type' => 'array' ),
				'workflows'            => array( 'type' => 'object' ),
				'intelligence'         => array( 'type' => 'object' ),
				'blocked_capabilities' => array( 'type' => 'object' ),
				'example_prompts'      => array( 'type' => 'array' ),
				'next_actions'         => array( 'type' => 'array' ),
				'guidance'             => array( 'type' => 'object' ),
				'learning_protocol'    => array( 'type' => 'object' ),
				'items'                => array( 'type' => 'array' ),
				'summary'              => array( 'type' => 'object' ),
				'error'                => array( 'type' => 'string' ),
				'message'              => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Check whether the module returns a collection-like payload.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 */
	private function is_collection_module( AbilityModuleInterface $module ): bool {
		return in_array(
			$module->id(),
			array(
				'intelligence.blocks.list_available',
				'intelligence.patterns.list_available',
				'content_search.items',
				'content_search.chunks',
				'content_find.related',
				'content_internal_link.policy',
				'content_find.internal_links',
				'content_audit.internal_links',
				'memory.list',
			),
			true
		);
	}

	/**
	 * Build a client-safe object output schema.
	 *
	 * @param array<string, mixed> $properties Schema properties.
	 * @return array<string, mixed>
	 */
	private function object_output_schema( array $properties ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => true,
		);
	}
}

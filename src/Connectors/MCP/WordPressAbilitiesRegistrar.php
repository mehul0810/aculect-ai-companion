<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Registers first-party read-only intelligence with the WordPress Abilities API.
 */
final class WordPressAbilitiesRegistrar {

	private const CATEGORY  = 'aculect-intelligence';
	private const NAMESPACE = 'aculect-ai-companion';

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
				'description' => __( 'Read-only site, content, brand, block, pattern, search, and memory intelligence exposed by Aculect AI Companion.', 'aculect-ai-companion' ),
			)
		);
	}

	/**
	 * Register read-only Aculect Intelligence abilities.
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $this->read_only_modules() as $module ) {
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
			$this->ability_names = array_values(
				array_map(
					fn( AbilityModuleInterface $module ): string => $this->ability_name( $module ),
					$this->read_only_modules()
				)
			);
		}

		return $this->ability_names;
	}

	/**
	 * Check whether an ability name belongs to first-party read intelligence.
	 *
	 * @param string $name WordPress Ability name.
	 */
	public function is_first_party_read_intelligence( string $name ): bool {
		return in_array( sanitize_text_field( $name ), $this->ability_names(), true );
	}

	/**
	 * Return read-only intelligence modules that should be mirrored to WordPress Abilities.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	private function read_only_modules(): array {
		$modules = array();

		foreach ( ( new IntelligenceRegistry() )->modules() as $module ) {
			if ( $module->is_read_only() ) {
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
			'execute_callback'    => fn( mixed $input = array() ): array => $module->execute( is_array( $input ) ? $input : array() ),
			'permission_callback' => static function ( mixed $input = null ): bool {
				unset( $input );

				return current_user_can( 'read' );
			},
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				'mcp'          => array(
					'public' => true,
					'tool'   => ( new AbilitiesRegistry() )->tool_name( $module->id() ),
				),
			),
		);
	}

	/**
	 * Return a top-level output schema matching the existing MCP result shapes.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 * @return array<string, mixed>
	 */
	private function output_schema_for_module( AbilityModuleInterface $module ): array {
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
				'content_find.internal_links',
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

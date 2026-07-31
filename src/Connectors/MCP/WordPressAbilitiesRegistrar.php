<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Registers first-party intelligence with the WordPress Abilities API.
 */
final class WordPressAbilitiesRegistrar {

	private const CATEGORY     = 'aculect-intelligence';
	private const NAMESPACE    = 'aculect-ai-companion';
	private const MCP_ONLY_IDS = array(
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
				'description' => __( 'Site, admin menu, Site Editor, content, brand, block, pattern, search, memory, and incident history intelligence exposed by Aculect AI Companion.', 'aculect-ai-companion' ),
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

		foreach ( $this->read_only_module_registrations() as $registration ) {
			call_user_func(
				'wp_register_ability',
				$this->ability_name( $registration['module'] ),
				$this->ability_args( $registration['module'], $registration['execute'] )
			);
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
			$this->read_only_modules()
		);
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
	 * Check whether an ID must execute only through the MCP safety layer.
	 *
	 * @param string $id Module ID, MCP tool name, legacy alias, or public ability name.
	 */
	public function is_mcp_only_intelligence( string $id ): bool {
		$id       = sanitize_text_field( $id );
		$registry = new IntelligenceRegistry();
		$module   = $registry->module( $id );

		if ( null !== $module && in_array( $module->id(), self::MCP_ONLY_IDS, true ) ) {
			return true;
		}

		foreach ( self::MCP_ONLY_IDS as $module_id ) {
			$module = $registry->module( $module_id );
			if ( null !== $module && hash_equals( $this->ability_name( $module ), $id ) ) {
				return true;
			}
		}

		return false;
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
	private function read_only_modules(): array {
		return array_map(
			static fn( array $registration ): AbilityModuleInterface => $registration['module'],
			$this->read_only_module_registrations()
		);
	}

	/**
	 * Return read-only modules together with their owning registry executor.
	 *
	 * Registry ownership is part of the execution contract. Keeping the
	 * executor beside the module prevents WordPress Abilities from routing a
	 * module through a different registry that happens to use the same module
	 * interface.
	 *
	 * @return array<string, array{module: AbilityModuleInterface, execute: callable(array<string, mixed>): array<string, mixed>}>
	 * @throws \LogicException When two internal registries claim the same module ID.
	 */
	private function read_only_module_registrations(): array {
		$registrations = array();
		$intelligence  = new IntelligenceRegistry();

		foreach ( $intelligence->modules() as $module ) {
			if ( $module->is_read_only() ) {
				$registrations[ $module->id() ] = array(
					'module'  => $module,
					'execute' => static fn( array $input ): array => $intelligence->execute( $module->id(), $input ),
				);
			}
		}

		$abilities = new AbilitiesRegistry();
		foreach ( $abilities->always_on_read_intelligence_modules() as $module ) {
			if ( $module->is_read_only() ) {
				if ( isset( $registrations[ $module->id() ] ) ) {
					throw new \LogicException( sprintf( 'Duplicate read-only ability module ID: %s', esc_html( $module->id() ) ) );
				}

				$registrations[ $module->id() ] = array(
					'module'  => $module,
					'execute' => static fn( array $input ): array => $abilities->execute( $module->id(), $input ),
				);
			}
		}

		return $registrations;
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
	 * @param callable               $execute Owning registry executor.
	 * @phpstan-param callable(array<string, mixed>): array<string, mixed> $execute
	 * @return array<string, mixed>
	 */
	private function ability_args( AbilityModuleInterface $module, callable $execute ): array {
		return array(
			'label'               => $module->title(),
			'description'         => $module->description(),
			'category'            => self::CATEGORY,
			'input_schema'        => $module->input_schema(),
			'output_schema'       => $this->output_schema_for_module( $module ),
			'execute_callback'    => static fn( mixed $input = array() ): array => $execute( is_array( $input ) ? $input : array() ),
			'permission_callback' => $this->permission_callback_for_module( $module ),
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

			if ( 'plugin.incident.list' === $id || str_starts_with( $id, 'admin_menu.' ) ) {
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

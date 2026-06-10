<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Registry of Aculect AI Companion abilities exposed through MCP tools.
 *
 * Internal IDs intentionally use dotted namespaces for maintainability. Public
 * MCP tool names are generated separately because clients such as Claude reject
 * tool names that do not match a stricter identifier pattern.
 */
final class AbilitiesRegistry {

	public const OPTION_ENABLED_ABILITIES = 'aculect_ai_companion_enabled_abilities';
	private const TOOL_NAME_PATTERN       = '/^[a-zA-Z0-9_-]{1,64}$/';

	/**
	 * Process-wide cached ability modules.
	 *
	 * The registry is instantiated many times within one MCP request; module
	 * construction is pure and definition data is immutable per request, so
	 * one shared map avoids rebuilding ~45 module objects per instantiation.
	 *
	 * @var array<string, AbilityModuleInterface>|null
	 */
	private static ?array $shared_modules = null;

	/**
	 * Return all abilities Aculect AI Companion can expose to assistant clients.
	 *
	 * @return array<string, array<string, bool|string>>
	 */
	public function definitions(): array {
		return array_map( array( $this, 'definition_from_module' ), $this->modules() );
	}

	/**
	 * Return ability definitions formatted for the admin UI.
	 *
	 * @return list<array<string, bool|string>>
	 */
	public function public_definitions(): array {
		$enabled = $this->enabled_ids();
		return array_map(
			function ( array $definition ) use ( $enabled ): array {
				$read_only                 = (bool) $definition['readOnly'];
				$definition['enabled']     = in_array( (string) $definition['id'], $enabled, true );
				$definition['toolName']    = $this->tool_name( (string) $definition['id'] );
				$definition['changesSite'] = ! $read_only;
				$definition['riskLevel']   = $read_only ? 'read-only' : 'write';
				return $definition;
			},
			array_values( $this->definitions() )
		);
	}

	/**
	 * Return only definitions enabled for MCP exposure.
	 *
	 * @return array<string, array<string, bool|string>>
	 */
	public function enabled_definitions(): array {
		$enabled     = $this->enabled_ids();
		$definitions = $this->definitions();
		return array_intersect_key( $definitions, array_flip( $enabled ) );
	}

	/**
	 * Return all registered ability modules.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	public function modules(): array {
		if ( null === self::$shared_modules ) {
			self::$shared_modules = ( new FirstPartyAbilityModules() )->all();
		}

		return self::$shared_modules;
	}

	/**
	 * Reset the shared module cache (used by tests).
	 */
	public static function reset_module_cache(): void {
		self::$shared_modules = null;
	}

	/**
	 * Return only modules enabled for MCP exposure.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	public function enabled_modules(): array {
		$enabled = $this->enabled_ids();

		return array_intersect_key( $this->modules(), array_flip( $enabled ) );
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
	 * Return the input schema for an ability.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 * @return array<string, mixed>
	 */
	public function input_schema( string $id ): array {
		$module = $this->module( $id );

		return null === $module ? array(
			'type'       => 'object',
			'properties' => new \stdClass(),
		) : $module->input_schema();
	}

	/**
	 * Execute a registered ability module.
	 *
	 * @param string               $id   Internal ID, legacy alias, or public tool name.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function execute( string $id, array $args ): array {
		$module = $this->module( $id );

		return null === $module ? array( 'error' => 'Unknown tool' ) : $module->execute( $args );
	}

	/**
	 * Return enabled internal ability IDs.
	 *
	 * @return list<string>
	 */
	public function enabled_ids(): array {
		$stored = get_option( self::OPTION_ENABLED_ABILITIES, null );
		if ( ! is_array( $stored ) ) {
			return array_keys( $this->definitions() );
		}

		return $this->sanitize_ids( $stored );
	}

	/**
	 * Persist enabled ability IDs after normalizing aliases and public names.
	 *
	 * @param array $ids Ability IDs or public tool names.
	 */
	public function save_enabled_ids( array $ids ): void {
		update_option( self::OPTION_ENABLED_ABILITIES, $this->sanitize_ids( $ids ), false );
	}

	/**
	 * Check whether an ability exists.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function is_known( string $id ): bool {
		return array_key_exists( $this->internal_id( $id ), $this->definitions() );
	}

	/**
	 * Check whether an ability is enabled.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function is_enabled( string $id ): bool {
		return in_array( $this->internal_id( $id ), $this->enabled_ids(), true );
	}

	/**
	 * Return OAuth scopes required for an ability.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 * @return list<string>
	 */
	public function required_scopes( string $id ): array {
		$module = $this->module( $id );

		return null === $module ? array( 'content:read' ) : $module->required_scopes();
	}

	/**
	 * Check whether an ability only reads site data.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function is_read_only( string $id ): bool {
		$module = $this->module( $id );

		return null === $module || $module->is_read_only();
	}

	/**
	 * Return operational ability IDs that must also be available before a workflow can run.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 * @return list<string>
	 */
	public function dependency_ids( string $id ): array {
		return match ( $this->internal_id( $id ) ) {
			'content_workflow.prepare_post' => array( 'site.list_post_types' ),
			'content_workflow.create_draft' => array( 'content.create_item' ),
			'content_workflow.update_post' => array( 'content.update_item' ),
			'seo_workflow.update_rankmath' => array( 'content.update_seo' ),
			default => array(),
		};
	}

	/**
	 * Convert a public tool name or legacy alias back to the internal ID.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function internal_id( string $id ): string {
		$id = $this->normalize_alias( $id );
		if ( array_key_exists( $id, $this->definitions() ) ) {
			return $id;
		}

		foreach ( array_keys( $this->definitions() ) as $definition_id ) {
			if ( hash_equals( $this->tool_name( (string) $definition_id ), $id ) ) {
				return (string) $definition_id;
			}
		}

		return $id;
	}

	/**
	 * Build a client-safe MCP tool name for an internal ability ID.
	 *
	 * @param string $id Internal ID or legacy alias.
	 */
	public function tool_name( string $id ): string {
		$id        = $this->normalize_alias( $id );
		$tool_name = preg_replace( '/[^a-zA-Z0-9_-]+/', '_', $id );
		$tool_name = trim( (string) $tool_name, '_-' );

		if ( '' === $tool_name ) {
			$tool_name = 'aculect_ai_companion_tool';
		}

		$tool_name = substr( $tool_name, 0, 64 );

		return $this->is_valid_tool_name( $tool_name ) ? $tool_name : 'aculect_ai_companion_tool';
	}

	/**
	 * Validate an MCP tool name against the stricter client-safe pattern.
	 *
	 * @param string $name Public MCP tool name.
	 */
	public function is_valid_tool_name( string $name ): bool {
		return 1 === preg_match( self::TOOL_NAME_PATTERN, $name );
	}

	/**
	 * Normalize deprecated ability names to the current internal ID.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function normalize_alias( string $id ): string {
		$aliases = array(
			'content.create_draft' => 'content.create_item',
			'content_create_draft' => 'content.create_item',
		);

		return $aliases[ $id ] ?? $id;
	}

	/**
	 * Sanitize persisted enabled IDs and drop unknown values.
	 *
	 * @param array $ids Ability IDs or public tool names.
	 * @return list<string>
	 */
	private function sanitize_ids( array $ids ): array {
		$definitions = $this->definitions();
		$sanitized   = array();

		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$id = preg_replace( '/[^a-zA-Z0-9:_\-.]/', '', (string) $id );
			$id = $this->internal_id( (string) $id );
			if ( array_key_exists( $id, $definitions ) ) {
				$sanitized[] = $id;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Convert an ability module into the admin-compatible definition shape.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 * @return array<string, bool|string>
	 */
	private function definition_from_module( AbilityModuleInterface $module ): array {
		$scopes = $module->required_scopes();

		return array(
			'id'          => $module->id(),
			'title'       => $module->title(),
			'description' => $module->description(),
			'group'       => $module->group(),
			'scope'       => (string) ( $scopes[0] ?? 'content:read' ),
			'readOnly'    => $module->is_read_only(),
		);
	}
}

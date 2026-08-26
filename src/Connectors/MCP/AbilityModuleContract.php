<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use LogicException;

/**
 * Validates the shared metadata contract for MCP and native WordPress abilities.
 *
 * This is intentionally a small boundary check. Domain providers own the
 * behavior; the contract prevents a module from being registered without the
 * identifiers, scope and closed input schema that every adapter requires.
 */
final class AbilityModuleContract {

	/**
	 * Validate an ordered module map once at registry construction time.
	 *
	 * @param array<string, AbilityModuleInterface> $modules Modules keyed by ID.
	 * @throws LogicException When a module violates the shared contract.
	 */
	public static function validate( array $modules ): void {
		$tool_names = array();

		foreach ( $modules as $key => $module ) {
			if ( ! $module instanceof AbilityModuleInterface ) {
				throw new LogicException( 'Ability registry entries must implement AbilityModuleInterface.' );
			}

			$id = $module->id();
			if ( (string) $key !== $id || 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $id ) ) {
				throw new LogicException( 'Ability module ID is invalid.' );
			}

			if ( '' === trim( $module->title() ) || '' === trim( $module->description() ) || '' === trim( $module->group() ) ) {
				throw new LogicException( 'Ability module must define title, description and group.' );
			}

			$scopes = $module->required_scopes();
			if ( array() === $scopes || array_filter( $scopes, static fn ( mixed $scope ): bool => ! is_string( $scope ) || '' === trim( $scope ) ) ) {
				throw new LogicException( 'Ability module must define at least one OAuth scope.' );
			}

			$schema = $module->input_schema();
			if ( 'object' !== ( $schema['type'] ?? null ) || ! array_key_exists( 'properties', $schema ) || ( $schema['additionalProperties'] ?? true ) !== false ) {
				throw new LogicException( 'Ability module must expose a closed object input schema.' );
			}

			$tool_name = self::tool_name( $id );
			if ( isset( $tool_names[ $tool_name ] ) ) {
				throw new LogicException( 'Ability modules must not share an MCP tool name.' );
			}

			$tool_names[ $tool_name ] = $id;
		}
	}

	/**
	 * Build the client-safe MCP name used for collision checks.
	 *
	 * @param string $id Internal ability ID.
	 */
	private static function tool_name( string $id ): string {
		$name = preg_replace( '/[^a-zA-Z0-9_-]+/', '_', $id );
		$name = trim( substr( (string) $name, 0, 64 ), '_-' );

		return '' === $name ? 'aculect_ai_companion_tool' : $name;
	}
}

<?php
/**
 * Admin-facing catalog of MCP surfaces.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use RuntimeException;

/**
 * Composes display metadata without affecting MCP availability.
 */
final class McpSurfaceCatalog {

	/**
	 * Return every registered first-party surface for the admin UI.
	 *
	 * @return list<array<string, bool|string|list<string>>>
	 */
	public function public_definitions(): array {
		$abilities    = new AbilitiesRegistry();
		$intelligence = new IntelligenceRegistry();
		$definitions  = array();
		$tool_names   = array();

		foreach ( $abilities->modules() as $module ) {
			$id = $module->id();
			$this->add_definition(
				$definitions,
				$tool_names,
				$this->definition(
					$module,
					$abilities->tool_name( $id ),
					$abilities->is_derived_workflow( $id ) ? 'workflow' : 'ability',
					$abilities->is_configurable( $id ),
					$abilities->is_core_default( $id ),
					$abilities->is_enabled( $id )
				)
			);
		}

		foreach ( $intelligence->modules() as $module ) {
			$this->add_definition(
				$definitions,
				$tool_names,
				$this->definition( $module, $intelligence->tool_name( $module->id() ), 'intelligence', false, false, true )
			);
		}

		usort(
			$definitions,
			static fn ( array $first, array $second ): int => strcmp( (string) $first['id'], (string) $second['id'] )
		);

		return $definitions;
	}

	/**
	 * Build one display-only definition.
	 *
	 * @param AbilityModuleInterface $module       Registered surface module.
	 * @param string                 $tool_name    Public MCP tool name.
	 * @param string                 $surface_type Catalog surface type.
	 * @param bool                   $configurable Whether admins can toggle the surface.
	 * @param bool                   $core_default Whether the surface is a core default.
	 * @param bool                   $enabled      Current global policy state.
	 * @return array<string, bool|string|list<string>>
	 */
	private function definition( AbilityModuleInterface $module, string $tool_name, string $surface_type, bool $configurable, bool $core_default, bool $enabled ): array {
		$scopes = $module->required_scopes();

		return array(
			'id'             => $module->id(),
			'title'          => $module->title(),
			'description'    => $module->description(),
			'group'          => $module->group(),
			'scope'          => (string) ( $scopes[0] ?? 'content:read' ),
			'requiredScopes' => array_values( $scopes ),
			'readOnly'       => $module->is_read_only(),
			'changesSite'    => ! $module->is_read_only(),
			'toolName'       => $tool_name,
			'surfaceType'    => $surface_type,
			'configurable'   => $configurable,
			'coreDefault'    => $core_default,
			'enabled'        => $enabled,
			'policyState'    => $this->policy_state( $surface_type, $configurable, $core_default, $enabled ),
		);
	}

	/**
	 * Add one unique definition.
	 *
	 * @param array<string, array<string, bool|string|list<string>>> $definitions Definitions keyed by ID.
	 * @param array<string, string>                                  $tool_names  IDs keyed by tool name.
	 * @param array<string, bool|string|list<string>>                $definition  Definition to add.
	 * @throws RuntimeException When an ID or public tool name is duplicated.
	 */
	private function add_definition( array &$definitions, array &$tool_names, array $definition ): void {
		if ( ! is_string( $definition['id'] ) || ! is_string( $definition['toolName'] ) ) {
			throw new RuntimeException( 'Invalid MCP surface ID or tool name.' );
		}

		$id        = $definition['id'];
		$tool_name = $definition['toolName'];

		if ( isset( $definitions[ $id ] ) || isset( $tool_names[ $tool_name ] ) ) {
			throw new RuntimeException( 'Duplicate MCP surface ID or tool name.' );
		}

		$definitions[ $id ]       = $definition;
		$tool_names[ $tool_name ] = $id;
	}

	/**
	 * Describe policy state without making an authorization decision.
	 *
	 * @param string $surface_type Catalog surface type.
	 * @param bool   $configurable Whether admins can toggle the surface.
	 * @param bool   $core_default Whether the surface is a core default.
	 * @param bool   $enabled      Current global policy state.
	 */
	private function policy_state( string $surface_type, bool $configurable, bool $core_default, bool $enabled ): string {
		if ( 'intelligence' === $surface_type ) {
			return 'context';
		}
		if ( 'workflow' === $surface_type ) {
			return 'composed';
		}
		if ( $core_default ) {
			return 'core-default';
		}
		if ( ! $configurable ) {
			return 'policy-managed';
		}

		return $enabled ? 'enabled' : 'disabled';
	}
}

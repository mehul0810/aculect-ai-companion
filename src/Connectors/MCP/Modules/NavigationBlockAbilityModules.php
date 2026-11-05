<?php
/**
 * Closed contracts for targeted database navigation item editing.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\NavigationBlockItemAbilities;

final class NavigationBlockAbilityModules {

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Declare explicit block item operations.
	 *
	 * @return array<string,AbilityModuleInterface>
	 */
	public function all(): array {
		$modules = array(
			$this->factory->create( 'navigation.read_block_item', 'Read Editable Block Navigation Item', 'Read an existing native navigation link or submenu by navigation_id and block_path from navigation.list_items. Works with classic, hybrid and block themes when a readable/editable wp_navigation record exists. Returns whole-record expected_state; no theme files or inline template navigation.', 'Navigation Intelligence', 'content:read', true, $this->schema( false ), static fn ( array $args ): array => ( new NavigationBlockItemAbilities() )->read_item( $args ) ),
			$this->factory->create( 'navigation.update_block_item', 'Update Block Navigation Item', 'Preview or update one existing wp_navigation link/submenu label, custom-link URL, target, rel, parent_path or order. Supply expected_state from navigation.read_block_item; explicit confirmation and a native recovery revision are required even for trusted connections. Preserves other supported blocks and attributes; refuses unsupported content. Does not create items, change template references or assign classic locations.', 'Navigation Intelligence', 'content:draft', false, $this->schema( true ), static fn ( array $args ): array => ( new NavigationBlockItemAbilities() )->update_item( $args ) ),
		);
		$result  = array();
		foreach ( $modules as $module ) {
			$result[ $module->id() ] = $module;
		}
		return $result;
	}

	/**
	 * Use structural paths from the full parsed record, not flattened list order.
	 *
	 * @param bool $write Include changes and optimistic state.
	 * @return array<string,mixed>
	 */
	private function schema( bool $write ): array {
		$properties = array(
			'navigation_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'block_path'    => array(
				'type'      => 'string',
				'pattern'   => '^(0|[1-9][0-9]{0,2})(\\.(0|[1-9][0-9]{0,2})){0,19}$',
				'maxLength' => 79,
			),
		);
		$required   = array( 'navigation_id', 'block_path' );
		if ( $write ) {
			$properties['expected_state'] = array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{64}$',
			);
			$properties['changes']        = $this->changes_schema();
			$required                     = array_merge( $required, array( 'expected_state', 'changes' ) );
		}
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'required'             => $required,
		);
	}

	/**
	 * Describe only supported fields and structural placement.
	 *
	 * @return array<string,mixed>
	 */
	private function changes_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'minProperties'        => 1,
			'properties'           => array(
				'label'       => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				),
				'url'         => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 2048,
				),
				'target'      => array(
					'type' => 'string',
					'enum' => array( '', '_self', '_blank' ),
				),
				'rel'         => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'parent_path' => array(
					'type'        => 'string',
					'pattern'     => '^((0|[1-9][0-9]{0,2})(\\.(0|[1-9][0-9]{0,2})){0,18})?$',
					'maxLength'   => 75,
					'description' => 'Existing submenu path from the pre-edit record, or empty for root. Omit to retain the parent.',
				),
				'order'       => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'description' => 'Final 1-based position among all destination sibling blocks. Omit to keep position, or append when changing parent.',
				),
			),
		);
	}
}

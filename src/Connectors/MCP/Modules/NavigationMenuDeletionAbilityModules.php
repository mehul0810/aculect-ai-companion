<?php
/**
 * Confirmed classic navigation menu deletion tools.
 *
 * @package Aculect\AICompanion\Connectors\MCP\Modules
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\NavigationMenuDeletion;

/** Publishes bounded inspect-before-confirmed-delete classic-menu tools. */
final class NavigationMenuDeletionAbilityModules {

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Return read and destructive deletion abilities.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	public function all(): array {
		$inspect_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'menu_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'menu_id' ),
		);
		$inspect        = $this->factory->create(
			'navigation.inspect_menu_deletion',
			'Inspect Classic Menu Deletion',
			'Inspect one unassigned classic menu and return only its ID, name, bounded item count, permanent-deletion warning and keyed expected_state. Menus with more than 500 items, shared item memberships or ambiguous location state are refused.',
			'Navigation Menu Deletion',
			'content:read',
			true,
			$inspect_schema,
			static fn ( array $args ): array => ( new NavigationMenuDeletion() )->inspect_menu_deletion( $args )
		);

		$delete_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'menu_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expected_state' => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
			),
			'required'             => array( 'menu_id', 'expected_state' ),
		);
		$delete        = $this->factory->create(
			'navigation.delete_menu',
			'Delete Classic Menu',
			'Permanently delete one exact unassigned classic menu and all of its exclusively owned native items through wp_delete_nav_menu. Requires the expected_state from navigation.inspect_menu_deletion, a destructive preview and explicit confirmation; no trash, compensation, location unassignment, menu creation or source-file changes are supported.',
			'Navigation Menu Deletion',
			'content:draft',
			false,
			$delete_schema,
			static fn ( array $args ): array => ( new NavigationMenuDeletion() )->delete_menu( $args )
		);

		return array(
			$inspect->id() => $inspect,
			$delete->id()  => $delete,
		);
	}
}

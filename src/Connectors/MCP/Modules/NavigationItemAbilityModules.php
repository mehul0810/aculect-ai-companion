<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\NavigationItemWriteAbilities;
use Aculect\AICompanion\Connectors\MCP\NavigationMenuDiscoveryAbilities;

/**
 * Couples navigation context with explicit existing-classic-item operations.
 */
final class NavigationItemAbilityModules {

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Preserve context discovery and add bounded read-before-write operations.
	 *
	 * @return array<string,AbilityModuleInterface>
	 */
	public function all(): array {
		$context = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'context' => array(
					'type'        => 'string',
					'enum'        => array( 'compact', 'full' ),
					'description' => 'Use compact for a short intelligence summary or full for bounded supporting context. Defaults to compact.',
				),
			),
		);
		$modules = array(
			$this->factory->create( 'navigation.get_context', 'Read Navigation Intelligence', 'Use this before planning navigation or menu work. Detects block theme, hybrid and classic menu sources. Existing classic items can be read with navigation.read_item and updated separately; block and location writes are unsupported.', 'Navigation Intelligence', 'content:read', true, $context, static fn ( array $args ): array => ( new NavigationMenuDiscoveryAbilities() )->get_context( $args ) ),
			$this->factory->create( 'navigation.read_item', 'Read Editable Classic Menu Item', 'Read one existing classic menu item with its expected_state token before planning a change. Requires edit_theme_options; block navigation and menus larger than 500 items are unsupported.', 'Navigation Intelligence', 'content:read', true, $this->schema( false ), static fn ( array $args ): array => ( new NavigationItemWriteAbilities() )->read_item( $args ) ),
			$this->factory->create( 'navigation.update_item', 'Update Classic Menu Item', 'Update one existing classic menu item label, custom-link URL, parent, order, target or rel. Supply expected_state from navigation.read_item and obtain explicit confirmation. Never creates items, reassigns menu locations or rewrites block navigation.', 'Navigation Intelligence', 'content:draft', false, $this->schema( true ), static fn ( array $args ): array => ( new NavigationItemWriteAbilities() )->update_item( $args ) ),
		);
		$result  = array();
		foreach ( $modules as $module ) {
			$result[ $module->id() ] = $module;
		}
		return $result;
	}

	/**
	 * Describe only supported existing-item fields.
	 *
	 * @param bool $write Include change and concurrency fields.
	 * @return array<string,mixed>
	 */
	private function schema( bool $write ): array {
		$properties = array(
			'menu_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'item_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$required   = array( 'menu_id', 'item_id' );
		if ( $write ) {
			$properties['expected_state'] = array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{64}$',
			);
			$properties['changes']        = array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'minProperties'        => 1,
				'properties'           => array(
					'label'     => array(
						'type'      => 'string',
						'maxLength' => 255,
					),
					'url'       => array(
						'type'      => 'string',
						'maxLength' => 2048,
					),
					'parent_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'order'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 500,
					),
					'target'    => array(
						'type' => 'string',
						'enum' => array( '', '_self', '_blank' ),
					),
					'rel'       => array(
						'type'      => 'string',
						'maxLength' => 255,
					),
				),
			);
			$required                     = array_merge( $required, array( 'expected_state', 'changes' ) );
		}
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'required'             => $required,
		);
	}
}

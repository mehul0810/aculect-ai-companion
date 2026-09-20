<?php
/**
 * Confirmed classic navigation location assignment tools.
 *
 * @package Aculect\AICompanion\Connectors\MCP\Modules
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\NavigationLocationAbilities;

/** Publishes bounded read-before-write menu location operations. */
final class NavigationLocationAbilityModules {

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Return the read context and always-confirmed location assignment abilities.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	public function all(): array {
		$read_schema  = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
			'required'             => array(),
		);
		$read         = $this->factory->create(
			'navigation.read_location_context',
			'Read Classic Menu Location Context',
			'Read the active theme, up to 100 registered classic menu locations, the complete native location-to-menu map including unregistered keys, and an HMAC expected_state before planning an assignment.',
			'Navigation Locations',
			'content:read',
			true,
			$read_schema,
			static fn ( array $args ): array => ( new NavigationLocationAbilities() )->read_context( $args )
		);
		$write_schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'location'       => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'menu_id'        => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Existing classic menu ID, or 0 to explicitly unassign this location.',
				),
				'expected_state' => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
			),
			'required'             => array( 'location', 'menu_id', 'expected_state' ),
		);
		$write        = $this->factory->create(
			'navigation.assign_location',
			'Assign Classic Menu Location',
			'Assign one existing classic menu to an exact registered active-theme location, or use menu_id 0 to unassign it. Requires a fresh full-map expected_state and explicit confirmation. Replacing an assignment affects only that location; it never creates or deletes menus, items, files or themes.',
			'Navigation Locations',
			'content:draft',
			false,
			$write_schema,
			static fn ( array $args ): array => ( new NavigationLocationAbilities() )->assign_location( $args )
		);

		return array(
			$read->id()  => $read,
			$write->id() => $write,
		);
	}
}

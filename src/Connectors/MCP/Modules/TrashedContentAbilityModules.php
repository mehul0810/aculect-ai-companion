<?php
/**
 * Explicitly confirmed native trashed-content recovery tools.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\TrashedContentRecovery;

/** Publishes bounded inspection and always-confirmed draft restoration. */
final class TrashedContentAbilityModules {

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Return read and write abilities for exact post/page trash recovery.
	 *
	 * @return list<AbilityModuleInterface>
	 */
	public function all(): array {
		$schema  = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'post_id' ),
		);
		$inspect = $this->factory->create(
			'content.inspect_trashed',
			'Inspect Trashed Content',
			'Inspect one trashed post or page and return only its ID, type, recorded prior status and keyed expected_state. Content and metadata values are never returned.',
			'Trashed Content Recovery',
			'content:read',
			true,
			$schema,
			static fn ( array $args ): array => ( new TrashedContentRecovery() )->inspect_trashed( $args )
		);

		$schema['properties']['expected_state'] = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);
		$schema['required'][]                   = 'expected_state';
		$restore                                = $this->factory->create(
			'content.restore_trashed',
			'Restore Trashed Content',
			'Restore one exact trashed post or page through native WordPress untrash hooks, with explicit confirmation and a fresh expected_state. It always returns the item to draft, even when its recorded prior status was published or scheduled, so it is never accidentally republished. Native WordPress hooks also restore associated trashed comments.',
			'Trashed Content Recovery',
			'content:draft',
			false,
			$schema,
			static fn ( array $args ): array => ( new TrashedContentRecovery() )->restore_trashed( $args )
		);

		return array( $inspect, $restore );
	}
}

<?php
/**
 * Explicitly confirmed content-field recovery tools.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\ContentRevisionRecovery;

/** Keeps the recovery write separate from existing revision discovery. */
final class ContentRecoveryAbilityModules {

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Return bounded read and always-confirmed write descriptors.
	 *
	 * @return list<AbilityModuleInterface>
	 */
	public function all(): array {
		$schema                                 = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'post_id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'revision_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'post_id', 'revision_id' ),
		);
		$read                                   = $this->factory->create( 'revisions.compare_content', 'Compare Revision Content', 'Compare the title, content and excerpt of one saved revision with its editable parent post or page. Returns bounded change flags and byte counts, never full text or metadata. Provides expected_state for confirmed recovery.', 'Content Recovery', 'content:read', true, $schema, static fn ( array $args ): array => ( new ContentRevisionRecovery() )->compare( $args ) );
		$schema['properties']['expected_state'] = array(
			'type'    => 'string',
			'pattern' => '^[a-f0-9]{64}$',
		);
		$schema['required'][]                   = 'expected_state';
		$write                                  = $this->factory->create( 'revisions.restore_content', 'Restore Revision Content', 'Restore only title, content and excerpt on an existing post or page. Requires write scope, explicit confirmation, fresh expected_state, no other-editor lock and a verified pre-restore revision. Preserves status; live content changes immediately and requires native publishing permission. Does not restore metadata, terms, media or site files.', 'Content Recovery', 'content:draft', false, $schema, static fn ( array $args ): array => ( new ContentRevisionRecovery() )->restore( $args ) );
		return array( $read, $write );
	}
}

<?php
/**
 * Supplemental MCP modules shared outside the domain write workflows.
 *
 * @package Aculect\AICompanion\Connectors\MCP\Modules
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\MediaUploadHandoff;

/** Keeps supplemental entry points out of the legacy first-party registry. */
final class SupplementalAbilityModules {

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Return supplemental tools with their individual access and write policies.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	public function all(): array {
		return array_merge(
			( new MemorySyncAbilityModules( $this->factory ) )->all(),
			array(
				'media.open_upload_handoff' => $this->factory->create(
					'media.open_upload_handoff',
					'Open WordPress Media Upload',
					'Return a same-site HTTPS WordPress upload screen for the human to open and use. No file bytes are sent through MCP; the human returns the attachment ID separately.',
					'Media',
					'content:read',
					true,
					array(
						'type'                 => 'object',
						'properties'           => new \stdClass(),
						'additionalProperties' => false,
					),
					static fn (): array => ( new MediaUploadHandoff() )->result()
				),
			)
		);
	}
}

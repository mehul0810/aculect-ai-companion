<?php
/**
 * Private-data-free native Tools inspection contracts.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\PrivacyRequestStatus;
use Aculect\AICompanion\Connectors\MCP\SiteHealthInfo;

/**
 * Adds inspection without accepting private input or executing native jobs.
 */
final class ToolsInspectionAbilityModules {

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Return fixed read contracts.
	 *
	 * @return list<AbilityModuleInterface>
	 */
	public function all(): array {
		return array(
			$this->factory->create(
				'site.health_info',
				'Read Safe Site Health Information',
				'Read a fixed allowlist from a native Site Health Info section. Excludes paths, identities, arbitrary constants and third-party sections. Not a complete debug dump.',
				'Site Tools',
				'content:read',
				true,
				$this->schema(
					array(
						'section' => array(
							'type' => 'string',
							'enum' => array( 'wp-core', 'wp-server', 'wp-database', 'wp-constants' ),
						),
					)
				),
				static fn ( array $args ): array => ( new SiteHealthInfo() )->read( $args )
			),
			$this->factory->create(
				'tools.privacy_request_status',
				'Read Privacy Request Status',
				'Read only the native lifecycle state of one known privacy request. Requires native per-action permission. No requester details, verification keys, archives, exporter results or job percentage. Never executes export or erasure.',
				'Site Tools',
				'content:read',
				true,
				$this->schema(
					array(
						'action'     => array(
							'type' => 'string',
							'enum' => array( 'export_personal_data', 'remove_personal_data' ),
						),
						'request_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					)
				),
				static fn ( array $args ): array => ( new PrivacyRequestStatus() )->read( $args )
			),
		);
	}

	/**
	 * Require every declared input and reject additional fields.
	 *
	 * @param array<string, mixed> $properties Fixed property schemas.
	 * @return array<string, mixed>
	 */
	private function schema( array $properties ): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
		);
	}
}

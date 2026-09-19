<?php
/**
 * Shared output schemas for MCP discovery modules.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Keeps route-specific schema declarations out of the MCP transport class.
 *
 * @internal
 */
final class McpOutputSchemaCatalog {

	/**
	 * Return the core schema discovery response contract.
	 *
	 * @return array<string,mixed>
	 */
	public static function core_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'schema_version' => array( 'type' => 'string' ),
				'description'    => array( 'type' => 'string' ),
				'wordpress'      => array( 'type' => 'object' ),
				'capabilities'   => array( 'type' => 'object' ),
				'post_types'     => array( 'type' => 'array' ),
				'taxonomies'     => array( 'type' => 'array' ),
				'statuses'       => array( 'type' => 'array' ),
				'rest'           => array( 'type' => 'object' ),
				'features'       => array( 'type' => 'object' ),
				'diagnostics'    => array( 'type' => 'array' ),
			),
			'required'             => array( 'schema_version', 'wordpress', 'capabilities', 'post_types', 'taxonomies', 'statuses', 'rest', 'features', 'diagnostics' ),
			'additionalProperties' => true,
		);
	}
}

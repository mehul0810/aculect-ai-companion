<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Adds the shared confirmation controls required by write-capable tools.
 */
final class ToolSafetySchema {

	/**
	 * Add dry-run, confirmation, and idempotency controls.
	 *
	 * @param array<string, mixed> $schema Tool input schema.
	 * @return array<string, mixed>
	 */
	public function augment( array $schema ): array {
		$properties                       = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$properties['dry_run']            = array(
			'type'        => 'boolean',
			'description' => 'When true, validate the request and return a preview without changing WordPress data.',
		);
		$properties['confirmation_token'] = array(
			'type'        => 'string',
			'description' => 'Short-lived token returned by a dry run or confirmation-required response for high-risk actions.',
		);
		$properties['idempotency_key']    = array(
			'type'        => 'string',
			'maxLength'   => 128,
			'description' => 'Optional client-chosen key that makes this write retry-safe: repeating the call with the same key and arguments returns the stored result instead of executing twice. Use a new key for new work.',
		);
		$schema['properties']             = $properties;

		return $schema;
	}
}

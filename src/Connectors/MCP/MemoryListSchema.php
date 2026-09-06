<?php
/**
 * Public pagination contract for durable memory retrieval.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Owns the cursor-first schema alongside the memory list service. */
final class MemoryListSchema {
	/**
	 * Return a closed, bounded list schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function build(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'domain'   => array(
					'type'        => 'string',
					'enum'        => array( 'brand', 'site', 'content', 'developer', 'seo', 'workflow' ),
					'description' => 'Memory domain to filter.',
				),
				'status'   => array(
					'type'        => 'string',
					'enum'        => array( 'approved', 'pending', 'dismissed' ),
					'description' => 'Memory review status. Defaults to approved.',
				),
				'query'    => array( 'type' => 'string' ),
				'cursor'   => array(
					'type'        => 'string',
					'description' => 'Opaque next_cursor from the previous page. Required for continuation; page alone cannot advance the result set.',
				),
				'page'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'One-based display counter. Defaults to 1. Supply cursor to continue.',
				),
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'description' => 'Memory rows per page. Defaults to 10.',
				),
			),
		);
	}
}

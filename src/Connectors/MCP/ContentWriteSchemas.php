<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Shared schemas for optimistic-concurrency content writes.
 */
final class ContentWriteSchemas {

	/**
	 * Return the optional modified timestamp precondition.
	 *
	 * @return array<string, string>
	 */
	public static function expected_modified_gmt(): array {
		return array(
			'type'        => 'string',
			'description' => 'Optional optimistic-concurrency token from a prior content read. The write is rejected if the item changed.',
		);
	}
}

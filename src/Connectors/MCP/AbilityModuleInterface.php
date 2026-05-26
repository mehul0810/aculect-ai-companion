<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Internal contract for one MCP-exposed ability.
 */
interface AbilityModuleInterface {

	/**
	 * Return the internal dotted ability ID.
	 */
	public function id(): string;

	/**
	 * Return the admin-facing ability title.
	 */
	public function title(): string;

	/**
	 * Return the assistant-facing ability description.
	 */
	public function description(): string;

	/**
	 * Return the admin grouping label.
	 */
	public function group(): string;

	/**
	 * Return OAuth scopes required to call this ability.
	 *
	 * @return list<string>
	 */
	public function required_scopes(): array;

	/**
	 * Whether this ability only reads site data.
	 */
	public function is_read_only(): bool;

	/**
	 * Return the JSON schema for this ability's input.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array;

	/**
	 * Execute the ability.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function execute( array $args ): array;
}

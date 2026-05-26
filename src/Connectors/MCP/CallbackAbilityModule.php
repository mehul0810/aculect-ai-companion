<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Closure;

/**
 * Callback-backed ability module for first-party MCP tools.
 */
final class CallbackAbilityModule implements AbilityModuleInterface {

	/**
	 * Ability execution callback.
	 *
	 * @var Closure(array<string, mixed>): array<string, mixed>
	 */
	private Closure $handler;

	/**
	 * Create a callback-backed ability module.
	 *
	 * @param string  $id              Internal ability ID.
	 * @param string  $title           Admin-facing title.
	 * @param string  $description     Assistant-facing description.
	 * @param string  $group           Admin grouping label.
	 * @param array   $required_scopes Required OAuth scopes.
	 * @param bool    $read_only       Whether the ability is read-only.
	 * @param array   $input_schema    JSON schema for tool input.
	 * @param Closure $handler         Ability execution callback.
	 */
	public function __construct(
		private readonly string $id,
		private readonly string $title,
		private readonly string $description,
		private readonly string $group,
		private readonly array $required_scopes,
		private readonly bool $read_only,
		private readonly array $input_schema,
		Closure $handler
	) {
		$this->handler = $handler;
	}

	public function id(): string {
		return $this->id;
	}

	public function title(): string {
		return $this->title;
	}

	public function description(): string {
		return $this->description;
	}

	public function group(): string {
		return $this->group;
	}

	public function required_scopes(): array {
		return $this->required_scopes;
	}

	public function is_read_only(): bool {
		return $this->read_only;
	}

	public function input_schema(): array {
		return $this->input_schema;
	}

	public function execute( array $args ): array {
		return ( $this->handler )( $args );
	}
}

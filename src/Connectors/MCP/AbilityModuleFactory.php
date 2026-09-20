<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Closure;

/**
 * Creates callback-backed modules with one shared write-safety policy.
 */
final class AbilityModuleFactory {

	public function __construct( private readonly ToolSafetySchema $safety_schema = new ToolSafetySchema() ) {}

	/**
	 * Create one internal ability module.
	 *
	 * @param string               $id          Internal ability ID.
	 * @param string               $title       Admin-facing title.
	 * @param string               $description Assistant-facing description.
	 * @param string               $group       Admin grouping label.
	 * @param string               $scope       Required OAuth scope.
	 * @param bool                 $read_only   Whether the ability is read-only.
	 * @param array<string, mixed> $schema      Input schema.
	 * @param Closure              $handler     Execution callback.
	 */
	public function create( string $id, string $title, string $description, string $group, string $scope, bool $read_only, array $schema, Closure $handler ): AbilityModuleInterface {
		return new CallbackAbilityModule(
			$id,
			$title,
			$description,
			$group,
			array( $scope ),
			$read_only,
			$read_only ? $schema : $this->safety_schema->augment( $schema ),
			$handler
		);
	}
}

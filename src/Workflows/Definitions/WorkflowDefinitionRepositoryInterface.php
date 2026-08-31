<?php
/**
 * Repository contract for durable workflow definitions.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

/**
 * Small persistence boundary consumed by admin and connector services.
 *
 * Keeping the application-facing contract separate from the SQL implementation
 * makes storage failures and test doubles explicit without weakening the
 * repository's production validation rules.
 */
interface WorkflowDefinitionRepositoryInterface {

	/**
	 * Create the first immutable version.
	 *
	 * @param WorkflowDefinition $definition Validated v1 definition.
	 * @param string             $template_id Built-in template identifier.
	 * @param int                $template_version Template revision.
	 * @param array<int,string>  $allowed_roles Role allowlist.
	 */
	public function create( WorkflowDefinition $definition, string $template_id = '', int $template_version = 0, array $allowed_roles = array() ): WorkflowDefinitionRecord;

	/**
	 * Read one immutable version.
	 *
	 * @param string   $workflow_id Stable workflow identifier.
	 * @param int|null $version Requested version, or null for latest.
	 * @param bool     $include_disabled Whether disabled workflows are visible.
	 */
	public function get( string $workflow_id, ?int $version = null, bool $include_disabled = false ): ?WorkflowDefinitionRecord;

	/**
	 * Read the currently published immutable version.
	 *
	 * @param string $workflow_id Stable workflow identifier.
	 */
	public function get_published( string $workflow_id ): ?WorkflowDefinitionRecord;

	/**
	 * List published immutable snapshots.
	 *
	 * @param array<string,mixed> $filters Bounded page and lookahead filters.
	 * @return list<WorkflowDefinitionRecord>
	 */
	public function list_published( array $filters = array() ): array;

	/**
	 * List latest workflow records.
	 *
	 * @param array<string,mixed> $filters Bounded list filters.
	 * @return list<WorkflowDefinitionRecord>
	 */
	public function list( array $filters = array() ): array;

	/**
	 * Append one immutable version.
	 *
	 * @param WorkflowDefinition     $definition Next validated definition.
	 * @param int                    $expected_version Current latest version.
	 * @param string|null            $template_id Template override.
	 * @param int|null               $template_version Template revision override.
	 * @param array<int,string>|null $allowed_roles Role allowlist override.
	 * @param string|null            $approved_migration_id Exact reviewed migration plan ID.
	 */
	public function update( WorkflowDefinition $definition, int $expected_version, ?string $template_id = null, ?int $template_version = null, ?array $allowed_roles = null, ?string $approved_migration_id = null ): WorkflowDefinitionRecord;

	/**
	 * Disable a workflow without deleting immutable versions.
	 *
	 * @param string   $workflow_id Stable workflow identifier.
	 * @param int      $actor_id Acting administrator.
	 * @param int|null $expected_version Optimistic version check.
	 */
	public function disable( string $workflow_id, int $actor_id, ?int $expected_version = null ): ?WorkflowDefinitionRecord;
}

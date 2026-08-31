<?php
/**
 * Durable workflow definition catalog record.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

/**
 * Detached projection of a catalog row and its immutable definition version.
 */
final readonly class WorkflowDefinitionRecord {

	/**
	 * Create a detached workflow definition record.
	 *
	 * @param int                $id               Catalog primary key.
	 * @param string             $workflow_id      Stable workflow identifier.
	 * @param string             $status           Catalog status.
	 * @param int                $latest_version  Latest immutable version.
	 * @param int                $published_version Published version pointer.
	 * @param string             $template_id      Optional template identifier.
	 * @param int                $template_version Optional template revision.
	 * @param int                $created_by       Catalog creator user ID.
	 * @param int                $updated_by       Last catalog updater user ID.
	 * @param int                $lock_version     Optimistic concurrency token.
	 * @param string             $created_at       Catalog creation timestamp.
	 * @param string             $updated_at       Catalog update timestamp.
	 * @param WorkflowDefinition $definition       Immutable definition snapshot.
	 * @param array<int,string>  $allowed_roles    Optional workflow role allowlist.
	 * @param int                $migrated_from_version Source revision for the stored migration preview.
	 * @param string             $migration_id     Deterministic migration preview identifier.
	 * @phpstan-param list<string> $allowed_roles
	 */
	public function __construct(
		private int $id,
		private string $workflow_id,
		private string $status,
		private int $latest_version,
		private int $published_version,
		private string $template_id,
		private int $template_version,
		private int $created_by,
		private int $updated_by,
		private int $lock_version,
		private string $created_at,
		private string $updated_at,
		private WorkflowDefinition $definition,
		private array $allowed_roles = array(),
		private int $migrated_from_version = 0,
		private string $migration_id = ''
	) {
	}

	/** Return the catalog primary key. */
	public function id(): int {
		return $this->id;
	}

	/** Return the stable workflow identifier. */
	public function workflow_id(): string {
		return $this->workflow_id;
	}

	/** Return the current catalog status. */
	public function status(): string {
		return $this->status;
	}

	/** Return the latest immutable version number. */
	public function latest_version(): int {
		return $this->latest_version;
	}

	/** Return the published version pointer, or zero when unpublished. */
	public function published_version(): int {
		return $this->published_version;
	}

	/** Return the optional template identifier. */
	public function template_id(): string {
		return $this->template_id;
	}

	/** Return the optional template revision. */
	public function template_version(): int {
		return $this->template_version;
	}

	/** Return the catalog creator user ID. */
	public function created_by(): int {
		return $this->created_by;
	}

	/** Return the last catalog updater user ID. */
	public function updated_by(): int {
		return $this->updated_by;
	}

	/** Return the optimistic concurrency token. */
	public function lock_version(): int {
		return $this->lock_version;
	}

	/** Return the catalog creation timestamp. */
	public function created_at(): string {
		return $this->created_at;
	}

	/** Return the catalog update timestamp. */
	public function updated_at(): string {
		return $this->updated_at;
	}

	/** Return the immutable definition snapshot. */
	public function definition(): WorkflowDefinition {
		return $this->definition;
	}

	/**
	 * Return the optional role allowlist for this immutable version.
	 *
	 * An empty list means the version inherits the existing ability policy.
	 *
	 * @return list<string>
	 */
	public function allowed_roles(): array {
		return array_values( $this->allowed_roles );
	}

	/** Return the source version recorded for a migration preview. */
	public function migrated_from_version(): int {
		return $this->migrated_from_version;
	}

	/** Return the deterministic migration preview identifier. */
	public function migration_id(): string {
		return $this->migration_id;
	}

	/**
	 * Return an admin/API-safe detached representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                    => $this->id,
			'workflow_id'           => $this->workflow_id,
			'status'                => $this->status,
			'latest_version'        => $this->latest_version,
			'published_version'     => $this->published_version,
			'template_id'           => $this->template_id,
			'template_version'      => $this->template_version,
			'created_by'            => $this->created_by,
			'updated_by'            => $this->updated_by,
			'lock_version'          => $this->lock_version,
			'created_at'            => $this->created_at,
			'updated_at'            => $this->updated_at,
			'allowed_roles'         => $this->allowed_roles(),
			'migrated_from_version' => $this->migrated_from_version,
			'migration_id'          => $this->migration_id,
			'definition'            => $this->definition->to_array(),
		);
	}
}

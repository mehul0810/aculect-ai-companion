<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Skills;

/**
 * Site-local CRUD and versioned JSON package storage for declarative Skills.
 *
 * This class deliberately stores only validated Markdown documents. It does
 * not interpret or execute Skill content.
 */
final class CustomMcpSkillStore {

	private const RECORD_PREFIX = 'aculect_ai_companion_custom_mcp_skill_';
	private const MAX_VERSION   = 1000000;

	private CustomSkillOptionStore $options;
	private CustomSkillValidator $validator;
	private CustomSkillPackageCodec $packages;
	private CustomSkillIndex $index;

	public function __construct( ?CustomSkillOptionStore $options = null, ?CustomSkillValidator $validator = null ) {
		$this->options   = $options ?? new WordPressCustomSkillOptionStore();
		$this->validator = $validator ?? new CustomSkillValidator();
		$this->packages  = new CustomSkillPackageCodec( $this->validator );
		$this->index     = new CustomSkillIndex( $this->options, $this->validator );
	}

	/**
	 * List stored entries in stable ID order without returning document bodies.
	 *
	 * @return list<array{id:string,name:string,description:string,required_abilities:list<string>,required_plugins:list<string>,version:int,digest:string,enabled:bool}>
	 */
	public function list(): array {
		$items = array();
		foreach ( $this->index->ids( fn ( string $id ): bool => null !== $this->record( $id ) ) as $id ) {
			$record = $this->record( $id );
			if ( null !== $record ) {
				$items[] = $this->summary( $record );
			}
		}

		return $items;
	}

	/**
	 * Retrieve a complete stored Skill.
	 *
	 * @param string $id Custom Skill ID.
	 * @return array<string,mixed>
	 */
	public function get( string $id ): array {
		$record = $this->record( $id );
		return null === $record ? $this->failure( 'skill_not_found' ) : $this->success( array( 'skill' => $record ) );
	}

	/**
	 * Create a custom Skill. add_option provides an atomic unique-ID claim.
	 *
	 * @param array<string,mixed> $input Skill fields.
	 * @param int                 $version Initial content revision, used by package imports.
	 * @return array<string,mixed>
	 */
	public function create( array $input, int $version = 1 ): array {
		if ( ! $this->has_only_fields( $input, array( 'id', 'skill_md', 'references', 'enabled', 'required_abilities', 'required_plugins' ) ) ) {
			return $this->failure( 'invalid_skill' );
		}

		$record = $this->build_record( $input, $version );
		if ( null === $record ) {
			return $this->failure( 'invalid_skill' );
		}
		return $this->persist_new_record( $record );
	}

	/**
	 * Replace documents for an existing Skill and increment its content version.
	 *
	 * @param string              $id Custom Skill ID.
	 * @param array<string,mixed> $input Replacement fields.
	 * @param int|null            $expected_version Expected revision from the caller's read.
	 * @param string|null         $expected_digest Expected content digest from the caller's read.
	 * @return array<string,mixed>
	 */
	public function update( string $id, array $input, ?int $expected_version = null, ?string $expected_digest = null ): array {
		$lock = $this->index->acquire_lock();
		if ( null === $lock ) {
			return $this->failure( 'storage_unavailable' );
		}

		try {
			$current = $this->record( $id );
			if ( null === $current ) {
				return $this->failure( 'skill_not_found' );
			}
			if ( ! $this->matches_expected_revision( $current, $expected_version, $expected_digest ) ) {
				return $this->failure( 'skill_conflict' );
			}
			if ( ! $this->has_only_fields( $input, array( 'id', 'skill_md', 'references', 'enabled', 'required_abilities', 'required_plugins' ) ) ) {
				return $this->failure( 'invalid_skill' );
			}

			$input['id']                 = $id;
			$input['enabled']            = (bool) $current['enabled'];
			$input['required_abilities'] = $input['required_abilities'] ?? $current['required_abilities'];
			$input['required_plugins']   = $input['required_plugins'] ?? $current['required_plugins'];
			$record                      = $this->build_record( $input, (int) $current['version'] );
			if ( null === $record ) {
				return $this->failure( 'invalid_skill' );
			}

			if ( ! hash_equals( $current['digest'], $record['digest'] ) ) {
				++$record['version'];
			}
			if ( self::MAX_VERSION < $record['version'] ) {
				return $this->failure( 'skill_version_limit' );
			}

			if ( $record === $current ) {
				return $this->success( array( 'skill' => $record ) );
			}

			if ( ! $this->options->update( self::RECORD_PREFIX . $id, $record ) && $record !== $this->record( $id ) ) {
				return $this->failure( 'storage_unavailable' );
			}

			return $this->success( array( 'skill' => $record ) );
		} finally {
			$this->index->release_lock( $lock );
		}
	}

	/**
	 * Enable or disable a stored Skill without changing its content version.
	 *
	 * @param string      $id Custom Skill ID.
	 * @param bool        $enabled Whether the Skill should be exposed.
	 * @param int|null    $expected_version Expected revision from the caller's read.
	 * @param string|null $expected_digest Expected content digest from the caller's read.
	 * @return array<string,mixed>
	 */
	public function set_enabled( string $id, bool $enabled, ?int $expected_version = null, ?string $expected_digest = null ): array {
		$lock = $this->index->acquire_lock();
		if ( null === $lock ) {
			return $this->failure( 'storage_unavailable' );
		}

		try {
			$record = $this->record( $id );
			if ( null === $record ) {
				return $this->failure( 'skill_not_found' );
			}
			if ( ! $this->matches_expected_revision( $record, $expected_version, $expected_digest ) ) {
				return $this->failure( 'skill_conflict' );
			}
			if ( $record['enabled'] === $enabled ) {
				return $this->success( array( 'skill' => $record ) );
			}

			$record['enabled'] = $enabled;
			++$record['version'];
			if ( self::MAX_VERSION < $record['version'] ) {
				return $this->failure( 'skill_version_limit' );
			}
			if ( ! $this->options->update( self::RECORD_PREFIX . $id, $record ) ) {
				return $this->failure( 'storage_unavailable' );
			}

			return $this->success( array( 'skill' => $record ) );
		} finally {
			$this->index->release_lock( $lock );
		}
	}

	/**
	 * Duplicate a Skill under a caller-selected deterministic custom ID.
	 *
	 * @param string $source_id Existing Skill ID.
	 * @param string $target_id New Skill ID.
	 * @return array<string,mixed>
	 */
	public function duplicate( string $source_id, string $target_id ): array {
		$source = $this->record( $source_id );
		if ( null === $source ) {
			return $this->failure( 'skill_not_found' );
		}

		$markdown = preg_replace( '/\A---\nname: [a-z0-9-]{1,64}\n/', "---\nname: {$target_id}\n", $source['skill_md'], 1 );
		if ( ! is_string( $markdown ) ) {
			return $this->failure( 'invalid_skill' );
		}

		return $this->create(
			array(
				'id'                 => $target_id,
				'skill_md'           => $markdown,
				'references'         => $source['references'],
				'enabled'            => (bool) $source['enabled'],
				'required_abilities' => $source['required_abilities'],
				'required_plugins'   => $source['required_plugins'],
			)
		);
	}

	/**
	 * Delete a stored Skill.
	 *
	 * @param string      $id Custom Skill ID.
	 * @param int|null    $expected_version Expected revision from the caller's read.
	 * @param string|null $expected_digest Expected content digest from the caller's read.
	 * @return array<string,mixed>
	 */
	public function delete( string $id, ?int $expected_version = null, ?string $expected_digest = null ): array {
		$lock = $this->index->acquire_lock();
		if ( null === $lock ) {
			return $this->failure( 'storage_unavailable' );
		}

		try {
			$record = $this->record( $id );
			if ( null === $record ) {
				return $this->failure( 'skill_not_found' );
			}
			if ( ! $this->matches_expected_revision( $record, $expected_version, $expected_digest ) ) {
				return $this->failure( 'skill_conflict' );
			}
			if ( ! $this->options->delete( self::RECORD_PREFIX . $id ) ) {
				return $this->failure( 'storage_unavailable' );
			}

			// Listing revalidates index IDs against records, so failure to compact a
			// stale index does not undo the completed record deletion.
			$this->index->write_locked( $id, false, fn ( string $candidate ): bool => null !== $this->record( $candidate ) );
			return $this->success();
		} finally {
			$this->index->release_lock( $lock );
		}
	}

	/**
	 * Export one validated Skill as a versioned UTF-8 JSON envelope.
	 *
	 * @param string $id Custom Skill ID.
	 * @return array<string,mixed>
	 */
	public function export( string $id ): array {
		return ( new CustomSkillPackageService( $this, $this->packages ) )->export( $id );
	}

	/**
	 * Import a strict JSON package. Existing IDs require explicit replacement.
	 *
	 * @param string      $json Versioned JSON package.
	 * @param bool        $replace Explicitly replace an existing Skill.
	 * @param int|null    $expected_version Expected revision from the caller's read.
	 * @param string|null $expected_digest Expected content digest from the caller's read.
	 * @return array<string,mixed>
	 */
	public function import( string $json, bool $replace = false, ?int $expected_version = null, ?string $expected_digest = null ): array {
		return ( new CustomSkillPackageService( $this, $this->packages ) )->import( $json, $replace, $expected_version, $expected_digest );
	}

	/**
	 * Atomically replace a validated package record with optimistic preconditions.
	 *
	 * @param array<string,mixed> $skill Decoded and validated package Skill.
	 * @param int|null            $expected_version Expected caller revision.
	 * @param string|null         $expected_digest Expected caller digest.
	 * @return array<string,mixed>
	 */
	public function replace_from_package( array $skill, ?int $expected_version, ?string $expected_digest ): array {
		$id = $skill['id'] ?? null;
		if ( ! is_string( $id ) || ! is_string( $skill['skill_md'] ?? null ) || ! is_array( $skill['references'] ?? null ) || ! is_array( $skill['required_abilities'] ?? null ) || ! is_array( $skill['required_plugins'] ?? null ) || ! is_bool( $skill['enabled'] ?? null ) ) {
			return $this->failure( 'invalid_package' );
		}

		$lock = $this->index->acquire_lock();
		if ( null === $lock ) {
			return $this->failure( 'storage_unavailable' );
		}

		try {
			$current = $this->record( $id );
			if ( null === $current ) {
				return $this->failure( null !== $expected_version || null !== $expected_digest ? 'skill_conflict' : 'skill_not_found' );
			}
			// A record may have appeared after an earlier import precheck. Never
			// replace it unless the caller observed this exact revision.
			if ( null === $expected_version || null === $expected_digest ) {
				return $this->failure( 'skill_conflict' );
			}
			if ( ! $this->matches_expected_revision( $current, $expected_version, $expected_digest ) ) {
				return $this->failure( 'skill_conflict' );
			}

			$record = $this->build_record( $skill, (int) $current['version'] );
			if ( null === $record ) {
				return $this->failure( 'invalid_package' );
			}
			if ( ! hash_equals( $current['digest'], $record['digest'] ) ) {
				++$record['version'];
			}
			$record['enabled'] = $skill['enabled'];
			if ( $current['enabled'] !== $record['enabled'] ) {
				++$record['version'];
			}
			if ( self::MAX_VERSION < $record['version'] ) {
				return $this->failure( 'skill_version_limit' );
			}
			if ( $record === $current ) {
				return $this->success( array( 'skill' => $record ) );
			}
			if ( ! $this->options->update( self::RECORD_PREFIX . $id, $record ) && $record !== $this->record( $id ) ) {
				return $this->failure( 'storage_unavailable' );
			}

			return $this->success( array( 'skill' => $record ) );
		} finally {
			$this->index->release_lock( $lock );
		}
	}

	/**
	 * Build a canonical stored record from an untrusted payload.
	 *
	 * @param array<string,mixed> $input Raw Skill fields.
	 * @param int                 $version Content version.
	 * @return array<string,mixed>|null
	 */
	private function build_record( array $input, int $version ): ?array {
		if ( 1 > $version || self::MAX_VERSION < $version ) {
			return null;
		}
		$id                 = $input['id'] ?? null;
		$skill_md           = $input['skill_md'] ?? null;
		$references         = $input['references'] ?? array();
		$enabled            = $input['enabled'] ?? true;
		$required_abilities = $input['required_abilities'] ?? array();
		$required_plugins   = $input['required_plugins'] ?? array();
		if ( ! is_string( $id ) || ! is_string( $skill_md ) || ! is_array( $references ) || ! is_bool( $enabled ) || ! is_array( $required_abilities ) || ! is_array( $required_plugins ) ) {
			return null;
		}

		$validated = $this->validator->validate( $id, $skill_md, $references, $required_abilities, $required_plugins );
		if ( null === $validated ) {
			return null;
		}

		$record = array(
			'id'                 => $id,
			'skill_md'           => $validated['skill_md'],
			'references'         => $validated['references'],
			'name'               => $validated['name'],
			'description'        => $validated['description'],
			'required_abilities' => $validated['required_abilities'],
			'required_plugins'   => $validated['required_plugins'],
			'version'            => max( 1, $version ),
			'digest'             => $this->packages->digest( $validated ),
			'enabled'            => $enabled,
		);
		return $record;
	}

	/**
	 * Return a safe metadata-only representation.
	 *
	 * @param array<string,mixed> $record Stored Skill.
	 * @return array{id:string,name:string,description:string,required_abilities:list<string>,required_plugins:list<string>,version:int,digest:string,enabled:bool}
	 */
	private function summary( array $record ): array {
		return array(
			'id'                 => (string) $record['id'],
			'name'               => (string) $record['name'],
			'description'        => (string) $record['description'],
			'required_abilities' => $record['required_abilities'],
			'required_plugins'   => $record['required_plugins'],
			'version'            => (int) $record['version'],
			'digest'             => (string) $record['digest'],
			'enabled'            => (bool) $record['enabled'],
		);
	}

	/**
	 * Save a validated record without overwriting a claimed ID.
	 *
	 * @param array<string,mixed> $record Validated record.
	 * @return array<string,mixed>
	 */
	private function persist_new_record( array $record ): array {
		$id   = $record['id'];
		$lock = $this->index->acquire_lock();
		if ( null === $lock ) {
			return $this->failure( 'storage_unavailable' );
		}

		try {
			$ids = $this->index->ids( fn ( string $candidate ): bool => null !== $this->record( $candidate ) );
			if ( CustomSkillIndex::MAX_SKILLS <= count( $ids ) ) {
				return $this->failure( 'skill_limit_reached' );
			}
			if ( ! $this->options->add( self::RECORD_PREFIX . $id, $record ) ) {
				return $this->failure( 'skill_exists' );
			}
			if ( ! $this->index->write_locked( $id, true, fn ( string $candidate ): bool => null !== $this->record( $candidate ) ) ) {
				$this->options->delete( self::RECORD_PREFIX . $id );
				return $this->failure( 'storage_unavailable' );
			}

			return $this->success( array( 'skill' => $record ) );
		} finally {
			$this->index->release_lock( $lock );
		}
	}

	/**
	 * Read a single option only after checking its ID.
	 *
	 * @param string $id Custom Skill ID.
	 * @return array<string,mixed>|null
	 */
	private function record( string $id ): ?array {
		if ( ! $this->validator->valid_id( $id ) ) {
			return null;
		}

		$record = $this->options->get( self::RECORD_PREFIX . $id, null );
		if ( ! is_array( $record ) || ( $record['id'] ?? null ) !== $id || ! is_string( $record['skill_md'] ?? null ) || ! is_array( $record['references'] ?? null ) || ! is_array( $record['required_abilities'] ?? null ) || ! is_array( $record['required_plugins'] ?? null ) || ! is_int( $record['version'] ?? null ) || $record['version'] < 1 ) {
			return null;
		}

		$rebuilt = $this->build_record( $record, $record['version'] );
		if ( null === $rebuilt || $record['version'] !== $rebuilt['version'] || ( $record['digest'] ?? null ) !== $rebuilt['digest'] || ( $record['name'] ?? null ) !== $rebuilt['name'] || ( $record['description'] ?? null ) !== $rebuilt['description'] || ! is_bool( $record['enabled'] ?? null ) ) {
			return null;
		}

		return $rebuilt;
	}

	/**
	 * Check optimistic concurrency tokens against the current validated record.
	 *
	 * @param array<string,mixed> $record Current record.
	 * @param int|null            $expected_version Caller-provided revision.
	 * @param string|null         $expected_digest Caller-provided content digest.
	 */
	private function matches_expected_revision( array $record, ?int $expected_version, ?string $expected_digest ): bool {
		if ( null !== $expected_version && $record['version'] !== $expected_version ) {
			return false;
		}
		return null === $expected_digest || ( is_string( $record['digest'] ) && hash_equals( $record['digest'], $expected_digest ) );
	}

	/**
	 * Check that only documented payload properties were supplied.
	 *
	 * @param array<string,mixed> $value Payload.
	 * @param array               $allowed Allowed keys.
	 * @phpstan-param list<string> $allowed Allowed keys.
	 */
	private function has_only_fields( array $value, array $allowed ): bool {
		return array_diff( array_keys( $value ), $allowed ) === array();
	}

	/**
	 * Build a generic success result.
	 *
	 * @param array<string,mixed> $data Additional data.
	 * @return array<string,mixed>
	 */
	private function success( array $data = array() ): array {
		return array_merge( array( 'success' => true ), $data );
	}

	/**
	 * Build a non-sensitive failure result.
	 *
	 * @param string $code Stable error code.
	 * @return array{success:false,error:string,message:string}
	 */
	private function failure( string $code ): array {
		$messages = array(
			'invalid_skill'       => 'The Skill is invalid or contains unsupported content.',
			'invalid_package'     => 'The Skill package is invalid.',
			'skill_exists'        => 'A Skill with this ID already exists.',
			'skill_conflict'      => 'This Skill changed since it was opened. Reload it before retrying.',
			'skill_version_limit' => 'This Skill has reached its revision limit and cannot be changed.',
			'skill_limit_reached' => 'The site has reached the maximum number of custom Skills.',
			'skill_not_found'     => 'The requested Skill was not found.',
			'storage_unavailable' => 'The Skill could not be saved. Try again.',
		);
		return array(
			'success' => false,
			'error'   => $code,
			'message' => $messages[ $code ] ?? 'The Skill operation could not be completed.',
		);
	}
}

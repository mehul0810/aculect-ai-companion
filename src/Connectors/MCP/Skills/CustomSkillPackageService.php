<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Skills;

/**
 * Coordinates strict custom Skill package import and export.
 */
final class CustomSkillPackageService {

	private CustomMcpSkillStore $store;
	private CustomSkillPackageCodec $codec;

	public function __construct( CustomMcpSkillStore $store, CustomSkillPackageCodec $codec ) {
		$this->store = $store;
		$this->codec = $codec;
	}

	/**
	 * Export one existing site-local Skill as a versioned JSON envelope.
	 *
	 * @param string $id Custom Skill ID.
	 * @return array<string,mixed>
	 */
	public function export( string $id ): array {
		$result = $this->store->get( $id );
		if ( ! isset( $result['skill'] ) || ! is_array( $result['skill'] ) ) {
			return $result;
		}

		$json = $this->codec->encode( $result['skill'] );
		return null === $json
			? $this->failure( 'storage_unavailable', 'The Skill could not be exported. Try again.' )
			: array(
				'success' => true,
				'package' => $json,
			);
	}

	/**
	 * Import a validated JSON package, preserving conflicts and explicit replacement.
	 *
	 * @param string      $json Versioned package JSON.
	 * @param bool        $replace Whether to replace an existing Skill.
	 * @param int|null    $expected_version Expected revision from the caller's read.
	 * @param string|null $expected_digest Expected content digest from the caller's read.
	 * @return array<string,mixed>
	 */
	public function import( string $json, bool $replace, ?int $expected_version, ?string $expected_digest ): array {
		$skill = $this->codec->decode( $json );
		if ( null === $skill ) {
			return $this->failure( 'invalid_package', 'The Skill package is invalid.' );
		}
		// Imported guidance is untrusted until the administrator reviews and
		// explicitly enables the stored Skill on this site. Never let an
		// exported activation flag publish it during import or replacement.
		$skill['enabled'] = false;

		$current = $this->store->get( $skill['id'] );
		if ( ! isset( $current['skill'] ) ) {
			if ( null !== $expected_version || null !== $expected_digest ) {
				return $this->failure( 'skill_conflict', 'This Skill changed since it was opened. Reload it before retrying.' );
			}
			$version = (int) $skill['version'];
			unset( $skill['name'], $skill['description'], $skill['digest'], $skill['version'] );
			return $this->store->create( $skill, $version );
		}
		if ( ! $replace ) {
			return $this->failure( 'skill_exists', 'A Skill with this ID already exists.' );
		}

		return $this->store->replace_from_package( $skill, $expected_version, $expected_digest );
	}

	/**
	 * Return a bounded non-sensitive error.
	 *
	 * @param string $code Stable error code.
	 * @param string $message Safe message.
	 * @return array{success:false,error:string,message:string}
	 */
	private function failure( string $code, string $message ): array {
		return array(
			'success' => false,
			'error'   => $code,
			'message' => $message,
		);
	}
}

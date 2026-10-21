<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Skills;

/**
 * Encodes the JSON envelope {format, format_version, skill} with format
 * `aculect-mcp-skill` and version 1. The Skill contains only SKILL.md,
 * references/*.md text, declarations, digest, enabled state, and revision.
 */
final class CustomSkillPackageCodec {

	private const FORMAT         = 'aculect-mcp-skill';
	private const FORMAT_VERSION = 1;
	private const MAX_REVISION   = 1000000;

	private CustomSkillValidator $validator;

	public function __construct( ?CustomSkillValidator $validator = null ) {
		$this->validator = $validator ?? new CustomSkillValidator();
	}

	/**
	 * Encode one validated record as a UTF-8 JSON package.
	 *
	 * @param array<string,mixed> $record Validated Skill record.
	 */
	public function encode( array $record ): ?string {
		$package = array(
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'skill'          => $record,
		);
		$json    = wp_json_encode( $package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		return is_string( $json ) ? $json : null;
	}

	/**
	 * Decode and fully validate a package, returning only the canonical record fields.
	 *
	 * @param string $json Versioned package JSON.
	 * @return array<string,mixed>|null
	 */
	public function decode( string $json ): ?array {
		if ( strlen( $json ) > ( 2 * CustomSkillValidator::MAX_TOTAL_BYTES ) + 8192 ) {
			return null;
		}

		$package = json_decode( $json, true, 12 );
		if ( ! is_array( $package ) || ! $this->has_exact_fields( $package, array( 'format', 'format_version', 'skill' ) ) || self::FORMAT !== ( $package['format'] ?? null ) || self::FORMAT_VERSION !== ( $package['format_version'] ?? null ) || ! is_array( $package['skill'] ?? null ) ) {
			return null;
		}

		$skill           = $package['skill'];
		$expected_fields = array( 'id', 'skill_md', 'references', 'name', 'description', 'required_abilities', 'required_plugins', 'version', 'digest', 'enabled' );
		if ( ! $this->has_exact_fields( $skill, $expected_fields ) || ! is_string( $skill['id'] ) || ! is_string( $skill['skill_md'] ) || ! is_array( $skill['references'] ) || ! is_array( $skill['required_abilities'] ) || ! is_array( $skill['required_plugins'] ) || ! is_int( $skill['version'] ) || $skill['version'] < 1 || $skill['version'] > self::MAX_REVISION || ! is_string( $skill['digest'] ) || ! is_bool( $skill['enabled'] ) ) {
			return null;
		}

		$validated = $this->validator->validate( $skill['id'], $skill['skill_md'], $skill['references'], $skill['required_abilities'], $skill['required_plugins'] );
		if ( null === $validated || $validated['name'] !== $skill['name'] || $validated['description'] !== $skill['description'] || ! hash_equals( $this->digest( $validated ), $skill['digest'] ) ) {
			return null;
		}

		return array(
			'id'                 => $skill['id'],
			'skill_md'           => $validated['skill_md'],
			'references'         => $validated['references'],
			'name'               => $validated['name'],
			'description'        => $validated['description'],
			'required_abilities' => $validated['required_abilities'],
			'required_plugins'   => $validated['required_plugins'],
			'version'            => $skill['version'],
			'digest'             => $skill['digest'],
			'enabled'            => $skill['enabled'],
		);
	}

	/**
	 * Hash canonical documents and dependencies; activation state is not content.
	 *
	 * @param array{skill_md:string,references:array<string,string>,required_abilities:list<string>,required_plugins:list<string>} $content Validated Skill content.
	 */
	public function digest( array $content ): string {
		$canonical = wp_json_encode(
			array(
				'skill_md'           => $content['skill_md'],
				'references'         => $content['references'],
				'required_abilities' => $content['required_abilities'],
				'required_plugins'   => $content['required_plugins'],
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		return 'sha256:' . hash( 'sha256', false === $canonical ? '' : $canonical );
	}

	/**
	 * Verify that an imported object has only its documented properties.
	 *
	 * @param array<string,mixed> $value Package object.
	 * @param array               $expected Expected keys.
	 * @phpstan-param list<string> $expected Expected keys.
	 */
	private function has_exact_fields( array $value, array $expected ): bool {
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		sort( $expected, SORT_STRING );
		return $keys === $expected;
	}
}

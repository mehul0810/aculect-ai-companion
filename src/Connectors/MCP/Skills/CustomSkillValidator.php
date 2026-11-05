<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Skills;

/**
 * Validates bounded, declarative Markdown-only custom Skills.
 */
final class CustomSkillValidator {

	public const MAX_SKILL_BYTES     = 32768;
	public const MAX_REFERENCE_BYTES = 16384;
	public const MAX_TOTAL_BYTES     = 131072;
	public const MAX_REFERENCES      = 20;

	/**
	 * Validate and normalize the supplied Markdown documents.
	 *
	 * @param string              $id Skill ID.
	 * @param string              $skill_md Skill document.
	 * @param array<string,mixed> $references Relative Markdown resource paths mapped to text.
	 * @param array<mixed>        $required_abilities Required connection ability IDs.
	 * @param array<mixed>        $required_plugins Required plugin basenames.
	 * @return array{skill_md:string,references:array<string,string>,name:string,description:string,required_abilities:list<string>,required_plugins:list<string>}|null
	 */
	public function validate( string $id, string $skill_md, array $references, array $required_abilities = array(), array $required_plugins = array() ): ?array {
		if ( ! $this->valid_id( $id ) ) {
			return null;
		}

		return $this->validate_documents( $id, $skill_md, $references, $required_abilities, $required_plugins );
	}

	/**
	 * Validate plugin-owned declarative Skill documents without executable files.
	 *
	 * @param string              $id Plugin Skill ID.
	 * @param string              $skill_md Skill document.
	 * @param array<string,mixed> $references Relative Markdown resources.
	 * @param array<mixed>        $required_abilities Required connection ability IDs.
	 * @param array<mixed>        $required_plugins Required plugin basenames.
	 * @return array{skill_md:string,references:array<string,string>,name:string,description:string,required_abilities:list<string>,required_plugins:list<string>}|null
	 */
	public function validate_plugin( string $id, string $skill_md, array $references, array $required_abilities = array(), array $required_plugins = array() ): ?array {
		if ( 1 !== preg_match( '/\Aplugin-[a-z0-9]+(?:-[a-z0-9]+)*\z/', $id ) || strlen( $id ) > 64 ) {
			return null;
		}

		return $this->validate_documents( $id, $skill_md, $references, $required_abilities, $required_plugins );
	}

	/**
	 * Apply common byte, dependency, frontmatter, and Markdown rules.
	 *
	 * @param string              $id Validated Skill ID.
	 * @param string              $skill_md Skill document.
	 * @param array<string,mixed> $references Relative Markdown resources.
	 * @param array<mixed>        $required_abilities Required connection abilities.
	 * @param array<mixed>        $required_plugins Required active plugins.
	 * @return array{skill_md:string,references:array<string,string>,name:string,description:string,required_abilities:list<string>,required_plugins:list<string>}|null
	 */
	private function validate_documents( string $id, string $skill_md, array $references, array $required_abilities, array $required_plugins ): ?array {
		if ( strlen( $skill_md ) > self::MAX_SKILL_BYTES || count( $references ) > self::MAX_REFERENCES ) {
			return null;
		}
		$abilities = $this->normalize_dependencies( $required_abilities, '/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}\z/', 50 );
		$plugins   = $this->normalize_dependencies( $required_plugins, '/\A[a-zA-Z0-9][a-zA-Z0-9._-]*(?:\/[a-zA-Z0-9][a-zA-Z0-9._-]*)?\.php\z/', 25 );
		if ( null === $abilities || null === $plugins ) {
			return null;
		}

		$frontmatter = $this->frontmatter( $skill_md );
		if ( null === $frontmatter || $frontmatter['name'] !== $id || ! $this->safe_markdown( $skill_md ) ) {
			return null;
		}

		$total      = strlen( $skill_md );
		$normalized = array();
		$path_keys  = array();
		foreach ( $references as $path => $content ) {
			if ( ! is_string( $path ) || ! is_string( $content ) || ! $this->valid_reference_path( $path ) || strlen( $content ) > self::MAX_REFERENCE_BYTES || ! $this->safe_markdown( $content ) ) {
				return null;
			}
			$path_key = strtolower( $path );
			if ( isset( $path_keys[ $path_key ] ) ) {
				return null;
			}
			$path_keys[ $path_key ] = true;

			$total              += strlen( $content );
			$normalized[ $path ] = $content;
			if ( $total > self::MAX_TOTAL_BYTES ) {
				return null;
			}
		}

		ksort( $normalized, SORT_STRING );
		return array(
			'skill_md'           => $skill_md,
			'references'         => $normalized,
			'name'               => $frontmatter['name'],
			'description'        => $frontmatter['description'],
			'required_abilities' => $abilities,
			'required_plugins'   => $plugins,
		);
	}

	/**
	 * Validate and sort a bounded dependency list while rejecting duplicates.
	 *
	 * @param array<mixed> $values Dependency names.
	 * @param string       $pattern Identifier grammar.
	 * @param int          $maximum Maximum list length.
	 * @return list<string>|null
	 */
	private function normalize_dependencies( array $values, string $pattern, int $maximum ): ?array {
		if ( ! array_is_list( $values ) || count( $values ) > $maximum ) {
			return null;
		}

		$normalized = array();
		$seen       = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > 191 || 1 !== preg_match( $pattern, $value ) ) {
				return null;
			}
			$key = strtolower( $value );
			if ( isset( $seen[ $key ] ) ) {
				return null;
			}
			$seen[ $key ] = true;
			$normalized[] = $value;
		}

		sort( $normalized, SORT_STRING );
		return $normalized;
	}

	/**
	 * Check the Skill identifier grammar and reserve bundled Skill IDs.
	 *
	 * @param string $id Skill ID.
	 */
	public function valid_id( string $id ): bool {
		return 1 === preg_match( '/\Acustom-[a-z0-9]+(?:-[a-z0-9]+)*\z/', $id )
			&& strlen( $id ) <= 64;
	}

	/**
	 * Parse the deliberately restricted, single-line YAML frontmatter subset.
	 *
	 * @param string $content Skill document.
	 * @return array{name:string,description:string}|null
	 */
	private function frontmatter( string $content ): ?array {
		if ( 1 !== preg_match( '/\A---\nname: ([a-z0-9-]{1,64})\ndescription: ([^\r\n]{1,1024})\n---\n/', $content, $matches ) ) {
			return null;
		}

		$description = trim( $matches[2] );
		if ( '' === $description || 1 === preg_match( '/[\x00-\x1f\x7f]/', $description ) || str_contains( $description, ':' ) ) {
			return null;
		}

		return array(
			'name'        => $matches[1],
			'description' => $description,
		);
	}

	/**
	 * Reject executable, network, binary, active HTML, and path-bearing content.
	 *
	 * @param string $content Markdown content.
	 */
	private function safe_markdown( string $content ): bool {
		if ( '' === $content || strlen( $content ) > self::MAX_TOTAL_BYTES || 1 !== preg_match( '//u', $content ) || 1 === preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $content ) ) {
			return false;
		}

		$forbidden = array(
			'/<\s*\/?\s*[a-z][^>]*>/i',
			'/```|~~~/',
			'/\b(?:https?|ftp|file|javascript|data):/i',
			'/!\[[^\]]*\]\s*\(/',
			'/\]\(\s*(?:\/|\\\\|[a-z][a-z0-9+.-]*:|\.\.?\/)/i',
			'/\b(?:curl|wget|sudo|chmod|chown|rm\s+-|bash\b|sh\s+-|powershell|cmd\.exe|mysql\b|psql\b|sqlite3\b|wp\s+eval|php\s+-r|node\s+-e)\b/i',
			'/\b(?:SELECT\s+.+\s+FROM|INSERT\s+INTO|UPDATE\s+.+\s+SET|DELETE\s+FROM|DROP\s+(?:TABLE|DATABASE))\b/i',
			'~(?:^|[\s(])(?:[A-Za-z]:[\\/]|\\\\[^\\/]+[\\/]|/(?:[A-Za-z0-9._-]+/)+(?:[A-Za-z0-9._-]+)?)~m',
			'~(?:^|[\s(])\.\.(?:[\\/]|$)~m',
		);

		foreach ( $forbidden as $pattern ) {
			if ( 1 === preg_match( $pattern, $content ) ) {
				return false;
			}
		}

		$link_count = preg_match_all( '/\]\(([^)\r\n]*)\)/', $content, $links );
		if ( false === $link_count ) {
			return false;
		}
		if ( 0 < $link_count ) {
			foreach ( $links[1] as $target ) {
				if ( ! $this->valid_reference_path( trim( $target ) ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Permit only normalized references/*.md paths, without traversal or collisions.
	 *
	 * @param string $path Relative reference path.
	 */
	private function valid_reference_path( string $path ): bool {
		if ( strlen( $path ) > 180 || ! str_starts_with( $path, 'references/' ) || ! str_ends_with( strtolower( $path ), '.md' ) || str_contains( $path, '\\' ) || str_contains( $path, ':' ) || str_contains( $path, "\0" ) ) {
			return false;
		}

		$parts = explode( '/', $path );
		if ( count( $parts ) < 2 || count( $parts ) > 4 || 'references' !== array_shift( $parts ) ) {
			return false;
		}

		foreach ( $parts as $index => $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || 1 !== preg_match( '/\A[a-zA-Z0-9][a-zA-Z0-9._-]*\z/', $part ) ) {
				return false;
			}
			if ( $index < count( $parts ) - 1 && str_ends_with( strtolower( $part ), '.md' ) ) {
				return false;
			}
		}

		return true;
	}
}

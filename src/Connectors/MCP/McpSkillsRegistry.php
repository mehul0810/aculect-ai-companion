<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\Skills\CustomMcpSkillStore;

/**
 * Serves reviewed, packaged Agent Skills through the stable MCP Skills extension.
 *
 * Skills are declarative guidance only. They do not add an execution path.
 */
final class McpSkillsRegistry {

	private const PAGE_SIZE = 2;
	private McpSkillProviderRegistry $providers;

	public function __construct( ?CustomMcpSkillStore $custom_store = null, ?McpSkillProviderRegistry $providers = null ) {
		$this->providers = $providers ?? new McpSkillProviderRegistry( $custom_store );
	}

	/**
	 * List available skills using the extension's required atomic-entry pagination.
	 *
	 * @param array  $available_abilities Tools exposed to this connection.
	 * @phpstan-param list<string> $available_abilities Tools exposed to this connection.
	 * @param string $cursor              Opaque cursor from a previous page.
	 * @return array<string, mixed>
	 */
	public function list_skills( array $available_abilities, string $cursor = '' ): array {
		$entries = $this->available_entries( $available_abilities );
		$offset  = $this->cursor_offset( $cursor, $entries );
		if ( is_array( $offset ) ) {
			return $offset;
		}

		$page   = array_map( array( $this, 'public_entry' ), array_slice( $entries, $offset, self::PAGE_SIZE ) );
		$result = array( 'skills' => $page );
		if ( $offset + count( $page ) < count( $entries ) ) {
			$result['nextCursor'] = $this->encode_cursor( $offset + count( $page ), $entries );
		}

		return $result;
	}

	/**
	 * Return one complete Skill entry by its SKILL.md URI.
	 *
	 * @param string $uri                  Skill document URI.
	 * @param array  $available_abilities Tools exposed to this connection.
	 * @phpstan-param list<string> $available_abilities Tools exposed to this connection.
	 * @return array<string, mixed>
	 */
	public function get_skill( string $uri, array $available_abilities ): array {
		foreach ( $this->available_entries( $available_abilities ) as $entry ) {
			if ( hash_equals( 'skill://' . $entry['id'] . '/SKILL.md', $uri ) ) {
				return array( 'skill' => $this->public_entry( $entry ) );
			}
		}

		return $this->not_found();
	}

	/**
	 * Read one available packaged SKILL.md as an ordinary MCP resource.
	 *
	 * @param string $uri                  Resource URI.
	 * @param array  $available_abilities Tools exposed to this connection.
	 * @phpstan-param list<string> $available_abilities Tools exposed to this connection.
	 * @return array<string, mixed>
	 */
	public function read_resource( string $uri, array $available_abilities ): array {
		foreach ( $this->available_entries( $available_abilities ) as $entry ) {
			if ( isset( $entry['documents'][ $uri ] ) && is_string( $entry['documents'][ $uri ] ) ) {
				return array(
					'contents' => array(
						array(
							'uri'      => $uri,
							'mimeType' => 'text/markdown',
							'text'     => $entry['documents'][ $uri ],
						),
					),
				);
			}
		}

		return $this->not_found();
	}

	/**
	 * Return standard resource-list descriptors for compatible clients.
	 *
	 * @param array $available_abilities Tools exposed to this connection.
	 * @phpstan-param list<string> $available_abilities Tools exposed to this connection.
	 * @return list<array<string, string>>
	 */
	public function resource_descriptors( array $available_abilities ): array {
		$resources = array();
		foreach ( $this->available_entries( $available_abilities ) as $entry ) {
			foreach ( $entry['resources'] as $resource ) {
				$is_skill_document = str_ends_with( $resource['uri'], '/SKILL.md' );
				$resources[]       = array(
					'uri'         => $resource['uri'],
					'name'        => $is_skill_document ? $entry['frontmatter']['name'] : basename( $resource['uri'] ),
					'description' => $is_skill_document ? $entry['frontmatter']['description'] : 'Reference material for ' . $entry['frontmatter']['name'] . '.',
					'mimeType'    => 'text/markdown',
				);
			}
		}

		return $resources;
	}

	/**
	 * Return source metadata for the local admin catalog without document bodies.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function admin_catalog(): array {
		return $this->providers->admin_catalog();
	}

	/**
	 * Build all well-formed entries available to this connection.
	 *
	 * @param array $available_abilities Tools exposed to this connection.
	 * @phpstan-param list<string> $available_abilities Tools exposed to this connection.
	 * @return list<array<string,mixed>>
	 */
	private function available_entries( array $available_abilities ): array {
		$ability_registry = new AbilitiesRegistry();
		$tool_ids         = array_values( array_unique( array_map( array( $ability_registry, 'tool_name' ), $available_abilities ) ) );
		$entries          = $this->providers->collect( $tool_ids );
		return array_values( array_filter( $entries, static fn ( array $entry ): bool => true === ( $entry['available'] ?? false ) ) );
	}

	/**
	 * Shape one provider record as the stable Skills protocol entry.
	 *
	 * @param array<string,mixed> $entry Validated provider record.
	 * @return array<string,mixed>
	 */
	private function public_entry( array $entry ): array {
		return array(
			'uri'         => 'skill://' . $entry['id'] . '/SKILL.md',
			'frontmatter' => $entry['frontmatter'],
			'resources'   => $entry['resources'],
		);
	}

	/**
	 * Resolve a cursor against the current availability-filtered manifest.
	 *
	 * @param string                    $cursor  Opaque cursor.
	 * @param list<array<string,mixed>> $entries Current entries.
	 * @return int|array<string, string>
	 */
	private function cursor_offset( string $cursor, array $entries ): int|array {
		if ( '' === $cursor ) {
			return 0;
		}
		if ( 256 < strlen( $cursor ) ) {
			return array(
				'error'   => 'invalid_cursor',
				'message' => 'The skill listing cursor exceeds the supported size.',
			);
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Opaque, non-secret MCP pagination cursor.
		$decoded     = base64_decode( $cursor, true );
		$fingerprint = $this->fingerprint( $entries );
		if ( false === $decoded || 1 !== preg_match( '/\Av1:([0-9]+):([a-f0-9]{64})\z/', $decoded, $matches ) || ! hash_equals( $fingerprint, $matches[2] ) ) {
			return array(
				'error'   => 'invalid_cursor',
				'message' => 'The skill listing cursor is invalid or stale. Request the first page again.',
			);
		}

		$offset = (int) $matches[1];
		if ( 0 === $offset || $offset >= count( $entries ) ) {
			return array(
				'error'   => 'invalid_cursor',
				'message' => 'The skill listing cursor is outside the available result range.',
			);
		}

		return $offset;
	}

	/**
	 * Encode a versioned cursor bound to the current manifest.
	 *
	 * @param int                       $offset  Next result offset.
	 * @param list<array<string,mixed>> $entries Current entries.
	 */
	private function encode_cursor( int $offset, array $entries ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque, non-secret MCP pagination cursor.
		return base64_encode( 'v1:' . $offset . ':' . $this->fingerprint( $entries ) );
	}

	/**
	 * Hash the full manifest so a cursor cannot silently page a changed availability set.
	 *
	 * @param list<array<string,mixed>> $entries Manifest entries.
	 */
	private function fingerprint( array $entries ): string {
		$encoded = wp_json_encode( $entries, JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	/**
	 * Return a stable, non-sensitive unknown-skill error.
	 *
	 * @return array<string, string>
	 */
	private function not_found(): array {
		return array(
			'error'   => 'skill_not_found',
			'message' => 'No available skill is served at the requested URI.',
		);
	}
}

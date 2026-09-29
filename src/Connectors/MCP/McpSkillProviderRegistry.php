<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\Skills\CustomMcpSkillStore;
use Aculect\AICompanion\Connectors\MCP\Skills\CustomSkillValidator;

/**
 * Collects and validates declarative Skill sources without granting abilities.
 *
 * Plugin providers register data through the
 * `aculect_ai_companion_mcp_skill_providers` filter. Its value is a map keyed
 * by the exact WordPress plugin basename; each value is a list of records with
 * `id`, `skill_md`, `references`, `required_abilities`, `required_plugins`,
 * and `enabled`. The basename must also be active for the current site or
 * network. Skills are Markdown guidance only: availability never enables a
 * tool or changes a connection's grants. Workspace and remote sources are
 * reserved and are not accepted by this provider.
 */
final class McpSkillProviderRegistry {
	private const MAX_PLUGIN_SKILLS = 100;
	private const MAX_PLUGIN_BYTES  = 2097152;

	private CustomMcpSkillStore $custom_store;
	private CustomSkillValidator $validator;

	public function __construct( ?CustomMcpSkillStore $custom_store = null, ?CustomSkillValidator $validator = null ) {
		$this->custom_store = $custom_store ?? new CustomMcpSkillStore();
		$this->validator    = $validator ?? new CustomSkillValidator();
	}

	/**
	 * Collect validated sources, retaining dependency state for admin display.
	 *
	 * @param list<string>|null $available_tool_ids Exact MCP tool IDs available to the current connection, or null for an admin catalog.
	 * @return list<array<string,mixed>>
	 */
	public function collect( ?array $available_tool_ids = null ): array {
		$entries = $this->core_entries( $available_tool_ids );
		foreach ( $this->custom_store->list() as $summary ) {
			$stored = $this->custom_store->get( $summary['id'] );
			$skill  = $stored['skill'] ?? null;
			if ( ! is_array( $skill ) || ! is_string( $skill['skill_md'] ?? null ) || ! is_array( $skill['references'] ?? null ) ) {
				continue;
			}

			$validated = $this->validator->validate( $summary['id'], $skill['skill_md'], $skill['references'], $summary['required_abilities'], $summary['required_plugins'] );
			if ( null === $validated || ! hash_equals( (string) $summary['digest'], (string) ( $skill['digest'] ?? '' ) ) ) {
				continue;
			}
			$entries[] = $this->entry(
				$summary['id'],
				'custom',
				'custom',
				$validated,
				(bool) $summary['enabled'],
				(int) $summary['version'],
				(string) $summary['digest'],
				$available_tool_ids
			);
		}

		$providers = apply_filters( 'aculect_ai_companion_mcp_skill_providers', array(), array( 'site_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 ) );
		if ( ! is_array( $providers ) || 100 < count( $providers ) || ! $this->within_plugin_budget( $providers ) ) {
			return $this->without_collisions( $entries );
		}

		foreach ( $providers as $provider_basename => $skills ) {
			if ( ! is_string( $provider_basename ) || ! $this->valid_plugin_basename( $provider_basename ) || ! $this->is_active_plugin( $provider_basename ) || ! is_array( $skills ) || ! array_is_list( $skills ) || 100 < count( $skills ) ) {
				continue;
			}

			foreach ( $skills as $declaration ) {
				if ( ! is_array( $declaration ) || ! $this->has_only_fields( $declaration, array( 'id', 'skill_md', 'references', 'required_abilities', 'required_plugins', 'enabled' ) ) ) {
					continue;
				}
				$id         = $declaration['id'] ?? null;
				$skill_md   = $declaration['skill_md'] ?? null;
				$references = $declaration['references'] ?? null;
				$abilities  = $declaration['required_abilities'] ?? array();
				$plugins    = $declaration['required_plugins'] ?? array();
				$enabled    = $declaration['enabled'] ?? true;
				if ( ! is_string( $id ) || ! is_string( $skill_md ) || ! is_array( $references ) || ! is_array( $abilities ) || ! is_array( $plugins ) || ! is_bool( $enabled ) ) {
					continue;
				}

				$validated = $this->validator->validate_plugin( $id, $skill_md, $references, $abilities, $plugins );
				if ( null === $validated ) {
					continue;
				}
				$entries[] = $this->entry( $id, 'plugin', $provider_basename, $validated, $enabled, 1, $this->digest( $validated ), $available_tool_ids );
			}
		}

		return $this->without_collisions( $entries );
	}

	/**
	 * Bound the whole plugin catalog before materializing resource manifests.
	 *
	 * @param array<mixed> $providers Filtered provider declarations.
	 */
	private function within_plugin_budget( array $providers ): bool {
		$declarations = 0;
		$bytes        = 0;
		foreach ( $providers as $skills ) {
			if ( ! is_array( $skills ) || ! array_is_list( $skills ) ) {
				continue;
			}
			$declarations += count( $skills );
			if ( self::MAX_PLUGIN_SKILLS < $declarations ) {
				return false;
			}
			foreach ( $skills as $skill ) {
				if ( ! is_array( $skill ) ) {
					continue;
				}
				$bytes += is_string( $skill['skill_md'] ?? null ) ? strlen( $skill['skill_md'] ) : 0;
				if ( is_array( $skill['references'] ?? null ) ) {
					foreach ( $skill['references'] as $reference ) {
						$bytes += is_string( $reference ) ? strlen( $reference ) : 0;
					}
				}
				if ( self::MAX_PLUGIN_BYTES < $bytes ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Return source metadata without Markdown bodies for the admin catalog.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function admin_catalog(): array {
		$catalog = array();
		foreach ( $this->collect() as $entry ) {
			$catalog[] = array(
				'id'                 => $entry['id'],
				'provider_id'        => $entry['provider_id'],
				'source'             => $entry['source'],
				'name'               => $entry['frontmatter']['name'],
				'description'        => $entry['frontmatter']['description'],
				'required_abilities' => $entry['required_abilities'],
				'required_plugins'   => $entry['required_plugins'],
				'version'            => $entry['version'],
				'digest'             => $entry['digest'],
				'enabled'            => $entry['enabled'],
				'available'          => $entry['available'],
				'blocked_by'         => $entry['blocked_by'],
			);
		}

		return $catalog;
	}

	/**
	 * Read packaged core Skills from fixed plugin-owned paths.
	 *
	 * @param list<string>|null $available_tool_ids Exact MCP tool IDs available to the current connection.
	 * @return list<array<string,mixed>>
	 */
	private function core_entries( ?array $available_tool_ids ): array {
		$definitions = array(
			'wordpress-site-audit'      => array( 'site_workflow.audit' ),
			'content-optimization'      => array( 'content_search.items', 'content.get_item' ),
			'wordpress-troubleshooting' => array( 'site.get_info', 'site.get_health' ),
		);
		$entries     = array();
		foreach ( $definitions as $id => $required ) {
			$content     = $this->core_skill_content( $id );
			$frontmatter = null === $content ? null : $this->core_frontmatter( $content, $id );
			if ( null === $content || null === $frontmatter ) {
				continue;
			}
			$validated = array(
				'skill_md'           => $content,
				'references'         => array(),
				'name'               => $frontmatter['name'],
				'description'        => $frontmatter['description'],
				'required_abilities' => $required,
				'required_plugins'   => array(),
			);
			$entries[] = $this->entry( $id, 'core', 'core', $validated, true, 1, $this->digest( $validated ), $available_tool_ids );
		}

		return $entries;
	}

	/**
	 * Build one canonical internal source record.
	 *
	 * @param string              $id Skill ID.
	 * @param string              $source Fixed provider source.
	 * @param string              $provider_id Stable source/provider identifier.
	 * @param array<string,mixed> $validated Validated Markdown data.
	 * @param bool                $enabled Whether the provider declared the Skill enabled.
	 * @param int                 $version Content version.
	 * @param string              $digest Content digest.
	 * @param list<string>|null   $available_tool_ids Exact MCP tool IDs available to the current connection.
	 * @return array<string,mixed>
	 */
	private function entry( string $id, string $source, string $provider_id, array $validated, bool $enabled, int $version, string $digest, ?array $available_tool_ids ): array {
		$documents = array( 'skill://' . $id . '/SKILL.md' => $validated['skill_md'] );
		foreach ( $validated['references'] as $path => $text ) {
			$documents[ 'skill://' . $id . '/' . $path ] = $text;
		}
		$blocked_by  = array();
		$available   = $enabled;
		$tool_ids    = array_fill_keys( $available_tool_ids ?? array(), true );
		$abilities   = new AbilitiesRegistry();
		$definitions = $abilities->definitions();
		foreach ( $validated['required_abilities'] as $ability_id ) {
			if ( ! array_key_exists( $ability_id, $definitions ) ) {
				$available    = false;
				$blocked_by[] = 'ability:' . $ability_id;
				continue;
			}
			$tool_name = $abilities->tool_name( $ability_id );
			if ( null !== $available_tool_ids && ! isset( $tool_ids[ $tool_name ] ) ) {
				$available    = false;
				$blocked_by[] = 'ability:' . $ability_id;
			}
		}
		foreach ( $validated['required_plugins'] as $plugin ) {
			if ( ! $this->is_active_plugin( $plugin ) ) {
				$available    = false;
				$blocked_by[] = 'plugin:' . $plugin;
			}
		}

		$resources = array();
		foreach ( $documents as $uri => $text ) {
			$resources[] = array(
				'uri'    => $uri,
				'digest' => 'sha256:' . hash( 'sha256', $text ),
				'size'   => strlen( $text ),
			);
		}

		return array(
			'id'                 => $id,
			'provider_id'        => $provider_id,
			'source'             => $source,
			'frontmatter'        => array(
				'name'        => $validated['name'],
				'description' => $validated['description'],
			),
			'required_abilities' => $validated['required_abilities'],
			'required_plugins'   => $validated['required_plugins'],
			'documents'          => $documents,
			'resources'          => $resources,
			'enabled'            => $enabled,
			'available'          => $available,
			'blocked_by'         => $blocked_by,
			'version'            => $version,
			'digest'             => $digest,
		);
	}

	/**
	 * Calculate a stable digest over normalized documents and dependency declarations.
	 *
	 * @param array<string,mixed> $validated Validated Skill content.
	 */
	private function digest( array $validated ): string {
		$canonical = wp_json_encode( array( $validated['skill_md'], $validated['references'], $validated['required_abilities'], $validated['required_plugins'] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return 'sha256:' . hash( 'sha256', false === $canonical ? '' : $canonical );
	}

	private function is_active_plugin( string $basename ): bool {
		$site_active = get_option( 'active_plugins', array() );
		if ( is_array( $site_active ) && in_array( $basename, $site_active, true ) ) {
			return true;
		}
		if ( ! is_multisite() ) {
			return false;
		}
		$network_active = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $network_active ) && array_key_exists( $basename, $network_active );
	}

	private function valid_plugin_basename( string $basename ): bool {
		return strlen( $basename ) <= 191 && 1 === preg_match( '/\A[a-zA-Z0-9][a-zA-Z0-9._-]*(?:\/[a-zA-Z0-9][a-zA-Z0-9._-]*)?\.php\z/', $basename );
	}

	/**
	 * Reject unexpected provider declaration fields.
	 *
	 * @param array<string|int,mixed> $value Provider declaration.
	 * @param string[]                $allowed Allowed declaration fields.
	 */
	private function has_only_fields( array $value, array $allowed ): bool {
		return array() === array_diff( array_keys( $value ), $allowed );
	}

	/**
	 * Fail closed when two providers claim the same deterministic Skill ID.
	 *
	 * @param list<array<string,mixed>> $entries Collected source records.
	 * @return list<array<string,mixed>>
	 */
	private function without_collisions( array $entries ): array {
		$counts = array_count_values( array_column( $entries, 'id' ) );
		return array_values( array_filter( $entries, static fn ( array $entry ): bool => 1 === ( $counts[ $entry['id'] ] ?? 0 ) ) );
	}

	/**
	 * Read a known core Skill without accepting caller-selected filesystem paths.
	 *
	 * @param string $id Bundled Skill ID.
	 */
	private function core_skill_content( string $id ): ?string {
		if ( ! defined( 'ACULECT_AI_COMPANION_PLUGIN_DIR' ) ) {
			return null;
		}
		$path = ACULECT_AI_COMPANION_PLUGIN_DIR . 'skills/core/' . $id . '/SKILL.md';
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a fixed plugin-packaged Skill path only.
		return false === $content ? null : $content;
	}

	/**
	 * Parse the constrained core Skill frontmatter.
	 *
	 * @param string $content SKILL.md contents.
	 * @param string $id Expected Skill identifier.
	 * @return array{name:string,description:string}|null
	 */
	private function core_frontmatter( string $content, string $id ): ?array {
		if ( 1 !== preg_match( '/\A---\r?\n(?<yaml>.*?)\r?\n---\r?\n/s', $content, $matches ) ) {
			return null;
		}
		$lines = preg_split( '/\r?\n/', (string) $matches['yaml'] );
		if ( ! is_array( $lines ) || 2 !== count( $lines ) || 1 !== preg_match( '/\Aname: ([a-z0-9]+(?:-[a-z0-9]+)*)\z/', $lines[0], $name_match ) || 1 !== preg_match( '/\Adescription: ([^\r\n]{1,1024})\z/', $lines[1], $description_match ) || $id !== $name_match[1] ) {
			return null;
		}
		return array(
			'name'        => $name_match[1],
			'description' => $description_match[1],
		);
	}
}

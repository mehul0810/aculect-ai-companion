<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Skills;

use Aculect\AICompanion\Connectors\MCP\McpSkillProviderRegistry;

/**
 * Private, schema-neutral inventory of explicitly selected packaged Skill bytes.
 *
 * This is not a public endpoint or an authorization decision. A future public
 * adapter must separately review publication, routing, cache and wire schema.
 */
final class AgentSkillsDiscoveryCatalog {

	private McpSkillProviderRegistry $providers;

	public function __construct( ?McpSkillProviderRegistry $providers = null ) {
		$this->providers = $providers ?? new McpSkillProviderRegistry();
	}

	/**
	 * Describe exact packaged documents without site, user, or connection state.
	 *
	 * An empty allowlist produces no candidates. Unknown or duplicate IDs fail
	 * closed rather than silently changing a future public manifest.
	 *
	 * @param array $approved_ids Explicitly reviewed bundled Skill IDs.
	 * @phpstan-param list<string> $approved_ids
	 * @return list<array{id:string,content:string,sha256:string,byte_count:int}>
	 */
	public function documents( array $approved_ids = array() ): array {
		if ( ! array_is_list( $approved_ids ) || count( $approved_ids ) > 10 ) {
			return array();
		}
		$approved = array();
		foreach ( $approved_ids as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $id ) || isset( $approved[ $id ] ) ) {
				return array();
			}
			$approved[ $id ] = true;
		}
		if ( array() === $approved ) {
			return array();
		}

		$documents = array();
		foreach ( $this->providers->packaged_core_sources() as $source ) {
			$id       = $source['id'] ?? null;
			$uri      = is_string( $id ) ? 'skill://' . $id . '/SKILL.md' : '';
			$content  = $source['documents'][ $uri ] ?? null;
			$resource = $source['resources'][0] ?? null;
			if ( ! is_string( $id ) || ! isset( $approved[ $id ] ) || 'core' !== ( $source['source'] ?? null ) || 'core' !== ( $source['provider_id'] ?? null ) || ! is_string( $content ) || ! is_array( $resource ) || ( $resource['uri'] ?? null ) !== $uri ) {
				continue;
			}
			$digest = hash( 'sha256', $content );
			if ( ( $resource['digest'] ?? null ) !== 'sha256:' . $digest || ( $resource['size'] ?? null ) !== strlen( $content ) ) {
				return array();
			}
			$documents[] = array(
				'id'         => $id,
				'content'    => $content,
				'sha256'     => $digest,
				'byte_count' => strlen( $content ),
			);
			unset( $approved[ $id ] );
		}
		return array() === $approved ? $documents : array();
	}
}

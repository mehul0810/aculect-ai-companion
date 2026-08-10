<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Builds version-aware MCP result payloads without negotiating a version.
 */
final class McpResultPolicy {

	private const PUBLIC_TTL_MS = 3600000;

	/**
	 * Shape an already-authorized operation result for an explicit protocol.
	 *
	 * Legacy results are returned byte-shape compatible. Current results use
	 * conservative private/no-cache defaults unless the operation is proven
	 * authorization independent by the caller.
	 *
	 * @param string               $protocol_version          Resolved protocol version.
	 * @param string               $method                    JSON-RPC method.
	 * @param array<string, mixed> $result                    Operation result.
	 * @param bool                 $authorization_independent Whether a list is public and user independent.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When the protocol version is unknown.
	 */
	public function shape( string $protocol_version, string $method, array $result, bool $authorization_independent = false ): array {
		if ( McpProtocolVersion::LEGACY === $protocol_version ) {
			return $result;
		}

		if ( McpProtocolVersion::CURRENT !== $protocol_version ) {
			throw new \InvalidArgumentException( 'Unsupported MCP protocol version.' );
		}

		$shaped = array( 'resultType' => 'complete' ) + $result;
		$cache  = $this->cache_policy( $method, $authorization_independent );
		if ( null !== $cache ) {
			$shaped['ttlMs']      = $cache['ttlMs'];
			$shaped['cacheScope'] = $cache['cacheScope'];
		}

		return $shaped;
	}

	/**
	 * Return an honest cache policy for current-protocol cacheable methods.
	 *
	 * @param string $method                    JSON-RPC method.
	 * @param bool   $authorization_independent Whether a list is public and user independent.
	 * @return array{ttlMs: int, cacheScope: string}|null
	 */
	private function cache_policy( string $method, bool $authorization_independent ): ?array {
		if ( 'server/discover' === $method && $authorization_independent ) {
			return array(
				'ttlMs'      => self::PUBLIC_TTL_MS,
				'cacheScope' => 'public',
			);
		}

		if ( 'resources/list' === $method && $authorization_independent ) {
			return array(
				'ttlMs'      => self::PUBLIC_TTL_MS,
				'cacheScope' => 'public',
			);
		}

		if ( in_array( $method, array( 'server/discover', 'tools/list', 'resources/list', 'resources/read', 'prompts/list' ), true ) ) {
			return array(
				'ttlMs'      => 0,
				'cacheScope' => 'private',
			);
		}

		return null;
	}
}

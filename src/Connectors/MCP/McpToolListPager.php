<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Builds deterministic, cursor-paginated MCP tools/list payloads.
 *
 * @internal
 */
final class McpToolListPager {

	/**
	 * Tools returned per tools/list page. Below typical client truncation
	 * thresholds while keeping most installs single-page.
	 */
	private const PAGE_SIZE = 60;

	/**
	 * Return the current tools/list page size for diagnostics.
	 */
	public static function page_size(): int {
		return self::PAGE_SIZE;
	}

	/**
	 * Return one deterministic page from a complete tools/list descriptor set.
	 *
	 * @param list<array<string, mixed>> $tools  Complete tool descriptors.
	 * @param string                     $cursor Opaque cursor from a previous page.
	 * @return array{tools: list<array<string, mixed>>, nextCursor?: string, _meta: array<string, int|string|bool>}
	 */
	public function page( array $tools, string $cursor = '' ): array {
		$fingerprint = $this->fingerprint( $tools );
		$cursor_data = $this->cursor_data( $cursor, $fingerprint );
		$offset      = $cursor_data['fingerprint_matches'] ? $cursor_data['offset'] : 0;
		$page        = array_slice( $tools, $offset, self::PAGE_SIZE );
		$result      = array(
			'tools' => $page,
			'_meta' => array(
				'aculect/toolListFingerprint' => $fingerprint,
				'aculect/toolListVersion'     => ACULECT_AI_COMPANION_VERSION,
				'aculect/totalTools'          => count( $tools ),
				'aculect/pageSize'            => self::PAGE_SIZE,
				'aculect/pageOffset'          => $offset,
				'aculect/pageToolCount'       => count( $page ),
				'aculect/cursorValid'         => $cursor_data['fingerprint_matches'],
			),
		);

		if ( $offset + count( $page ) < count( $tools ) ) {
			$result['nextCursor']                           = $this->encode_cursor( $offset + count( $page ), $fingerprint );
			$result['_meta']['aculect/nextCursorOffset']    = $offset + count( $page );
			$result['_meta']['aculect/nextCursorVersioned'] = true;
		}

		return $result;
	}

	/**
	 * Return a deterministic, support-safe metadata fingerprint.
	 *
	 * @param list<array<string, mixed>> $tools Complete tool descriptors.
	 */
	private function fingerprint( array $tools ): string {
		$json = wp_json_encode(
			array(
				'plugin_version' => ACULECT_AI_COMPANION_VERSION,
				'tools'          => $this->canonicalize( $tools ),
			),
			JSON_UNESCAPED_SLASHES
		);

		return hash( 'sha256', false === $json ? '' : $json );
	}

	/**
	 * Keep cursor fingerprints stable without exposing request, token, or content data.
	 *
	 * @param list<array<string, mixed>> $tools Complete tool descriptors.
	 * @return list<array<string, mixed>>
	 */
	private function canonicalize( array $tools ): array {
		return array_values(
			array_map(
				static function ( array $tool ): array {
					return array(
						'name'            => (string) ( $tool['name'] ?? '' ),
						'title'           => (string) ( $tool['title'] ?? '' ),
						'description'     => (string) ( $tool['description'] ?? '' ),
						'inputSchema'     => $tool['inputSchema'] ?? array(),
						'outputSchema'    => $tool['outputSchema'] ?? array(),
						'annotations'     => $tool['annotations'] ?? array(),
						'securitySchemes' => $tool['securitySchemes'] ?? array(),
						'_meta'           => $tool['_meta'] ?? array(),
					);
				},
				$tools
			)
		);
	}

	/**
	 * Encode a versioned cursor with its tools/list fingerprint.
	 *
	 * @param int    $offset      Next result offset.
	 * @param string $fingerprint Current full tools/list fingerprint.
	 */
	private function encode_cursor( int $offset, string $fingerprint ): string {
		$json = wp_json_encode(
			array(
				'v'  => 2,
				'o'  => max( 0, $offset ),
				'fp' => $fingerprint,
			),
			JSON_UNESCAPED_SLASHES
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque MCP pagination cursor, not obfuscation.
		return base64_encode( false === $json ? (string) max( 0, $offset ) : $json );
	}

	/**
	 * Decode an opaque cursor into an offset plus fingerprint match status.
	 *
	 * @param string $cursor      Opaque cursor value.
	 * @param string $fingerprint Current full tools/list fingerprint.
	 * @return array{offset:int, fingerprint_matches:bool}
	 */
	private function cursor_data( string $cursor, string $fingerprint ): array {
		if ( '' === $cursor ) {
			return array(
				'offset'              => 0,
				'fingerprint_matches' => true,
			);
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Opaque MCP pagination cursor, not obfuscation.
		$decoded = base64_decode( $cursor, true );

		if ( false === $decoded ) {
			return array(
				'offset'              => 0,
				'fingerprint_matches' => false,
			);
		}

		$payload = json_decode( $decoded, true );
		if ( is_array( $payload ) ) {
			return array(
				'offset'              => absint( $payload['o'] ?? 0 ),
				'fingerprint_matches' => hash_equals( $fingerprint, (string) ( $payload['fp'] ?? '' ) ),
			);
		}

		return array(
			'offset'              => max( 0, absint( $decoded ) ),
			'fingerprint_matches' => true,
		);
	}
}

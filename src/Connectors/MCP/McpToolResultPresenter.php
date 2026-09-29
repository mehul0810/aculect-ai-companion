<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Present a tool result to text-only and structured MCP clients.
 */
final class McpToolResultPresenter {
	/**
	 * Present a tool result with a plain-text fallback.
	 *
	 * @param array<string, mixed> $result Ability result.
	 * @return array<string, mixed>
	 */
	public function present( array $result ): array {
		$text = (string) wp_json_encode( $result );
		if ( McpAppsPostUpdateResult::SCHEMA === ( $result['schema'] ?? null ) ) {
			$fallback = McpAppsPostUpdateResult::text_fallback( $result );
			if ( null !== $fallback ) {
				$text = $fallback;
			}
		}

		return array(
			'content'           => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'structuredContent' => $result,
		);
	}
}

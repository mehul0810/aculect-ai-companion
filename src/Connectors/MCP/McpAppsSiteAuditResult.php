<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Presents the existing bounded read-only audit without performing more reads.
 */
final class McpAppsSiteAuditResult {

	/**
	 * Render audit evidence and next steps for hosts without Apps support.
	 *
	 * @param array<string, mixed> $result Existing workflow result.
	 */
	public static function text_fallback( array $result ): ?string {
		if ( 'site_workflow_audit' !== ( $result['workflow'] ?? null ) || 'ready' !== ( $result['status'] ?? null ) || ! empty( $result['error'] ) ) {
			return null;
		}

		$lines    = array( 'WordPress site audit (read-only).', 'Configuration and cached signals only. External reachability, OAuth connectivity, and update freshness were not verified.' );
		$findings = is_array( $result['findings'] ?? null ) ? array_slice( $result['findings'], 0, 20 ) : array();
		foreach ( $findings as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$lines[]  = self::text( $finding['title'] ?? '' ) . ' — ' . self::text( $finding['severity'] ?? 'unknown' );
			$evidence = is_array( $finding['evidence'] ?? null ) ? array_slice( $finding['evidence'], 0, 20, true ) : array();
			foreach ( $evidence as $key => $value ) {
				$lines[] = '  ' . self::text( $key ) . ': ' . self::text( $value );
			}
			$lines[] = '  Next step: ' . self::text( $finding['recommended_action'] ?? '' );
			$lines[] = '  Suggested read tool: ' . self::text( $finding['required_tool'] ?? '' );
		}
		$lines[] = 'Recommended next steps:';
		$actions = is_array( $result['next_actions'] ?? null ) ? array_slice( $result['next_actions'], 0, 20 ) : array();
		foreach ( $actions as $action ) {
			if ( is_array( $action ) ) {
				$lines[] = '- ' . self::text( $action['action'] ?? '' ) . ' (' . self::text( $action['tool'] ?? '' ) . ')';
			}
		}
		$lines[] = 'No changes were made. Review any proposed change separately using the existing tool confirmation flow.';
		return implode( "\n", $lines );
	}

	/**
	 * Format bounded scalar evidence without interpreting markup.
	 *
	 * @param mixed $value Evidence field.
	 */
	private static function text( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		return is_scalar( $value ) ? substr( sanitize_text_field( (string) $value ), 0, 2000 ) : 'unknown';
	}
}

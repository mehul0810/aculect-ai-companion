<?php
/**
 * Native Tools handoffs without personal data or file transport.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Leaves uploads, downloads and verified destructive actions in native admin.
 */
final class ToolsHandoffAbilities {

	/**
	 * Return an authenticated native screen, never an action or download URL.
	 *
	 * @param array<string, mixed> $args Fixed target identifier only.
	 * @return array<string, mixed>
	 */
	public function prepare( array $args ): array {
		$targets = array(
			'import'         => array( 'import.php', 'import' ),
			'export'         => array( 'export.php', 'export' ),
			'privacy_export' => array( 'export-personal-data.php', 'export_others_personal_data' ),
			'privacy_erase'  => array( 'erase-personal-data.php', 'erase_others_personal_data' ),
			'site_health'    => array( 'site-health.php', 'view_site_health_checks' ),
		);
		$target  = $args['target'] ?? null;
		if ( ! is_string( $target ) || ! isset( $targets[ $target ] ) || array_diff( array_keys( $args ), array( 'target' ) ) ) {
			return array( 'error' => 'invalid_target' );
		}
		list( $page, $capability ) = $targets[ $target ];
		if ( ! current_user_can( $capability ) ) {
			return array( 'error' => 'forbidden' );
		}
		return array(
			'status'               => 'manual_action_required',
			'target'               => $target,
			'url'                  => admin_url( $page ),
			'operation_executed'   => false,
			'completion_verified'  => false,
			'private_input_policy' => 'The user must select files, enter personal information, confirm requests, and download archives manually in WordPress. Do not enter, read, capture, or transfer these inputs or files through AI chat or browser automation.',
			'guidance'             => match ( $target ) {
				'import' => 'Choose a native importer, select the file, review author and attachment mappings, then execute and inspect progress in WordPress.',
				'export' => 'Choose content filters and download the WordPress WXR export manually. This is not a full database and files backup.',
				'privacy_export' => 'Use the native verified privacy request and exporter workflow. Keep the requester identity and generated archive outside AI chat.',
				'privacy_erase' => 'Use native requester verification and explicitly confirm erasure in WordPress. Review retained items and completion there; erasure may be irreversible.',
				default => 'Open Status for native tests or Info for diagnostics. Review and redact private details before sharing.',
			},
		);
	}
}

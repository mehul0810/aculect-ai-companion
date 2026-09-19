<?php
/**
 * Explicit execution boundaries for native administrative tools.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Distinguishes a discoverable page from an executable ability.
 */
final class ToolsOperationPolicy {

	/**
	 * Describe the execution policy without changing native navigation access.
	 *
	 * @param string $slug Native admin page slug.
	 * @return array<string, mixed>
	 */
	public static function for_page( string $slug ): array {
		$page = explode( '?', $slug, 2 )[0];
		if ( in_array( $page, array( 'theme-editor.php', 'plugin-editor.php', 'ms-delete-site.php' ), true ) ) {
			return array(
				'status'            => 'not_allowed',
				'execution_allowed' => false,
				'message'           => 'This action is not allowed through Aculect abilities. We do not recommend editing theme or plugin files or deleting a site using abilities. Use WordPress administration manually if necessary.',
			);
		}
		return array(
			'status'            => 'navigation_only',
			'execution_allowed' => false,
			'message'           => 'Navigation does not authorize execution. Use a separately available typed ability or the native WordPress administration page.',
		);
	}
}

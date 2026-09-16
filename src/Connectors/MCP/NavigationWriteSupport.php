<?php
/**
 * Implemented navigation write surfaces, not a grant of caller permission.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Keeps discovery aligned with the guarded navigation tools. */
final class NavigationWriteSupport {

	/**
	 * Preserve existing discovery keys while naming the separate write paths.
	 *
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		return array(
			'implemented'                          => true,
			'current_slice'                        => 'existing_items_locations_and_database_navigation',
			'block_writes_implemented'             => true,
			'location_writes_implemented'          => true,
			'read_before_write'                    => 'navigation_read_item',
			'update_tool'                          => 'navigation_update_item',
			'location_read_tool'                   => 'navigation_read_location_context',
			'location_update_tool'                 => 'navigation_assign_location',
			'block_read_tool'                      => 'site_editor_read_record',
			'block_update_tool'                    => 'site_editor_update_record',
			'block_recovery_tool'                  => 'site_editor_restore_record',
			'block_deletion_tool'                  => 'site_editor_delete_record',
			'menu_deletion_read_tool'              => 'navigation_inspect_menu_deletion',
			'menu_deletion_tool'                   => 'navigation_delete_menu',
			'classic_location_reassignment'        => 'explicit_only_with_confirmation_and_audit',
			'block_navigation_write_model'         => 'existing_database_record_native_block_allowlist',
			'preserve_unknown_custom_blocks_attrs' => false,
			'validate_parsed_block_structure'      => true,
			'raw_string_navigation_edits_allowed'  => false,
			'fail_closed_with_recovery_guidance'   => true,
			'creation_implemented'                 => false,
			'deletion_implemented'                 => true,
			'deletion_policy'                      => 'Explicit confirmation required. Classic menus must be unassigned and pass bounded ownership checks; database editor records use enabled native trash only.',
			'authorization'                        => 'Implementation flags are not permissions. Consult capabilities; OAuth scopes, role policy, enabled tools, native capabilities and runtime checks still apply.',
		);
	}
}

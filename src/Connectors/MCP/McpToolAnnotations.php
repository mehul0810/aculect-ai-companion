<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Builds provider-facing behavioral hints without granting tool access.
 */
final class McpToolAnnotations {

	/**
	 * Return annotations for one registered ability.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 * @return array<string, bool>
	 */
	public function for_module( AbilityModuleInterface $module ): array {
		$risk = AbilityExecutionGateway::tool_risk_level( $module->id(), array() );

		return array(
			'readOnlyHint'    => $module->is_read_only(),
			'destructiveHint' => in_array( $risk, array( 'destructive', 'system' ), true ),
			'idempotentHint'  => in_array( $module->id(), array( 'content_index.refresh_batch', 'memory.save', 'memory.bootstrap' ), true ),
			'openWorldHint'   => $this->interacts_with_open_world( $module->id() ),
		);
	}

	/**
	 * Identify tools that read or change systems beyond local plugin state.
	 *
	 * @param string $ability_id Internal ability ID.
	 */
	private function interacts_with_open_world( string $ability_id ): bool {
		return in_array(
			$ability_id,
			array(
				'content.create_item',
				'content.update_item',
				'content.update_block',
				'content_media.search_cc0_images',
				'content_media.apply_image',
				'comments.create_item',
				'comments.update_item',
				'comments.bulk_update',
				'media.upload_item',
				'media.upload_image_data',
				'plugin_lifecycle.install_plugin',
				'plugin_lifecycle.update_plugin',
				'plugin_lifecycle.activate_plugin',
				'plugin_lifecycle.deactivate_plugin',
				'theme_lifecycle.switch_theme',
				'redirects.create',
				'wp_abilities.run',
			),
			true
		);
	}
}

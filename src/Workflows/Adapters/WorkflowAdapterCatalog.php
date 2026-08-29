<?php
/**
 * Closed catalog of workflow adapters over existing abilities.
 *
 * @package Aculect\AICompanion\Workflows\Adapters
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Adapters;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;

/**
 * Composes the supported content, SEO, media, and discovery adapters.
 *
 * @internal This catalog is an internal composition boundary. It does not
 * register public REST, MCP, or WordPress Abilities API surfaces.
 */
final class WorkflowAdapterCatalog {

	/**
	 * Return all currently supported adapter implementations.
	 *
	 * @param AbilitiesRegistry|null $abilities Existing ability registry.
	 * @return list<WorkflowAdapterInterface>
	 */
	public static function adapters( ?AbilitiesRegistry $abilities = null ): array {
		$abilities = $abilities ?? new AbilitiesRegistry();

		return array_merge(
			array(
				new WordPressReadAdapter( $abilities ),
				new ContentPlannerAdapter( $abilities ),
			),
			self::native_adapters( $abilities )
		);
	}

	/**
	 * Return detached descriptors for the closed adapter catalog.
	 *
	 * @param AbilitiesRegistry|null $abilities Existing ability registry.
	 * @return list<WorkflowAdapterDescriptor>
	 */
	public static function descriptors( ?AbilitiesRegistry $abilities = null ): array {
		$descriptors = array();
		foreach ( self::adapters( $abilities ) as $adapter ) {
			$descriptors[] = new WorkflowAdapterDescriptor(
				$adapter->adapter_id(),
				$adapter->adapter_version(),
				$adapter->ability_id(),
				$adapter->kind(),
				$adapter->is_read_only(),
				$adapter->required_capabilities(),
				$adapter->input_schema(),
				$adapter->output_schema()
			);
		}

		return $descriptors;
	}

	/**
	 * Return native adapters for existing first-party ability modules.
	 *
	 * @param AbilitiesRegistry $abilities Existing ability registry.
	 * @return list<WorkflowAdapterInterface>
	 */
	private static function native_adapters( AbilitiesRegistry $abilities ): array {
		$read  = static fn ( string $adapter_id, string $workflow_id, string $internal_id, array $capabilities = array() ): NativeAbilityWorkflowAdapter => new NativeAbilityWorkflowAdapter( $adapter_id, 1, $workflow_id, $internal_id, 'read', true, $capabilities, null, $abilities );
		$write = static fn ( string $adapter_id, string $workflow_id, string $internal_id, array $capabilities = array( 'edit_posts' ) ): NativeAbilityWorkflowAdapter => new NativeAbilityWorkflowAdapter( $adapter_id, 1, $workflow_id, $internal_id, 'write', false, $capabilities, null, $abilities );

		return array(
			$read( 'wordpress_content_list', 'content/list-items', 'content.list_items', array( 'read_post' ) ),
			$read( 'wordpress_content_seo', 'content/get-seo', 'content.get_seo', array( 'read_post' ) ),
			$read( 'wordpress_media_list', 'media/list-items', 'media.list_items', array( 'upload_files' ) ),
			$read( 'wordpress_media_get', 'media/get-item', 'media.get_item', array( 'upload_files' ) ),
			$read( 'wordpress_media_audit', 'media/audit-usage', 'media.audit_usage', array( 'upload_files' ) ),
			$read( 'wordpress_content_search', 'content/search-items', 'content_search.items', array( 'read_post' ) ),
			$read( 'wordpress_content_search_chunks', 'content/search-chunks', 'content_search.chunks', array( 'read_post' ) ),
			$read( 'wordpress_content_related', 'content/find-related', 'content_find.related', array( 'read_post' ) ),
			$read( 'wordpress_internal_links', 'content/find-internal-links', 'content_find.internal_links', array( 'read_post' ) ),
			$write( 'wordpress_content_create', 'content/create-item', 'content.create_item' ),
			$write( 'wordpress_content_update', 'content/update-item', 'content.update_item', array( 'edit_post' ) ),
			$write( 'wordpress_content_block_update', 'content/update-block', 'content.update_block', array( 'edit_post' ) ),
			$write( 'wordpress_seo_update', 'content/update-seo', 'content.update_seo', array( 'edit_post' ) ),
			$write( 'wordpress_media_update', 'media/update-item', 'media.update_item', array( 'upload_files' ) ),
			$write( 'wordpress_media_upload', 'media/upload-item', 'media.upload_item', array( 'upload_files' ) ),
			$write( 'wordpress_content_media', 'content-media/apply-image', 'content_media.apply_image', array( 'edit_post', 'upload_files' ) ),
		);
	}
}

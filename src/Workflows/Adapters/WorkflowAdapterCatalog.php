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
	 * Contract fingerprints pinned to native adapter version 1.
	 *
	 * Each value covers the native ID, read-only flag, OAuth scopes, input
	 * schema, and adapter output schema. A changed native contract must update
	 * the adapter version and this explicit map before a persisted plan can run.
	 *
	 * @var array<string, non-empty-string>
	 */
	private const NATIVE_CONTRACT_HASHES = array(
		'content.list_items'          => 'b870ffdfee69897fe9ce5423910ff6180a80af87f1ded4b71e71d5339e262745',
		'content.get_seo'             => 'c8c5ab4926a78ab101f1f61ec54bd1442c0a9132c2eee7169746d8ba7f8aa414',
		'media.list_items'            => 'a6fef8c71eaff52f0c4b8e7bd4ca5b56f5c4e8eea1399bc93292ef7280049b4e',
		'media.get_item'              => 'a23967081cf81b910f88650bfd83ee777b21e3ed59756329f516cccf3f7f98a8',
		'media.audit_usage'           => 'df5a94c8d2d212e9cafb531e7b247bff78b7416430998ad10d3305eff3e28408',
		'content_search.items'        => '2bedb16c0b5e985c457d06d3003429d5a4e28afd8b69bcd36b07b05bc8b8de69',
		'content_search.chunks'       => '2855f445b3395d3465e1f492e2a255f51985c613b0ef797b48055af910bd6e90',
		'content_find.related'        => '11560e280ea4f8aef0ccdc1c4d1470c0e7f8066e9b770e46a771e6da125136b2',
		'content_find.internal_links' => '548cf811de598fe2275a5aa1c1fbdbb28bc2a3da4d8dfc80a2ffed6173ce4fa5',
		'content.create_item'         => '942d1ceb28be75a05e6e3efac5755b4fd8aeae0693f936ba01fd162de04f3160',
		'content.update_item'         => '9a4b015f78ae094caddbc1b75f2058a92a2f7b794f860e7da8c3adad59713044',
		'content.update_block'        => '2b8431de3663bb347814067197cf2a2c1757420c6f84c7d08b5ccd7a5bf455f8',
		'content.update_seo'          => '13a37853f7bb4fab701629ec44d29d9fd19b5e009eb3a3b24fe168220441d88e',
		'media.update_item'           => '7087d7dc0337b27b4a2e44583c83227f2ea88ca2a6361c5d6540984f857b880a',
		'media.upload_item'           => 'c25bbab705b1bccc5cf7f7bb1edbc93df653f6a292084c81d30e76b49472ad4f',
		'content_media.apply_image'   => '8994d845091bade2ce285be8f94af0affcecd2ac452b87115a06d533eb17c8d4',
	);

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
		$read  = static fn ( string $adapter_id, string $workflow_id, string $internal_id, array $capabilities = array() ): NativeAbilityWorkflowAdapter => new NativeAbilityWorkflowAdapter( $adapter_id, 1, $workflow_id, $internal_id, 'read', true, $capabilities, null, $abilities, self::NATIVE_CONTRACT_HASHES[ $internal_id ] ?? null );
		$write = static fn ( string $adapter_id, string $workflow_id, string $internal_id, array $capabilities = array( 'edit_posts' ) ): NativeAbilityWorkflowAdapter => new NativeAbilityWorkflowAdapter( $adapter_id, 1, $workflow_id, $internal_id, 'write', false, $capabilities, null, $abilities, self::NATIVE_CONTRACT_HASHES[ $internal_id ] ?? null );

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

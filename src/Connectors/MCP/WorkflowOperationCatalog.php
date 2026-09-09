<?php
/**
 * Public operation names for workflow availability discovery.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Keeps the workflow operation map separate from availability orchestration.
 *
 * @internal
 */
final class WorkflowOperationCatalog {

	/**
	 * Return the public operation key to internal ability ID map.
	 *
	 * @return array<string,string>
	 */
	public static function map(): array {
		return array(
			'route_request'       => 'workflow.route_request',
			'prepare_post'        => 'content_workflow.prepare_post',
			'create_draft'        => 'content_workflow.create_draft',
			'update_post'         => 'content_workflow.update_post',
			'apply_image'         => 'content_media.apply_image',
			'update_rankmath_seo' => 'seo_workflow.update_rankmath',
			'site_audit'          => 'site_workflow.audit',
		);
	}
}

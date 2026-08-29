<?php
/**
 * Safe starter templates for custom content workflows.
 *
 * @package Aculect\AICompanion\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

/**
 * Provides declarative, code-owned workflow starters for the admin UI.
 *
 * Templates contain no executable callbacks or arbitrary code. The admin
 * service resolves their ability IDs against the closed adapter catalog before
 * creating a validated immutable definition.
 */
final class WorkflowTemplateCatalog {

	/**
	 * Return all starter templates in deterministic display order.
	 *
	 * @return array<string, array<string,mixed>>
	 */
	public function all(): array {
		return array(
			'existing_page_refresh'     => array(
				'label'          => 'Existing page refresh',
				'description'    => 'Review an existing page, prepare an improved draft, and save it as a draft after approval.',
				'target_mode'    => 'existing',
				'post_types'     => array( 'page' ),
				'input_fields'   => array( 'post_id:integer:required' ),
				'step_abilities' => array( 'content/get-item', 'content/prepare-draft', 'content/update-item' ),
				'write_policy'   => 'draft_only',
			),
			'blog_post_draft'           => array(
				'label'          => 'Blog post draft',
				'description'    => 'Turn a brief into a reviewable draft post without publishing it.',
				'target_mode'    => 'new',
				'post_types'     => array( 'post' ),
				'input_fields'   => array( 'brief:string:required' ),
				'step_abilities' => array( 'content/prepare-draft', 'content/create-draft' ),
				'write_policy'   => 'draft_only',
			),
			'seo_rewrite'               => array(
				'label'          => 'SEO rewrite',
				'description'    => 'Inspect existing SEO data and prepare an approved SEO update for review.',
				'target_mode'    => 'existing',
				'post_types'     => array( 'post', 'page' ),
				'input_fields'   => array( 'post_id:integer:required', 'seo_title:string' ),
				'step_abilities' => array( 'content/get-seo', 'content/update-seo' ),
				'write_policy'   => 'approved_update',
			),
			'internal_link_improvement' => array(
				'label'          => 'Internal link improvement',
				'description'    => 'Find related content and prepare a bounded internal-link improvement for approval.',
				'target_mode'    => 'existing',
				'post_types'     => array( 'post', 'page' ),
				'input_fields'   => array( 'post_id:integer:required' ),
				'step_abilities' => array( 'content/find-internal-links', 'content/prepare-draft', 'content/update-item' ),
				'write_policy'   => 'draft_only',
			),
			'custom_post_type_creation' => array(
				'label'          => 'Custom post type content creation',
				'description'    => 'Create a reviewable draft for a selected post type. Publishing and deletion are unavailable.',
				'target_mode'    => 'new',
				'post_types'     => array( 'post' ),
				'input_fields'   => array( 'brief:string:required' ),
				'step_abilities' => array( 'content/prepare-draft', 'content/create-draft' ),
				'write_policy'   => 'draft_only',
			),
			'blank'                     => array(
				'label'          => 'Blank guided workflow',
				'description'    => 'Start with a read-only content inspection and add only adapters exposed below.',
				'target_mode'    => 'either',
				'post_types'     => array( 'post' ),
				'input_fields'   => array( 'post_id:integer' ),
				'step_abilities' => array( 'content/get-item' ),
				'write_policy'   => 'proposal_only',
			),
		);
	}

	/**
	 * Return one starter template, or null for an unknown key.
	 *
	 * @param string $template_id Template key.
	 * @return array<string,mixed>|null
	 */
	public function get( string $template_id ): ?array {
		$templates = $this->all();

		return isset( $templates[ $template_id ] ) ? $templates[ $template_id ] : null;
	}
}

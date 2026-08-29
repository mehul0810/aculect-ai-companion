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
				'description'    => 'Review an existing page, then save the supplied improved title and block content as a draft after approval.',
				'target_mode'    => 'existing',
				'post_types'     => array( 'page' ),
				'input_fields'   => array( 'post_id:integer:required', 'brief:string:required', 'title:string:required', 'content:string:required' ),
				'step_abilities' => array( 'content/get-item', 'content/prepare-draft', 'content/update-item' ),
				'step_arguments' => array(
					'step_1' => array( 'id' => '{{input.post_id}}' ),
					'step_2' => array(
						'brief'            => '{{input.brief}}',
						'post_type'        => 'page',
						'existing_post_id' => '{{input.post_id}}',
						'content_mode'     => 'page',
					),
					'step_3' => array(
						'id'      => '{{input.post_id}}',
						'title'   => '{{input.title}}',
						'content' => '{{input.content}}',
						'status'  => 'draft',
					),
				),
				'write_policy'   => 'draft_only',
			),
			'blog_post_draft'           => array(
				'label'          => 'Blog post draft',
				'description'    => 'Review a brief and save supplied title and block content as a reviewable draft post without publishing it.',
				'target_mode'    => 'new',
				'post_types'     => array( 'post' ),
				'input_fields'   => array( 'brief:string:required', 'title:string:required', 'content:string:required' ),
				'step_abilities' => array( 'content/prepare-draft', 'content/create-item' ),
				'step_arguments' => array(
					'step_1' => array(
						'brief'     => '{{input.brief}}',
						'post_type' => 'post',
					),
					'step_2' => array(
						'post_type' => 'post',
						'title'     => '{{input.title}}',
						'content'   => '{{input.content}}',
						'status'    => 'draft',
					),
				),
				'write_policy'   => 'draft_only',
			),
			'seo_rewrite'               => array(
				'label'          => 'SEO rewrite',
				'description'    => 'Inspect existing SEO data and apply the supplied metadata title after explicit approval.',
				'target_mode'    => 'existing',
				'post_types'     => array( 'post', 'page' ),
				'input_fields'   => array( 'post_id:integer:required', 'meta_title:string:required' ),
				'step_abilities' => array( 'content/get-seo', 'content/update-seo' ),
				'step_arguments' => array(
					'step_1' => array( 'id' => '{{input.post_id}}' ),
					'step_2' => array(
						'id'         => '{{input.post_id}}',
						'meta_title' => '{{input.meta_title}}',
					),
				),
				'write_policy'   => 'approved_update',
			),
			'internal_link_improvement' => array(
				'label'          => 'Internal link improvement',
				'description'    => 'Find related content, review the proposed change, and save supplied improved block content as a draft.',
				'target_mode'    => 'existing',
				'post_types'     => array( 'post', 'page' ),
				'input_fields'   => array( 'post_id:integer:required', 'brief:string:required', 'content:string:required' ),
				'step_abilities' => array( 'content/find-internal-links', 'content/prepare-draft', 'content/update-item' ),
				'step_arguments' => array(
					'step_1' => array(
						'source_id' => '{{input.post_id}}',
						'topic'     => '{{input.brief}}',
						'limit'     => 8,
					),
					'step_2' => array( 'brief' => '{{input.brief}}' ),
					'step_3' => array(
						'id'      => '{{input.post_id}}',
						'content' => '{{input.content}}',
						'status'  => 'draft',
					),
				),
				'write_policy'   => 'draft_only',
			),
			'custom_post_type_creation' => array(
				'label'          => 'Custom post type content creation',
				'description'    => 'Create a reviewable draft post from a brief and supplied block content. Publishing and deletion are unavailable.',
				'target_mode'    => 'new',
				'post_types'     => array( 'post' ),
				'input_fields'   => array( 'brief:string:required', 'title:string:required', 'content:string:required' ),
				'step_abilities' => array( 'content/prepare-draft', 'content/create-item' ),
				'step_arguments' => array(
					'step_1' => array(
						'brief'     => '{{input.brief}}',
						'post_type' => 'post',
					),
					'step_2' => array(
						'post_type' => 'post',
						'title'     => '{{input.title}}',
						'content'   => '{{input.content}}',
						'status'    => 'draft',
					),
				),
				'write_policy'   => 'draft_only',
			),
			'blank'                     => array(
				'label'          => 'Blank guided workflow',
				'description'    => 'Start with a read-only content inspection and add only adapters exposed below.',
				'target_mode'    => 'either',
				'post_types'     => array( 'post' ),
				'input_fields'   => array( 'post_id:integer' ),
				'step_abilities' => array( 'content/get-item' ),
				'step_arguments' => array( 'step_1' => array( 'id' => '{{input.post_id}}' ) ),
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

<?php
/**
 * Tests for the guided custom workflow admin service.
 *
 * @package Aculect\AICompanion\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\WorkflowAdminService;
use Aculect\AICompanion\Admin\WorkflowAdminValidationException;
use PHPUnit\Framework\TestCase;
use PHPUnitFrameworkTestCase;

/**
 * Verifies templates resolve only to bounded, validated workflow definitions.
 */
final class WorkflowAdminServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_post_types'] = array();
	}

	public function test_template_catalog_contains_required_safe_starters(): void {
		$templates = ( new WorkflowAdminService() )->templates();

		self::assertSame(
			array(
				'existing_page_refresh',
				'blog_post_draft',
				'seo_rewrite',
				'internal_link_improvement',
				'custom_post_type_creation',
				'blank',
			),
			array_keys( $templates )
		);
	}

	public function test_guided_fields_build_a_valid_versioned_definition(): void {
		$definition = ( new WorkflowAdminService() )->definition_from_input(
			array(
				'workflow_id'    => 'editorial_refresh',
				'template_id'    => 'existing_page_refresh',
				'name'           => 'Editorial refresh',
				'description'    => 'Refresh existing editorial content.',
				'target_mode'    => 'existing',
				'post_types'     => 'page, post',
				'input_fields'   => "post_id:integer:required\nbrief:string",
				'step_abilities' => "content/get-item\ncontent/prepare-draft\ncontent/update-item",
				'write_policy'   => 'draft_only',
				'status'         => 'published',
			),
			7
		);
		$value      = $definition->to_array();

		self::assertSame( 'editorial_refresh', $value['workflow_id'] );
		self::assertSame( 1, $value['workflow_version'] );
		self::assertSame( array( 'page', 'post' ), $value['content_target']['post_types'] );
		self::assertSame( array( 'post_id' ), $value['input_schema']['required'] );
		self::assertSame( array( 'step_3' ), $value['approval_gates'] );
		self::assertSame( 'published', $value['status'] );
		self::assertSame( 'content/update-item', $value['steps'][2]['ability_id'] );
		self::assertSame( array( 'step_2' ), $value['steps'][2]['depends_on'] );
	}

	public function test_unknown_step_ability_fails_closed_before_persistence(): void {
		$service = new WorkflowAdminService();

		try {
			$service->definition_from_input(
				array(
					'workflow_id'    => 'unsafe_workflow',
					'name'           => 'Unsafe',
					'description'    => 'Invalid adapter test.',
					'post_types'     => 'post',
					'input_fields'   => 'brief:string',
					'step_abilities' => 'unknown/write',
				),
				7
			);
			self::fail( 'Expected guided validation to reject an unknown adapter.' );
		} catch ( WorkflowAdminValidationException $exception ) {
			self::assertSame( 'Every step must use an ability from the supported catalog.', $exception->errors()['step_abilities'] );
		}
	}

	public function test_proposal_only_cannot_contain_write_steps(): void {
		$service = new WorkflowAdminService();

		$this->expectException( WorkflowAdminValidationException::class );
		$this->expectExceptionMessage( 'workflow_admin_validation_failed' );
		$service->definition_from_input(
			array(
				'workflow_id'    => 'proposal_workflow',
				'name'           => 'Proposal',
				'description'    => 'Proposal mode.',
				'post_types'     => 'post',
				'input_fields'   => 'brief:string',
				'step_abilities' => 'content/create-draft',
				'write_policy'   => 'proposal_only',
			),
			7
		);
	}

	public function test_malformed_input_field_is_reported_before_definition_validation(): void {
		$service = new WorkflowAdminService();

		try {
			$service->definition_from_input(
				array(
					'workflow_id'    => 'bad_input_workflow',
					'name'           => 'Bad input',
					'description'    => 'Malformed input test.',
					'post_types'     => 'post',
					'input_fields'   => 'title:unsupported',
					'step_abilities' => 'content/get-item',
				),
				7
			);
			self::fail( 'Expected malformed input syntax to be rejected.' );
		} catch ( WorkflowAdminValidationException $exception ) {
			self::assertSame( 'Inputs must use field:type[:required] with a supported primitive type.', $exception->errors()['input_fields'] );
		}
	}
}

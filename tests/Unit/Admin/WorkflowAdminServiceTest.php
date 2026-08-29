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
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRecord;
use Aculect\AICompanion\Workflows\Execution\WorkflowAuditRecord;
use Aculect\AICompanion\Workflows\Execution\WorkflowAuditStoreInterface;
use PHPUnit\Framework\TestCase;

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
		self::assertContains( 'title:string:required', $templates['blog_post_draft']['input_fields'] );
		self::assertContains( 'content:string:required', $templates['blog_post_draft']['input_fields'] );
		self::assertSame( '{{input.title}}', $templates['blog_post_draft']['step_arguments']['step_2']['title'] );
		self::assertSame( '{{input.meta_title}}', $templates['seo_rewrite']['step_arguments']['step_2']['meta_title'] );
	}

	public function test_blog_template_builds_executable_input_bound_create_arguments(): void {
		$definition = ( new WorkflowAdminService() )->definition_from_input(
			array(
				'workflow_id' => 'blog_template_defaults',
				'template_id' => 'blog_post_draft',
			),
			7
		);
		$value = $definition->to_array();

		self::assertSame( array( 'brief', 'title', 'content' ), $value['input_schema']['required'] );
		self::assertSame( '{{input.brief}}', $value['steps'][0]['arguments']['brief'] );
		self::assertSame( '{{input.title}}', $value['steps'][1]['arguments']['title'] );
		self::assertSame( '{{input.content}}', $value['steps'][1]['arguments']['content'] );
		self::assertSame( 'draft', $value['steps'][1]['arguments']['status'] );
	}

	public function test_missing_required_adapter_argument_is_rejected_before_persistence(): void {
		$service = new WorkflowAdminService();

		try {
			$service->definition_from_input(
				array(
					'workflow_id'    => 'missing_adapter_argument',
					'name'           => 'Missing adapter argument',
					'description'    => 'Required adapter argument test.',
					'post_types'     => 'post',
					'input_fields'   => 'post_id:integer:required',
					'step_abilities' => 'content/get-item',
					'step_arguments' => '{"step_1":{}}',
				),
				7
			);
			self::fail( 'A required adapter argument must not be silently omitted.' );
		} catch ( WorkflowAdminValidationException $exception ) {
			self::assertSame( 'Provide arguments for required adapter fields: id.', $exception->errors()['step_arguments'] );
		}
	}

	public function test_roles_are_exposed_without_the_administrator_bypass_role(): void {
		$GLOBALS['aculect_ai_companion_test_roles'] = array(
			'administrator' => array( 'name' => 'Administrator' ),
			'editor'        => array( 'name' => 'Editor' ),
			'author'        => array( 'name' => 'Author' ),
		);

		self::assertSame( array( 'author', 'editor' ), array_column( ( new WorkflowAdminService() )->roles(), 'id' ) );
	}

	public function test_invalid_role_selection_is_rejected_before_storage(): void {
		$result = ( new WorkflowAdminService() )->save(
			array(
				'workflow_id'   => '',
				'allowed_roles' => array( 'administrator' ),
			),
			7
		);

		self::assertFalse( $result['ok'] );
		self::assertArrayHasKey( 'errors', $result );
		$errors = (array) ( $result['errors'] ?? array() );
		self::assertSame( 'Choose only registered non-administrator roles.', (string) ( $errors['allowed_roles'] ?? '' ) );
	}

	public function test_migration_preview_returns_a_stable_plan_for_an_edit(): void {
		$service    = new WorkflowAdminService();
		$fields     = array(
			'workflow_id'    => 'migration_preview',
			'template_id'    => 'blank',
			'name'           => 'Migration preview',
			'description'    => 'Initial workflow.',
			'target_mode'    => 'existing',
			'post_types'     => 'post',
			'input_fields'   => 'brief:string',
			'step_abilities' => 'content/get-item',
			'write_policy'   => 'proposal_only',
			'status'         => 'published',
		);
		$definition = $service->definition_from_input( $fields, 7 );
		$record     = new WorkflowDefinitionRecord(
			1,
			'migration_preview',
			'published',
			1,
			1,
			'blank',
			1,
			7,
			7,
			1,
			'2026-08-29 00:00:00',
			'2026-08-29 00:00:00',
			$definition
		);

		$fields['description'] = 'Updated workflow.';
		$preview               = $service->migration_preview( $fields, 7, $record );

		self::assertIsArray( $preview );
		self::assertSame( 1, $preview['source_version'] );
		self::assertSame( 2, $preview['target_version'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) $preview['migration_id'] );
	}

	public function test_recent_audit_is_bounded_and_fail_closed(): void {
		$event = new WorkflowAuditRecord(
			'run_123',
			'workflow_audit',
			1,
			str_repeat( 'a', 64 ),
			'workflow_completed',
			'',
			7,
			'completed',
			null,
			array( 'status' ),
			'',
			'2026-08-29 00:00:00'
		);
		$audit = new class( $event ) implements WorkflowAuditStoreInterface {
			public function __construct( private WorkflowAuditRecord $event ) {}

			public function append( WorkflowAuditRecord $event ): void {
				$this->event = $event;
			}

			public function for_run( string $run_id ): array {
				unset( $run_id );

				return array( $this->event );
			}

			public function recent( int $limit = 25 ): array {
				unset( $limit );

				return array( $this->event );
			}
		};

		$result = ( new WorkflowAdminService( null, null, null, $audit ) )->recent_audit();

		self::assertNull( $result['error'] );
		self::assertSame( $event, $result['events'][0] );
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

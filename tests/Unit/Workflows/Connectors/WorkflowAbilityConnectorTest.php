<?php
/**
 * Tests for the public custom workflow connector policy boundary.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Connectors
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Connectors;

use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterRegistry;
use Aculect\AICompanion\Workflows\Connectors\WorkflowAbilityConnector;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRecord;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRepositoryInterface;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunRecord;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunStoreInterface;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;
use PHPUnit\Framework\TestCase;

/**
 * Verifies saved role narrowing is enforced by the live connector methods,
 * while empty allowlists continue to inherit the existing global policy.
 */
final class WorkflowAbilityConnectorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_roles'] = array(
			'administrator' => array( 'name' => 'Administrator' ),
			'editor'        => array( 'name' => 'Editor' ),
			'author'        => array( 'name' => 'Author' ),
		);
		$GLOBALS['aculect_ai_companion_test_users'] = array(
			7 => (object) array(
				'ID'    => 7,
				'roles' => array( 'editor' ),
			),
			8 => (object) array(
				'ID'    => 8,
				'roles' => array( 'author' ),
			),
			9 => (object) array(
				'ID'    => 9,
				'roles' => array( 'administrator' ),
			),
		);
	}

	public function test_list_filters_restricted_records_and_preserves_repository_lookahead(): void {
		$records = array(
			$this->record( 'author_workflow', array( 'author' ) ),
			$this->record( 'editor_workflow', array( 'editor' ) ),
			$this->record( 'open_workflow', array( 'author' ) ),
		);
		$pages   = array(
			1 => array_slice( $records, 0, 2 ),
			2 => array_slice( $records, 1, 2 ),
			3 => array(),
		);
		$calls   = array();
		$repo    = $this->createMock( WorkflowDefinitionRepositoryInterface::class );
		$repo->method( 'list_published' )->willReturnCallback(
			static function ( array $filters ) use ( &$calls, $pages ): array {
				$calls[] = $filters;

				return $pages[ (int) ( $filters['page'] ?? 0 ) ] ?? array();
			}
		);

		$connector = new WorkflowAbilityConnector(
			definitions: $repo,
			adapters: new WorkflowAdapterRegistry( array() ),
			auth_provider: static fn (): array => array( 'user_id' => 7 )
		);
		$result    = $connector->list_workflows(
			array(
				'limit' => 1,
				'page'  => 1,
			)
		);

		self::assertSame( 'ok', $result['status'] ?? null );
		self::assertSame( array( 'editor_workflow' ), array_column( $result['custom_workflows'] ?? array(), 'workflow_id' ) );
		self::assertFalse( (bool) ( $result['pagination']['has_more'] ?? true ) );
		self::assertSame( 1, $calls[0]['page_stride'] ?? null );
		self::assertSame( 2, $calls[0]['per_page'] ?? null );
		self::assertSame( array( 1, 2, 3 ), array_column( $calls, 'page' ) );
	}

	public function test_get_and_prepare_hide_a_workflow_from_an_excluded_role(): void {
		$restricted = $this->record( 'editor_workflow', array( 'editor' ) );
		$repo       = $this->createMock( WorkflowDefinitionRepositoryInterface::class );
		$repo->method( 'get_published' )->with( 'editor_workflow' )->willReturn( $restricted );
		$runs = $this->createMock( WorkflowRunStoreInterface::class );
		$runs->expects( self::never() )->method( 'create' );
		$connector = new WorkflowAbilityConnector(
			definitions: $repo,
			adapters: new WorkflowAdapterRegistry( array() ),
			runs: $runs,
			auth_provider: static fn (): array => array( 'user_id' => 8 )
		);

		$get = $connector->get( array( 'workflow_id' => 'editor_workflow' ) );
		self::assertSame( 'workflow_not_found', $get['error'] ?? null );

		$prepare = $connector->prepare(
			array(
				'workflow_id' => 'editor_workflow',
				'input'       => (object) array(),
			)
		);
		self::assertSame( 'workflow_not_found', $prepare['error'] ?? null );
	}

	public function test_empty_allowlist_and_administrator_inherit_global_access(): void {
		$open       = $this->record( 'open_workflow' );
		$restricted = $this->record( 'editor_workflow', array( 'editor' ) );
		$repo       = $this->createMock( WorkflowDefinitionRepositoryInterface::class );
		$repo->method( 'get_published' )->willReturnMap(
			array(
				array( 'open_workflow', $open ),
				array( 'editor_workflow', $restricted ),
			)
		);

		$author_connector = new WorkflowAbilityConnector(
			definitions: $repo,
			adapters: new WorkflowAdapterRegistry( array() ),
			auth_provider: static fn (): array => array( 'user_id' => 8 )
		);
		$admin_connector  = new WorkflowAbilityConnector(
			definitions: $repo,
			adapters: new WorkflowAdapterRegistry( array() ),
			auth_provider: static fn (): array => array( 'user_id' => 9 )
		);

		self::assertSame( 'ok', $author_connector->get( array( 'workflow_id' => 'open_workflow' ) )['status'] ?? null );
		self::assertSame( 'workflow_not_found', $author_connector->get( array( 'workflow_id' => 'editor_workflow' ) )['error'] ?? null );
		self::assertSame( 'ok', $admin_connector->get( array( 'workflow_id' => 'editor_workflow' ) )['status'] ?? null );
	}

	/**
	 * Every run operation must apply the role check before planning or mutation.
	 */
	public function test_all_run_operations_reject_a_stale_or_excluded_role(): void {
		$restricted = $this->record( 'editor_workflow', array( 'editor' ) );
		$repo       = $this->createMock( WorkflowDefinitionRepositoryInterface::class );
		$repo->method( 'get' )->with( 'editor_workflow', 1, true )->willReturn( $restricted );
		$run  = new WorkflowRunRecord(
			1,
			'role-run',
			'editor_workflow',
			1,
			$restricted->definition()->checksum(),
			str_repeat( 'a', 64 ),
			str_repeat( 'b', 64 ),
			WorkflowRunState::PREPARED,
			1,
			null,
			null,
			8,
			8,
			'2026-08-31 00:00:00',
			'2026-08-31 00:00:00'
		);
		$runs = $this->createMock( WorkflowRunStoreInterface::class );
		$runs->method( 'get' )->with( 'role-run' )->willReturn( $run );
		$connector = new WorkflowAbilityConnector(
			definitions: $repo,
			adapters: new WorkflowAdapterRegistry( array() ),
			runs: $runs,
			auth_provider: static fn (): array => array( 'user_id' => 8 )
		);

		$args = array(
			'run_id' => 'role-run',
			'input'  => (object) array(),
		);
		foreach ( array( 'dry_run', 'execute', 'resume', 'cancel', 'status', 'result' ) as $operation ) {
			$result = $connector->{$operation}( $args );

			self::assertSame( 'error', $result['status'] ?? null, $operation );
			self::assertSame( 'workflow_forbidden', $result['error'] ?? null, $operation );
		}
	}

	/**
	 * Build a published record with the requested workflow-level role policy.
	 *
	 * @param string            $workflow_id Stable workflow identifier.
	 * @param array<int,string> $allowed_roles Stored role allowlist.
	 * @phpstan-param list<string> $allowed_roles
	 */
	private function record( string $workflow_id, array $allowed_roles = array() ): WorkflowDefinitionRecord {
		$json = file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/proposal-only-v1.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned fixture.
		self::assertIsString( $json );
		$value                = json_decode( $json, true );
		$value['workflow_id'] = $workflow_id;
		$value['status']      = 'published';
		$value['created_by']  = 7;
		$value['updated_by']  = 7;
		$definition           = WorkflowDefinition::from_array( $value );

		return new WorkflowDefinitionRecord(
			1,
			$workflow_id,
			'published',
			1,
			1,
			'',
			0,
			7,
			7,
			1,
			'2026-08-31 00:00:00',
			'2026-08-31 00:00:00',
			$definition,
			$allowed_roles
		);
	}
}

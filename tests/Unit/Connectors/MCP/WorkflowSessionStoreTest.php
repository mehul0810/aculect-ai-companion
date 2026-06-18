<?php
/**
 * Tests for transient-backed MCP workflow sessions.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\WorkflowSessionStore;
use PHPUnit\Framework\TestCase;

/**
 * Verifies bounded workflow session persistence.
 */
final class WorkflowSessionStoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_transients']      = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
	}

	public function test_start_get_and_update_workflow_session(): void {
		$store   = new WorkflowSessionStore();
		$started = $store->start(
			array(
				'workflow'     => 'content_long_form_draft',
				'state'        => 'routed',
				'brief'        => 'Create an article.',
				'content_mode' => 'article',
				'post_type'    => 'post',
			)
		);

		self::assertSame( 'success', $started['status'] );
		self::assertSame( 'routed', $started['workflow_session']['state'] );
		self::assertSame( 7, $started['workflow_session']['user_id'] );
		self::assertSame( 'Create an article.', $started['workflow_session']['context']['brief'] );

		$read = $store->get( array( 'workflow_session_id' => $started['workflow_session']['id'] ) );
		self::assertSame( $started['workflow_session']['id'], $read['workflow_session']['id'] );

		$updated = $store->update(
			array(
				'workflow_session_id' => $started['workflow_session']['id'],
				'state'               => 'prepared',
				'tool'                => 'content_workflow_prepare_post',
				'message'             => 'Plan ready.',
			)
		);

		self::assertSame( 'success', $updated['status'] );
		self::assertSame( 'prepared', $updated['workflow_session']['state'] );
		self::assertCount( 2, $updated['workflow_session']['events'] );
	}

	public function test_advance_from_tool_result_marks_errors_failed(): void {
		$store   = new WorkflowSessionStore();
		$started = $store->start( array( 'workflow' => 'content_long_form_draft' ) );

		$updated = $store->advance_from_tool_result(
			(string) $started['workflow_session']['id'],
			'prepared',
			'content_workflow_prepare_post',
			array(
				'error'   => 'invalid_brief',
				'message' => 'Provide a brief.',
			)
		);

		self::assertSame( 'success', $updated['status'] );
		self::assertSame( 'failed', $updated['workflow_session']['state'] );
		self::assertStringContainsString( 'failed', $updated['workflow_session']['events'][1]['message'] );
	}

	public function test_session_state_cannot_be_read_or_mutated_by_another_user(): void {
		$store   = new WorkflowSessionStore();
		$started = $store->start(
			array(
				'workflow' => 'content_long_form_draft',
				'state'    => 'started',
			)
		);

		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 9;

		$read = $store->get( array( 'workflow_session_id' => $started['workflow_session']['id'] ) );
		self::assertSame( 'error', $read['status'] );
		self::assertSame( 'workflow_session_not_found', $read['error'] );

		$updated = $store->update(
			array(
				'workflow_session_id' => $started['workflow_session']['id'],
				'state'               => 'prepared',
			)
		);
		self::assertSame( 'error', $updated['status'] );
		self::assertSame( 'workflow_session_not_found', $updated['error'] );

		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
		$owner_read = $store->get( array( 'workflow_session_id' => $started['workflow_session']['id'] ) );

		self::assertSame( 'started', $owner_read['workflow_session']['state'] );
		self::assertCount( 1, $owner_read['workflow_session']['events'] );
	}
}

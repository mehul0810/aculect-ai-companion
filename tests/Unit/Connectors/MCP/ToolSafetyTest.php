<?php
/**
 * Tests for MCP safety controls.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use PHPUnit\Framework\TestCase;

/**
 * Verifies dry-run and confirmation helpers remain deterministic.
 */
final class ToolSafetyTest extends TestCase {

	private ToolSafety $safety;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']    = array();
		$GLOBALS['aculect_ai_companion_test_transients'] = array();
		$this->safety = new ToolSafety();
	}

	public function test_high_risk_actions_require_confirmation_by_default(): void {
		self::assertSame( 'publish', $this->safety->risk_level( 'content.create_item', array( 'status' => 'publish' ) ) );
		self::assertTrue( $this->safety->requires_confirmation( 'content.create_item', array( 'status' => 'publish' ) ) );

		self::assertSame( 'destructive', $this->safety->risk_level( 'content.update_item', array( 'status' => 'trash' ) ) );
		self::assertTrue( $this->safety->requires_confirmation( 'content.update_item', array( 'status' => 'trash' ) ) );

		self::assertSame( 'update', $this->safety->risk_level( 'content.update_item', array( 'title' => 'Draft edit' ) ) );
		self::assertFalse( $this->safety->requires_confirmation( 'content.update_item', array( 'title' => 'Draft edit' ) ) );

		self::assertSame( 'update', $this->safety->risk_level( 'comments.bulk_update', array( 'status' => 'hold' ) ) );
		self::assertTrue( $this->safety->requires_confirmation( 'comments.bulk_update', array( 'status' => 'hold' ) ) );

		self::assertSame( 'draft', $this->safety->risk_level( 'content_workflow.create_draft', array( 'title' => 'Draft' ) ) );
		self::assertSame( 'destructive', $this->safety->risk_level( 'content_workflow.update_post', array( 'id' => 123, 'content' => '<!-- wp:paragraph --><p>Updated</p><!-- /wp:paragraph -->' ) ) );
		self::assertTrue( $this->safety->requires_confirmation( 'content_workflow.update_post', array( 'id' => 123, 'content' => '<!-- wp:paragraph --><p>Updated</p><!-- /wp:paragraph -->' ) ) );
		self::assertSame( 'destructive', $this->safety->risk_level( 'content_workflow.update_post', array( 'id' => 123, 'section_map' => array( 'intro' => '<!-- wp:paragraph --><p>Updated</p><!-- /wp:paragraph -->' ) ) ) );
		self::assertTrue( $this->safety->requires_confirmation( 'content_workflow.update_post', array( 'id' => 123, 'section_map' => array( 'intro' => '<!-- wp:paragraph --><p>Updated</p><!-- /wp:paragraph -->' ) ) ) );
	}

	public function test_configured_groups_require_confirmation_for_all_write_actions(): void {
		$this->safety->save_confirmation_groups( array( 'Content Groups', '<script>' ) );

		self::assertSame( array( 'Content Groups' ), $this->safety->confirmation_groups() );
		self::assertTrue( $this->safety->requires_confirmation( 'taxonomy.update_term', array( 'name' => 'News' ) ) );
		self::assertFalse( $this->safety->requires_confirmation( 'content.update_item', array( 'title' => 'Draft edit' ) ) );
	}

	public function test_confirmation_token_validates_then_replays_after_success(): void {
		$auth = array(
			'user_id'   => 7,
			'client_id' => 'client-1',
			'provider'  => 'chatgpt',
		);
		$args = array(
			'id'     => 123,
			'status' => 'publish',
		);

		$token     = $this->safety->issue_confirmation_token( 'content.update_item', $args, $auth );
		$call_args = array_merge( $args, array( 'confirmation_token' => $token ) );
		self::assertNotSame( '', $token );

		// Validation does not consume: a crash before the write keeps the token usable.
		self::assertTrue( $this->safety->validate_confirmation_token( 'content.update_item', $call_args, $auth ) );
		self::assertTrue( $this->safety->validate_confirmation_token( 'content.update_item', $call_args, $auth ) );
		self::assertNull( $this->safety->confirmation_replay( 'content.update_item', $call_args, $auth ) );

		$result = array(
			'status'  => 'success',
			'post_id' => 123,
		);
		$this->safety->finalize_confirmation_token( 'content.update_item', $call_args, $auth, $result );

		// After success the token no longer validates but replays the stored result.
		self::assertFalse( $this->safety->validate_confirmation_token( 'content.update_item', $call_args, $auth ) );
		$replay = $this->safety->confirmation_replay( 'content.update_item', $call_args, $auth );
		self::assertIsArray( $replay );
		self::assertSame( 123, $replay['post_id'] );
		self::assertTrue( $replay['replayed'] );
	}

	public function test_confirmation_token_rejects_changed_payload(): void {
		$auth  = array(
			'user_id'   => 7,
			'client_id' => 'client-1',
			'provider'  => 'chatgpt',
		);
		$token = $this->safety->issue_confirmation_token(
			'content.update_item',
			array(
				'id'     => 123,
				'status' => 'publish',
			),
			$auth
		);

		self::assertFalse(
			$this->safety->validate_confirmation_token(
				'content.update_item',
				array(
					'id'                 => 124,
					'status'             => 'publish',
					'confirmation_token' => $token,
				),
				$auth
			)
		);
	}

	public function test_idempotency_key_replays_identical_calls_and_rejects_reuse(): void {
		$auth = array(
			'user_id'   => 7,
			'client_id' => 'client-1',
			'provider'  => 'claude',
		);
		$args = array(
			'title'           => 'New draft',
			'idempotency_key' => 'draft-2026-06-10-a',
		);

		self::assertNull( $this->safety->idempotent_replay( 'content_workflow.create_draft', $args, $auth ) );

		$this->safety->remember_write_result(
			'content_workflow.create_draft',
			$args,
			$auth,
			array(
				'status'  => 'success',
				'post_id' => 555,
			)
		);

		$replay = $this->safety->idempotent_replay( 'content_workflow.create_draft', $args, $auth );
		self::assertIsArray( $replay );
		self::assertSame( 555, $replay['post_id'] );
		self::assertTrue( $replay['replayed'] );

		// Same key, different payload: explicit error instead of silent replay or duplicate.
		$reused = $this->safety->idempotent_replay(
			'content_workflow.create_draft',
			array(
				'title'           => 'Different draft',
				'idempotency_key' => 'draft-2026-06-10-a',
			),
			$auth
		);
		self::assertIsArray( $reused );
		self::assertSame( 'idempotency_key_reuse', $reused['error'] );

		// Different OAuth identity cannot read another connection's results.
		$other = array(
			'user_id'   => 8,
			'client_id' => 'client-2',
			'provider'  => 'claude',
		);
		self::assertNull( $this->safety->idempotent_replay( 'content_workflow.create_draft', $args, $other ) );
	}

	public function test_control_args_are_stripped_before_execution(): void {
		$stripped = $this->safety->strip_control_args(
			array(
				'title'              => 'Post',
				'dry_run'            => true,
				'confirmation_token' => 'abc',
				'idempotency_key'    => 'key-1',
			)
		);

		self::assertSame( array( 'title' => 'Post' ), $stripped );
	}
}

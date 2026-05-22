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
	}

	public function test_configured_groups_require_confirmation_for_all_write_actions(): void {
		$this->safety->save_confirmation_groups( array( 'Content Groups', '<script>' ) );

		self::assertSame( array( 'Content Groups' ), $this->safety->confirmation_groups() );
		self::assertTrue( $this->safety->requires_confirmation( 'taxonomy.update_term', array( 'name' => 'News' ) ) );
		self::assertFalse( $this->safety->requires_confirmation( 'content.update_item', array( 'title' => 'Draft edit' ) ) );
	}

	public function test_confirmation_token_is_bound_to_payload_and_consumed_once(): void {
		$auth = array(
			'user_id'   => 7,
			'client_id' => 'client-1',
			'provider'  => 'chatgpt',
		);
		$args = array(
			'id'     => 123,
			'status' => 'publish',
		);

		$token = $this->safety->issue_confirmation_token( 'content.update_item', $args, $auth );
		self::assertNotSame( '', $token );

		self::assertTrue(
			$this->safety->consume_confirmation_token(
				'content.update_item',
				array_merge( $args, array( 'confirmation_token' => $token ) ),
				$auth
			)
		);

		self::assertFalse(
			$this->safety->consume_confirmation_token(
				'content.update_item',
				array_merge( $args, array( 'confirmation_token' => $token ) ),
				$auth
			)
		);
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
			$this->safety->consume_confirmation_token(
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
}

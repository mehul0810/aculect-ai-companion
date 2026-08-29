<?php
/**
 * Tests for workflow-level role access narrowing.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Authorization
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Authorization;

use Aculect\AICompanion\Workflows\Authorization\WorkflowRoleAccessPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Verifies role metadata is bounded and never bypasses the global policy.
 */
final class WorkflowRoleAccessPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_roles'] = array(
			'administrator' => array( 'name' => 'Administrator' ),
			'editor'        => array( 'name' => 'Editor' ),
			'author'        => array( 'name' => 'Author' ),
			'contributor'   => array( 'name' => 'Contributor' ),
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

	public function test_available_roles_excludes_administrator_and_is_deterministic(): void {
		$roles = ( new WorkflowRoleAccessPolicy() )->available_roles();

		self::assertSame(
			array( 'author', 'contributor', 'editor' ),
			array_column( $roles, 'id' )
		);
		self::assertSame( 'Author', $roles[0]['label'] );
	}

	public function test_normalize_drops_unknown_values_but_validation_rejects_them(): void {
		$policy = new WorkflowRoleAccessPolicy();

		self::assertSame( array( 'author', 'editor' ), $policy->normalize( array( 'editor', 'author', 'unknown', 'administrator' ) ) );
		self::assertFalse( $policy->is_valid( array( 'unknown' ) ) );
		self::assertFalse( $policy->is_valid( array( 'administrator' ) ) );
		self::assertFalse( $policy->is_valid( array( 'editor' => 'editor' ) ) );
		self::assertTrue( $policy->is_valid( array( 'editor', 'editor' ) ) );
	}

	public function test_empty_allowlist_inherits_and_non_empty_allowlist_narrows(): void {
		$policy = new WorkflowRoleAccessPolicy();

		self::assertTrue( $policy->is_allowed( array(), array( 'user_id' => 8 ) ) );
		self::assertTrue( $policy->is_allowed( array( 'editor' ), array( 'user_id' => 7 ) ) );
		self::assertFalse( $policy->is_allowed( array( 'editor' ), array( 'user_id' => 8 ) ) );
		self::assertTrue( $policy->is_allowed( array( 'editor' ), array( 'user_id' => 9 ) ) );
	}

	public function test_explicit_auth_roles_are_used_without_relying_on_user_lookup(): void {
		$policy = new WorkflowRoleAccessPolicy();

		self::assertTrue(
			$policy->is_allowed(
				array( 'author' ),
				array(
					'user_id' => 7,
					'roles'   => array( 'author' ),
				)
			)
		);
		self::assertFalse(
			$policy->is_allowed(
				array( 'author' ),
				array(
					'user_id' => 7,
					'roles'   => array( 'editor' ),
				)
			)
		);
	}
}

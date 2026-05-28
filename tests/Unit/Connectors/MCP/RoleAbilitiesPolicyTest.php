<?php
/**
 * Role ability policy tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests role-specific ability policy behavior.
 *
 * @covers \Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy
 */
final class RoleAbilitiesPolicyTest extends TestCase {

	private AbilitiesRegistry $registry;
	private RoleAbilitiesPolicy $policy;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$GLOBALS['aculect_ai_companion_test_users']   = array(
			7  => (object) array(
				'ID'           => 7,
				'roles'        => array( 'editor' ),
				'display_name' => 'Ed Editor',
				'user_login'   => 'ed',
			),
			11 => (object) array(
				'ID'           => 11,
				'roles'        => array( 'author' ),
				'display_name' => 'Ava Author',
				'user_login'   => 'ava',
			),
		);
		$this->registry                               = new AbilitiesRegistry();
		$this->policy                                 = new RoleAbilitiesPolicy();
	}

	public function test_role_inherits_global_enabled_abilities_by_default(): void {
		$global = $this->registry->enabled_ids();

		self::assertSame( $global, $this->policy->allowed_ids_for_role( 'editor', $this->registry ) );
		self::assertFalse( $this->policy->has_explicit_policy( 'editor' ) );
	}

	public function test_admin_payload_includes_role_metadata(): void {
		$payload = $this->policy->admin_payload( $this->registry );
		$roles   = array_column( $payload['roles'], null, 'id' );

		self::assertSame( $this->registry->enabled_ids(), $payload['globalEnabledIds'] );
		self::assertArrayHasKey( 'editor', $roles );
		self::assertSame( 'Editor', $roles['editor']['label'] );
		self::assertSame( 1, $roles['editor']['userCount'] );
		self::assertSame( 'Ed Editor', $roles['editor']['users'][0]['label'] );
		self::assertFalse( $roles['editor']['explicit'] );
	}

	public function test_role_policy_sanitizes_unknown_abilities_and_resets_to_default(): void {
		$this->policy->save_role_policy(
			'editor',
			array( 'content.get_item', 'unknown.tool', 'content_get_item' ),
			$this->registry
		);

		self::assertTrue( $this->policy->has_explicit_policy( 'editor' ) );
		self::assertSame( array( 'content.get_item' ), $this->policy->allowed_ids_for_role( 'editor', $this->registry ) );

		$this->policy->reset_role_policy( 'editor', $this->registry );

		self::assertFalse( $this->policy->has_explicit_policy( 'editor' ) );
		self::assertSame( $this->registry->enabled_ids(), $this->policy->allowed_ids_for_role( 'editor', $this->registry ) );
	}

	public function test_user_policy_uses_assigned_roles_and_never_exceeds_global_policy(): void {
		$this->registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );
		$this->policy->save_role_policy( 'editor', array( 'content.get_item', 'content.update_item' ), $this->registry );
		$this->policy->save_role_policy( 'author', array( 'content.update_item', 'media.delete_item' ), $this->registry );

		self::assertSame( array( 'content.get_item', 'content.update_item' ), $this->policy->allowed_ids_for_user( 7, $this->registry ) );
		self::assertSame( array( 'content.update_item' ), $this->policy->allowed_ids_for_user( 11, $this->registry ) );
		self::assertFalse( $this->policy->is_allowed_for_user( 'media.delete_item', 11, $this->registry ) );
	}
}

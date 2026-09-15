<?php
/**
 * Tests confirmation and failure handling for extension deletion abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\PluginLifecycleAbilities;
use Aculect\AICompanion\Connectors\MCP\ExtensionDeletionPolicy;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Tests\Fixtures\ExtensionDeletionAbilitiesStub;
use Aculect\AICompanion\Tests\Fixtures\ExtensionDeletionPolicyStub;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/plugin-lifecycle-upgrader-stubs.php';
require_once dirname( __DIR__, 3 ) . '/fixtures/extension-deletion-stubs.php';

/**
 * Confirms deletes remain bound, gated, and testable without filesystem writes.
 */
final class ExtensionDeletionAbilitiesTest extends TestCase {

	private ExtensionDeletionAbilitiesStub $abilities;

	private ExtensionDeletionPolicyStub $policy;

	protected function setUp(): void {
		parent::setUp();

		$this->policy                                      = new ExtensionDeletionPolicyStub();
		$this->abilities                                   = new ExtensionDeletionAbilitiesStub( $this->policy );
		$this->policy->plan                                = $this->plan( 'plugin' );
		$this->policy->installed                           = false;
		$this->abilities->delete_calls                     = 0;
		$this->abilities->delete_result                    = true;
		$this->abilities->throw_on_delete                  = false;
		$GLOBALS['aculect_ai_companion_test_denied_caps']  = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = false;
		$GLOBALS['aculect_ai_companion_test_file_mod_allowed']  = true;
		$GLOBALS['aculect_ai_companion_test_filesystem_method'] = 'direct';
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps']       = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite']      = false;
		$GLOBALS['aculect_ai_companion_test_file_mod_allowed']  = true;
		$GLOBALS['aculect_ai_companion_test_filesystem_method'] = 'direct';
		parent::tearDown();
	}

	public function test_dry_run_returns_a_bound_preview_and_never_deletes_even_with_a_binding(): void {
		$result = $this->abilities->delete(
			'plugin',
			array(
				'plugin'  => 'example/example.php',
				'dry_run' => true,
				PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY => array( 'forged' => true ),
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'example/example.php', $result['target']['id'] );
		self::assertSame( 12, $result['target']['blog_id'] );
		self::assertSame( $this->policy->plan['binding'], $result[ PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY ] );
		self::assertSame( 0, $this->abilities->delete_calls );
	}

	public function test_delete_requires_the_preview_binding_and_verifies_absence(): void {
		$preview = $this->abilities->delete(
			'plugin',
			array(
				'plugin'  => 'example/example.php',
				'dry_run' => true,
			)
		);
		$binding = $preview[ PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY ];
		$result  = $this->abilities->delete(
			'plugin',
			array(
				'plugin' => 'example/example.php',
				PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY => $binding,
			)
		);

		self::assertSame( 'deleted', $result['status'] );
		self::assertTrue( $result['verified'] );
		self::assertSame( 1, $this->abilities->delete_calls );
	}

	public function test_theme_delete_uses_its_stylesheet_and_theme_capability(): void {
		$this->policy->plan = $this->plan( 'theme' );
		$preview            = $this->abilities->delete(
			'theme',
			array(
				'stylesheet' => 'example-theme',
				'dry_run'    => true,
			)
		);
		$result             = $this->abilities->delete(
			'theme',
			array(
				'stylesheet' => 'example-theme',
				PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY => $preview[ PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY ],
			)
		);

		self::assertSame( 'theme_lifecycle.delete_theme', $result['operation'] );
		self::assertSame( 'example-theme', $result['target']['id'] );
		self::assertSame( 1, $this->abilities->delete_calls );
	}

	public function test_missing_or_stale_binding_never_reaches_wordpress_core(): void {
		$missing = $this->abilities->delete( 'plugin', array( 'plugin' => 'example/example.php' ) );
		self::assertSame( 'confirmation_required', $missing['error'] );

		$preview                                = $this->abilities->delete(
			'plugin',
			array(
				'plugin'  => 'example/example.php',
				'dry_run' => true,
			)
		);
		$this->policy->plan['binding']['state'] = 'changed-after-preview';
		$stale                                  = $this->abilities->delete(
			'plugin',
			array(
				'plugin' => 'example/example.php',
				PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY => $preview[ PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY ],
			)
		);

		self::assertSame( 'invalid_confirmation_binding', $stale['error'] );
		self::assertSame( 0, $this->abilities->delete_calls );
	}

	public function test_capability_multisite_and_file_modification_gates_fail_closed(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'delete_plugins' );
		$forbidden                                        = $this->abilities->delete(
			'plugin',
			array(
				'plugin'  => 'example/example.php',
				'dry_run' => true,
			)
		);
		self::assertSame( 'forbidden', $forbidden['error'] );

		$GLOBALS['aculect_ai_companion_test_denied_caps']  = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = true;
		$multisite = $this->abilities->delete(
			'plugin',
			array(
				'plugin'  => 'example/example.php',
				'dry_run' => true,
			)
		);
		self::assertSame( 'multisite_scope', $multisite['error'] );

		$GLOBALS['aculect_ai_companion_test_is_multisite']     = false;
		$GLOBALS['aculect_ai_companion_test_file_mod_allowed'] = false;
		$file_mods = $this->abilities->delete(
			'plugin',
			array(
				'plugin'  => 'example/example.php',
				'dry_run' => true,
			)
		);
		self::assertSame( 'file_modifications_disabled', $file_mods['error'] );

		$GLOBALS['aculect_ai_companion_test_file_mod_allowed']  = true;
		$GLOBALS['aculect_ai_companion_test_filesystem_method'] = 'ssh2';
		$filesystem = $this->abilities->delete(
			'plugin',
			array(
				'plugin'  => 'example/example.php',
				'dry_run' => true,
			)
		);
		self::assertSame( 'direct_filesystem_required', $filesystem['error'] );
		self::assertSame( 0, $this->abilities->delete_calls );
	}

	public function test_unknown_extension_kind_is_rejected(): void {
		$result = $this->abilities->delete( 'widget', array() );

		self::assertSame( 'invalid_extension_kind', $result['error'] );
		self::assertSame( 0, $this->abilities->delete_calls );
	}

	public function test_uncertain_core_failures_are_terminal_and_do_not_leak_exceptions(): void {
		$preview                        = $this->abilities->delete(
			'plugin',
			array(
				'plugin'  => 'example/example.php',
				'dry_run' => true,
			)
		);
		$args                           = array(
			'plugin' => 'example/example.php',
			PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY => $preview[ PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY ],
		);
		$this->abilities->delete_result = false;
		$failed                         = $this->abilities->delete( 'plugin', $args );
		self::assertSame( 'partial_write', $failed['error'] );
		self::assertTrue( $failed['terminal'] );

		$this->abilities->throw_on_delete = true;
		$thrown                           = $this->abilities->delete( 'plugin', $args );
		self::assertSame( 'partial_write', $thrown['error'] );
		self::assertStringNotContainsString( 'Fixture failure', $thrown['message'] );
		self::assertSame( 2, $this->abilities->delete_calls );
	}

	public function test_postcondition_failure_is_terminal(): void {
		$preview                 = $this->abilities->delete(
			'plugin',
			array(
				'plugin'  => 'example/example.php',
				'dry_run' => true,
			)
		);
		$this->policy->installed = true;
		$result                  = $this->abilities->delete(
			'plugin',
			array(
				'plugin' => 'example/example.php',
				PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY => $preview[ PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY ],
			)
		);

		self::assertSame( 'partial_write', $result['error'] );
		self::assertTrue( $result['terminal'] );
	}

	public function test_real_plan_binding_survives_confirmation_storage_without_weakening_state_checks(): void {
		$policy = new ExtensionDeletionPolicy();
		$method = new \ReflectionMethod( $policy, 'plan' );
		$plan   = $method->invoke( $policy, 'plugin', 'example/example.php', '1.2.3', 'inactive', array( 'active' => false ), '/fixture/extensions' );
		$safety = new ToolSafety();
		$token  = $safety->issue_confirmation_token( 'plugin_lifecycle.delete_plugin', array( 'plugin' => 'example/example.php' ), array( 'user_id' => 1 ), $plan['binding'] );
		$stored = $safety->confirmation_binding( array( 'confirmation_token' => $token ) );

		self::assertTrue( $policy->binding_matches( $stored, $plan['binding'] ) );
		$stale            = $stored;
		$stale['blog_id'] = 'other-site';
		self::assertFalse( $policy->binding_matches( $stale, $plan['binding'] ) );
		$stale          = $stored;
		$stale['state'] = 'changed-state';
		self::assertFalse( $policy->binding_matches( $stale, $plan['binding'] ) );
	}

	/**
	 * Build a fixed installed-extension plan for the injected policy.
	 *
	 * @param string $kind Plugin or theme.
	 * @return array<string, mixed>
	 */
	private function plan( string $kind ): array {
		$id = 'plugin' === $kind ? 'example/example.php' : 'example-theme';
		return array(
			'target'             => array(
				'type'    => $kind,
				'id'      => $id,
				'version' => '1.2.3',
				'status'  => 'inactive',
				'blog_id' => 12,
			),
			'binding'            => array(
				'operation' => 'delete',
				'kind'      => $kind,
				'target'    => $id,
				'version'   => '1.2.3',
				'blog_id'   => '12',
				'state'     => 'fixture-state',
			),
			'filesystem_context' => '/fixture/extensions',
		);
	}
}

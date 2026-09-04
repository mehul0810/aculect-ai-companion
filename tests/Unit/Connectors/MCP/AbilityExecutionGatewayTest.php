<?php
/**
 * Tests for the policy-preserving MCP ability execution gateway.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Activity\ActivityLogger;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionRequest;
use Aculect\AICompanion\Connectors\MCP\AccessLockdown;
use Aculect\AICompanion\Connectors\MCP\PluginLifecycleAbilities;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use Aculect\AICompanion\Diagnostics\Logger;
use Aculect\AICompanion\Tests\Support\InMemoryExecutionClaimStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/plugin-lifecycle-stubs.php';
require_once dirname( __DIR__, 3 ) . '/fixtures/plugin-lifecycle-upgrader-stubs.php';

/**
 * Verifies that direct callers receive the same policy and safety boundary as MCP transport.
 */
final class AbilityExecutionGatewayTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']             = array();
		$GLOBALS['aculect_ai_companion_test_transients']          = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']    = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']     = 1;
		$GLOBALS['aculect_ai_companion_test_users']               = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
			2 => (object) array(
				'ID'           => 2,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Low Privilege Test User',
				'user_login'   => 'low-privilege',
			),
		);
		$GLOBALS['aculect_ai_companion_test_posts']               = array(
			123 => new \WP_Post(
				array(
					'ID'           => 123,
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_title'   => 'Original title',
					'post_content' => '<!-- wp:paragraph --><p>Original content.</p><!-- /wp:paragraph -->',
				)
			),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_capability_callback']       = null;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']          = array();
		$GLOBALS['aculect_ai_companion_test_set_object_terms_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_wp_insert_post_callback']   = null;
		$GLOBALS['aculect_ai_companion_test_wp_trash_post_callback']    = null;

		parent::tearDown();
	}

	public function test_denial_precedence_rejects_unknown_and_disabled_tools_before_pause_or_scope(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.list_items' ) );
		AccessLockdown::set_paused( true );
		$gateway = new AbilityExecutionGateway( $registry );
		$auth    = array(
			'user_id' => 1,
			'scopes'  => array(),
			'profile' => 'full_access',
		);

		$unknown  = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'not_a_real_tool',
					'arguments' => array(),
				),
				$auth
			)
		);
		$disabled = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_update_item',
					'arguments' => array( 'id' => 42 ),
				),
				$auth
			)
		);

		self::assertSame( AbilityExecutionGateway::OUTCOME_UNKNOWN_TOOL, $unknown->type );
		self::assertSame( AbilityExecutionGateway::OUTCOME_TOOL_ERROR, $disabled->type );
		self::assertSame( 'This ability is disabled in Aculect AI Companion settings.', $disabled->data['message'] ?? '' );
	}

	public function test_gateway_enforces_role_capability_and_dependency_without_profile_denial(): void {
		$gateway = new AbilityExecutionGateway();
		$GLOBALS['aculect_ai_companion_test_users'][2]->roles = array( 'author' );
		$role    = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_update_item',
					'arguments' => array(
						'id'    => 123,
						'title' => 'Role denial',
					),
				),
				$this->trusted_write_auth( 2 )
			)
		);
		$profile = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_update_item',
					'arguments' => array(
						'id'    => 123,
						'title' => 'Profile denial',
						'dry_run' => true,
					),
				),
				array_merge( $this->trusted_write_auth( 1 ), array( 'profile' => 'read_only_audit' ) )
			)
		);
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn (): bool => false;
		$capability = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_incident_report',
					'arguments' => array(
						'title'   => 'Capability denial',
						'summary' => 'Static capability policy must run in the gateway.',
					),
				),
				$this->trusted_write_auth( 1 )
			)
		);
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
		$dependency_registry                                      = new AbilitiesRegistry();
		$dependency_registry->save_enabled_ids( array( 'content_workflow.create_draft' ) );
		$dependency = ( new AbilityExecutionGateway( $dependency_registry ) )->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_workflow_create_draft',
					'arguments' => array(
						'title'   => 'Dependency denial',
						'content' => '<!-- wp:paragraph --><p>Safe block content.</p><!-- /wp:paragraph -->',
					),
				),
				$this->trusted_write_auth( 1 )
			)
		);

		self::assertSame( 'This ability is not available for the connected WordPress role.', $role->data['message'] ?? '' );
		self::assertSame( AbilityExecutionGateway::OUTCOME_SUCCESS, $profile->type );
		self::assertSame( 'preview', $profile->data['result']['status'] ?? '' );
		self::assertSame( 'This ability is not available for the connected WordPress capabilities.', $capability->data['message'] ?? '' );
		self::assertSame( 'This ability is disabled in Aculect AI Companion settings.', $dependency->data['message'] ?? '' );
		self::assertSame( 'Original title', get_post( 123 )?->post_title );
	}

	public function test_gateway_returns_pause_and_scope_challenge_outcomes_before_dispatch(): void {
		AccessLockdown::set_paused( true );
		$paused = ( new AbilityExecutionGateway() )->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_incident_report',
					'arguments' => array(
						'title'   => 'Paused',
						'summary' => 'Pause state must win before the write callback.',
					),
				),
				$this->trusted_write_auth( 1 )
			)
		);
		AccessLockdown::set_paused( false );
		$scope = ( new AbilityExecutionGateway() )->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_incident_report',
					'arguments' => array(
						'title'   => 'Scope',
						'summary' => 'Missing scope must produce a gateway challenge.',
					),
				),
				array_merge( $this->trusted_write_auth( 1 ), array( 'scopes' => array() ) )
			)
		);

		self::assertSame( AbilityExecutionGateway::OUTCOME_TOOL_ERROR, $paused->type );
		self::assertSame( 'AI access is paused in Aculect AI Companion settings.', $paused->data['message'] ?? '' );
		self::assertSame( AbilityExecutionGateway::OUTCOME_AUTH_CHALLENGE, $scope->type );
		self::assertSame( array( 'content:read' ), $scope->data['required_scopes'] ?? array() );
		self::assertSame( array(), get_option( 'aculect_ai_companion_incident_reports', array() ) );
	}

	public function test_gateway_confirmation_and_replay_strip_control_arguments_before_callback_dispatch(): void {
		$gateway = new AbilityExecutionGateway( null, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
		$auth    = $this->incident_auth();
		$args    = array(
			'title'   => 'Gateway confirmation contract',
			'summary' => 'The shared execution boundary must not dispatch a write before confirmation.',
		);

		$initial = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_incident_report',
					'arguments' => $args,
				),
				$auth
			)
		);

		self::assertSame( AbilityExecutionGateway::OUTCOME_SUCCESS, $initial->type );
		self::assertSame( 'confirmation_required', $initial->data['result']['status'] ?? '' );
		self::assertTrue( $initial->data['result']['preview']['dry_run'] ?? false );
		self::assertSame( array(), get_option( 'aculect_ai_companion_incident_reports', array() ) );

		$args['dry_run']            = true;
		$args['confirmation_token'] = (string) ( $initial->data['result']['confirmation_token'] ?? '' );
		$confirmed                  = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_incident_report',
					'arguments' => $args,
				),
				$auth
			)
		);
		$replayed                   = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_incident_report',
					'arguments' => $args,
				),
				$auth
			)
		);
		$reports                    = get_option( 'aculect_ai_companion_incident_reports', array() );

		self::assertSame( 'stored_ready_for_client_submission', $confirmed->data['result']['status'] ?? '' );
		self::assertTrue( $replayed->data['result']['replayed'] ?? false );
		self::assertCount( 1, $reports );
		self::assertArrayNotHasKey( 'dry_run', $reports[0] ?? array() );
		self::assertArrayNotHasKey( 'confirmation_token', $reports[0] ?? array() );
	}

	public function test_plugin_confirmation_binds_exact_package_and_hides_binding_from_transport(): void {
		$GLOBALS['aculect_ai_companion_test_is_multisite']           = false;
		$GLOBALS['aculect_ai_companion_test_plugins']                = array(
			'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '1.0.0' ),
		);
		$GLOBALS['aculect_ai_companion_test_plugin_api']              = (object) array(
			'name'          => 'Classic Editor',
			'version'       => '1.6.0',
			'download_link' => 'https://downloads.wordpress.org/plugin/classic-editor.zip',
		);
		$GLOBALS['aculect_ai_companion_test_plugin_to_install']       = array(
			'file'    => 'classic-editor/classic-editor.php',
			'headers' => array( 'Name' => 'Classic Editor', 'Version' => '1.6.0' ),
		);
		$GLOBALS['aculect_ai_companion_test_plugin_install_result']   = true;
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'plugin_lifecycle.install_plugin' ) );
		$auth    = array_merge(
			$this->trusted_write_auth( 1 ),
			array(
				'access_level'             => '',
				'write_permission_enabled' => false,
			)
		);
		$gateway = new AbilityExecutionGateway( $registry, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
		$initial = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_lifecycle_install_plugin',
					'arguments' => array( 'slug' => 'classic-editor' ),
				),
				$auth
			)
		);

		self::assertSame( 'confirmation_required', $initial->data['result']['status'] ?? '' );
		self::assertStringNotContainsString( 'classic-editor.zip', wp_json_encode( $initial->data['result'] ) );
		$GLOBALS['aculect_ai_companion_test_plugin_api']->download_link = 'https://example.com/changed.zip';
		$confirmed = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_lifecycle_install_plugin',
					'arguments' => array(
						'slug'              => 'classic-editor',
						'confirmation_token' => (string) ( $initial->data['result']['confirmation_token'] ?? '' ),
					),
				),
				$auth
			)
		);

		self::assertSame( 'installed', $confirmed->data['result']['status'] ?? '' );
		self::assertSame( 'https://downloads.wordpress.org/plugin/classic-editor.zip', $GLOBALS['aculect_ai_companion_test_last_plugin_package'] );
		self::assertStringNotContainsString( PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY, wp_json_encode( $confirmed->data ) );
	}

	public function test_plugin_install_requires_confirmation_for_current_and_legacy_write_auth(): void {
		$auth_variants = array(
			'connection_access_level' => $this->trusted_write_auth( 1 ),
			'legacy_write_flag'       => array_merge(
				$this->trusted_write_auth( 1 ),
				array(
					'access_level'             => '',
					'write_permission_enabled' => true,
				)
			),
		);

		foreach ( $auth_variants as $auth ) {
			$GLOBALS['aculect_ai_companion_test_plugins']              = array();
			$GLOBALS['aculect_ai_companion_test_plugin_api']           = (object) array(
				'name'          => 'Classic Editor',
				'version'       => '1.6.0',
				'download_link' => 'https://downloads.wordpress.org/plugin/classic-editor.zip',
			);
			$GLOBALS['aculect_ai_companion_test_plugin_to_install']    = array(
				'file'    => 'classic-editor/classic-editor.php',
				'headers' => array( 'Name' => 'Classic Editor', 'Version' => '1.6.0' ),
			);
			$GLOBALS['aculect_ai_companion_test_last_plugin_package'] = '';
			$registry = new AbilitiesRegistry();
			$registry->save_enabled_ids( array( 'plugin_lifecycle.install_plugin' ) );
			$gateway = new AbilityExecutionGateway( $registry, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
			$result  = $gateway->execute(
				new AbilityExecutionRequest(
					array(
						'name'      => 'plugin_lifecycle_install_plugin',
						'arguments' => array( 'slug' => 'classic-editor' ),
					),
					$auth
				)
			);

			self::assertSame( AbilityExecutionGateway::OUTCOME_SUCCESS, $result->type );
			self::assertSame( 'confirmation_required', $result->data['result']['status'] ?? '' );
			self::assertSame( '', $GLOBALS['aculect_ai_companion_test_last_plugin_package'] );
			self::assertArrayNotHasKey( 'classic-editor/classic-editor.php', $GLOBALS['aculect_ai_companion_test_plugins'] );
		}
	}

	public function test_plugin_update_requires_confirmation_and_executes_only_with_bound_package(): void {
		$auth_variants = array(
			$this->trusted_write_auth( 1 ),
			array_merge(
				$this->trusted_write_auth( 1 ),
				array(
					'access_level'             => '',
					'write_permission_enabled' => true,
				)
			),
		);

		foreach ( $auth_variants as $auth ) {
			$GLOBALS['aculect_ai_companion_test_is_multisite']              = false;
			$GLOBALS['aculect_ai_companion_test_plugins']                   = array(
				'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '1.0.0' ),
			);
			$GLOBALS['aculect_ai_companion_test_site_options']              = array(
				'_site_transient_update_plugins' => (object) array(
					'last_checked' => time(),
					'response'     => array(
						'acme/acme.php' => (object) array(
							'new_version'  => '2.0.0',
							'requires'     => '6.7',
							'requires_php' => '8.2',
							'package'      => 'https://downloads.wordpress.org/plugin/acme.2.0.0.zip',
						),
					),
				),
			);
			$GLOBALS['aculect_ai_companion_test_plugin_update_versions']    = array( 'acme/acme.php' => '2.0.0' );
			$GLOBALS['aculect_ai_companion_test_last_plugin_upgrade_package'] = '';
			$registry = new AbilitiesRegistry();
			$registry->save_enabled_ids( array( 'plugin_lifecycle.update_plugin' ) );
			$gateway = new AbilityExecutionGateway( $registry, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
			$initial = $gateway->execute(
				new AbilityExecutionRequest(
					array(
						'name'      => 'plugin_lifecycle_update_plugin',
						'arguments' => array( 'plugin' => 'acme/acme.php' ),
					),
					$auth
				)
			);

			self::assertSame( 'confirmation_required', $initial->data['result']['status'] ?? '' );
			self::assertSame( '1.0.0', $GLOBALS['aculect_ai_companion_test_plugins']['acme/acme.php']['Version'] );
			self::assertSame( '', $GLOBALS['aculect_ai_companion_test_last_plugin_upgrade_package'] );
			$confirmed = $gateway->execute(
				new AbilityExecutionRequest(
					array(
						'name'      => 'plugin_lifecycle_update_plugin',
						'arguments' => array(
							'plugin'             => 'acme/acme.php',
							'confirmation_token' => (string) ( $initial->data['result']['confirmation_token'] ?? '' ),
						),
					),
					$auth
				)
			);

			self::assertSame( 'updated', $confirmed->data['result']['status'] ?? '' );
			self::assertSame( '2.0.0', $GLOBALS['aculect_ai_companion_test_plugins']['acme/acme.php']['Version'] );
			self::assertSame( 'https://downloads.wordpress.org/plugin/acme.2.0.0.zip', $GLOBALS['aculect_ai_companion_test_last_plugin_upgrade_package'] );
		}
	}

	public function test_plugin_activation_changes_require_confirmation_for_trusted_write_auth(): void {
		$auth_variants = array(
			$this->trusted_write_auth( 1 ),
			array_merge(
				$this->trusted_write_auth( 1 ),
				array(
					'access_level'             => '',
					'write_permission_enabled' => true,
				)
			),
		);

		foreach ( array( 'activate', 'deactivate' ) as $operation ) {
			foreach ( $auth_variants as $auth ) {
				$GLOBALS['aculect_ai_companion_test_plugins']                 = array(
					'acme/acme.php' => array( 'Name' => 'Acme', 'Version' => '1.0.0' ),
				);
				$GLOBALS['aculect_ai_companion_test_active_plugins']          = 'activate' === $operation ? array() : array( 'acme/acme.php' );
				$GLOBALS['aculect_ai_companion_test_last_plugin_activation']   = '';
				$GLOBALS['aculect_ai_companion_test_last_plugin_deactivation'] = array();
				$GLOBALS['aculect_ai_companion_test_options']                  = array( 'active_plugins' => $GLOBALS['aculect_ai_companion_test_active_plugins'] );
				$registry = new AbilitiesRegistry();
				$registry->save_enabled_ids( array( 'plugin_lifecycle.' . $operation . '_plugin' ) );
				$gateway = new AbilityExecutionGateway( $registry, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
				$result  = $gateway->execute(
					new AbilityExecutionRequest(
						array(
							'name'      => 'plugin_lifecycle_' . $operation . '_plugin',
							'arguments' => array( 'plugin' => 'acme/acme.php' ),
						),
						$auth
					)
				);

				self::assertSame( AbilityExecutionGateway::OUTCOME_SUCCESS, $result->type );
				self::assertSame( 'confirmation_required', $result->data['result']['status'] ?? '' );
				self::assertSame( 'activate' === $operation ? array() : array( 'acme/acme.php' ), $GLOBALS['aculect_ai_companion_test_active_plugins'] );
				if ( 'activate' === $operation ) {
					self::assertSame( '', $GLOBALS['aculect_ai_companion_test_last_plugin_activation'] );
				} else {
					self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_last_plugin_deactivation'] );
				}
			}
		}
	}

	public function test_standard_write_dry_run_confirmation_and_idempotent_replay_are_gateway_owned(): void {
		$safety = new ToolSafety( new InMemoryExecutionClaimStore() );
		$safety->save_confirmation_groups( array( 'Content' ) );
		$gateway = new AbilityExecutionGateway( null, null, null, $safety );
		$dry_run = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_update_item',
					'arguments' => array(
						'id'      => 123,
						'title'   => 'Preview only',
						'dry_run' => true,
					),
				),
				$this->trusted_write_auth( 1 )
			)
		);
		self::assertTrue( $dry_run->data['result']['dry_run'] ?? false );
		self::assertSame( 'Original title', get_post( 123 )?->post_title );

		$invalid_confirmation = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_update_item',
					'arguments' => array(
						'id'                 => 123,
						'title'              => 'Invalid confirmation',
						'confirmation_token' => 'not-a-valid-token',
					),
				),
				array_merge(
					$this->trusted_write_auth( 1 ),
					array(
						'access_level'             => '',
						'write_permission_enabled' => false,
					)
				)
			)
		);
		$write_args           = array(
			'id'              => 123,
			'title'           => 'Committed once',
			'idempotency_key' => 'gateway-standard-write-replay',
		);
		$written              = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_update_item',
					'arguments' => $write_args,
				),
				$this->trusted_write_auth( 1 )
			)
		);
		$replayed             = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_update_item',
					'arguments' => $write_args,
				),
				$this->trusted_write_auth( 1 )
			)
		);

		self::assertSame( 'blocked', $invalid_confirmation->data['result']['status'] ?? '' );
		self::assertSame( 'invalid_confirmation_token', $invalid_confirmation->data['result']['error'] ?? '' );
		self::assertArrayNotHasKey( 'error', $written->data['result'] ?? array() );
		self::assertTrue( $replayed->data['result']['replayed'] ?? false );
		self::assertSame( 'Committed once', get_post( 123 )?->post_title );
	}

	public function test_workflow_mutation_confirmation_preview_never_dispatches_connector_callback(): void {
		$safety = new ToolSafety( new InMemoryExecutionClaimStore() );
		$safety->save_confirmation_groups( array( 'Custom Content Workflows' ) );
		$gateway = new AbilityExecutionGateway( null, null, null, $safety );
		$result  = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_workflow_execute',
					'arguments' => array(
						'run_id' => 'run-preview-only',
						'input'  => array(),
					),
				),
				array_merge(
					$this->trusted_write_auth( 1 ),
					array(
						'access_level'             => '',
						'write_permission_enabled' => false,
					)
				)
			)
		);

		self::assertSame( AbilityExecutionGateway::OUTCOME_SUCCESS, $result->type );
		self::assertSame( 'confirmation_required', $result->data['result']['status'] ?? '' );
		self::assertTrue( $result->data['result']['preview']['preview_only'] ?? false );
		self::assertTrue( $result->data['result']['preview']['mutation_blocked'] ?? false );
		self::assertSame( 'content_workflow.execute', $result->data['result']['preview']['action'] ?? '' );
	}

	public function test_atomic_claim_returns_bounded_in_progress_without_dispatching_the_contender(): void {
		$store  = new InMemoryExecutionClaimStore();
		$safety = new ToolSafety( $store );
		$auth   = $this->trusted_write_auth( 1 );
		$args   = array(
			'id'              => 123,
			'title'           => 'Atomic contender',
			'idempotency_key' => 'atomic-contender-key',
		);
		$owner  = $safety->claim_write_execution( 'content.update_item', $args, $auth, true );
		self::assertSame( 'acquired', $owner->type );

		$result = ( new AbilityExecutionGateway( null, null, null, $safety ) )->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_update_item',
					'arguments' => $args,
				),
				$auth
			)
		);

		self::assertSame( 'execution_in_progress', $result->data['result']['error'] ?? '' );
		self::assertSame( 5, $result->data['result']['retry_after'] ?? 0 );
		self::assertSame( 'Original title', get_post( 123 )?->post_title );
		self::assertStringNotContainsString( 'atomic-contender-key', (string) wp_json_encode( $result->data ) );
	}

	public function test_normal_callback_error_releases_claim_for_same_payload_retry(): void {
		$store   = new InMemoryExecutionClaimStore();
		$gateway = new AbilityExecutionGateway( null, null, null, new ToolSafety( $store ) );
		$auth    = $this->trusted_write_auth( 1 );
		$args    = array(
			'id'              => 999,
			'title'           => 'Retry after normal error',
			'idempotency_key' => 'normal-error-retry',
		);
		$failed  = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_update_item',
					'arguments' => $args,
				),
				$auth
			)
		);
		self::assertSame( 'not_found', $failed->data['result']['error'] ?? '' );

		$GLOBALS['aculect_ai_companion_test_posts'][999] = new \WP_Post(
			array(
				'ID'           => 999,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => 'Before retry',
				'post_content' => '',
			)
		);
		$retried = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_update_item',
					'arguments' => $args,
				),
				$auth
			)
		);
		self::assertArrayNotHasKey( 'error', $retried->data['result'] ?? array() );
		self::assertSame( 'Retry after normal error', get_post( 999 )?->post_title );
	}

	public function test_terminal_partial_write_is_completed_and_replayed_without_retrying_the_insert(): void {
		$store   = new InMemoryExecutionClaimStore();
		$gateway = new AbilityExecutionGateway( null, null, null, new ToolSafety( $store ) );
		$args    = array(
			'title'           => 'Needs manual recovery',
			'taxonomies'      => array( 'category' => array( 1 ) ),
			'idempotency_key' => 'terminal-partial-write',
		);
		$GLOBALS['aculect_ai_companion_test_taxonomies']                = array(
			'category' => new \WP_Taxonomy( 'category', array( 'hierarchical' => true ) ),
		);
		$GLOBALS['aculect_ai_companion_test_terms']                     = array(
			'category' => array(
				1 => new \WP_Term(
					array(
						'term_id'  => 1,
						'name'     => 'News',
						'taxonomy' => 'category',
					)
				),
			),
		);
		$GLOBALS['aculect_ai_companion_test_set_object_terms_callback'] = static fn(): \WP_Error => new \WP_Error( 'term_failure', 'Terms failed.' );
		$insertions = 0;
		$GLOBALS['aculect_ai_companion_test_wp_insert_post_callback'] = static function ( array $postarr ) use ( &$insertions ): int {
			unset( $postarr );
			++$insertions;
			return 900 + $insertions;
		};
		$GLOBALS['aculect_ai_companion_test_wp_trash_post_callback']  = static fn(): false => false;
		$auth = $this->trusted_write_auth( 1 );

		$first = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_create_item',
					'arguments' => $args,
				),
				$auth
			)
		);
		$retry = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_create_item',
					'arguments' => $args,
				),
				$auth
			)
		);

		self::assertSame( 'partial_write', $first->data['result']['error'] ?? '' );
		self::assertTrue( $first->data['result']['terminal'] ?? false );
		self::assertSame( 'partial_write', $retry->data['result']['error'] ?? '' );
		self::assertSame( 1, $insertions );
	}

	public function test_thrown_claimed_callback_becomes_uncertain_and_never_retries(): void {
		$store   = new InMemoryExecutionClaimStore();
		$gateway = new AbilityExecutionGateway( null, null, null, new ToolSafety( $store ) );
		$auth    = array_merge(
			$this->incident_auth(),
			array(
				'access_level'             => ConnectionAccessLevel::WRITE,
				'write_permission_enabled' => true,
			)
		);
		$args    = array(
			'title'           => 'Ambiguous callback',
			'summary'         => 'Thrown callbacks must never retry automatically.',
			'idempotency_key' => 'ambiguous-callback-key',
		);
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_github_incident_reporter_repository'] = static function (): string {
			throw new \RuntimeException( 'Ambiguous callback failure.' );
		};

		$first = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_incident_report',
					'arguments' => $args,
				),
				$auth
			)
		);
		unset( $GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_github_incident_reporter_repository'] );
		$retry = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_incident_report',
					'arguments' => $args,
				),
				$auth
			)
		);

		self::assertSame( 'execution_uncertain', $first->data['result']['error'] ?? '' );
		self::assertSame( 'execution_uncertain', $retry->data['result']['error'] ?? '' );
		self::assertSame( array(), get_option( 'aculect_ai_companion_incident_reports', array() ) );
		self::assertStringNotContainsString( 'ambiguous-callback-key', (string) wp_json_encode( $retry->data ) );
	}

	public function test_gateway_trusted_write_and_activity_failures_do_not_change_execution_result(): void {
		$gateway = new AbilityExecutionGateway(
			null,
			null,
			null,
			null,
			static function (): ActivityLogger {
				throw new \RuntimeException( 'Activity storage unavailable.' );
			},
			static function (): Logger {
				throw new \RuntimeException( 'Diagnostic storage unavailable.' );
			}
		);
		$result  = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_incident_report',
					'arguments' => array(
						'title'   => 'Trusted gateway write',
						'summary' => 'Activity failure must not change the protected write result.',
					),
				),
				array_merge(
					$this->incident_auth(),
					array(
						'access_level'             => ConnectionAccessLevel::WRITE,
						'write_permission_enabled' => true,
					)
				)
			)
		);

		self::assertSame( AbilityExecutionGateway::OUTCOME_SUCCESS, $result->type );
		self::assertSame( 'stored_ready_for_client_submission', $result->data['result']['status'] ?? '' );
		self::assertSame( 'trusted_connection_direct_write', $result->data['result']['confirmation_policy'] ?? '' );
		self::assertFalse( $result->data['result']['confirmation_required'] ?? true );
		self::assertCount( 1, get_option( 'aculect_ai_companion_incident_reports', array() ) );
	}

	public function test_gateway_validates_advertised_aliases_without_rewriting_callback_arguments(): void {
		$result = ( new AbilityExecutionGateway() )->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_internal_link_suggestion_review',
					'arguments' => array(
						'suggestion_id' => 'missing-suggestion',
						'status'        => 'approved',
					),
				),
				array(
					'user_id' => 1,
					'scopes'  => array( 'content:read', 'content:draft' ),
					'profile' => 'full_access',
				)
			)
		);

		self::assertNotSame( AbilityExecutionGateway::OUTCOME_INVALID_PARAMS, $result->type );
		self::assertSame( AbilityExecutionGateway::OUTCOME_SUCCESS, $result->type );
		self::assertSame( 'suggestion_not_found', $result->data['result']['error'] ?? '' );
	}

	public function test_standard_object_write_uses_oauth_actor_and_restores_previous_wordpress_user(): void {
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability, array $args, int $user_id ): bool {
			unset( $capability, $args );

			return 1 === $user_id;
		};
		$gateway = new AbilityExecutionGateway();
		$low     = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_update_item',
					'arguments' => array(
						'id'    => 123,
						'title' => 'Unauthorized title',
					),
				),
				$this->trusted_write_auth( 2 )
			)
		);

		self::assertSame( AbilityExecutionGateway::OUTCOME_SUCCESS, $low->type );
		self::assertSame( 'forbidden', $low->data['result']['error'] ?? '' );
		self::assertSame( 'Original title', get_post( 123 )?->post_title );
		self::assertSame( 1, get_current_user_id() );

		$matching = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_update_item',
					'arguments' => array(
						'id'    => 123,
						'title' => 'Authorized title',
					),
				),
				$this->trusted_write_auth( 1 )
			)
		);

		self::assertSame( AbilityExecutionGateway::OUTCOME_SUCCESS, $matching->type );
		self::assertArrayNotHasKey( 'error', $matching->data['result'] ?? array() );
		self::assertSame( 'Authorized title', get_post( 123 )?->post_title );
		self::assertSame( 1, get_current_user_id() );
	}

	public function test_plugin_incident_report_cannot_inherit_admin_actor_and_matching_actor_still_works(): void {
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability, array $args, int $user_id ): bool {
			unset( $capability, $args );

			return 1 === $user_id;
		};
		$gateway = new AbilityExecutionGateway();
		$args    = array(
			'title'   => 'Actor-bound incident report',
			'summary' => 'The authenticated OAuth user must own the capability check and stored report.',
		);
		$low     = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_incident_report',
					'arguments' => $args,
				),
				$this->trusted_write_auth( 2 )
			)
		);

		self::assertSame( AbilityExecutionGateway::OUTCOME_TOOL_ERROR, $low->type );
		self::assertSame( 'This ability is not available for the connected WordPress capabilities.', $low->data['message'] ?? '' );
		self::assertSame( array(), get_option( 'aculect_ai_companion_incident_reports', array() ) );
		self::assertSame( 1, get_current_user_id() );

		$matching = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'plugin_incident_report',
					'arguments' => $args,
				),
				$this->trusted_write_auth( 1 )
			)
		);

		self::assertSame( AbilityExecutionGateway::OUTCOME_SUCCESS, $matching->type );
		self::assertSame( 'stored_ready_for_client_submission', $matching->data['result']['status'] ?? '' );
		self::assertCount( 1, get_option( 'aculect_ai_companion_incident_reports', array() ) );
		self::assertSame( 1, get_current_user_id() );
	}

	public function test_gateway_restores_previous_wordpress_user_when_module_callback_throws(): void {
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 2;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_github_incident_reporter_repository'] = static function ( string $repository ): string {
			unset( $repository );

			throw new \RuntimeException( 'Callback failure.' );
		};

		try {
			( new AbilityExecutionGateway() )->execute(
				new AbilityExecutionRequest(
					array(
						'name'      => 'plugin_incident_report',
						'arguments' => array(
							'title'   => 'Actor restoration',
							'summary' => 'A module callback failure must not leak the OAuth actor to later work.',
						),
					),
					$this->trusted_write_auth( 1 )
				)
			);
			self::fail( 'Expected the injected module callback exception.' );
		} catch ( \RuntimeException $throwable ) {
			self::assertSame( 'Callback failure.', $throwable->getMessage() );
		}

		self::assertSame( 2, get_current_user_id() );
	}

	/**
	 * Build the default OAuth context for incident-report gateway calls.
	 *
	 * @return array<string, mixed>
	 */
	private function incident_auth(): array {
		return array(
			'user_id'   => 1,
			'client_id' => 'gateway-test-client',
			'provider'  => 'chatgpt',
			'scopes'    => array( 'content:read' ),
			'profile'   => 'full_access',
		);
	}

	/**
	 * Build a direct-write OAuth context for the selected WordPress user.
	 *
	 * @param int $user_id Authenticated WordPress user ID.
	 * @return array<string, mixed>
	 */
	private function trusted_write_auth( int $user_id ): array {
		return array(
			'user_id'                  => $user_id,
			'client_id'                => 'gateway-actor-test-client',
			'provider'                 => 'chatgpt',
			'scopes'                   => array( 'content:read', 'content:draft' ),
			'profile'                  => 'full_access',
			'access_level'             => ConnectionAccessLevel::WRITE,
			'write_permission_enabled' => true,
		);
	}
}

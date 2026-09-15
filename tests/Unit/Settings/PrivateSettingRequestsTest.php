<?php
/**
 * Private input lifecycle, privacy and write-boundary regression tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Settings
 */

declare(strict_types=1);
namespace Aculect\AICompanion\Tests\Unit\Settings;

use Aculect\AICompanion\Settings\PrivateSettingRequests;
use Aculect\AICompanion\Settings\PrivateSettingTargets;
use Aculect\AICompanion\Settings\PrivateSettingLock;
use Aculect\AICompanion\Settings\PrivateSettingAudit;
use Aculect\AICompanion\Connectors\MCP\Modules\PrivateSettingsAbilityModules;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/fixtures/private-settings-stubs.php';

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.FunctionComment.MissingParamTag, WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused stateful boundary double.

final class PrivateSettingRequestsTest extends TestCase {
	private mixed $wpdb;
	protected function setUp(): void {
		$this->wpdb                                   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']                              = new PrivateSettingsWpdb();
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array();
		$GLOBALS['aculect_ai_companion_test_failed_option_adds']    = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']           = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback']   = null;
		$GLOBALS['aculect_ai_companion_test_current_user_id']       = 1;
		$GLOBALS['aculect_ai_companion_test_blog_id']               = 1;
		$GLOBALS['private_settings_ssl']                            = true;
		$GLOBALS['private_settings_meta']                           = array();
		$GLOBALS['private_settings_provider']                       = true;
		$GLOBALS['private_settings_key_valid']                      = true;
		$GLOBALS['private_settings_provider_throws']                = false;
		unset( $GLOBALS['private_settings_override_option'] );
	}
	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb; }

	public function test_secret_is_stored_only_at_native_destination_not_request_or_result(): void {
		$service = new PrivateSettingRequests();
		$begin   = $service->begin( 'openai_api_key' );
		self::assertSame( 'input_required', $begin['status'] );
		$result = $service->submit( $begin['request_id'], 'fixture-key-ONLY-NATIVE' );
		self::assertSame( 'updated', $result['status'] );
		self::assertSame( 'fixture-key-ONLY-NATIVE', get_option( 'connectors_ai_openai_api_key' ) );
		self::assertStringNotContainsString( 'fixture-key-ONLY-NATIVE', wp_json_encode( array( $begin, $result, $service->status( $begin['request_id'] ), $GLOBALS['private_settings_meta'] ) ) );
		self::assertSame( 'error', $service->submit( $begin['request_id'], 'second-key' )['status'] );
		self::assertSame( 'fixture-key-ONLY-NATIVE', get_option( 'connectors_ai_openai_api_key' ) );
	}
	public function test_rejected_or_throwing_provider_preserves_existing_key(): void {
		update_option( 'connectors_ai_openai_api_key', 'previous-fixture' );
		$service = new PrivateSettingRequests();
		foreach ( array( false, true ) as $throws ) {
			$GLOBALS['private_settings_key_valid']       = false;
			$GLOBALS['private_settings_provider_throws'] = $throws;
			$request                                     = $service->begin( 'openai_api_key' );
			$result                                      = $service->submit( $request['request_id'], 'rejected-fixture' );
			self::assertSame( 'failed', $result['status'] );
			self::assertSame( 'previous-fixture', get_option( 'connectors_ai_openai_api_key' ) );
			self::assertStringNotContainsString( 'sensitive', wp_json_encode( $result ) );
		}
	}
	public function test_wrong_actor_expired_and_superseded_requests_cannot_write(): void {
		$service = new PrivateSettingRequests();
		$request = $service->begin( 'site_title' );
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 2;
		self::assertSame( 'error', $service->submit( $request['request_id'], 'Wrong actor' )['status'] );
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$service->begin( 'tagline' );
		self::assertSame( 'error', $service->submit( $request['request_id'], 'Old request' )['status'] );
		$request = $service->begin( 'site_title' );
		$GLOBALS['private_settings_meta'][1]['_aculect_private_setting_request']['expires'] = time() - 1;
		self::assertSame( 'error', $service->submit( $request['request_id'], 'Expired' )['status'] );
		self::assertFalse( get_option( 'blogname' ) );
	}
	public function test_https_permissions_and_fixed_targets_are_required(): void {
		$service = new PrivateSettingRequests();
		self::assertSame( 'error', $service->begin( 'arbitrary_option' )['status'] );
		$GLOBALS['private_settings_ssl'] = false;
		self::assertSame( 'error', $service->begin( 'site_title' )['status'] );
		$GLOBALS['private_settings_ssl']                  = true;
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		self::assertSame( 'error', $service->begin( 'site_title' )['status'] );
	}
	public function test_stale_writes_preserve_changes_made_since_request(): void {
		$service = new PrivateSettingRequests();
		$request = $service->begin( 'site_title' );
		update_option( 'blogname', 'Newer title' );
		self::assertSame( 'stale', $service->submit( $request['request_id'], 'Old title' )['status'] );
		self::assertSame( 'Newer title', get_option( 'blogname' ) );
	}
	public function test_busy_lock_cannot_be_removed_by_losing_request(): void {
		$lock = new PrivateSettingLock();
		self::assertTrue( $lock->acquire( 'site_title' ) );
		$service = new PrivateSettingRequests();
		$request = $service->begin( 'site_title' );
		self::assertSame( 'busy', $service->submit( $request['request_id'], 'Blocked' )['status'] );
		self::assertTrue( $lock->valid() );
		$lock->release();
		self::assertTrue( ( new PrivateSettingLock() )->acquire( 'site_title' ) );
	}
	public function test_invalid_values_and_remapped_connector_options_are_rejected(): void {
		$targets = new PrivateSettingTargets();
		foreach ( array( '-1', '101', '1.5', 'invalid' ) as $value ) {
			self::assertNull( $targets->validate( 'posts_per_page', $value ) ); }
		self::assertSame( 25, $targets->validate( 'posts_per_page', '25' ) );
		self::assertNull( $targets->validate( 'timezone', 'Not/AZone' ) );
		self::assertSame( 'Asia/Kolkata', $targets->validate( 'timezone', 'Asia/Kolkata' ) );
		$GLOBALS['private_settings_override_option'] = 'admin_email';
		self::assertFalse( $targets->available( 'openai_api_key' ) );
	}
	public function test_mcp_schema_contains_no_value_input(): void {
		$modules = ( new PrivateSettingsAbilityModules() )->all();
		foreach ( $modules as $module ) {
			$schema = $module->input_schema();
			self::assertArrayNotHasKey( 'value', $schema['properties'] );
			self::assertArrayNotHasKey( 'private_value', $schema['properties'] );
			self::assertFalse( $schema['additionalProperties'] );
		}
	}
	public function test_malformed_input_and_storage_failure_do_not_replace_option(): void {
		$service = new PrivateSettingRequests();
		update_option( 'blogname', 'Original' );
		foreach ( array( null, false, 42, array(), new \stdClass() ) as $value ) {
			$request = $service->begin( 'site_title' );
			self::assertSame( 'error', $service->submit( $request['request_id'], $value )['status'] );
			self::assertSame( 'Original', get_option( 'blogname' ) );
		}
		$request = $service->begin( 'site_title' );
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array( 'blogname' );
		self::assertSame( 'failed', $service->submit( $request['request_id'], 'Replacement' )['status'] );
		self::assertSame( 'Original', get_option( 'blogname' ) );
	}
	public function test_empty_titles_and_whitespace_keys_are_rejected(): void {
		$targets = new PrivateSettingTargets();
		self::assertNull( $targets->validate( 'site_title', '   ' ) );
		self::assertSame( '', $targets->validate( 'tagline', '' ) );
		foreach ( array( '', "fixture\nkey", 'fixture key', str_repeat( 'a', 4097 ) ) as $value ) {
			self::assertNull( $targets->validate( 'openai_api_key', $value ) );
		}
	}
	public function test_audit_accepts_only_fixed_target_and_outcome_without_private_input(): void {
		$audit = new PrivateSettingAudit();
		$audit->record( 'openai_api_key', 'updated' );
		$rows = $GLOBALS['wpdb']->rows;
		self::assertCount( 1, $rows );
		self::assertSame( 'settings.private_submit', $rows[0]['action'] );
		self::assertSame( 'success', $rows[0]['status'] );
		self::assertSame(
			array(
				'target'     => 'openai_api_key',
				'status'     => 'updated',
				'risk_level' => 'system',
			),
			json_decode( $rows[0]['context'], true )
		);
		$audit->record( 'arbitrary-secret-fixture', 'updated' );
		$audit->record( 'openai_api_key', 'arbitrary-secret-fixture' );
		self::assertCount( 1, $GLOBALS['wpdb']->rows );
		$audit->record( 'site_title', 'stale' );
		self::assertSame( 'error', $GLOBALS['wpdb']->rows[1]['status'] );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		$audit->record( 'site_title', 'updated' );
		self::assertCount( 2, $GLOBALS['wpdb']->rows );
	}
	public function test_request_is_bound_to_its_originating_site_on_multisite(): void {
		$service                                      = new PrivateSettingRequests();
		$request                                      = $service->begin( 'openai_api_key' );
		$GLOBALS['aculect_ai_companion_test_blog_id'] = 2;
		self::assertSame( 'error', $service->status( $request['request_id'] )['status'] );
		self::assertSame( 'error', $service->submit( $request['request_id'], 'cross-site-fixture' )['status'] );
		self::assertFalse( get_option( 'connectors_ai_openai_api_key' ) );
		$GLOBALS['aculect_ai_companion_test_blog_id'] = 1;
		self::assertSame( 'pending', $service->status( $request['request_id'] )['status'] );
		self::assertSame( 'updated', $service->submit( $request['request_id'], 'same-site-fixture' )['status'] );
	}
	public function test_request_without_site_binding_is_rejected(): void {
		$service = new PrivateSettingRequests();
		$request = $service->begin( 'site_title' );
		unset( $GLOBALS['private_settings_meta'][1]['_aculect_private_setting_request']['blog_id'] );
		self::assertSame( 'error', $service->submit( $request['request_id'], 'Legacy fixture' )['status'] );
		self::assertFalse( get_option( 'blogname' ) );
	}
	public function test_title_validation_matches_native_html_escaped_storage(): void {
		$targets = new PrivateSettingTargets();
		self::assertSame( 'R&amp;D &quot;Lab&quot;', $targets->validate( 'site_title', 'R&D "Lab"' ) );
		self::assertSame( 'Research &amp; development', $targets->validate( 'tagline', 'Research & development' ) );
	}
}

final class PrivateSettingsWpdb {
	public string $options = 'wp_options';
	public string $prefix  = 'wp_';
	public array $rows     = array();
	private array $args    = array();
	public function insert( string $table, array $data, array $formats ): int {
		assert( count( $data ) === count( $formats ) && '' !== $table );
		$this->rows[] = $data;
		return 1;
	}
	public function prepare( string $query, mixed ...$args ): string {
		$this->args = $args;
		return $query; }
	public function query( string $query ): int {
		assert( str_starts_with( $query, 'DELETE FROM' ) );
		$name = $this->args[1];
		if ( ( $GLOBALS['aculect_ai_companion_test_options'][ $name ] ?? null ) !== $this->args[2] ) {
			return 0; }
		unset( $GLOBALS['aculect_ai_companion_test_options'][ $name ] );
		return 1;
	}
}

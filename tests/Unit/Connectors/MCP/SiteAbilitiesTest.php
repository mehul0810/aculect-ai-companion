<?php
/**
 * Tests for MCP site administration abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\SiteAbilities;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/site-workflow-stubs.php';

/**
 * Verifies safe read-only site administration inventory.
 */
final class SiteAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_denied_caps']            = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite']           = false;
		$GLOBALS['aculect_ai_companion_test_using_ext_object_cache'] = false;
		$GLOBALS['aculect_ai_companion_test_cache_supports']         = array();
		$GLOBALS['aculect_ai_companion_test_options']                = array(
			'permalink_structure' => '/%postname%/',
			'rewrite_rules'       => array(
				'^post/([^/]+)/?$' => 'index.php?name=$matches[1]',
				'^page/([^/]+)/?$' => 'index.php?pagename=$matches[1]',
				'^feed/?$'         => 'index.php?feed=rss2',
			),
		);
		$GLOBALS['aculect_ai_companion_test_cron_array']             = array(
			1_789_000_000 => array(
				'aculect_first_event' => array(
					'abc123' => array(
						'schedule' => 'hourly',
						'args'     => array( 'sensitive-value' ),
					),
				),
			),
			1_789_000_100 => array(
				'aculect_second_event' => array(
					'def456' => array(
						'schedule' => false,
						'args'     => array(),
					),
				),
			),
		);
	}

	public function test_list_cron_events_returns_bounded_redacted_events(): void {
		$result = ( new SiteAbilities() )->list_cron_events( array( 'per_page' => 1_000 ) );

		self::assertSame( 2, $result['total'] );
		self::assertSame( 100, $result['per_page'] );
		self::assertFalse( $result['truncated'] );
		self::assertSame( 'aculect_first_event', $result['items'][0]['hook'] );
		self::assertSame( 1, $result['items'][0]['argument_count'] );
		self::assertArrayNotHasKey( 'args', $result['items'][0] );
	}

	public function test_list_cron_events_truncates_output(): void {
		$result = ( new SiteAbilities() )->list_cron_events( array( 'per_page' => 1 ) );

		self::assertCount( 1, $result['items'] );
		self::assertSame( 2, $result['total'] );
		self::assertTrue( $result['truncated'] );
	}

	public function test_list_cron_events_requires_manage_options(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );

		$result = ( new SiteAbilities() )->list_cron_events();

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_get_rewrite_rules_returns_bounded_permalink_summary(): void {
		$result = ( new SiteAbilities() )->get_rewrite_rules( array( 'per_page' => 2 ) );

		self::assertSame( '/%postname%/', $result['permalink_structure'] );
		self::assertTrue( $result['pretty_permalinks'] );
		self::assertSame( 3, $result['total'] );
		self::assertCount( 2, $result['items'] );
		self::assertTrue( $result['truncated'] );
		self::assertSame( '^post/([^/]+)/?$', $result['items'][0]['match'] );
	}

	public function test_get_rewrite_rules_requires_manage_options(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );

		$result = ( new SiteAbilities() )->get_rewrite_rules();

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_get_cache_status_reports_safe_cache_and_multisite_state(): void {
		$GLOBALS['aculect_ai_companion_test_is_multisite']           = true;
		$GLOBALS['aculect_ai_companion_test_using_ext_object_cache'] = true;
		$GLOBALS['aculect_ai_companion_test_cache_supports']         = array( 'get_multiple', 'flush_group' );

		$result = ( new SiteAbilities() )->get_cache_status();

		self::assertTrue( $result['using_ext_object_cache'] );
		self::assertTrue( $result['object_cache_supports']['get_multiple'] );
		self::assertTrue( $result['object_cache_supports']['flush_group'] );
		self::assertFalse( $result['object_cache_supports']['set_multiple'] );
		self::assertTrue( $result['multisite'] );
		self::assertArrayHasKey( 'dropins', $result );
	}

	public function test_get_cache_status_requires_manage_options(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );

		$result = ( new SiteAbilities() )->get_cache_status();

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_new_site_admin_abilities_are_registered_and_manifested(): void {
		$registry = new AbilitiesRegistry();

		foreach ( array( 'site.list_cron_events', 'site.get_rewrite_rules', 'site.get_cache_status' ) as $ability_id ) {
			self::assertTrue( $registry->is_known( $ability_id ) );
			self::assertSame( array( 'content:read' ), $registry->required_scopes( $ability_id ) );
			self::assertTrue( $registry->is_read_only( $ability_id ) );
		}

		self::assertArrayHasKey( 'per_page', $registry->input_schema( 'site.list_cron_events' )['properties'] );
		self::assertArrayHasKey( 'per_page', $registry->input_schema( 'site.get_rewrite_rules' )['properties'] );

		$tools   = ( new McpController() )->tool_manifest_for_current_user();
		$by_name = array_column( $tools['tools'], null, 'name' );

		self::assertArrayHasKey( 'site_list_cron_events', $by_name );
		self::assertArrayHasKey( 'site_get_rewrite_rules', $by_name );
		self::assertArrayHasKey( 'site_get_cache_status', $by_name );
		self::assertSame( 'array', $by_name['site_list_cron_events']['outputSchema']['properties']['items']['type'] );
	}
}

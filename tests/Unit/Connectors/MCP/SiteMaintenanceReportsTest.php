<?php
/**
 * Tests for MCP site maintenance reports.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use Aculect\AICompanion\Connectors\MCP\SiteMaintenanceReports;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/site-workflow-stubs.php';

/**
 * Verifies read-only maintenance reports stay bounded and support-safe.
 */
final class SiteMaintenanceReportsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids']    = array();
		$GLOBALS['aculect_ai_companion_test_attachment_metadata'] = array();
		$GLOBALS['aculect_ai_companion_test_post_types']          = array(
			'post' => new \WP_Post_Type( 'post', array( 'label' => 'Posts', 'show_ui' => true ) ),
			'page' => new \WP_Post_Type( 'page', array( 'label' => 'Pages', 'show_ui' => true ) ),
		);
		$GLOBALS['aculect_ai_companion_test_posts']               = array(
			101 => array(
				'ID'                => 101,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_title'        => 'Older draft <script>alert(1)</script>',
				'post_author'       => 7,
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 45 * 86400 ) ),
			),
			102 => array(
				'ID'                => 102,
				'post_type'         => 'page',
				'post_status'       => 'pending',
				'post_title'        => 'Fresh pending page',
				'post_author'       => 8,
				'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 4 * 86400 ) ),
			),
			201 => array(
				'ID'             => 201,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Large hero',
				'post_parent'    => 0,
				'post_mime_type' => 'image/jpeg',
			),
			202 => array(
				'ID'             => 202,
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Inline image',
				'post_parent'    => 101,
				'post_mime_type' => 'image/png',
			),
		);
		$GLOBALS['aculect_ai_companion_test_attachment_metadata'] = array(
			201 => array(
				'filesize' => 7340032,
				'width'    => 3000,
				'height'   => 2000,
				'file'     => '2026/07/private-path.jpg',
			),
			202 => array(
				'filesize' => 100000,
				'width'    => 1200,
				'height'   => 800,
				'file'     => '2026/07/inline-image.png',
			),
		);
	}

	public function test_content_review_report_returns_bounded_redacted_findings(): void {
		$result   = ( new SiteMaintenanceReports() )->report(
			array(
				'report_type' => 'content_review',
				'per_page'    => 1,
			)
		);
		$findings = $result['findings'];

		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'content_review', $result['report_type'] );
		self::assertTrue( $result['read_only'] );
		self::assertSame( 1, $result['pagination']['returned'] );
		self::assertSame( 2, $result['pagination']['total'] );
		self::assertSame( 'warning', $result['summary']['overall_severity'] );
		self::assertSame( 'Older draft alert(1)', $findings[0]['title'] );
		self::assertSame( 'warning', $findings[0]['severity'] );
		self::assertSame( 101, $findings[0]['evidence']['post_id'] );
		self::assertFalse( $findings[0]['evidence']['content_included'] );
		self::assertFalse( $findings[0]['evidence']['private_data_shown'] );
		self::assertArrayNotHasKey( 'post_content', $findings[0]['evidence'] );
		self::assertFalse( $result['safety']['option_values_included'] );
		self::assertFalse( $result['safety']['raw_database_access'] );
	}

	public function test_media_inventory_report_hides_file_paths_and_flags_maintenance_signals(): void {
		$result   = ( new SiteMaintenanceReports() )->report(
			array(
				'report_type' => 'media_inventory',
				'per_page'    => 20,
			)
		);
		$findings = array_column( $result['findings'], null, 'id' );

		self::assertSame( 'media_inventory', $result['report_type'] );
		self::assertSame( 2, $result['pagination']['returned'] );
		self::assertSame( 'warning', $findings['media_201']['severity'] );
		self::assertTrue( $findings['media_201']['evidence']['unattached'] );
		self::assertSame( 7340032, $findings['media_201']['evidence']['filesize_bytes'] );
		self::assertFalse( $findings['media_201']['evidence']['file_path_included'] );
		self::assertArrayNotHasKey( 'file', $findings['media_201']['evidence'] );
		self::assertSame( 'info', $findings['media_202']['severity'] );
		self::assertFalse( $result['filters']['filesystem_scanned'] );
		self::assertFalse( $result['safety']['filesystem_writes'] );
	}

	public function test_report_requires_manage_options(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );

		$result = ( new SiteMaintenanceReports() )->report( array( 'report_type' => 'content_review' ) );

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_report_schema_and_availability_are_exposed_with_capability_block(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'site.maintenance_report' ) );

		$tools   = ( new McpController() )->tool_manifest_for_current_user();
		$by_name = array_column( $tools['tools'], null, 'name' );

		self::assertArrayHasKey( 'site_maintenance_report', $by_name );
		self::assertTrue( $by_name['site_maintenance_report']['annotations']['readOnlyHint'] );
		self::assertSame( array( 'content_review', 'media_inventory' ), $by_name['site_maintenance_report']['inputSchema']['properties']['report_type']['enum'] );
		self::assertSame( 20, $by_name['site_maintenance_report']['inputSchema']['properties']['per_page']['maximum'] );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 1, $registry, array( 'content:read' ) );

		self::assertFalse( $operations['site_information']['report']['available'] );
		self::assertSame( 'capability', $operations['site_information']['report']['blocked_by'] );
	}
}

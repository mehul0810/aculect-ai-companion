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
use Aculect\AICompanion\Intelligence\ContentIndexer;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/site-workflow-stubs.php';

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused maintenance-report tests replace wpdb with a local aggregate-only test double.

/**
 * Verifies read-only maintenance reports stay bounded and support-safe.
 */
final class SiteMaintenanceReportsTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['wpdb'] );

		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids']     = array();
		$GLOBALS['aculect_ai_companion_test_attachment_metadata'] = array();
		$GLOBALS['aculect_ai_companion_test_transients']          = array();
		$GLOBALS['aculect_ai_companion_test_site_options']        = array();
		$GLOBALS['aculect_ai_companion_test_home_url']            = 'https://example.com';
		$GLOBALS['aculect_ai_companion_test_site_url']            = 'https://example.com';
		$GLOBALS['aculect_ai_companion_test_rest_url']            = 'https://example.com/wp-json/';
		$GLOBALS['aculect_ai_companion_test_using_https']         = true;
		$GLOBALS['aculect_ai_companion_test_cron_array']          = array( time() + HOUR_IN_SECONDS => array( 'example_hook' => array() ) );
		$GLOBALS['aculect_ai_companion_test_scheduled_events']    = array();
		$GLOBALS['aculect_ai_companion_test_options']             = array(
			'permalink_structure'                    => '/%postname%/',
			'aculect_ai_companion_pending_index_ids' => array(),
		);
		$GLOBALS['aculect_ai_companion_test_post_types']          = array(
			'post' => new \WP_Post_Type(
				'post',
				array(
					'label'   => 'Posts',
					'show_ui' => true,
				)
			),
			'page' => new \WP_Post_Type(
				'page',
				array(
					'label'   => 'Pages',
					'show_ui' => true,
				)
			),
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

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
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

	public function test_site_readiness_report_returns_safe_status_signals(): void {
		$GLOBALS['aculect_ai_companion_test_scheduled_events'][ ContentIndexer::STALE_SWEEP_HOOK ] = time() + HOUR_IN_SECONDS;

		$result   = ( new SiteMaintenanceReports() )->report(
			array(
				'report_type' => 'site_readiness',
				'per_page'    => 20,
			)
		);
		$findings = array_column( $result['findings'], null, 'id' );

		self::assertSame( 'site_readiness', $result['report_type'] );
		self::assertSame( 5, $result['pagination']['returned'] );
		self::assertSame( 'info', $result['summary']['overall_severity'] );
		self::assertSame( 'info', $findings['permalinks']['severity'] );
		self::assertTrue( $findings['permalinks']['evidence']['pretty_permalinks'] );
		self::assertFalse( $findings['permalinks']['evidence']['structure_value_included'] );
		self::assertTrue( $findings['https_urls']['evidence']['https_consistent'] );
		self::assertTrue( $findings['https_urls']['evidence']['hosts_match'] );
		self::assertTrue( $findings['rest_api']['evidence']['rest_url_present'] );
		self::assertSame( 2, $findings['rest_api']['evidence']['rest_enabled_post_types'] );
		self::assertSame( 1, $findings['cron']['evidence']['event_bucket_count'] );
		self::assertTrue( $findings['background_tasks']['evidence']['stale_sweep_scheduled'] );
		self::assertFalse( $result['filters']['raw_urls_included'] );
		self::assertFalse( $result['filters']['option_values_included'] );
		self::assertFalse( $result['safety']['secret_values_included'] );
	}

	public function test_site_readiness_report_flags_warnings_without_sensitive_values(): void {
		$GLOBALS['aculect_ai_companion_test_options']['permalink_structure']                    = '';
		$GLOBALS['aculect_ai_companion_test_options']['aculect_ai_companion_pending_index_ids'] = array( 101, 102 );
		$GLOBALS['aculect_ai_companion_test_home_url']    = 'http://example.com';
		$GLOBALS['aculect_ai_companion_test_site_url']    = 'https://admin.example.test';
		$GLOBALS['aculect_ai_companion_test_rest_url']    = 'http://example.com/wp-json/';
		$GLOBALS['aculect_ai_companion_test_using_https'] = false;
		$GLOBALS['aculect_ai_companion_test_cron_array']  = array();

		$result   = ( new SiteMaintenanceReports() )->report(
			array(
				'report_type' => 'site_readiness',
				'per_page'    => 20,
			)
		);
		$findings = array_column( $result['findings'], null, 'id' );

		self::assertSame( 'critical', $result['summary']['overall_severity'] );
		self::assertSame( 'warning', $findings['permalinks']['severity'] );
		self::assertSame( 'critical', $findings['https_urls']['severity'] );
		self::assertSame( 'warning', $findings['rest_api']['severity'] );
		self::assertSame( 'warning', $findings['cron']['severity'] );
		self::assertSame( 'warning', $findings['background_tasks']['severity'] );
		self::assertFalse( $findings['https_urls']['evidence']['raw_urls_included'] );
		self::assertFalse( $findings['rest_api']['evidence']['raw_rest_url_included'] );
		self::assertFalse( $findings['cron']['evidence']['hook_names_included'] );
		self::assertFalse( $findings['background_tasks']['evidence']['job_payloads_included'] );
		self::assertArrayNotHasKey( 'home_url', $findings['https_urls']['evidence'] );
		self::assertArrayNotHasKey( 'site_url', $findings['https_urls']['evidence'] );
		self::assertArrayNotHasKey( 'permalink_structure', $findings['permalinks']['evidence'] );
	}

	public function test_update_readiness_report_summarizes_existing_update_metadata_safely(): void {
		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_core']    = (object) array(
			'last_checked' => time() - HOUR_IN_SECONDS,
			'updates'      => array(
				(object) array(
					'response' => 'upgrade',
					'current'  => '6.8.2',
				),
			),
		);
		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_plugins'] = (object) array(
			'last_checked' => time() - ( 2 * HOUR_IN_SECONDS ),
			'response'     => array(
				'acme/acme.php'   => (object) array(
					'new_version'  => '2.0.0',
					'tested'       => '6.7',
					'requires_php' => '8.5',
					'package'      => 'https://downloads.example.test/acme.zip',
				),
				'other/other.php' => (object) array(
					'new_version' => '1.2.0',
				),
			),
		);
		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_themes']  = (object) array(
			'last_checked' => time() - ( 3 * HOUR_IN_SECONDS ),
			'response'     => array(
				'test-theme' => array(
					'new_version' => '1.1.0',
					'tested'      => '6.8.1',
					'requires'    => '6.9',
				),
			),
		);

		$before_options      = $GLOBALS['aculect_ai_companion_test_options'];
		$before_site_options = $GLOBALS['aculect_ai_companion_test_site_options'];
		$before_transients   = $GLOBALS['aculect_ai_companion_test_transients'];

		$result   = ( new SiteMaintenanceReports() )->report(
			array(
				'report_type' => 'update_readiness',
				'per_page'    => 20,
			)
		);
		$findings = array_column( $result['findings'], null, 'id' );

		self::assertSame( 'update_readiness', $result['report_type'] );
		self::assertTrue( $result['read_only'] );
		self::assertSame( 5, $result['pagination']['returned'] );
		self::assertSame( 'critical', $result['summary']['overall_severity'] );
		self::assertSame( 'info', $findings['update_metadata']['severity'] );
		self::assertSame( 1, $findings['core_updates']['evidence']['available_update_count'] );
		self::assertSame( 2, $findings['plugin_updates']['evidence']['available_update_count'] );
		self::assertSame( 1, $findings['theme_updates']['evidence']['available_update_count'] );
		self::assertSame( 'critical', $findings['compatibility_signals']['severity'] );
		self::assertSame( 1, $findings['compatibility_signals']['evidence']['wordpress_tested_cautions'] );
		self::assertSame( 1, $findings['compatibility_signals']['evidence']['unknown_wordpress_tested'] );
		self::assertSame( 1, $findings['compatibility_signals']['evidence']['php_requirement_blockers'] );
		self::assertSame( 1, $findings['compatibility_signals']['evidence']['wordpress_requirement_blockers'] );
		self::assertFalse( $result['filters']['forced_update_checks'] );
		self::assertFalse( $result['filters']['updates_applied'] );
		self::assertFalse( $result['filters']['raw_update_payloads_included'] );
		self::assertFalse( $result['filters']['plugin_theme_source_scanned'] );
		self::assertFalse( $result['filters']['filesystem_paths_included'] );
		self::assertFalse( $result['safety']['filesystem_writes'] );
		self::assertFalse( $result['safety']['option_values_included'] );
		self::assertArrayNotHasKey( 'package', $findings['compatibility_signals']['evidence'] );
		self::assertArrayNotHasKey( 'acme/acme.php', $findings['plugin_updates']['evidence'] );
		self::assertSame( $before_options, $GLOBALS['aculect_ai_companion_test_options'] );
		self::assertSame( $before_site_options, $GLOBALS['aculect_ai_companion_test_site_options'] );
		self::assertSame( $before_transients, $GLOBALS['aculect_ai_companion_test_transients'] );
	}

	public function test_update_readiness_report_flags_missing_and_stale_metadata_without_raw_payloads(): void {
		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_core']   = (object) array(
			'last_checked' => time() - ( 72 * HOUR_IN_SECONDS ),
			'updates'      => array(),
		);
		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_themes'] = (object) array(
			'response' => array(),
		);

		$result   = ( new SiteMaintenanceReports() )->report(
			array(
				'report_type' => 'update_readiness',
				'per_page'    => 20,
			)
		);
		$findings = array_column( $result['findings'], null, 'id' );

		self::assertSame( 'warning', $result['summary']['overall_severity'] );
		self::assertSame( 'warning', $findings['update_metadata']['severity'] );
		self::assertTrue( $findings['update_metadata']['evidence']['core_metadata_available'] );
		self::assertFalse( $findings['update_metadata']['evidence']['plugin_metadata_available'] );
		self::assertTrue( $findings['update_metadata']['evidence']['theme_metadata_available'] );
		self::assertSame( 1, $findings['update_metadata']['evidence']['missing_metadata_groups'] );
		self::assertSame( 2, $findings['update_metadata']['evidence']['stale_metadata_groups'] );
		self::assertSame( 'warning', $findings['plugin_updates']['severity'] );
		self::assertFalse( $findings['plugin_updates']['evidence']['metadata_available'] );
		self::assertFalse( $findings['plugin_updates']['evidence']['item_identifiers_included'] );
		self::assertFalse( $findings['plugin_updates']['evidence']['filesystem_paths_included'] );
		self::assertFalse( $findings['plugin_updates']['evidence']['raw_update_payloads_included'] );
		self::assertFalse( $findings['compatibility_signals']['evidence']['package_urls_included'] );
		self::assertFalse( $findings['compatibility_signals']['evidence']['raw_update_payloads_included'] );
		self::assertArrayNotHasKey( 'response', $findings['update_metadata']['evidence'] );
	}

	public function test_autoload_readiness_report_summarizes_aggregate_option_size_safely(): void {
		$wpdb            = new SiteMaintenanceReportsWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$before_options      = $GLOBALS['aculect_ai_companion_test_options'];
		$before_site_options = $GLOBALS['aculect_ai_companion_test_site_options'];
		$before_transients   = $GLOBALS['aculect_ai_companion_test_transients'];

		$result   = ( new SiteMaintenanceReports() )->report(
			array(
				'report_type' => 'autoload_readiness',
				'per_page'    => 20,
			)
		);
		$findings = array_column( $result['findings'], null, 'id' );

		self::assertSame( 'autoload_readiness', $result['report_type'] );
		self::assertTrue( $result['read_only'] );
		self::assertSame( 4, $result['pagination']['returned'] );
		self::assertSame( 'critical', $result['summary']['overall_severity'] );
		self::assertTrue( $result['filters']['aggregate_database_access'] );
		self::assertFalse( $result['filters']['option_values_included'] );
		self::assertFalse( $result['filters']['option_names_included'] );
		self::assertFalse( $result['filters']['serialized_data_included'] );
		self::assertFalse( $result['filters']['raw_sql_results_included'] );
		self::assertFalse( $result['filters']['secret_values_included'] );
		self::assertFalse( $result['filters']['write_actions_performed'] );
		self::assertFalse( $result['filters']['customer_data_included'] );
		self::assertFalse( $result['filters']['order_payment_data_included'] );
		self::assertFalse( $result['safety']['database_writes'] );
		self::assertFalse( $result['safety']['write_actions_performed'] );
		self::assertFalse( $result['safety']['option_values_included'] );
		self::assertFalse( $result['safety']['option_names_included'] );
		self::assertFalse( $result['safety']['raw_sql_results_included'] );
		self::assertSame( 42, $findings['autoload_total_size']['evidence']['autoloaded_option_count'] );
		self::assertSame( 1250000, $findings['autoload_total_size']['evidence']['total_bytes'] );
		self::assertFalse( $findings['autoload_total_size']['evidence']['option_values_included'] );
		self::assertFalse( $findings['autoload_total_size']['evidence']['option_names_included'] );
		self::assertFalse( $findings['autoload_total_size']['evidence']['raw_sql_results_included'] );
		self::assertSame( 1, $findings['autoload_size_buckets']['evidence']['oversized_option_count'] );
		self::assertSame( 250000, $findings['autoload_largest_option_size']['evidence']['largest_option_bytes'] );
		self::assertFalse( $findings['autoload_largest_option_size']['evidence']['option_name_included'] );
		self::assertFalse( $findings['autoload_largest_option_size']['evidence']['option_value_included'] );
		self::assertTrue( $findings['autoload_report_safety']['evidence']['aggregate_only'] );
		self::assertFalse( $findings['autoload_report_safety']['evidence']['database_writes'] );
		self::assertSame( 1, $wpdb->get_row_calls );
		self::assertSame( 0, $wpdb->query_calls );
		self::assertSame( 0, $wpdb->insert_calls );
		self::assertStringContainsString( 'COUNT(*) AS total_options', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'FROM %i WHERE autoload IN', $wpdb->prepared[0]['query'] );
		self::assertContains( 'wp_options', $wpdb->prepared[0]['args'] );
		self::assertContains( 'yes', $wpdb->prepared[0]['args'] );
		self::assertContains( 'auto-on', $wpdb->prepared[0]['args'] );
		self::assertArrayNotHasKey( 'option_name', $findings['autoload_total_size']['evidence'] );
		self::assertArrayNotHasKey( 'option_value', $findings['autoload_total_size']['evidence'] );
		self::assertSame( $before_options, $GLOBALS['aculect_ai_companion_test_options'] );
		self::assertSame( $before_site_options, $GLOBALS['aculect_ai_companion_test_site_options'] );
		self::assertSame( $before_transients, $GLOBALS['aculect_ai_companion_test_transients'] );
	}

	public function test_autoload_readiness_report_handles_missing_database_access_without_values(): void {
		$result   = ( new SiteMaintenanceReports() )->report(
			array(
				'report_type' => 'autoload_readiness',
				'per_page'    => 20,
			)
		);
		$findings = array_column( $result['findings'], null, 'id' );

		self::assertSame( 'autoload_readiness', $result['report_type'] );
		self::assertSame( 'warning', $result['summary']['overall_severity'] );
		self::assertFalse( $result['filters']['aggregate_database_access'] );
		self::assertFalse( $result['filters']['option_values_included'] );
		self::assertFalse( $result['filters']['option_names_included'] );
		self::assertFalse( $result['filters']['raw_sql_results_included'] );
		self::assertFalse( $result['safety']['write_actions_performed'] );
		self::assertFalse( $findings['autoload_report_safety']['evidence']['summary_available'] );
		self::assertFalse( $findings['autoload_report_safety']['evidence']['option_values_included'] );
		self::assertFalse( $findings['autoload_report_safety']['evidence']['option_names_included'] );
		self::assertFalse( $findings['autoload_report_safety']['evidence']['raw_sql_results_included'] );
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
		self::assertSame( array( 'content_review', 'media_inventory', 'site_readiness', 'update_readiness', 'autoload_readiness' ), $by_name['site_maintenance_report']['inputSchema']['properties']['report_type']['enum'] );
		self::assertSame( 20, $by_name['site_maintenance_report']['inputSchema']['properties']['per_page']['maximum'] );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		$operations                                       = ( new McpToolAvailability() )->operations_manifest_for_user( 1, $registry, array( 'content:read' ) );

		self::assertFalse( $operations['site_information']['report']['available'] );
		self::assertSame( 'capability', $operations['site_information']['report']['blocked_by'] );
	}
}

/**
 * Minimal wpdb double for aggregate autoload-readiness diagnostics.
 */
final class SiteMaintenanceReportsWpdb {

	public string $prefix = 'wp_';

	public string $options = 'wp_options';

	public int $get_row_calls = 0;

	public int $query_calls = 0;

	public int $insert_calls = 0;

	/**
	 * Prepared SQL calls.
	 *
	 * @var list<array{query: string, args: array<int, mixed>}>
	 */
	public array $prepared = array();

	/**
	 * Record prepared SQL.
	 *
	 * @param string $query SQL query.
	 * @param mixed  ...$args Placeholder arguments.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->prepared[] = array(
			'query' => $query,
			'args'  => $args,
		);

		return $query;
	}

	/**
	 * Return aggregate autoload counts only.
	 *
	 * @param string $query  Prepared SQL query.
	 * @param string $output Output format.
	 * @return array<string, string>
	 */
	public function get_row( string $query, string $output ): array {
		unset( $query, $output );

		++$this->get_row_calls;

		return array(
			'total_options'   => '42',
			'total_bytes'     => '1250000',
			'largest_bytes'   => '250000',
			'standard_count'  => '20',
			'medium_count'    => '12',
			'large_count'     => '9',
			'oversized_count' => '1',
		);
	}

	/**
	 * Track accidental write queries.
	 *
	 * @param string $query SQL query.
	 */
	public function query( string $query ): int {
		unset( $query );

		++$this->query_calls;

		return 0;
	}

	/**
	 * Track accidental inserts.
	 *
	 * @param string               $table   Table name.
	 * @param array<string, mixed> $data    Insert data.
	 * @param array<string>        $formats Field formats.
	 */
	public function insert( string $table, array $data, array $formats ): false {
		unset( $table, $data, $formats );

		++$this->insert_calls;

		return false;
	}
}

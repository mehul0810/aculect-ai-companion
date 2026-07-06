<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Intelligence\ContentIndexer;

/**
 * Bounded read-only site maintenance reports for assistant diagnostics.
 */
final class SiteMaintenanceReports extends AbstractAbilityService {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Autoload readiness uses one aggregate-only options-table query and never returns option names, values, or raw rows.

	private const REPORT_TYPES                   = array( 'content_review', 'media_inventory', 'site_readiness', 'update_readiness', 'autoload_readiness' );
	private const DEFAULT_PER_PAGE               = 10;
	private const MAX_PER_PAGE                   = 20;
	private const STALE_DRAFT_DAYS               = 30;
	private const OVERSIZED_BYTES                = 5242880;
	private const MAX_TITLE_LENGTH               = 120;
	private const MAX_POST_TYPE_KEYS             = 8;
	private const STALE_UPDATE_HOURS             = 48;
	private const AUTOLOAD_WARNING_BYTES         = 800000;
	private const AUTOLOAD_CRITICAL_BYTES        = 1000000;
	private const AUTOLOAD_LARGE_OPTION_BYTES    = 102400;
	private const AUTOLOAD_MEDIUM_OPTION_BYTES   = 10240;
	private const AUTOLOAD_STANDARD_OPTION_BYTES = 1024;

	/**
	 * Return a compact maintenance report.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function report( array $args ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to view site maintenance reports.' );
		}

		$report_type = $this->report_type( $args['report_type'] ?? 'content_review' );
		$page        = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page    = min( self::MAX_PER_PAGE, max( 1, absint( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );

		return match ( $report_type ) {
			'media_inventory' => $this->media_inventory_report( $page, $per_page ),
			'site_readiness'  => $this->site_readiness_report( $page, $per_page ),
			'update_readiness' => $this->update_readiness_report( $page, $per_page ),
			'autoload_readiness' => $this->autoload_readiness_report( $page, $per_page ),
			default => $this->content_review_report( $page, $per_page ),
		};
	}

	/**
	 * Return supported report types.
	 *
	 * @return list<string>
	 */
	public static function report_types(): array {
		return self::REPORT_TYPES;
	}

	/**
	 * Build a stale content review report.
	 *
	 * @param int $page     One-based page.
	 * @param int $per_page Results per page.
	 * @return array<string, mixed>
	 */
	private function content_review_report( int $page, int $per_page ): array {
		$post_types = $this->content_post_types();
		$query      = new \WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => array( 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => 'modified',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$findings = array();
		foreach ( $this->query_posts( $query ) as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$age_days   = $this->age_days( (string) $post->post_modified_gmt );
			$is_stale   = self::STALE_DRAFT_DAYS <= $age_days;
			$findings[] = $this->finding(
				'content_' . $post->ID,
				$this->safe_title( $post->post_title, 'Untitled content #' . $post->ID ),
				$is_stale ? 'warning' : 'info',
				array(
					'post_id'            => $post->ID,
					'post_type'          => $post->post_type,
					'status'             => $post->post_status,
					'author_id'          => $post->post_author,
					'modified_gmt'       => $post->post_modified_gmt,
					'age_days'           => $age_days,
					'stale_after_days'   => self::STALE_DRAFT_DAYS,
					'content_included'   => false,
					'private_data_shown' => false,
				),
				$is_stale ? 'Review, update, publish, schedule, or archive this stale draft-like item.' : 'Confirm whether this reviewable content still needs editorial action.'
			);
		}

		return $this->report_payload(
			'content_review',
			'Content Review Queue',
			'Reviewable draft-like content ordered by oldest modified date without exposing post bodies.',
			$page,
			$per_page,
			$query,
			$findings,
			array(
				'post_types'       => $post_types,
				'statuses'         => array( 'draft', 'pending', 'future', 'private' ),
				'content_included' => false,
			)
		);
	}

	/**
	 * Build a media inventory report.
	 *
	 * @param int $page     One-based page.
	 * @param int $per_page Results per page.
	 * @return array<string, mixed>
	 */
	private function media_inventory_report( int $page, int $per_page ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => array( 'inherit', 'private' ),
				'post_mime_type'         => 'image',
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$findings = array();
		foreach ( $this->query_posts( $query ) as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$metadata   = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $post->ID ) : array();
			$metadata   = is_array( $metadata ) ? $metadata : array();
			$bytes      = absint( $metadata['filesize'] ?? 0 );
			$unattached = 0 === $post->post_parent;
			$oversized  = self::OVERSIZED_BYTES < $bytes;
			$severity   = $unattached || $oversized ? 'warning' : 'info';

			$findings[] = $this->finding(
				'media_' . $post->ID,
				$this->safe_title( $post->post_title, 'Media item #' . $post->ID ),
				$severity,
				array(
					'attachment_id'       => $post->ID,
					'mime_type'           => $post->post_mime_type,
					'parent_id'           => $post->post_parent,
					'unattached'          => $unattached,
					'filesize_bytes'      => $bytes,
					'filesize_mb'         => round( $bytes / 1048576, 2 ),
					'oversized_threshold' => self::OVERSIZED_BYTES,
					'width'               => absint( $metadata['width'] ?? 0 ),
					'height'              => absint( $metadata['height'] ?? 0 ),
					'file_path_included'  => false,
					'private_data_shown'  => false,
				),
				$unattached || $oversized ? 'Review whether this media item is still needed, should be compressed, or should be attached to relevant content.' : 'No immediate media maintenance action is suggested for this item.'
			);
		}

		return $this->report_payload(
			'media_inventory',
			'Media Inventory',
			'Image attachment signals from WordPress metadata without exposing file paths or reading files.',
			$page,
			$per_page,
			$query,
			$findings,
			array(
				'post_type'          => 'attachment',
				'mime_type'          => 'image',
				'file_paths_hidden'  => true,
				'filesystem_scanned' => false,
			)
		);
	}

	/**
	 * Build a bounded site readiness report from safe WordPress status signals.
	 *
	 * @param int $page     One-based page.
	 * @param int $per_page Results per page.
	 * @return array<string, mixed>
	 */
	private function site_readiness_report( int $page, int $per_page ): array {
		$findings = array(
			$this->permalink_readiness_finding(),
			$this->https_url_readiness_finding(),
			$this->rest_api_readiness_finding(),
			$this->cron_readiness_finding(),
			$this->background_task_readiness_finding(),
		);

		$offset             = ( $page - 1 ) * $per_page;
		$paginated_findings = array_slice( $findings, $offset, $per_page );

		return $this->static_report_payload(
			'site_readiness',
			'Site Readiness',
			'Safe WordPress readiness signals for permalink, HTTPS URL consistency, REST API, cron, and background task planning without exposing option values.',
			$page,
			$per_page,
			$findings,
			$paginated_findings,
			array(
				'checks'                 => array( 'permalinks', 'https_urls', 'rest_api', 'cron', 'background_tasks' ),
				'raw_urls_included'      => false,
				'option_values_included' => false,
				'filesystem_scanned'     => false,
			)
		);
	}

	/**
	 * Build a bounded update readiness report from existing WordPress update metadata.
	 *
	 * @param int $page     One-based page.
	 * @param int $per_page Results per page.
	 * @return array<string, mixed>
	 */
	private function update_readiness_report( int $page, int $per_page ): array {
		$core    = $this->read_update_metadata( 'update_core' );
		$plugins = $this->read_update_metadata( 'update_plugins' );
		$themes  = $this->read_update_metadata( 'update_themes' );

		$findings = array(
			$this->update_metadata_finding( $core, $plugins, $themes ),
			$this->core_update_finding( $core ),
			$this->extension_update_finding( 'plugin_updates', 'Plugin Updates', $plugins ),
			$this->extension_update_finding( 'theme_updates', 'Theme Updates', $themes ),
			$this->update_compatibility_finding( $plugins, $themes ),
		);

		$offset             = ( $page - 1 ) * $per_page;
		$paginated_findings = array_slice( $findings, $offset, $per_page );

		return $this->static_report_payload(
			'update_readiness',
			'Update Readiness',
			'Safe core, plugin, and theme update-readiness signals from existing WordPress update metadata without forcing checks or exposing raw payloads.',
			$page,
			$per_page,
			$findings,
			$paginated_findings,
			array(
				'metadata_sources'             => array( 'update_core', 'update_plugins', 'update_themes' ),
				'forced_update_checks'         => false,
				'updates_applied'              => false,
				'raw_update_payloads_included' => false,
				'plugin_theme_source_scanned'  => false,
				'filesystem_paths_included'    => false,
			)
		);
	}

	/**
	 * Build a safe autoloaded option size report from aggregate database counts only.
	 *
	 * @param int $page     One-based page.
	 * @param int $per_page Results per page.
	 * @return array<string, mixed>
	 */
	private function autoload_readiness_report( int $page, int $per_page ): array {
		$summary  = $this->autoload_option_summary();
		$findings = array(
			$this->autoload_total_size_finding( $summary ),
			$this->autoload_bucket_finding( $summary ),
			$this->autoload_largest_option_finding( $summary ),
			$this->autoload_safety_finding( $summary ),
		);

		$offset             = ( $page - 1 ) * $per_page;
		$paginated_findings = array_slice( $findings, $offset, $per_page );

		return $this->static_report_payload(
			'autoload_readiness',
			'Autoload Readiness',
			'Safe aggregate autoloaded option size signals without exposing option names, option values, serialized data, secrets, or raw SQL results.',
			$page,
			$per_page,
			$findings,
			$paginated_findings,
			array(
				'sources'                     => array( 'wp_options_autoload_aggregate' ),
				'aggregate_database_access'   => $summary['available'],
				'autoload_flags_counted'      => array( 'yes', 'on', 'auto-on', 'auto' ),
				'option_values_included'      => false,
				'option_names_included'       => false,
				'serialized_data_included'    => false,
				'raw_sql_results_included'    => false,
				'secret_values_included'      => false,
				'write_actions_performed'     => false,
				'customer_data_included'      => false,
				'order_payment_data_included' => false,
			)
		);
	}

	/**
	 * Build an aggregate autoload-size finding.
	 *
	 * @param array<string, mixed> $summary Aggregate autoload summary.
	 * @return array<string, mixed>
	 */
	private function autoload_total_size_finding( array $summary ): array {
		$total_bytes = (int) $summary['total_bytes'];
		$severity    = $this->autoload_severity( $summary );

		return $this->finding(
			'autoload_total_size',
			'Autoloaded Option Size',
			$severity,
			array(
				'summary_available'        => $summary['available'],
				'autoloaded_option_count'  => (int) $summary['total_options'],
				'total_bytes'              => $total_bytes,
				'total_mb'                 => round( $total_bytes / 1048576, 2 ),
				'warning_threshold_bytes'  => self::AUTOLOAD_WARNING_BYTES,
				'critical_threshold_bytes' => self::AUTOLOAD_CRITICAL_BYTES,
				'option_values_included'   => false,
				'option_names_included'    => false,
				'raw_sql_results_included' => false,
			),
			match ( $severity ) {
				'critical' => 'Audit autoloaded options in WordPress or a trusted database tool and reduce entries that load on every request.',
				'warning'  => 'Review autoloaded option growth before it becomes a front-end performance risk.',
				default    => $summary['available'] ? 'Autoloaded option size is below the maintenance warning threshold.' : 'Run this report in a WordPress environment with database access to summarize autoloaded option size.',
			}
		);
	}

	/**
	 * Build an autoload size bucket finding.
	 *
	 * @param array<string, mixed> $summary Aggregate autoload summary.
	 * @return array<string, mixed>
	 */
	private function autoload_bucket_finding( array $summary ): array {
		$oversized = (int) $summary['oversized_count'];

		return $this->finding(
			'autoload_size_buckets',
			'Autoload Size Buckets',
			0 < $oversized ? 'warning' : ( $summary['available'] ? 'info' : 'warning' ),
			array(
				'summary_available'      => $summary['available'],
				'standard_option_count'  => (int) $summary['standard_count'],
				'medium_option_count'    => (int) $summary['medium_count'],
				'large_option_count'     => (int) $summary['large_count'],
				'oversized_option_count' => $oversized,
				'standard_max_bytes'     => self::AUTOLOAD_STANDARD_OPTION_BYTES - 1,
				'medium_max_bytes'       => self::AUTOLOAD_MEDIUM_OPTION_BYTES - 1,
				'large_max_bytes'        => self::AUTOLOAD_LARGE_OPTION_BYTES - 1,
				'option_values_included' => false,
				'option_names_included'  => false,
			),
			0 < $oversized ? 'Prioritize reviewing very large autoloaded options because they increase every-request memory pressure.' : 'No very large autoloaded option buckets were detected in the aggregate summary.'
		);
	}

	/**
	 * Build a largest autoloaded option size finding without identifying the option.
	 *
	 * @param array<string, mixed> $summary Aggregate autoload summary.
	 * @return array<string, mixed>
	 */
	private function autoload_largest_option_finding( array $summary ): array {
		$largest = (int) $summary['largest_bytes'];

		return $this->finding(
			'autoload_largest_option_size',
			'Largest Autoloaded Option Size',
			self::AUTOLOAD_LARGE_OPTION_BYTES <= $largest ? 'warning' : ( $summary['available'] ? 'info' : 'warning' ),
			array(
				'summary_available'            => $summary['available'],
				'largest_option_bytes'         => $largest,
				'largest_option_mb'            => round( $largest / 1048576, 2 ),
				'large_option_threshold_bytes' => self::AUTOLOAD_LARGE_OPTION_BYTES,
				'option_name_included'         => false,
				'option_value_included'        => false,
				'serialized_data_included'     => false,
			),
			self::AUTOLOAD_LARGE_OPTION_BYTES <= $largest ? 'Investigate large autoloaded options through trusted admin or database tooling without exposing values to assistant clients.' : 'The largest autoloaded option size is below the large-option threshold.'
		);
	}

	/**
	 * Build a finding that documents the safety boundary for this report.
	 *
	 * @param array<string, mixed> $summary Aggregate autoload summary.
	 * @return array<string, mixed>
	 */
	private function autoload_safety_finding( array $summary ): array {
		return $this->finding(
			'autoload_report_safety',
			'Autoload Report Safety',
			$summary['available'] ? 'info' : 'warning',
			array(
				'summary_available'           => $summary['available'],
				'aggregate_only'              => true,
				'option_values_included'      => false,
				'option_names_included'       => false,
				'serialized_data_included'    => false,
				'secret_values_included'      => false,
				'raw_sql_results_included'    => false,
				'database_writes'             => false,
				'filesystem_writes'           => false,
				'customer_data_included'      => false,
				'order_payment_data_included' => false,
			),
			$summary['available'] ? 'Use the aggregate counts to decide whether a deeper owner-approved database review is needed.' : 'Database access was unavailable, so no autoload aggregate was produced.'
		);
	}

	/**
	 * Read aggregate autoloaded option counts and byte totals without returning option names or values.
	 *
	 * @return array<string, mixed>
	 */
	private function autoload_option_summary(): array {
		global $wpdb;

		$defaults = array(
			'available'       => false,
			'total_options'   => 0,
			'total_bytes'     => 0,
			'largest_bytes'   => 0,
			'standard_count'  => 0,
			'medium_count'    => 0,
			'large_count'     => 0,
			'oversized_count' => 0,
		);

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) || ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) ) {
			return $defaults;
		}

		$table = $wpdb->options;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total_options, COALESCE(SUM(LENGTH(option_value)), 0) AS total_bytes, COALESCE(MAX(LENGTH(option_value)), 0) AS largest_bytes, COALESCE(SUM(CASE WHEN LENGTH(option_value) < %d THEN 1 ELSE 0 END), 0) AS standard_count, COALESCE(SUM(CASE WHEN LENGTH(option_value) >= %d AND LENGTH(option_value) < %d THEN 1 ELSE 0 END), 0) AS medium_count, COALESCE(SUM(CASE WHEN LENGTH(option_value) >= %d AND LENGTH(option_value) < %d THEN 1 ELSE 0 END), 0) AS large_count, COALESCE(SUM(CASE WHEN LENGTH(option_value) >= %d THEN 1 ELSE 0 END), 0) AS oversized_count FROM %i WHERE autoload IN (%s, %s, %s, %s)',
				self::AUTOLOAD_STANDARD_OPTION_BYTES,
				self::AUTOLOAD_STANDARD_OPTION_BYTES,
				self::AUTOLOAD_MEDIUM_OPTION_BYTES,
				self::AUTOLOAD_MEDIUM_OPTION_BYTES,
				self::AUTOLOAD_LARGE_OPTION_BYTES,
				self::AUTOLOAD_LARGE_OPTION_BYTES,
				$table,
				'yes',
				'on',
				'auto-on',
				'auto'
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return $defaults;
		}

		return array(
			'available'       => true,
			'total_options'   => absint( $row['total_options'] ?? 0 ),
			'total_bytes'     => absint( $row['total_bytes'] ?? 0 ),
			'largest_bytes'   => absint( $row['largest_bytes'] ?? 0 ),
			'standard_count'  => absint( $row['standard_count'] ?? 0 ),
			'medium_count'    => absint( $row['medium_count'] ?? 0 ),
			'large_count'     => absint( $row['large_count'] ?? 0 ),
			'oversized_count' => absint( $row['oversized_count'] ?? 0 ),
		);
	}

	/**
	 * Return severity for the autoload aggregate.
	 *
	 * @param array<string, mixed> $summary Aggregate autoload summary.
	 */
	private function autoload_severity( array $summary ): string {
		if ( ! $summary['available'] ) {
			return 'warning';
		}

		$total = (int) $summary['total_bytes'];
		if ( self::AUTOLOAD_CRITICAL_BYTES <= $total ) {
			return 'critical';
		}

		if ( self::AUTOLOAD_WARNING_BYTES <= $total ) {
			return 'warning';
		}

		return 'info';
	}

	/**
	 * Build a permalink readiness finding without exposing the stored structure.
	 *
	 * @return array<string, mixed>
	 */
	private function permalink_readiness_finding(): array {
		$structure  = (string) get_option( 'permalink_structure', '' );
		$configured = '' !== $structure;

		return $this->finding(
			'permalinks',
			'Permalink Structure',
			$configured ? 'info' : 'warning',
			array(
				'pretty_permalinks'        => $configured,
				'structure_present'        => $configured,
				'structure_value_included' => false,
			),
			$configured ? 'Pretty permalinks are configured for stable content and REST-adjacent routing.' : 'Configure pretty permalinks before relying on clean site-management URLs.'
		);
	}

	/**
	 * Build an HTTPS and home/site URL consistency finding.
	 *
	 * @return array<string, mixed>
	 */
	private function https_url_readiness_finding(): array {
		$home       = $this->url_parts( home_url() );
		$site       = $this->url_parts( site_url() );
		$wp_https   = function_exists( 'wp_is_using_https' ) ? wp_is_using_https() : 'https' === $home['scheme'];
		$https      = $wp_https && 'https' === $home['scheme'] && 'https' === $site['scheme'];
		$host_match = '' !== $home['host'] && '' !== $site['host'] && strtolower( $home['host'] ) === strtolower( $site['host'] );

		if ( ! $https ) {
			$severity = 'critical';
			$next     = 'Enable HTTPS consistently for the WordPress Address and Site Address before relying on remote OAuth or MCP clients.';
		} elseif ( ! $host_match ) {
			$severity = 'warning';
			$next     = 'Confirm the WordPress Address and Site Address hostnames intentionally differ before troubleshooting connector routing.';
		} else {
			$severity = 'info';
			$next     = 'HTTPS is reported consistently for home and site URLs.';
		}

		return $this->finding(
			'https_urls',
			'HTTPS and Site URLs',
			$severity,
			array(
				'using_https'       => $wp_https,
				'home_scheme'       => $home['scheme'],
				'site_scheme'       => $site['scheme'],
				'https_consistent'  => $https,
				'hosts_match'       => $host_match,
				'raw_urls_included' => false,
			),
			$next
		);
	}

	/**
	 * Build a REST API readiness finding.
	 *
	 * @return array<string, mixed>
	 */
	private function rest_api_readiness_finding(): array {
		$rest       = $this->url_parts( rest_url() );
		$rest_types = 0;
		$post_types = get_post_types( array(), 'objects' );

		foreach ( $post_types as $post_type ) {
			if ( $post_type instanceof \WP_Post_Type && $post_type->show_in_rest ) {
				++$rest_types;
			}
		}

		$present = '' !== $rest['scheme'];
		$secure  = 'https' === $rest['scheme'];

		return $this->finding(
			'rest_api',
			'REST API Metadata',
			$present && $secure ? 'info' : 'warning',
			array(
				'rest_url_present'         => $present,
				'rest_scheme'              => $rest['scheme'],
				'rest_enabled_post_types'  => $rest_types,
				'raw_rest_url_included'    => false,
				'route_callbacks_included' => false,
				'nonces_included'          => false,
			),
			$present && $secure ? 'REST API discovery metadata is available over HTTPS.' : 'Confirm REST API discovery is available over HTTPS before connecting assistant clients.'
		);
	}

	/**
	 * Build a cron readiness finding without exposing hook names.
	 *
	 * @return array<string, mixed>
	 */
	private function cron_readiness_finding(): array {
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$events   = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$next     = array() !== $events ? min( array_map( 'intval', array_keys( $events ) ) ) : 0;
		$now      = time();
		$due      = 0;

		foreach ( array_keys( $events ) as $timestamp ) {
			if ( (int) $timestamp <= $now ) {
				++$due;
			}
		}

		if ( $disabled || 0 === $next || 5 < $due ) {
			$severity  = 'warning';
			$next_step = $disabled ? 'Confirm an external cron runner is configured because WP-Cron is disabled.' : 'Verify scheduled tasks are running before relying on maintenance automation.';
		} else {
			$severity  = 'info';
			$next_step = 'Cron has scheduled events visible to WordPress.';
		}

		return $this->finding(
			'cron',
			'Cron Readiness',
			$severity,
			array(
				'disabled_by_constant' => $disabled,
				'event_bucket_count'   => count( $events ),
				'due_bucket_count'     => $due,
				'next_event_gmt'       => 0 < $next ? gmdate( 'c', $next ) : '',
				'hook_names_included'  => false,
			),
			$next_step
		);
	}

	/**
	 * Build an Aculect background task readiness finding.
	 *
	 * @return array<string, mixed>
	 */
	private function background_task_readiness_finding(): array {
		$indexer   = new ContentIndexer();
		$pending   = $indexer->pending_index_count();
		$scheduled = $indexer->stale_sweep_scheduled_at();
		$ready     = 0 === $pending || 0 < $scheduled;

		return $this->finding(
			'background_tasks',
			'Background Task Readiness',
			$ready ? 'info' : 'warning',
			array(
				'pending_index_count'       => $pending,
				'stale_sweep_scheduled'     => 0 < $scheduled,
				'stale_sweep_scheduled_gmt' => 0 < $scheduled ? gmdate( 'c', $scheduled ) : '',
				'job_payloads_included'     => false,
				'option_values_included'    => false,
			),
			$ready ? 'Aculect background indexing does not show an unscheduled backlog.' : 'Schedule or run the stale index sweep before relying on fresh assistant search results.'
		);
	}

	/**
	 * Build a finding that summarizes update metadata freshness.
	 *
	 * @param array<string, mixed> $core    Core update metadata summary.
	 * @param array<string, mixed> $plugins Plugin update metadata summary.
	 * @param array<string, mixed> $themes  Theme update metadata summary.
	 * @return array<string, mixed>
	 */
	private function update_metadata_finding( array $core, array $plugins, array $themes ): array {
		$missing = array();
		$stale   = array();

		foreach ( array(
			'core'    => $core,
			'plugins' => $plugins,
			'themes'  => $themes,
		) as $label => $metadata ) {
			if ( ! $metadata['available'] ) {
				$missing[] = $label;
			} elseif ( $metadata['stale'] ) {
				$stale[] = $label;
			}
		}

		$severity = array() !== $missing ? 'warning' : ( array() !== $stale ? 'warning' : 'info' );

		return $this->finding(
			'update_metadata',
			'Update Metadata Freshness',
			$severity,
			array(
				'core_metadata_available'      => $core['available'],
				'plugin_metadata_available'    => $plugins['available'],
				'theme_metadata_available'     => $themes['available'],
				'missing_metadata_groups'      => count( $missing ),
				'stale_metadata_groups'        => count( $stale ),
				'stale_after_hours'            => self::STALE_UPDATE_HOURS,
				'oldest_metadata_age_hours'    => max( $core['age_hours'], $plugins['age_hours'], $themes['age_hours'] ),
				'forced_update_checks'         => false,
				'raw_update_payloads_included' => false,
			),
			'info' === $severity ? 'Existing WordPress update metadata appears present and recent.' : 'Refresh WordPress update metadata from the admin updates screen before making update decisions.'
		);
	}

	/**
	 * Build a core update count finding.
	 *
	 * @param array<string, mixed> $core Core update metadata summary.
	 * @return array<string, mixed>
	 */
	private function core_update_finding( array $core ): array {
		$count    = $this->core_update_count( $core['value'] );
		$severity = 0 < $count ? 'warning' : ( $core['available'] ? 'info' : 'warning' );

		return $this->finding(
			'core_updates',
			'Core Updates',
			$severity,
			array(
				'available_update_count'       => $count,
				'metadata_available'           => $core['available'],
				'metadata_age_hours'           => $core['age_hours'],
				'metadata_stale'               => $core['stale'],
				'current_version_included'     => false,
				'target_versions_included'     => false,
				'raw_update_payloads_included' => false,
			),
			0 < $count ? 'Review the available WordPress core update in the dashboard and confirm compatibility before updating.' : 'No core update is visible in existing update metadata.'
		);
	}

	/**
	 * Build a plugin or theme update count finding.
	 *
	 * @param string               $id       Finding ID.
	 * @param string               $title    Finding title.
	 * @param array<string, mixed> $metadata Update metadata summary.
	 * @return array<string, mixed>
	 */
	private function extension_update_finding( string $id, string $title, array $metadata ): array {
		$count    = $this->response_count( $metadata['value'] );
		$severity = 0 < $count ? 'warning' : ( $metadata['available'] ? 'info' : 'warning' );

		return $this->finding(
			$id,
			$title,
			$severity,
			array(
				'available_update_count'       => $count,
				'metadata_available'           => $metadata['available'],
				'metadata_age_hours'           => $metadata['age_hours'],
				'metadata_stale'               => $metadata['stale'],
				'item_identifiers_included'    => false,
				'filesystem_paths_included'    => false,
				'raw_update_payloads_included' => false,
			),
			0 < $count ? 'Review available updates, changelogs, backups, and compatibility before updating.' : 'No updates are visible in existing WordPress update metadata.'
		);
	}

	/**
	 * Build a compatibility summary from plugin and theme update metadata.
	 *
	 * @param array<string, mixed> $plugins Plugin update metadata summary.
	 * @param array<string, mixed> $themes  Theme update metadata summary.
	 * @return array<string, mixed>
	 */
	private function update_compatibility_finding( array $plugins, array $themes ): array {
		$signals = array(
			'plugin' => $this->compatibility_counts( $plugins['value'] ),
			'theme'  => $this->compatibility_counts( $themes['value'] ),
		);

		$unknown     = $signals['plugin']['unknown_wordpress_tested'] + $signals['theme']['unknown_wordpress_tested'];
		$wp_cautions = $signals['plugin']['wordpress_tested_cautions'] + $signals['theme']['wordpress_tested_cautions'];
		$blockers    = $signals['plugin']['php_requirement_blockers'] + $signals['theme']['php_requirement_blockers'] + $signals['plugin']['wordpress_requirement_blockers'] + $signals['theme']['wordpress_requirement_blockers'];

		$severity = 0 < $blockers ? 'critical' : ( 0 < $wp_cautions || 0 < $unknown ? 'warning' : 'info' );

		return $this->finding(
			'compatibility_signals',
			'Compatibility Signals',
			$severity,
			array(
				'plugin_updates_checked'         => $signals['plugin']['updates_checked'],
				'theme_updates_checked'          => $signals['theme']['updates_checked'],
				'wordpress_tested_cautions'      => $wp_cautions,
				'unknown_wordpress_tested'       => $unknown,
				'php_requirement_blockers'       => $signals['plugin']['php_requirement_blockers'] + $signals['theme']['php_requirement_blockers'],
				'wordpress_requirement_blockers' => $signals['plugin']['wordpress_requirement_blockers'] + $signals['theme']['wordpress_requirement_blockers'],
				'installed_versions_included'    => false,
				'package_urls_included'          => false,
				'plugin_theme_source_scanned'    => false,
				'raw_update_payloads_included'   => false,
			),
			'info' === $severity ? 'Existing update metadata does not show obvious compatibility cautions.' : 'Review compatibility details in WordPress before applying updates.'
		);
	}

	/**
	 * Read a WordPress site transient option without triggering transient cleanup or update checks.
	 *
	 * @param string $transient Site transient name.
	 * @return array{available: bool, value: mixed, last_checked: int, age_hours: int, stale: bool}
	 */
	private function read_update_metadata( string $transient ): array {
		$value        = get_site_option( '_site_transient_' . $transient, false );
		$available    = false !== $value && null !== $value;
		$last_checked = $this->metadata_int( $value, 'last_checked' );
		$age_hours    = 0 < $last_checked ? max( 0, (int) floor( ( time() - $last_checked ) / HOUR_IN_SECONDS ) ) : 0;
		$stale        = ! $available || 0 === $last_checked || self::STALE_UPDATE_HOURS < $age_hours;

		return array(
			'available'    => $available,
			'value'        => $value,
			'last_checked' => $last_checked,
			'age_hours'    => $age_hours,
			'stale'        => $stale,
		);
	}

	/**
	 * Count available extension updates from a WordPress update metadata object.
	 *
	 * @param mixed $metadata Raw update metadata.
	 */
	private function response_count( mixed $metadata ): int {
		$response = $this->metadata_value( $metadata, 'response' );

		return is_countable( $response ) ? count( $response ) : 0;
	}

	/**
	 * Count available core updates from a WordPress core update metadata object.
	 *
	 * @param mixed $metadata Raw core update metadata.
	 */
	private function core_update_count( mixed $metadata ): int {
		$updates = $this->metadata_value( $metadata, 'updates' );
		if ( ! is_iterable( $updates ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $updates as $update ) {
			$response = (string) $this->metadata_value( $update, 'response' );
			if ( in_array( $response, array( 'upgrade', 'development' ), true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Count safe compatibility signals in plugin/theme update response metadata.
	 *
	 * @param mixed $metadata Raw update metadata.
	 * @return array<string, int>
	 */
	private function compatibility_counts( mixed $metadata ): array {
		$response = $this->metadata_value( $metadata, 'response' );
		$counts   = array(
			'updates_checked'                => 0,
			'wordpress_tested_cautions'      => 0,
			'unknown_wordpress_tested'       => 0,
			'php_requirement_blockers'       => 0,
			'wordpress_requirement_blockers' => 0,
		);

		if ( ! is_iterable( $response ) ) {
			return $counts;
		}

		$wp_version  = get_bloginfo( 'version' );
		$php_version = PHP_VERSION;

		foreach ( $response as $update ) {
			++$counts['updates_checked'];

			$tested = $this->metadata_string( $update, 'tested' );
			if ( '' === $tested ) {
				++$counts['unknown_wordpress_tested'];
			} elseif ( '' !== $wp_version && version_compare( $tested, $wp_version, '<' ) ) {
				++$counts['wordpress_tested_cautions'];
			}

			$requires_php = $this->metadata_string( $update, 'requires_php' );
			if ( '' !== $requires_php && version_compare( $php_version, $requires_php, '<' ) ) {
				++$counts['php_requirement_blockers'];
			}

			$requires_wp = $this->metadata_string( $update, 'requires' );
			if ( '' !== $requires_wp && '' !== $wp_version && version_compare( $wp_version, $requires_wp, '<' ) ) {
				++$counts['wordpress_requirement_blockers'];
			}
		}

		return $counts;
	}

	/**
	 * Return an update metadata field from an object or array.
	 *
	 * @param mixed  $metadata Raw metadata.
	 * @param string $key      Field key.
	 */
	private function metadata_value( mixed $metadata, string $key ): mixed {
		if ( is_array( $metadata ) ) {
			return $metadata[ $key ] ?? null;
		}

		if ( is_object( $metadata ) ) {
			return $metadata->{$key} ?? null;
		}

		return null;
	}

	/**
	 * Return an integer metadata field.
	 *
	 * @param mixed  $metadata Raw metadata.
	 * @param string $key      Field key.
	 */
	private function metadata_int( mixed $metadata, string $key ): int {
		$value = $this->metadata_value( $metadata, $key );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Return a string metadata field.
	 *
	 * @param mixed  $metadata Raw metadata.
	 * @param string $key      Field key.
	 */
	private function metadata_string( mixed $metadata, string $key ): string {
		$value = $this->metadata_value( $metadata, $key );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Normalize a report type.
	 *
	 * @param mixed $value Raw report type.
	 */
	private function report_type( mixed $value ): string {
		$report_type = sanitize_key( (string) $value );

		return in_array( $report_type, self::REPORT_TYPES, true ) ? $report_type : 'content_review';
	}

	/**
	 * Return supported content post type keys.
	 *
	 * @return list<string>
	 */
	private function content_post_types(): array {
		$post_types = get_post_types( array( 'show_ui' => true ), 'objects' );
		$keys       = array();

		foreach ( $post_types as $name => $post_type ) {
			if ( ! $post_type instanceof \WP_Post_Type ) {
				continue;
			}

			if ( in_array( (string) $name, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
				continue;
			}

			if ( ! $post_type->show_ui ) {
				continue;
			}

			$keys[] = (string) $name;
			if ( self::MAX_POST_TYPE_KEYS <= count( $keys ) ) {
				break;
			}
		}

		return array() === $keys ? array( 'post', 'page' ) : $keys;
	}

	/**
	 * Return query posts as a list.
	 *
	 * @param \WP_Query $query Query object.
	 * @return list<\WP_Post>
	 */
	private function query_posts( \WP_Query $query ): array {
		return array_values(
			array_filter(
				$query->posts,
				static fn( mixed $post ): bool => $post instanceof \WP_Post
			)
		);
	}

	/**
	 * Build one normalized finding.
	 *
	 * @param string               $id        Finding ID.
	 * @param string               $title     Finding title.
	 * @param string               $severity  Severity.
	 * @param array<string, mixed> $evidence Bounded evidence.
	 * @param string               $next_step Suggested next step.
	 * @return array<string, mixed>
	 */
	private function finding( string $id, string $title, string $severity, array $evidence, string $next_step ): array {
		return array(
			'id'        => $id,
			'title'     => $title,
			'severity'  => $severity,
			'evidence'  => $evidence,
			'next_step' => $next_step,
		);
	}

	/**
	 * Build a normalized report response.
	 *
	 * @param string                     $report_type Report type.
	 * @param string                     $title       Report title.
	 * @param string                     $description Report description.
	 * @param int                        $page        One-based page.
	 * @param int                        $per_page    Results per page.
	 * @param \WP_Query                  $query       Query object.
	 * @param list<array<string, mixed>> $findings Findings.
	 * @param array<string, mixed>       $filters  Safe filters summary.
	 * @return array<string, mixed>
	 */
	private function report_payload( string $report_type, string $title, string $description, int $page, int $per_page, \WP_Query $query, array $findings, array $filters ): array {
		return array(
			'status'      => 'ready',
			'report_type' => $report_type,
			'title'       => $title,
			'description' => $description,
			'read_only'   => true,
			'pagination'  => array(
				'page'         => $page,
				'per_page'     => $per_page,
				'max_per_page' => self::MAX_PER_PAGE,
				'returned'     => count( $findings ),
				'total'        => absint( $query->found_posts ),
				'total_pages'  => absint( $query->max_num_pages ),
			),
			'summary'     => $this->summary( $findings ),
			'filters'     => $filters,
			'findings'    => $findings,
			'safety'      => array(
				'arbitrary_php_execution'  => false,
				'raw_database_access'      => false,
				'filesystem_writes'        => false,
				'database_writes'          => false,
				'write_actions_performed'  => false,
				'option_values_included'   => false,
				'option_names_included'    => false,
				'raw_sql_results_included' => false,
				'secret_values_included'   => false,
			),
		);
	}

	/**
	 * Build a normalized response for fixed-size reports.
	 *
	 * @param string                     $report_type Report type.
	 * @param string                     $title       Report title.
	 * @param string                     $description Report description.
	 * @param int                        $page        One-based page.
	 * @param int                        $per_page    Results per page.
	 * @param list<array<string, mixed>> $all_findings All findings.
	 * @param list<array<string, mixed>> $findings Returned findings.
	 * @param array<string, mixed>       $filters  Safe filters summary.
	 * @return array<string, mixed>
	 */
	private function static_report_payload( string $report_type, string $title, string $description, int $page, int $per_page, array $all_findings, array $findings, array $filters ): array {
		$total = count( $all_findings );

		return array(
			'status'      => 'ready',
			'report_type' => $report_type,
			'title'       => $title,
			'description' => $description,
			'read_only'   => true,
			'pagination'  => array(
				'page'         => $page,
				'per_page'     => $per_page,
				'max_per_page' => self::MAX_PER_PAGE,
				'returned'     => count( $findings ),
				'total'        => $total,
				'total_pages'  => (int) ceil( $total / $per_page ),
			),
			'summary'     => $this->summary( $all_findings ),
			'filters'     => $filters,
			'findings'    => $findings,
			'safety'      => array(
				'arbitrary_php_execution'  => false,
				'raw_database_access'      => false,
				'filesystem_writes'        => false,
				'database_writes'          => false,
				'write_actions_performed'  => false,
				'option_values_included'   => false,
				'option_names_included'    => false,
				'raw_sql_results_included' => false,
				'secret_values_included'   => false,
			),
		);
	}

	/**
	 * Summarize findings by severity.
	 *
	 * @param list<array<string, mixed>> $findings Findings.
	 * @return array<string, mixed>
	 */
	private function summary( array $findings ): array {
		$counts = array(
			'info'     => 0,
			'warning'  => 0,
			'critical' => 0,
		);

		foreach ( $findings as $finding ) {
			$severity            = (string) ( $finding['severity'] ?? 'info' );
			$counts[ $severity ] = (int) ( $counts[ $severity ] ?? 0 ) + 1;
		}

		return array(
			'overall_severity' => 0 < $counts['critical'] ? 'critical' : ( 0 < $counts['warning'] ? 'warning' : 'info' ),
			'counts'           => $counts,
		);
	}

	/**
	 * Return age in days from a GMT timestamp.
	 *
	 * @param string $gmt GMT timestamp.
	 */
	private function age_days( string $gmt ): int {
		$timestamp = '' === $gmt ? 0 : strtotime( $gmt . ' UTC' );
		if ( false === $timestamp || 0 >= $timestamp ) {
			return 0;
		}

		return max( 0, (int) floor( ( time() - $timestamp ) / 86400 ) );
	}

	/**
	 * Return a compact single-line title.
	 *
	 * @param string $title    Raw title.
	 * @param string $fallback Fallback title.
	 */
	private function safe_title( string $title, string $fallback ): string {
		$title = trim( wp_strip_all_tags( $title ) );
		if ( '' === $title ) {
			$title = $fallback;
		}

		return substr( $title, 0, self::MAX_TITLE_LENGTH );
	}

	/**
	 * Return safe URL metadata without returning the URL itself.
	 *
	 * @param string $url Raw URL.
	 * @return array{scheme: string, host: string}
	 */
	private function url_parts( string $url ): array {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		return array(
			'scheme' => is_string( $scheme ) ? strtolower( $scheme ) : '',
			'host'   => is_string( $host ) ? strtolower( $host ) : '',
		);
	}
}

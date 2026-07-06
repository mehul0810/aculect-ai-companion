<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Intelligence\ContentIndexer;

/**
 * Bounded read-only site maintenance reports for assistant diagnostics.
 */
final class SiteMaintenanceReports extends AbstractAbilityService {
	private const REPORT_TYPES       = array( 'content_review', 'media_inventory', 'site_readiness' );
	private const DEFAULT_PER_PAGE   = 10;
	private const MAX_PER_PAGE       = 20;
	private const STALE_DRAFT_DAYS   = 30;
	private const OVERSIZED_BYTES    = 5242880;
	private const MAX_TITLE_LENGTH   = 120;
	private const MAX_POST_TYPE_KEYS = 8;

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
				'arbitrary_php_execution' => false,
				'raw_database_access'     => false,
				'filesystem_writes'       => false,
				'option_values_included'  => false,
				'secret_values_included'  => false,
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
				'arbitrary_php_execution' => false,
				'raw_database_access'     => false,
				'filesystem_writes'       => false,
				'option_values_included'  => false,
				'secret_values_included'  => false,
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

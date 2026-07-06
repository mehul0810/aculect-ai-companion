<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Bounded read-only site maintenance reports for assistant diagnostics.
 */
final class SiteMaintenanceReports extends AbstractAbilityService {
	private const REPORT_TYPES       = array( 'content_review', 'media_inventory' );
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
}

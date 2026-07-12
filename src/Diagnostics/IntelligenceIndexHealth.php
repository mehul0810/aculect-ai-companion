<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

use Aculect\AICompanion\Intelligence\ContentIndexer;
use Aculect\AICompanion\Intelligence\ContentIndexRepository;

/**
 * Builds support-safe operational status for the local intelligence index.
 */
final class IntelligenceIndexHealth {

	/**
	 * Return a safe index-health payload without raw indexed content.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$repository = new ContentIndexRepository();
		$indexer    = new ContentIndexer( $repository );
		$summary    = $repository->summary();
		$jobs       = $repository->job_status_counts();
		$scheduled  = $indexer->stale_sweep_scheduled_at();
		$pending    = $indexer->pending_index_count();
		$total      = (int) ( $summary['total_items'] ?? 0 );
		$stale      = (int) ( $summary['stale_items'] ?? 0 );

		return array(
			'status'                   => $this->status_label( $total, $stale, $pending, $jobs ),
			'total_items'              => $total,
			'stale_items'              => $stale,
			'latest_indexed_at'        => (string) ( $summary['latest_indexed_at'] ?? '' ),
			'is_empty'                 => 0 === $total,
			'pending_object_count'     => $pending,
			'stale_sweep_scheduled'    => $scheduled > 0,
			'stale_sweep_scheduled_at' => $scheduled > 0 ? gmdate( 'c', $scheduled ) : '',
			'job_status_counts'        => $jobs,
			'recent_refresh_jobs'      => $repository->recent_job_summaries( 5 ),
		);
	}

	/**
	 * Convert raw index counters into an admin-facing status label.
	 *
	 * @param int                $total   Indexed item count.
	 * @param int                $stale   Stale item count.
	 * @param int                $pending Pending object count.
	 * @param array<string, int> $jobs    Job counts by status.
	 */
	private function status_label( int $total, int $stale, int $pending, array $jobs ): string {
		$queued_or_running = (int) ( $jobs['queued'] ?? 0 ) + (int) ( $jobs['running'] ?? 0 ) + (int) ( $jobs['partial'] ?? 0 );

		if ( 0 === $total ) {
			return 'empty';
		}

		if ( $pending > 0 || $queued_or_running > 0 ) {
			return 'backlogged';
		}

		if ( $stale > 0 ) {
			return 'stale';
		}

		return 'healthy';
	}
}

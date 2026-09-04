<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence;

/**
 * Persists deferred index work without running full indexing in save requests.
 */
final class ContentIndexDeferrer {

	/**
	 * Defer one content index refresh.
	 *
	 * @param int            $post_id Post ID.
	 * @param ContentIndexer $indexer Content indexer.
	 * @return array{queued: bool, scheduled: bool, queue_token: string}
	 */
	public function defer( int $post_id, ContentIndexer $indexer ): array {
		$post_id = absint( $post_id );
		if ( 0 >= $post_id ) {
			return $this->result( false, false, '' );
		}

		$indexer->mark_post_stale( $post_id );
		$queue_token = ( new ContentIndexQueue() )->enqueue_generation( $post_id );
		if ( '' === $queue_token ) {
			( new ContentIndexRetryScheduler() )->schedule( $post_id );
			return $this->result( false, false, '' );
		}

		$scheduled = $indexer->schedule_stale_sweep();
		$retry     = new ContentIndexRetryScheduler();
		if ( $scheduled ) {
			$retry->clear( $post_id );
		} else {
			$retry->schedule( $post_id );
		}

		return $this->result( true, $scheduled, $queue_token );
	}

	/**
	 * Build a normalized deferral result.
	 *
	 * @param bool   $queued      Whether the queue generation was persisted.
	 * @param bool   $scheduled   Whether the stale sweep was scheduled.
	 * @param string $queue_token Queue generation token.
	 * @return array{queued: bool, scheduled: bool, queue_token: string}
	 */
	private function result( bool $queued, bool $scheduled, string $queue_token ): array {
		return array(
			'queued'      => $queued,
			'scheduled'   => $scheduled,
			'queue_token' => $queue_token,
		);
	}
}

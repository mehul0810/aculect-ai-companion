<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence;

/**
 * Schedules lightweight retries for deferred index queue persistence.
 */
final class ContentIndexRetryScheduler {

	public const HOOK = 'aculect_ai_companion_content_index_retry';

	public function schedule( int $post_id, int $delay = 300 ): bool {
		$post_id = absint( $post_id );
		if ( 0 >= $post_id || ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}

		$args = array( $post_id );
		if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( self::HOOK, $args ) ) {
			return true;
		}

		$scheduled = wp_schedule_single_event( time() + max( 30, $delay ), self::HOOK, $args, true );

		return false !== $scheduled && ! is_wp_error( $scheduled );
	}

	public function clear( int $post_id ): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_unschedule_event' ) ) {
			return;
		}

		$args      = array( absint( $post_id ) );
		$scheduled = wp_next_scheduled( self::HOOK, $args );
		if ( false !== $scheduled ) {
			wp_unschedule_event( (int) $scheduled, self::HOOK, $args );
		}
	}
}

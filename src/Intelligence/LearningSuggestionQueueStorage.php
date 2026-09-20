<?php
/**
 * Compare-and-swap storage for the bounded learning suggestion queue.
 *
 * @package Aculect\AICompanion\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence;

defined( 'ABSPATH' ) || exit;

/**
 * Reads queue state from the authoritative options row and conditionally saves it.
 */
final class LearningSuggestionQueueStorage {

	private const OPTION = 'aculect_ai_companion_learning_suggestions';

	/**
	 * Set a test-only option action dispatcher.
	 *
	 * @param \Closure|null $action_dispatcher Test-only option action dispatcher.
	 */
	public function __construct( private ?\Closure $action_dispatcher = null ) {
	}

	/**
	 * Read the current option without trusting a stale object-cache value.
	 *
	 * @return array{exists:bool,value:mixed,token:string}
	 */
	public function read(): array {
		if ( $this->uses_test_options() ) {
			$exists = array_key_exists( self::OPTION, $GLOBALS['aculect_ai_companion_test_options'] );

			return array(
				'exists' => $exists,
				'value'  => $exists ? $GLOBALS['aculect_ai_companion_test_options'][ self::OPTION ] : null,
				'token'  => $exists ? $this->storage_value( $GLOBALS['aculect_ai_companion_test_options'][ self::OPTION ] ) : '',
			);
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Queue correctness requires an authoritative read outside the options object cache.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION
			)
		);
		if ( ! is_string( $value ) ) {
			return array(
				'exists' => false,
				'value'  => null,
				'token'  => '',
			);
		}

		return array(
			'exists' => true,
			'value'  => function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $value ) : $value,
			'token'  => $value,
		);
	}

	/**
	 * Persist a replacement only when the previously read state is still current.
	 *
	 * @param bool                      $expected_exists Whether the prior read found an option row.
	 * @param mixed                     $expected_value  Exact prior option value.
	 * @param string                    $expected_token  Exact raw prior option_value token.
	 * @param list<array<string,mixed>> $next_value      Replacement queue value.
	 * @return list<array<string,mixed>>|false Saved value, or false for a stale/rejected write.
	 */
	public function compare_and_swap( bool $expected_exists, mixed $expected_value, string $expected_token, array $next_value ): array|false {
		$next_value = function_exists( 'sanitize_option' ) ? sanitize_option( self::OPTION, $next_value ) : $next_value;
		$old_value  = $expected_exists ? $expected_value : false;
		$next_value = apply_filters( 'pre_update_option_' . self::OPTION, $next_value, $old_value, self::OPTION );
		$next_value = apply_filters( 'pre_update_option', $next_value, self::OPTION, $old_value );
		if ( ! is_array( $next_value ) ) {
			return false;
		}
		if ( $next_value === $old_value ) {
			return false;
		}

		if ( ! $expected_exists ) {
			$added = add_option( self::OPTION, $next_value, '', false );
			$this->invalidate_caches();

			return $added ? $next_value : false;
		}

		if ( $this->uses_test_options() ) {
			$current = $this->read();
			if ( ! $current['exists'] || $current['token'] !== $expected_token ) {
				$this->invalidate_caches();
				return false;
			}

			$updated = update_option( self::OPTION, $next_value, false );
			$this->invalidate_caches();

			return $updated ? $next_value : false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- An exact binary conditional update prevents a stale queue snapshot from replacing newer state.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND HEX(option_value) = %s",
				$this->storage_value( $next_value ),
				self::OPTION,
				strtoupper( bin2hex( $expected_token ) )
			)
		);
		$this->invalidate_caches();

		return 1 === (int) $updated ? $next_value : false;
	}

	/**
	 * Notify WordPress listeners only after a successful, durable existing-row update.
	 *
	 * Core normally emits update_option, update_option_{$option}, and
	 * updated_option around an unconditional SQL write. A CAS cannot know
	 * whether the old row still matches until its conditional write completes,
	 * so emitting a pre-write action could falsely announce a stale loser.
	 * Preserve the standard action names, argument order, and relative order,
	 * but deliberately dispatch them only after a successful CAS. Review
	 * transactions defer this method until after COMMIT.
	 *
	 * @param mixed                     $old_value Prior option value.
	 * @param list<array<string,mixed>> $new_value Saved option value.
	 */
	public function dispatch_updated( mixed $old_value, array $new_value ): void {
		if ( null !== $this->action_dispatcher ) {
			( $this->action_dispatcher )( 'update_option', self::OPTION, $old_value, $new_value );
			( $this->action_dispatcher )( 'update_option_' . self::OPTION, $old_value, $new_value, self::OPTION );
			( $this->action_dispatcher )( 'updated_option', self::OPTION, $old_value, $new_value );
			return;
		}
		if ( ! function_exists( 'do_action' ) ) {
			return;
		}

		do_action( 'update_option', self::OPTION, $old_value, $new_value );
		do_action( 'update_option_' . self::OPTION, $old_value, $new_value, self::OPTION );
		do_action( 'updated_option', self::OPTION, $old_value, $new_value );
	}

	/**
	 * Clear option and aggregate cache entries after a direct SQL mutation.
	 */
	private function invalidate_caches(): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Convert a value to its exact wp_options representation for CAS.
	 *
	 * @param mixed $value Option value.
	 */
	private function storage_value( mixed $value ): string {
		if ( is_scalar( $value ) || null === $value ) {
			return (string) $value;
		}

		return function_exists( 'maybe_serialize' )
			? (string) maybe_serialize( $value )
			: serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fallback is only for an impossible partial WordPress runtime.
	}

	/** Check whether the lightweight PHPUnit option store is active. */
	private function uses_test_options(): bool {
		return isset( $GLOBALS['aculect_ai_companion_test_options'] ) && is_array( $GLOBALS['aculect_ai_companion_test_options'] );
	}
}

<?php
/**
 * Serializes Aculect form submissions for a fixed setting across administrators.
 *
 * @package Aculect\AICompanion\Settings
 */

declare(strict_types=1);
namespace Aculect\AICompanion\Settings;

/** Uses a unique non-autoloaded option and owner-checked cleanup. */
final class PrivateSettingLock {
	private string $name  = '';
	private string $owner = '';

	public function acquire( string $target ): bool {
		$this->name = '_aculect_setting_lock_' . hash( 'sha256', $target );
		$previous   = get_option( $this->name, '' );
		if ( is_string( $previous ) && preg_match( '/^([0-9]+):[a-f0-9]{32}$/', $previous, $matches ) && (int) $matches[1] < time() ) {
			$this->remove( $previous );
		}
		$this->owner = ( time() + 300 ) . ':' . bin2hex( random_bytes( 16 ) );
		return add_option( $this->name, $this->owner, '', false );
	}

	public function release(): void {
		$this->remove( $this->owner );
	}

	public function valid(): bool {
		return time() < (int) $this->owner && get_option( $this->name, '' ) === $this->owner;
	}

	private function remove( string $owner ): void {
		if ( '' === $this->name || '' === $owner ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only this lock owner; never clear a successor's lock.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $this->name, $owner ) );
		wp_cache_delete( $this->name, 'options' );
	}
}

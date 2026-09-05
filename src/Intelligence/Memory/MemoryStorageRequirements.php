<?php
/**
 * Storage requirements for transactional Aculect Memory writes.
 *
 * @package Aculect\AICompanion\Intelligence\Memory
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Memory;

use Aculect\AICompanion\Intelligence\Database\Installer;

/**
 * Verifies that canonical memory and event tables support transactions.
 */
final class MemoryStorageRequirements {
	private ?bool $supported = null;

	/**
	 * Return whether both mutation tables use a transactional engine.
	 */
	public function supports_transactions(): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		if ( null !== $this->supported ) {
			return $this->supported;
		}

		if ( ! method_exists( $wpdb, 'get_row' ) ) {
			$this->supported = false;
			return $this->supported;
		}

		foreach ( array( Installer::memory_items_table(), Installer::memory_events_table() ) as $table ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT TABLE_NAME AS Name, ENGINE AS Engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1', $table ),
				ARRAY_A
			);
			if ( ! is_array( $row ) || (string) ( $row['Name'] ?? '' ) !== $table || 'innodb' !== strtolower( (string) ( $row['Engine'] ?? '' ) ) ) {
				$this->supported = false;
				return $this->supported;
			}
		}

		$this->supported = true;
		return $this->supported;
	}
}

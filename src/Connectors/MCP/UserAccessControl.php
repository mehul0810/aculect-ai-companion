<?php
/**
 * Per-user MCP access pause state.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Stores user-specific AI access pauses independently from site-wide lockdown.
 */
final class UserAccessControl {

	private const OPTION_PAUSED_USERS = 'aculect_ai_companion_paused_user_access';

	/**
	 * Determine whether AI access is paused for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function is_paused( int $user_id ): bool {
		return $user_id > 0 && in_array( $user_id, self::paused_user_ids(), true );
	}

	/**
	 * Pause or resume AI access for a user.
	 *
	 * @param int  $user_id WordPress user ID.
	 * @param bool $paused  Whether access should be paused.
	 */
	public static function set_paused( int $user_id, bool $paused ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$user_ids = self::paused_user_ids();
		if ( $paused ) {
			$user_ids[] = $user_id;
		} else {
			$user_ids = array_values(
				array_filter(
					$user_ids,
					static fn( int $paused_user_id ): bool => $paused_user_id !== $user_id
				)
			);
		}

		update_option( self::OPTION_PAUSED_USERS, self::sanitize_user_ids( $user_ids ), false );
	}

	/**
	 * Return paused user IDs.
	 *
	 * @return int[]
	 */
	public static function paused_user_ids(): array {
		$stored = get_option( self::OPTION_PAUSED_USERS, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return self::sanitize_user_ids( $stored );
	}

	/**
	 * Remove every per-user pause state.
	 */
	public static function delete(): void {
		delete_option( self::OPTION_PAUSED_USERS );
	}

	/**
	 * Sanitize stored user IDs without turning invalid negatives into valid IDs.
	 *
	 * @param array<mixed> $user_ids Raw user IDs.
	 * @return int[]
	 */
	private static function sanitize_user_ids( array $user_ids ): array {
		$sanitized = array();
		foreach ( $user_ids as $user_id ) {
			if ( ! is_scalar( $user_id ) ) {
				continue;
			}

			$user_id = (int) $user_id;
			if ( $user_id > 0 ) {
				$sanitized[] = $user_id;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}
}

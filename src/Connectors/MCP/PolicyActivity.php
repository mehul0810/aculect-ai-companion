<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Activity\ActivityLogger;

/**
 * Best-effort policy audit records without policy payloads or resource content.
 */
final class PolicyActivity {

	/**
	 * Record a resource outcome using an opaque URI correlation, never its text.
	 *
	 * @param string               $uri Requested resource URI.
	 * @param array<string, mixed> $result Resource result.
	 * @param array<string, mixed> $auth Authenticated connection context.
	 */
	public static function resource_read( string $uri, array $result, array $auth ): void {
		$payload = json_decode( (string) ( $result['contents'][0]['text'] ?? '' ), true );
		$error   = $result['error'] ?? ( is_array( $payload ) ? ( $payload['error'] ?? '' ) : '' );
		self::record(
			'resources_read',
			array(
				'method'         => 'resources/read',
				'status'         => '' !== $error ? 'blocked' : 'success',
				'error_code'     => is_string( $error ) ? $error : 'resource_error',
				'target_summary' => 'resource:' . substr( hash( 'sha256', $uri ), 0, 16 ),
			),
			$auth
		);
	}

	/**
	 * Persist a policy option and audit only actual successful changes.
	 *
	 * @param string $option Fixed role-policy option name.
	 * @param mixed  $value Sanitized value; null deletes the option.
	 */
	public static function persist( string $option, mixed $value ): void {
		$before = get_option( $option, null );
		if ( $before === $value ) {
			return;
		}
		$changed = null === $value ? delete_option( $option ) : update_option( $option, $value, false );
		if ( $changed ) {
			$old_policies = is_array( $before ) ? $before : array();
			$new_policies = is_array( $value ) ? $value : array();
			$roles        = array_filter( array_unique( array_merge( array_keys( $old_policies ), array_keys( $new_policies ) ) ), static fn( $role ): bool => ( $old_policies[ $role ] ?? null ) !== ( $new_policies[ $role ] ?? null ) );
			self::record(
				'role_policy_change',
				array(
					'method'         => 'role_policy',
					'target_summary' => $option . ';entries:' . ( is_array( $value ) ? count( $value ) : (int) (bool) $value ) . ';roles:' . implode( ',', $roles ),
				),
				array(
					'user_id'  => get_current_user_id(),
					'provider' => 'admin',
				)
			);
		}
	}

	/**
	 * Logging failure never changes authorization or persistence behavior.
	 *
	 * @param string               $event Allowlisted event.
	 * @param array<string, mixed> $metadata Bounded non-sensitive metadata.
	 * @param array<string, mixed> $auth Actor context.
	 */
	private static function record( string $event, array $metadata, array $auth ): void {
		try {
			( new ActivityLogger() )->record_timeline_event( $event, $metadata, $auth );
		} catch ( \Throwable ) {
			// Activity storage is best effort and cannot grant or deny access.
			return;
		}
	}
}

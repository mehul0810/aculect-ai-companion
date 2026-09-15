<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Closure;
use Throwable;

/**
 * Reads a minimal lifecycle projection of a native WordPress privacy request.
 */
final class PrivacyRequestStatus {

	private const ACTION_CAPABILITIES = array(
		'export_personal_data' => 'export_others_personal_data',
		'remove_personal_data' => 'erase_others_personal_data',
	);

	private const ALLOWED_STATUSES = array(
		'request-pending',
		'request-confirmed',
		'request-failed',
		'request-completed',
	);

	private readonly Closure $request_reader;

	/**
	 * Create a privacy request status reader.
	 *
	 * @param Closure|null $request_reader Optional narrow adapter for reading one native request.
	 */
	public function __construct( ?Closure $request_reader = null ) {
		$this->request_reader = $request_reader ?? static function ( int $request_id ): mixed {
			if ( ! function_exists( 'wp_get_user_request' ) ) {
				return false;
			}

			return wp_get_user_request( $request_id );
		};
	}

	/**
	 * Read only the lifecycle status of a native export or erasure request.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function read( array $args ): array {
		if ( 2 !== count( $args ) || ! array_key_exists( 'action', $args ) || ! array_key_exists( 'request_id', $args ) ) {
			return $this->error( 'invalid_request_arguments', 'Provide only a supported privacy request action and positive request ID.' );
		}

		$action     = $args['action'];
		$request_id = $args['request_id'];
		if ( ! is_string( $action ) || ! array_key_exists( $action, self::ACTION_CAPABILITIES ) || ! is_int( $request_id ) || $request_id <= 0 ) {
			return $this->error( 'invalid_request_arguments', 'Provide only a supported privacy request action and positive request ID.' );
		}

		if ( ! current_user_can( self::ACTION_CAPABILITIES[ $action ] ) ) {
			return $this->error( 'forbidden', 'You do not have permission to read this privacy request status.' );
		}

		try {
			$request = ( $this->request_reader )( $request_id );
		} catch ( Throwable ) {
			return $this->error( 'request_status_unavailable', 'The native privacy request status is unavailable.' );
		}

		if ( false === $request ) {
			return $this->error( 'request_not_found', 'The native privacy request was not found.' );
		}

		if ( ! is_object( $request ) ) {
			return $this->error( 'request_status_unavailable', 'The native privacy request status is unavailable.' );
		}

		$request_fields = get_object_vars( $request );
		if ( ! array_key_exists( 'ID', $request_fields ) || ! array_key_exists( 'action_name', $request_fields ) || ! array_key_exists( 'status', $request_fields ) ) {
			return $this->error( 'request_status_unavailable', 'The native privacy request status is unavailable.' );
		}

		$native_request_id = $request_fields['ID'];
		$native_action     = $request_fields['action_name'];
		$native_status     = $request_fields['status'];
		if ( ! is_int( $native_request_id ) || $native_request_id !== $request_id || ! is_string( $native_action ) || ! is_string( $native_status ) ) {
			return $this->error( 'request_status_unavailable', 'The native privacy request status is unavailable.' );
		}

		if ( $native_action !== $action ) {
			return $this->error( 'request_action_mismatch', 'The native privacy request action does not match the requested action.' );
		}

		if ( ! in_array( $native_status, self::ALLOWED_STATUSES, true ) ) {
			return $this->error( 'request_status_unavailable', 'The native privacy request status is unavailable.' );
		}

		return array(
			'request_id'      => $request_id,
			'action'          => $action,
			'status'          => $native_status,
			'progress'        => 'unavailable',
			'completion_note' => 'Completion reflects WordPress native request status; it is not proof that no personal data remains.',
		);
	}

	/**
	 * Build a static, privacy-safe error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Client-safe message.
	 * @return array{status: string, error: string, message: string}
	 */
	private function error( string $code, string $message ): array {
		return array(
			'status'  => 'error',
			'error'   => $code,
			'message' => $message,
		);
	}
}

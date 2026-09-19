<?php
/**
 * Tests for privacy-safe native WordPress request status reads.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\PrivacyRequestStatus;
use PHPUnit\Framework\TestCase;

/**
 * Verifies request status reads are capability-gated and narrowly projected.
 */
final class PrivacyRequestStatusTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		unset( $GLOBALS['aculect_ai_companion_test_capability_callback'] );
	}

	public function test_capability_denial_precedes_request_access_for_each_action(): void {
		$actions = array(
			'export_personal_data' => 'export_others_personal_data',
			'remove_personal_data' => 'erase_others_personal_data',
		);

		foreach ( $actions as $action => $expected_capability ) {
			$checked_capabilities = array();
			$reader_ids           = array();
			$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability, array $capability_args, int $user_id ) use ( &$checked_capabilities ): bool {
				unset( $capability_args, $user_id );
				$checked_capabilities[] = $capability;

				return false;
			};

			$service = new PrivacyRequestStatus(
				static function ( int $request_id ) use ( &$reader_ids ): mixed {
					$reader_ids[] = $request_id;

					return false;
				}
			);

			$result = $service->read(
				array(
					'action'     => $action,
					'request_id' => 42,
				)
			);

			self::assertSame( 'forbidden', $result['error'] );
			self::assertSame( array( $expected_capability ), $checked_capabilities );
			self::assertSame( array(), $reader_ids );
		}
	}

	public function test_rejects_malformed_arguments_and_extra_private_fields_before_access(): void {
		$invalid_arguments = array(
			array(),
			array( 'action' => 'export_personal_data' ),
			array( 'request_id' => 42 ),
			array(
				'action'     => 'export_personal_data',
				'request_id' => 42,
				'email'      => 'private@example.test',
			),
			array(
				'action'     => 'export_personal_data',
				'request_id' => 42,
				'user_id'    => 7,
			),
			array(
				'action'       => 'export_personal_data',
				'request_id'   => 42,
				'request_data' => array( 'key' => 'private-key' ),
			),
			array(
				'action'     => 'unknown_action',
				'request_id' => 42,
			),
			array(
				'action'     => 'export_personal_data',
				'request_id' => 0,
			),
			array(
				'action'     => 'export_personal_data',
				'request_id' => -1,
			),
			array(
				'action'     => 'export_personal_data',
				'request_id' => '42',
			),
			array(
				'action'     => 'export_personal_data',
				'request_id' => 42.0,
			),
			array(
				'action'     => 'export_personal_data',
				'request_id' => true,
			),
			array(
				'action'     => 'export_personal_data',
				'request_id' => array( 42 ),
			),
			array( 'export_personal_data', 42 ),
		);
		$reader_ids        = array();
		$service           = new PrivacyRequestStatus(
			static function ( int $request_id ) use ( &$reader_ids ): mixed {
				$reader_ids[] = $request_id;

				return false;
			}
		);

		foreach ( $invalid_arguments as $arguments ) {
			$result = $service->read( $arguments );

			self::assertSame( 'invalid_request_arguments', $result['error'] );
		}

		self::assertSame( array(), $reader_ids );
	}

	public function test_returns_only_the_safe_projection_even_when_private_fields_are_malformed(): void {
		$request                     = $this->request( 42, 'export_personal_data', 'request-completed' );
		$request->email              = array( 'malformed-private-email' );
		$request->user_id            = (object) array( 'unexpected' => 'private-user-data' );
		$request->request_data       = array( 'key' => array( 'malformed-private-key' ) );
		$request->confirmation_key   = 'private-confirmation-key';
		$request->file_url           = 'https://example.test/private-export.zip';
		$request->modified_timestamp = array( 'malformed-private-timestamp' );

		$service = new PrivacyRequestStatus(
			static function ( int $request_id ) use ( $request ): mixed {
				return 42 === $request_id ? $request : false;
			}
		);
		$result  = $service->read(
			array(
				'action'     => 'export_personal_data',
				'request_id' => 42,
			)
		);

		self::assertSame(
			array(
				'request_id',
				'action',
				'status',
				'progress',
				'completion_note',
			),
			array_keys( $result )
		);
		self::assertSame( 42, $result['request_id'] );
		self::assertSame( 'export_personal_data', $result['action'] );
		self::assertSame( 'request-completed', $result['status'] );
		self::assertSame( 'unavailable', $result['progress'] );
		self::assertSame( 'Completion reflects WordPress native request status; it is not proof that no personal data remains.', $result['completion_note'] );
		self::assertStringNotContainsString( 'private', strtolower( wp_json_encode( $result ) ) );
	}

	public function test_accepts_only_the_native_lifecycle_statuses_and_both_actions(): void {
		$cases = array(
			array( 'export_personal_data', 'request-pending' ),
			array( 'export_personal_data', 'request-confirmed' ),
			array( 'remove_personal_data', 'request-failed' ),
			array( 'remove_personal_data', 'request-completed' ),
		);

		foreach ( $cases as $case ) {
			[ $action, $status ] = $case;
			$request             = $this->request( 42, $action, $status );
			$service             = new PrivacyRequestStatus(
				static function ( int $request_id ) use ( $request ): mixed {
					return 42 === $request_id ? $request : false;
				}
			);
			$result              = $service->read(
				array(
					'action'     => $action,
					'request_id' => 42,
				)
			);

			self::assertSame( $status, $result['status'] );
			self::assertSame( $action, $result['action'] );
		}
	}

	public function test_rejects_missing_mismatched_and_invalid_native_requests(): void {
		$cases = array(
			array( false, 'export_personal_data', 'request_not_found' ),
			array( $this->request( 42, 'remove_personal_data', 'request-pending' ), 'export_personal_data', 'request_action_mismatch' ),
			array( $this->request( 42, 'export_personal_data', 'queued' ), 'export_personal_data', 'request_status_unavailable' ),
			array( $this->request( 43, 'export_personal_data', 'request-pending' ), 'export_personal_data', 'request_status_unavailable' ),
			array( (object) array( 'ID' => 42 ), 'export_personal_data', 'request_status_unavailable' ),
		);

		foreach ( $cases as $case ) {
			[ $request, $action, $expected_error ] = $case;
			$service                               = new PrivacyRequestStatus(
				static function ( int $request_id ) use ( $request ): mixed {
					return 42 === $request_id ? $request : false;
				}
			);
			$result                                = $service->read(
				array(
					'action'     => $action,
					'request_id' => 42,
				)
			);

			self::assertSame( 'error', $result['status'] );
			self::assertSame( $expected_error, $result['error'] );
		}
	}

	public function test_fails_closed_without_invoking_throwing_magic_accessors(): void {
		$requests = array();
		foreach ( array( true, false ) as $throw_from_isset ) {
			$requests[] = new class( $throw_from_isset ) {
				public function __construct( private bool $throw_from_isset ) {
				}

				public function __isset( string $property ): bool {
					if ( 'ID' === $property && $this->throw_from_isset ) {
						throw new \RuntimeException( 'Magic isset was invoked.' );
					}

					return true;
				}

				public function __get( string $property ): mixed {
					if ( 'ID' === $property ) {
						throw new \RuntimeException( 'Magic get was invoked.' );
					}

					return null;
				}
			};
		}

		foreach ( $requests as $request ) {
			$service = new PrivacyRequestStatus(
				static function ( int $request_id ) use ( $request ): mixed {
					return 42 === $request_id ? $request : false;
				}
			);
			$result  = $service->read(
				array(
					'action'     => 'export_personal_data',
					'request_id' => 42,
				)
			);

			self::assertSame( 'request_status_unavailable', $result['error'] );
		}
	}

	/**
	 * Build a native-request-shaped test object.
	 *
	 * @param int    $request_id Request ID.
	 * @param string $action     Native privacy action.
	 * @param string $status     Native lifecycle status.
	 * @return object
	 */
	private function request( int $request_id, string $action, string $status ): object {
		return (object) array(
			'ID'          => $request_id,
			'action_name' => $action,
			'status'      => $status,
		);
	}
}

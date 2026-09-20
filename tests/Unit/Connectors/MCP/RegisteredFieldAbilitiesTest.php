<?php
/**
 * Registered scalar field boundary tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\RegisteredFieldAbilities;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionRequest;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use Aculect\AICompanion\Tests\Support\InMemoryExecutionClaimStore;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class RegisteredFieldAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/fixtures/registered-field-stubs.php';
		$GLOBALS['aculect_ai_companion_test_posts']               = array(
			10 => array(
				'ID'          => 10,
				'post_type'   => 'post',
				'post_status' => 'draft',
			),
		);
		$GLOBALS['aculect_ai_companion_test_post_types']          = array( 'post' => new \WP_Post_Type( 'post' ) );
		$GLOBALS['aculect_ai_companion_test_post_type_supports']  = array( 'post' => array( 'custom-fields' ) );
		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids']     = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
		$GLOBALS['registered_field_test_registry']                = array( 'post' => array( 'rating' => $this->registration( 'integer' ) ) );
		$GLOBALS['registered_field_test_rows']                    = array( 'rating' => array( '3' ) );
		$GLOBALS['registered_field_test_writes']                  = 0;
		$GLOBALS['registered_field_test_write_mode']              = 'normal';
		$GLOBALS['registered_field_test_sanitizer']               = null;
	}

	public function test_read_normalizes_scalar_storage_and_provides_state(): void {
		foreach ( array(
			'integer' => array( '3', 3 ),
			'number'  => array( '3.5', 3.5 ),
			'boolean' => array( '', false ),
			'string'  => array( 'hello', 'hello' ),
		) as $type => $values ) {
			$GLOBALS['registered_field_test_registry']['post']['rating'] = $this->registration( $type );
			$GLOBALS['registered_field_test_rows']['rating']             = array( $values[0] );
			$result = $this->read();
			self::assertSame( $values[1], $result['value'] );
			self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['expected_state'] );
			self::assertArrayNotHasKey( 'rows', $result );
		}
	}

	public function test_list_filters_schema_without_reading_values_and_supports_pagination(): void {
		$registry                  = array(
			'rating' => $this->registration( 'integer' ),
			'title'  => $this->registration( 'string' ),
		);
		$registry['hidden']        = array_merge( $this->registration( 'string' ), array( 'show_in_rest' => false ) );
		$registry['_secret']       = $this->registration( 'string' );
		$registry['private_field'] = $this->registration( 'string' );
		$registry['complex']       = $this->registration( 'array' );
		$registry['multiple']      = array_merge( $this->registration( 'string' ), array( 'single' => false ) );
		$GLOBALS['registered_field_test_registry']['post'] = $registry;
		$result = ( new RegisteredFieldAbilities() )->list_fields(
			array(
				'post_id'  => 10,
				'page'     => 2,
				'per_page' => 1,
			)
		);
		self::assertSame( 2, $result['total'] );
		self::assertSame( 'title', $result['items'][0]['key'] );
		self::assertArrayNotHasKey( 'value', $result['items'][0] );
	}

	public function test_permissions_gate_reads_and_writes(): void {
		foreach ( array( 'read_post', 'edit_post', 'edit_post_meta' ) as $capability ) {
			$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( $capability );
			self::assertArrayHasKey( 'error', $this->read() );
			self::assertArrayHasKey(
				'error',
				( new RegisteredFieldAbilities() )->update_field(
					array(
						'post_id'        => 10,
						'key'            => 'rating',
						'value'          => 4,
						'expected_state' => str_repeat( 'a', 64 ),
					)
				)
			);
		}
		self::assertSame( 0, $GLOBALS['registered_field_test_writes'] );
	}

	public function test_unsupported_post_types_and_features_are_denied(): void {
		foreach ( array( 'public', 'show_in_rest' ) as $property ) {
			$GLOBALS['aculect_ai_companion_test_post_types']['post']->$property = false;
			self::assertSame( 'unsupported_post', $this->read()['error'] );
			$GLOBALS['aculect_ai_companion_test_post_types']['post']->$property = true;
		}
		$GLOBALS['aculect_ai_companion_test_post_type_supports']['post'] = array();
		self::assertSame( 'unsupported_post', $this->read()['error'] );
	}

	public function test_missing_duplicate_and_malformed_rows_are_explicit(): void {
		$GLOBALS['registered_field_test_default']        = 3;
		$GLOBALS['registered_field_test_rows']['rating'] = array();
		$result = $this->read();
		self::assertFalse( $result['exists'] );
		self::assertArrayNotHasKey( 'value', $result );
		self::assertSame( 'field_not_writable', $this->update( 4, $result['expected_state'] )['error'] );
		$GLOBALS['registered_field_test_rows']['rating'] = array( '3', '3' );
		self::assertSame( 'ambiguous_field_state', $this->read()['error'] );
		foreach ( array( new \stdClass(), array( 'nested' ), null, 'a:0:{}', INF ) as $bad ) {
			$GLOBALS['registered_field_test_rows']['rating'] = array( $bad );
			self::assertSame( 'invalid_field_value', $this->read()['error'] );
		}
	}

	public function test_hostile_arguments_are_rejected_without_throwing(): void {
		$service = new RegisteredFieldAbilities();
		foreach ( array( null, new \stdClass(), array(), '10', -1, false ) as $bad ) {
			self::assertArrayHasKey(
				'error',
				$service->read_field(
					array(
						'post_id' => $bad,
						'key'     => 'rating',
					)
				)
			);
		}
		foreach ( array( null, new \stdClass(), array(), '_secret', 'unknown', 'private_field' ) as $bad ) {
			self::assertArrayHasKey(
				'error',
				$service->read_field(
					array(
						'post_id' => 10,
						'key'     => $bad,
					)
				)
			);
		}
		foreach ( array( null, new \stdClass(), array(), '4', INF, NAN, str_repeat( 'a', 4001 ) ) as $bad ) {
			self::assertSame( 'invalid_field_value', $this->update( $bad )['error'] );
		}
		self::assertSame( 0, $GLOBALS['registered_field_test_writes'] );
	}

	public function test_rest_override_context_enum_and_schema_changes_are_honored(): void {
		$GLOBALS['registered_field_test_registry']['post']['rating']['show_in_rest'] = array(
			'name'   => 'review_rating',
			'schema' => array(
				'enum'    => array( 3, 5 ),
				'context' => array( 'edit' ),
			),
		);
		$read = $this->read();
		self::assertSame( 'review_rating', $read['schema']['x-rest-name'] );
		self::assertSame( 'invalid_field_value', $this->update( 4 )['error'] );
		$GLOBALS['registered_field_test_registry']['post']['rating']['show_in_rest']['schema']['maximum'] = 4;
		self::assertSame( 'stale_field_state', $this->update( 3, $read['expected_state'] )['error'] );
		$GLOBALS['registered_field_test_registry']['post']['rating']['show_in_rest']['schema']['context'] = array( 'view' );
		self::assertSame( 'unsupported_field', $this->read()['error'] );
	}

	public function test_malformed_registrations_fail_closed(): void {
		foreach ( array( array( 'type' => array( 'string' ) ), array( 'minimum' => new \stdClass() ), array( 'minimum' => 'bad' ), array( 'context' => 'edit' ), array( 'anyOf' => array() ), array( 'enum' => array( new \stdClass() ) ) ) as $schema ) {
			$GLOBALS['registered_field_test_registry']['post']['rating']['show_in_rest'] = array( 'schema' => $schema );
			self::assertSame( 'unsupported_field', $this->read()['error'] );
		}
	}

	public function test_preview_noop_and_verified_write(): void {
		$state   = $this->read()['expected_state'];
		$preview = ( new RegisteredFieldAbilities() )->update_field(
			array(
				'post_id'        => 10,
				'key'            => 'rating',
				'value'          => 4,
				'expected_state' => $state,
				'dry_run'        => true,
			)
		);
		self::assertTrue( $preview['dry_run'] );
		self::assertSame( 0, $GLOBALS['registered_field_test_writes'] );
		self::assertFalse( $this->update( 3 )['changed'] );
		self::assertSame( 0, $GLOBALS['registered_field_test_writes'] );
		$written = $this->update( 4 );
		self::assertTrue( $written['changed'] );
		self::assertSame( 4, $written['field']['value'] );
		self::assertSame( 1, $GLOBALS['registered_field_test_writes'] );
		self::assertSame( 'stale_field_state', $this->update( 5, $state )['error'] );
	}

	public function test_sanitized_type_and_readonly_fields_block_writes(): void {
		$GLOBALS['registered_field_test_sanitizer']                                  = static fn (): string => 'bad';
		self::assertSame( 'invalid_field_value', $this->update( 4 )['error'] );
		$GLOBALS['registered_field_test_sanitizer']                                  = null;
		$GLOBALS['registered_field_test_registry']['post']['rating']['show_in_rest'] = array( 'schema' => array( 'readonly' => true ) );
		self::assertSame( 'field_not_writable', $this->update( 4 )['error'] );
		self::assertSame( 0, $GLOBALS['registered_field_test_writes'] );
	}

	public function test_false_no_write_and_ambiguous_after_write_outcomes(): void {
		$GLOBALS['registered_field_test_write_mode'] = 'fail';
		self::assertSame( 'field_update_failed', $this->update( 4 )['error'] );
		foreach ( array( 'wrong', 'throw_after', 'inserted' ) as $mode ) {
			$GLOBALS['registered_field_test_rows']['rating'] = array( '3' );
			$GLOBALS['registered_field_test_write_mode']     = $mode;
			$result = $this->update( 4 );
			self::assertSame( 'partial_write', $result['error'] );
			self::assertTrue( $result['terminal'] );
		}
	}

	public function test_string_bounds_slashes_and_native_sanitization(): void {
		$GLOBALS['registered_field_test_registry']['post']['rating'] = $this->registration( 'string' );
		$GLOBALS['registered_field_test_rows']['rating']             = array( 'hello' );
		foreach ( array( str_repeat( 'x', 4001 ), "\xFF", 'a:0:{}' ) as $bad ) {
			self::assertSame( 'invalid_field_value', $this->update( $bad )['error'] );
		}
		$value = 'Editor\'s \\ literal';
		self::assertSame( $value, $this->update( $value )['field']['value'] );
		$GLOBALS['registered_field_test_sanitizer']                                  = static fn ( string $text ): string => trim( $text );
		self::assertSame( 'trimmed', $this->update( ' trimmed ' )['field']['value'] );
		$GLOBALS['registered_field_test_registry']['post']['rating']['show_in_rest'] = array( 'schema' => array( 'maxLength' => 7 ) );
		self::assertSame( 'invalid_field_value', $this->update( 'too long' )['error'] );
	}

	public function test_number_updates_remain_finite_and_schema_override_changes_type(): void {
		$GLOBALS['registered_field_test_registry']['post']['rating']['show_in_rest'] = array( 'schema' => array( 'type' => 'number' ) );
		self::assertSame( 3.0, $this->read()['value'] );
		foreach ( array( INF, -INF, NAN ) as $bad ) {
			self::assertSame( 'invalid_field_value', $this->update( $bad )['error'] );
		}
		self::assertSame( 4.5, $this->update( 4.5 )['field']['value'] );
	}

	public function test_actual_gateway_requires_confirmation_and_replays_once_for_trusted_actor(): void {
		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_transients']      = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Field Editor',
				'user_login'   => 'field_editor',
			),
		);
		$auth    = array(
			'user_id'                  => 1,
			'client_id'                => 'registered-field-gateway-test',
			'provider'                 => 'chatgpt',
			'scopes'                   => array( 'content:read', 'content:draft' ),
			'profile'                  => 'full_access',
			'access_level'             => ConnectionAccessLevel::WRITE,
			'write_permission_enabled' => true,
		);
		$gateway = new AbilityExecutionGateway( null, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
		$args    = array(
			'post_id'         => 10,
			'key'             => 'rating',
			'value'           => 4,
			'expected_state'  => $this->read()['expected_state'],
			'idempotency_key' => 'field-gateway-once',
		);
		$call    = static fn ( array $arguments ): array => $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'content_fields_update_field',
					'arguments' => $arguments,
				),
				$auth
			)
		)->data['result'];
		$preview = $call( $args );
		self::assertSame( 'confirmation_required', $preview['status'] );
		self::assertSame( 0, $GLOBALS['registered_field_test_writes'] );
		$args['confirmation_token'] = $preview['confirmation_token'];
		$written                    = $call( $args );
		self::assertTrue( $written['success'] );
		self::assertSame( 4, $written['field']['value'] );
		self::assertSame( 1, $GLOBALS['registered_field_test_writes'] );
		$replayed = $call( $args );
		self::assertTrue( $replayed['replayed'] );
		self::assertSame( 1, $GLOBALS['registered_field_test_writes'] );
		unset( $args['confirmation_token'] );
		$args['idempotency_key'] = 'field-gateway-stale';
		$args['value']           = 5;
		self::assertSame( 'stale_field_state', $call( $args )['error'] );
		self::assertSame( 1, $GLOBALS['registered_field_test_writes'] );
	}

	/**
	 * Create one scalar native registration.
	 *
	 * @param string $type Native type.
	 * @return array<string, mixed>
	 */
	private function registration( string $type ): array {
		return array(
			'single'       => true,
			'type'         => $type,
			'show_in_rest' => true,
		);
	}

	/**
	 * Read the fixture field.
	 *
	 * @return array<string, mixed>
	 */
	private function read(): array {
		return ( new RegisteredFieldAbilities() )->read_field(
			array(
				'post_id' => 10,
				'key'     => 'rating',
			)
		);
	}

	/**
	 * Update the fixture field using its current or supplied state.
	 *
	 * @param mixed       $value New value.
	 * @param string|null $state Expected state.
	 * @return array<string, mixed>
	 */
	private function update( mixed $value, ?string $state = null ): array {
		return ( new RegisteredFieldAbilities() )->update_field(
			array(
				'post_id'        => 10,
				'key'            => 'rating',
				'value'          => $value,
				'expected_state' => $state ?? $this->read()['expected_state'],
			)
		);
	}
}

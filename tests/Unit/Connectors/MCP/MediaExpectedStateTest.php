<?php
/**
 * Optional stale-state protection without breaking legacy media clients.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpInputValidator;
use Aculect\AICompanion\Connectors\MCP\MediaAbilities;
use Aculect\AICompanion\Connectors\MCP\MediaExpectedState;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class MediaExpectedStateTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 3 ) . '/fixtures/media-expected-state-stubs.php';
		$GLOBALS['aculect_ai_companion_test_posts']               = array(
			81 => new \WP_Post(
				array(
					'ID'          => 81,
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'post_title'  => 'Synthetic image',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_post_meta']           = array(
			81 => array(
				'_wp_attachment_image_alt' => 'Synthetic alt',
				'_wp_attached_file'        => 'synthetic/image.jpg',
				'_wp_attachment_metadata'  => array( 'width' => 400 ),
			),
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
	}

	public function test_token_is_private_bounded_and_changes_with_metadata(): void {
		$state = new MediaExpectedState();
		$token = $state->token( get_post( 81 ) );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $token );
		self::assertSame( $token, ( new MediaAbilities() )->get_media( 81 )['expected_state'] );
		self::assertNull( $state->error( get_post( 81 ), array( 'expected_state' => $token ) ) );
		$GLOBALS['aculect_ai_companion_test_post_meta'][81]['_wp_attachment_image_alt'] = 'New synthetic alt';
		self::assertNotSame( $token, $state->token( get_post( 81 ) ) );
		self::assertSame( 'stale_media_state', $state->error( get_post( 81 ), array( 'expected_state' => $token ) )['error'] );
		self::assertNull( $state->error( get_post( 81 ), array() ) );
		$GLOBALS['aculect_ai_companion_test_post_meta'][81]['_wp_attachment_metadata'] = new \stdClass();
		self::assertNull( $state->token( get_post( 81 ) ) );
		self::assertSame( 'stale_media_state', $state->error( get_post( 81 ), array( 'expected_state' => $token ) )['error'] );
	}

	public function test_oversized_or_invalid_native_metadata_has_no_state_token(): void {
		$state = new MediaExpectedState();
		foreach ( array( array_fill( 0, 2049, 'x' ), array( str_repeat( 'x', 600000 ), str_repeat( 'y', 600000 ) ), array( "\xff" ) ) as $metadata ) {
			$GLOBALS['aculect_ai_companion_test_post_meta'][81]['_wp_attachment_metadata'] = array( 'sizes' => $metadata );
			self::assertNull( $state->token( get_post( 81 ) ) );
		}
	}

	public function test_existing_writers_reject_stale_state_before_any_mutation(): void {
		$token                     = ( new MediaExpectedState() )->token( get_post( 81 ) );
		get_post( 81 )->post_title = 'Native concurrent title';
		$service                   = new MediaAbilities();
		foreach ( array( 'update_media', 'delete_media', 'rename_media_file' ) as $method ) {
			$result = $service->$method(
				array(
					'id'             => 81,
					'title'          => 'Must not save',
					'filename'       => 'must-not-rename.jpg',
					'expected_state' => $token,
				)
			);
			self::assertSame( 'stale_media_state', $result['error'], $method );
		}
		self::assertSame( 'Native concurrent title', get_post( 81 )->post_title );
	}

	public function test_schemas_accept_legacy_and_fresh_preconditions_but_not_malformed_state(): void {
		$registry  = new AbilitiesRegistry();
		$validator = new McpInputValidator();
		foreach ( array( 'media.update_item', 'media.delete_item', 'media.rename_file' ) as $id ) {
			$schema = $registry->modules()[ $id ]->input_schema();
			$args   = 'media.rename_file' === $id ? array(
				'id'       => 81,
				'filename' => 'renamed.jpg',
			) : array( 'id' => 81 );
			self::assertNull( $validator->arguments_error( $args, $schema ) );
			self::assertNull( $validator->arguments_error( $args + array( 'expected_state' => str_repeat( 'a', 64 ) ), $schema ) );
			foreach ( array( null, array(), 'bad' ) as $value ) {
				self::assertNotNull( $validator->arguments_error( $args + array( 'expected_state' => $value ), $schema ) );
			}
			self::assertSame( 'stale_media_state', ( new MediaExpectedState() )->error( get_post( 81 ), array( 'expected_state' => str_repeat( 'g', 64 ) ) )['error'] );
		}
	}
}

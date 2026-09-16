<?php
/**
 * Prevent media trash from falling through to permanent deletion.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\MediaAbilities;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class SafeMediaTrashTest extends TestCase {

	protected function setUp(): void {
		require_once dirname( __DIR__, 3 ) . '/fixtures/safe-media-trash-stubs.php';
		$GLOBALS['safe_media_trash_calls']                        = 0;
		$GLOBALS['safe_media_trash_mode']                         = 'success';
		$GLOBALS['aculect_ai_companion_test_posts']               = array(
			91 => new \WP_Post(
				array(
					'ID'          => 91,
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
	}

	public function test_disabled_trash_never_calls_native_api_even_for_preview(): void {
		define( 'EMPTY_TRASH_DAYS', 0 );
		foreach ( array( false, true ) as $preview ) {
			self::assertSame(
				'media_trash_disabled',
				( new MediaAbilities() )->delete_media(
					array(
						'id'      => 91,
						'dry_run' => $preview,
					)
				)['error']
			);
		}
		self::assertSame( 0, $GLOBALS['safe_media_trash_calls'] );
		self::assertSame( 'inherit', get_post( 91 )->post_status );
	}

	public function test_enabled_trash_previews_then_verifies_native_persistence(): void {
		define( 'EMPTY_TRASH_DAYS', 30 );
		$service = new MediaAbilities();
		self::assertTrue(
			$service->delete_media(
				array(
					'id'      => 91,
					'dry_run' => true,
				)
			)['dry_run']
		);
		self::assertSame( 0, $GLOBALS['safe_media_trash_calls'] );
		self::assertSame( 'trash', $service->delete_media( array( 'id' => 91 ) )['status'] );
		self::assertSame( 'trash', get_post( 91 )->post_status );
		self::assertSame( 1, $GLOBALS['safe_media_trash_calls'] );
		self::assertFalse( $service->delete_media( array( 'id' => 91 ) )['changed'] );
		self::assertSame( 1, $GLOBALS['safe_media_trash_calls'] );
	}

	public function test_uncertain_native_results_are_terminal(): void {
		define( 'EMPTY_TRASH_DAYS', 30 );
		foreach ( array( 'throw', 'false', 'unchanged' ) as $mode ) {
			$GLOBALS['safe_media_trash_mode'] = $mode;
			$result                           = ( new MediaAbilities() )->delete_media( array( 'id' => 91 ) );
			self::assertSame( 'partial_write', $result['error'] );
			self::assertTrue( $result['terminal'] );
		}
	}

	public function test_invalid_targets_and_permissions_do_not_write(): void {
		define( 'EMPTY_TRASH_DAYS', 30 );
		$service = new MediaAbilities();
		foreach ( array( -91, '91', null, array( 91 ), 0 ) as $id ) {
			self::assertSame( 'not_found', $service->delete_media( array( 'id' => $id ) )['error'] );
		}
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'delete_post' );
		self::assertSame( 'forbidden', $service->delete_media( array( 'id' => 91 ) )['error'] );
		self::assertSame( 0, $GLOBALS['safe_media_trash_calls'] );
	}
}

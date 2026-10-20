<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AccessLockdown;
use Aculect\AICompanion\Connectors\MCP\McpNegotiatedResourceReader;
use Aculect\AICompanion\Connectors\MCP\UserAccessControl;
use PHPUnit\Framework\TestCase;

/**
 * Verify direct resource reads enforce current actor, scope and access pauses.
 */
final class ResourceAuthorizationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_users'][2] = (object) array( 'ID' => 2, 'roles' => array( 'subscriber' ) );
	}

	public function test_draft_only_token_cannot_read_base_context_through_resource_uri(): void {
		$reader = new McpNegotiatedResourceReader();
		foreach ( array( 'aculect://site/summary', 'aculect://site-editor/context', 'aculect://content/model', 'aculect://memory/approved' ) as $uri ) {
			$result = $reader->read( array( 'uri' => $uri ), false, '2025-03-26', array( 'user_id' => 2, 'scopes' => array( 'content:draft' ) ) );
			self::assertSame( 'insufficient_scope', $result['error'] ?? '', $uri );
			self::assertArrayNotHasKey( 'contents', $result );
		}
		self::assertSame( 1, get_current_user_id() );
	}

	public function test_pause_is_checked_again_on_every_resource_read_and_resume_recovers(): void {
		$reader = new McpNegotiatedResourceReader();
		$auth = array( 'user_id' => 2, 'scopes' => array( 'content:read' ) );
		$args = array( 'uri' => 'aculect://site/summary' );
		self::assertArrayHasKey( 'contents', $reader->read( $args, false, '2025-03-26', $auth ) );
		AccessLockdown::set_paused( true );
		self::assertSame( 'access_paused', $reader->read( $args, false, '2025-03-26', $auth )['error'] ?? '' );
		AccessLockdown::set_paused( false );
		UserAccessControl::set_paused( 2, true );
		self::assertSame( 'access_paused', $reader->read( $args, false, '2025-03-26', $auth )['error'] ?? '' );
		UserAccessControl::set_paused( 2, false );
		self::assertArrayHasKey( 'contents', $reader->read( $args, false, '2025-03-26', $auth ) );
		self::assertSame( 1, get_current_user_id() );
	}

	public function test_missing_authenticated_actor_cannot_inherit_ambient_admin(): void {
		$result = ( new McpNegotiatedResourceReader() )->read( array( 'uri' => 'aculect://site/summary' ), false, '2025-03-26', array( 'scopes' => array( 'content:read' ) ) );
		self::assertSame( 'forbidden', $result['error'] ?? '' );
		self::assertSame( 1, get_current_user_id() );
	}
}

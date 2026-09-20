<?php
/**
 * Tests for OAuth browser-session redirect hints.
 *
 * @package Aculect_AI_Companion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\AuthorizationBrowserSession;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/oauth-browser-session-stubs.php';

/**
 * Cookie hints must never grant a REST request an authenticated identity.
 */
final class AuthorizationBrowserSessionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 0;
		$GLOBALS['aculect_browser_cookie_result']             = false;
		$GLOBALS['aculect_browser_cookie_calls']              = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['aculect_browser_cookie_result'], $GLOBALS['aculect_browser_cookie_calls'] );
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 0;
		parent::tearDown();
	}

	public function test_authenticated_users_do_not_need_a_cookie_fallback(): void {
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;

		self::assertTrue( AuthorizationBrowserSession::is_logged_in( false ) );
		self::assertTrue( AuthorizationBrowserSession::is_logged_in( true ) );
		self::assertSame( array(), $GLOBALS['aculect_browser_cookie_calls'] );
		self::assertSame( 7, get_current_user_id() );
	}

	public function test_root_entry_preserves_wordpress_authentication_decision(): void {
		$GLOBALS['aculect_browser_cookie_result'] = 7;

		self::assertFalse( AuthorizationBrowserSession::is_logged_in( false ) );
		self::assertSame( array(), $GLOBALS['aculect_browser_cookie_calls'] );
		self::assertSame( 0, get_current_user_id() );
	}

	public function test_rest_entry_uses_a_valid_cookie_only_for_redirect_selection(): void {
		$GLOBALS['aculect_browser_cookie_result'] = 7;

		self::assertTrue( AuthorizationBrowserSession::is_logged_in( true ) );
		self::assertSame( array( array( '', 'logged_in' ) ), $GLOBALS['aculect_browser_cookie_calls'] );
		self::assertSame( 0, get_current_user_id() );
	}

	public function test_missing_expired_or_revoked_cookie_still_requires_login(): void {
		self::assertFalse( AuthorizationBrowserSession::is_logged_in( true ) );
		self::assertSame( array( array( '', 'logged_in' ) ), $GLOBALS['aculect_browser_cookie_calls'] );
		self::assertSame( 0, get_current_user_id() );
	}

	public function test_unexpected_cookie_validation_results_fail_closed(): void {
		foreach ( array( 0, -1, true, '7', array( 7 ), new \stdClass() ) as $result ) {
			$GLOBALS['aculect_browser_cookie_result'] = $result;

			self::assertFalse( AuthorizationBrowserSession::is_logged_in( true ) );
			self::assertSame( 0, get_current_user_id() );
		}
	}
}

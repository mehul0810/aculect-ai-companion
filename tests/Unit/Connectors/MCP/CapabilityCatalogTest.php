<?php
/**
 * Unified catalog contracts.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\CapabilityCatalog;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesRegistrar;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/wordpress-abilities-stubs.php';

/** Proves complete discovery and preservation of execution boundaries. */
final class CapabilityCatalogTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_wp_abilities']    = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 9;
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			9 => (object) array(
				'ID'    => 9,
				'roles' => array( 'administrator' ),
			),
		);
		McpToolAvailability::set_current_granted_scopes( array( 'content:read', 'content:draft' ) );
	}

	protected function tearDown(): void {
		McpToolAvailability::set_current_granted_scopes( null );
	}

	public function test_pagination_covers_every_available_direct_tool_and_enabled_native_ability_once(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();
		wp_register_ability(
			'example/read',
			array(
				'label'       => 'Read example',
				'description' => 'Read example data.',
				'meta'        => array( 'show_in_rest' => true ),
			)
		);
		wp_register_ability(
			'example/disabled',
			array(
				'label'       => 'Disabled example',
				'description' => 'Disabled example.',
				'meta'        => array( 'show_in_rest' => true ),
			)
		);
		( new WordPressAbilitiesPolicy() )->save_decisions( array( 'example/read' => true ) );
		$catalog = new CapabilityCatalog();
		$ids     = array();
		for ( $page = 1; $page <= 100; ++$page ) {
			$result = $catalog->discover(
				array(
					'page'     => $page,
					'per_page' => 7,
				)
			);
			self::assertLessThanOrEqual( 7, count( $result['items'] ) );
			$ids = array_merge( $ids, array_column( $result['items'], 'id' ) );
			if ( ! $result['has_more'] ) {
				break;
			}
		}
		$expected = count( ( new McpToolAvailability() )->tool_modules_for_user( 9, null, null, array( 'content:read', 'content:draft' ) ) ) + 1;
		self::assertCount( $expected, $ids );
		self::assertSame( $expected, $result['total'] );
		self::assertSame( $ids, array_values( array_unique( $ids ) ) );
		self::assertContains( 'intelligence.site.get_context', $ids );
		self::assertContains( 'example/read', $ids );
		self::assertNotContains( 'example/disabled', $ids );
		self::assertNotContains( 'aculect-ai-companion/intelligence-site-get-context', $ids );
	}

	public function test_read_scope_does_not_advertise_native_execution_as_available(): void {
		McpToolAvailability::set_current_granted_scopes( array( 'content:read' ) );
		wp_register_ability(
			'example/read',
			array(
				'label'       => 'External example',
				'description' => 'Read example data.',
				'meta'        => array( 'show_in_rest' => true ),
			)
		);
		( new WordPressAbilitiesPolicy() )->save_decisions( array( 'example/read' => true ) );
		$result = ( new CapabilityCatalog() )->discover( array( 'search' => 'example' ) );
		self::assertSame( 1, $result['total'] );
		self::assertSame( 'execution_route_unavailable', $result['items'][0]['availability'] );
		self::assertSame( array( 'content:draft' ), $result['items'][0]['requiredScopes'] );
		$all = ( new CapabilityCatalog() )->discover( array( 'per_page' => 100 ) );
		self::assertNotContains( 'content.create_item', array_column( $all['items'], 'id' ) );
	}
}

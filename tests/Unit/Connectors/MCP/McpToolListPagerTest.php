<?php
/**
 * Tests for deterministic MCP tools/list pagination.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpToolListPager;
use Aculect\AICompanion\Diagnostics\McpToolManifest;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/wordpress-abilities-stubs.php';

/**
 * Verifies the extracted pager preserves the complete public cursor contract.
 */
final class McpToolListPagerTest extends TestCase {

	private const FIXTURE_FINGERPRINT = '3ae7fb79cafc55342ad851ec84009725a5d152ee4c25149ec9e2c5310d5faf45';
	private const FIXTURE_CURSOR      = 'eyJ2IjoyLCJvIjo2MCwiZnAiOiIzYWU3ZmI3OWNhZmM1NTM0MmFkODUxZWM4NDAwOTcyNWE1ZDE1MmVlNGMyNTE0OWVjOWUyYzUzMTBkNWZhZjQ1In0=';

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']               = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']       = 1;
		$GLOBALS['aculect_ai_companion_test_users']                 = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
		$GLOBALS['aculect_ai_companion_test_wp_abilities']          = array();
		$GLOBALS['aculect_ai_companion_test_wp_ability_categories'] = array();
	}

	public function test_first_page_payload_and_v2_cursor_are_exact(): void {
		$tools  = $this->tools( 61 );
		$result = ( new McpToolListPager() )->page( $tools );

		self::assertSame( 60, McpToolListPager::page_size() );
		self::assertSame(
			array(
				'tools'      => array_slice( $tools, 0, 60 ),
				'_meta'      => array(
					'aculect/toolListFingerprint' => self::FIXTURE_FINGERPRINT,
					'aculect/toolListVersion'     => ACULECT_AI_COMPANION_VERSION,
					'aculect/totalTools'          => 61,
					'aculect/pageSize'            => 60,
					'aculect/pageOffset'          => 0,
					'aculect/pageToolCount'       => 60,
					'aculect/cursorValid'         => true,
					'aculect/nextCursorOffset'    => 60,
					'aculect/nextCursorVersioned' => true,
				),
				'nextCursor' => self::FIXTURE_CURSOR,
			),
			$result
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Verifies the exact public cursor envelope.
		$decoded_cursor = base64_decode( self::FIXTURE_CURSOR, true );
		self::assertSame(
			array(
				'v'  => 2,
				'o'  => 60,
				'fp' => self::FIXTURE_FINGERPRINT,
			),
			json_decode( (string) $decoded_cursor, true )
		);
	}

	public function test_v2_and_legacy_numeric_cursors_return_the_exact_same_page(): void {
		$tools = $this->tools( 61 );
		$pager = new McpToolListPager();

		$versioned = $pager->page( $tools, self::FIXTURE_CURSOR );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Existing legacy MCP cursor compatibility fixture.
		$legacy = $pager->page( $tools, base64_encode( '60' ) );
		$exact  = array(
			'tools' => array( $tools[60] ),
			'_meta' => array(
				'aculect/toolListFingerprint' => self::FIXTURE_FINGERPRINT,
				'aculect/toolListVersion'     => ACULECT_AI_COMPANION_VERSION,
				'aculect/totalTools'          => 61,
				'aculect/pageSize'            => 60,
				'aculect/pageOffset'          => 60,
				'aculect/pageToolCount'       => 1,
				'aculect/cursorValid'         => true,
			),
		);

		self::assertSame( $exact, $versioned );
		self::assertSame( $exact, $legacy );
	}

	public function test_invalid_and_stale_cursors_reset_to_the_first_page_without_changing_shape(): void {
		$tools   = $this->tools( 61 );
		$pager   = new McpToolListPager();
		$invalid = $pager->page( $tools, 'not-base64!' );

		self::assertSame( array_slice( $tools, 0, 60 ), $invalid['tools'] );
		self::assertFalse( $invalid['_meta']['aculect/cursorValid'] );
		self::assertSame( 0, $invalid['_meta']['aculect/pageOffset'] );
		self::assertSame( self::FIXTURE_CURSOR, $invalid['nextCursor'] );

		$changed                   = $tools;
		$changed[0]['description'] = 'Changed description';
		$stale                     = $pager->page( $changed, self::FIXTURE_CURSOR );
		$fresh                     = $pager->page( $changed );

		self::assertFalse( $stale['_meta']['aculect/cursorValid'] );
		self::assertSame( 0, $stale['_meta']['aculect/pageOffset'] );
		self::assertSame( $fresh['tools'], $stale['tools'] );
		self::assertSame( $fresh['nextCursor'], $stale['nextCursor'] );
		self::assertSame( $fresh['_meta']['aculect/toolListFingerprint'], $stale['_meta']['aculect/toolListFingerprint'] );
		self::assertNotSame( self::FIXTURE_FINGERPRINT, $stale['_meta']['aculect/toolListFingerprint'] );
	}

	public function test_boundary_sizes_page_every_tool_once_without_skips_or_duplicates(): void {
		$pager = new McpToolListPager();

		foreach ( array( 0, 1, 60, 61, 125 ) as $count ) {
			$tools     = $this->tools( $count );
			$collected = array();
			$cursor    = '';

			do {
				$page      = $pager->page( $tools, $cursor );
				$collected = array_merge( $collected, array_column( $page['tools'], 'name' ) );
				$cursor    = isset( $page['nextCursor'] ) ? (string) $page['nextCursor'] : '';
			} while ( '' !== $cursor );

			self::assertSame( array_column( $tools, 'name' ), $collected, 'Count ' . $count );
			self::assertSame( count( $collected ), count( array_unique( $collected ) ), 'Count ' . $count );
		}
	}

	public function test_decodable_malformed_and_tampered_cursors_fail_closed_or_preserve_legacy_behavior(): void {
		$pager = new McpToolListPager();
		$tools = $this->tools( 61 );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Existing legacy MCP cursor compatibility fixture.
		$malformed = $pager->page( $tools, base64_encode( '{not-json' ) );
		self::assertTrue( $malformed['_meta']['aculect/cursorValid'] );
		self::assertSame( 0, $malformed['_meta']['aculect/pageOffset'] );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Verifies a tampered versioned cursor fails closed.
		$tampered = $pager->page( $tools, base64_encode( '{"v":2,"o":60,"fp":"tampered"}' ) );
		self::assertFalse( $tampered['_meta']['aculect/cursorValid'] );
		self::assertSame( 0, $tampered['_meta']['aculect/pageOffset'] );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Existing legacy MCP cursor compatibility fixture.
		$negative = $pager->page( $tools, base64_encode( '-60' ) );
		self::assertTrue( $negative['_meta']['aculect/cursorValid'] );
		self::assertSame( 60, $negative['_meta']['aculect/pageOffset'] );
	}

	public function test_fingerprint_projection_ignores_non_public_fields_and_covers_every_public_field(): void {
		$pager    = new McpToolListPager();
		$tool     = $this->tools( 1 )[0];
		$baseline = $pager->page( array( $tool ) )['_meta']['aculect/toolListFingerprint'];

		$tool['ignored_runtime_value'] = 'changed';
		self::assertSame( $baseline, $pager->page( array( $tool ) )['_meta']['aculect/toolListFingerprint'] );

		foreach ( array( 'name', 'title', 'description', 'inputSchema', 'outputSchema', 'annotations', 'securitySchemes', '_meta' ) as $field ) {
			$changed           = $tool;
			$changed[ $field ] = is_string( $tool[ $field ] ) ? $tool[ $field ] . '-changed' : array( 'changed' => true );

			self::assertNotSame(
				$baseline,
				$pager->page( array( $changed ) )['_meta']['aculect/toolListFingerprint'],
				$field
			);
		}
	}

	public function test_controller_wrappers_and_diagnostics_preserve_exact_pager_contract(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array_keys( $registry->configurable_definitions() ) );

		$controller = new McpController();
		$scopes     = array( 'content:read', 'content:draft' );
		$manifest   = $controller->tool_manifest_for_user( 1, $scopes );
		$expected   = ( new McpToolListPager() )->page( $manifest['tools'] );
		$actual     = $controller->tools_list_page_for_user( 1, $scopes );

		self::assertSame( McpToolListPager::page_size(), McpController::tools_page_size() );
		self::assertSame( wp_json_encode( $expected ), wp_json_encode( $actual ) );
		self::assertSame(
			wp_json_encode( ( new McpToolListPager() )->page( $manifest['tools'], (string) $expected['nextCursor'] ) ),
			wp_json_encode( $controller->tools_list_page_for_user( 1, $scopes, (string) $actual['nextCursor'] ) )
		);

		$export     = ( new McpToolManifest() )->export_for_current_user( array( 'scopes' => $scopes ) );
		$tool_count = count( $manifest['tools'] );

		self::assertSame( wp_json_encode( $manifest ), wp_json_encode( $export['tools_list_payload'] ) );
		self::assertSame(
			array(
				'mode'            => 'cursor',
				'page_size'       => McpToolListPager::page_size(),
				'total_tools'     => $tool_count,
				'estimated_pages' => (int) ceil( $tool_count / McpToolListPager::page_size() ),
				'paginated'       => $tool_count > McpToolListPager::page_size(),
				'export_shape'    => 'aggregated_all_pages',
			),
			$export['tools_list_pagination']
		);
	}

	/**
	 * Build deterministic tool descriptors with one intentionally ignored field.
	 *
	 * @param int $count Number of descriptors to build.
	 * @return list<array<string, mixed>>
	 */
	private function tools( int $count ): array {
		$tools = array();

		for ( $index = 0; $index < $count; ++$index ) {
			$security = array(
				array(
					'type'   => 'oauth2',
					'scopes' => array( 'content:read' ),
				),
			);
			$tools[]  = array(
				'name'                  => sprintf( 'tool_%02d', $index ),
				'title'                 => 'Tool ' . $index,
				'description'           => 'Description ' . $index,
				'inputSchema'           => array(
					'type'       => 'object',
					'properties' => array(
						'value' => array( 'type' => 'integer' ),
					),
				),
				'outputSchema'          => array( 'type' => 'object' ),
				'annotations'           => array( 'readOnlyHint' => 0 === $index % 2 ),
				'securitySchemes'       => $security,
				'_meta'                 => array( 'securitySchemes' => $security ),
				'ignored_runtime_value' => 'private-' . $index,
			);
		}

		return $tools;
	}
}

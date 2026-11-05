<?php
/**
 * Tests for MCP activity learning insights.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ActivityLearningInsights;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- This focused test installs a wpdb double.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The wpdb double is local to this test.

/**
 * Verifies sanitized activity errors produce bounded learning suggestions.
 */
final class ActivityLearningInsightsTest extends TestCase {

	private mixed $previous_wpdb;
	private mixed $previous_user_id;
	private mixed $previous_denied_caps;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$this->previous_wpdb                                  = $wpdb ?? null;
		$this->previous_user_id                               = $GLOBALS['aculect_ai_companion_test_current_user_id'] ?? null;
		$this->previous_denied_caps                           = $GLOBALS['aculect_ai_companion_test_denied_caps'] ?? null;
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$wpdb = new ActivityLearningInsightsTestWpdb(
			array(
				array(
					'id'          => 1,
					'created_at'  => '2026-06-17 10:00:00',
					'provider'    => 'chatgpt',
					'client_id'   => '',
					'client_name' => '',
					'user_id'     => 7,
					'action'      => 'content_search.items',
					'target_type' => null,
					'target_id'   => null,
					'status'      => 'error',
					'error_code'  => 'tool_disabled',
					'message'     => 'This ability is disabled in Aculect AI Companion settings.',
					'context'     => '{}',
				),
				array(
					'id'          => 2,
					'created_at'  => '2026-06-17 10:01:00',
					'provider'    => 'claude',
					'client_id'   => '',
					'client_name' => '',
					'user_id'     => 9,
					'action'      => 'content.update_item',
					'target_type' => null,
					'target_id'   => null,
					'status'      => 'error',
					'error_code'  => 'insufficient_scope',
					'message'     => 'The connection token does not include every required OAuth scope.',
					'context'     => '{}',
				),
			)
		);
	}

	protected function tearDown(): void {
		global $wpdb;

		$wpdb = $this->previous_wpdb;
		if ( null === $this->previous_user_id ) {
			unset( $GLOBALS['aculect_ai_companion_test_current_user_id'] );
		} else {
			$GLOBALS['aculect_ai_companion_test_current_user_id'] = $this->previous_user_id;
		}
		if ( null === $this->previous_denied_caps ) {
			unset( $GLOBALS['aculect_ai_companion_test_denied_caps'] );
		} else {
			$GLOBALS['aculect_ai_companion_test_denied_caps'] = $this->previous_denied_caps;
		}

		parent::tearDown();
	}

	public function test_activity_errors_return_learning_suggestions(): void {
		$result = ( new ActivityLearningInsights() )->inspect( array( 'per_page' => 25 ) );

		self::assertSame( 'success', $result['status'] );
		self::assertCount( 2, $result['items'] );
		self::assertNotEmpty( $result['insights'] );

		$insights_by_type = array_column( $result['insights'], null, 'type' );
		self::assertArrayHasKey( 'ability_routing', $insights_by_type );
		self::assertArrayHasKey( 'oauth_scope', $insights_by_type );
		self::assertSame( 'developer', $insights_by_type['ability_routing']['learning_domain'] );
		self::assertStringContainsString( 'workflow_route_request', $insights_by_type['ability_routing']['suggested_update'] );
	}

	public function test_read_level_user_sees_only_own_activity(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );

		$result = ( new ActivityLearningInsights() )->inspect( array( 'per_page' => 25 ) );

		self::assertSame( 'success', $result['status'] );
		self::assertCount( 1, $result['items'] );
		self::assertSame( 'chatgpt', $result['items'][0]['provider'] );
		self::assertSame( 7, $result['filters']['user_id'] );
	}

	public function test_no_user_cannot_read_activity_directly(): void {
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 0;
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array( 'manage_options' );

		$result = ( new ActivityLearningInsights() )->inspect( array() );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'forbidden', $result['error'] );
		self::assertSame( array(), $result['items'] );
	}
}

/**
 * Minimal wpdb double for ActivityRepository::list().
 */
final class ActivityLearningInsightsTestWpdb {

	public string $prefix       = 'wp_';
	private array $prepare_args = array();

	/**
	 * Construct the wpdb test double.
	 *
	 * @param list<array<string, mixed>> $rows Rows to return.
	 */
	public function __construct( private array $rows ) {}

	/**
	 * Preserve the prepared query and its arguments for filtering.
	 *
	 * @param string $query SQL query.
	 * @param mixed  ...$args Prepared arguments.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->prepare_args = $args;

		return $query;
	}

	/**
	 * Return matching synthetic activity rows.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output format.
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );
		$filter_position = strpos( $query, 'user_id = %d' );
		if ( false !== $filter_position ) {
			preg_match_all( '/%[isd]/', substr( $query, 0, $filter_position ), $placeholders );
			$user_id = (int) ( $this->prepare_args[ count( $placeholders[0] ) ] ?? 0 );
			return array_values( array_filter( $this->rows, static fn ( array $row ): bool => (int) ( $row['user_id'] ?? 0 ) === $user_id ) );
		}

		return $this->rows;
	}

	/**
	 * Escape a synthetic SQL LIKE value.
	 *
	 * @param string $text Value to escape.
	 */
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}
}

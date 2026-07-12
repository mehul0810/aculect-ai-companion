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

/**
 * Verifies sanitized activity errors produce bounded learning suggestions.
 */
final class ActivityLearningInsightsTest extends TestCase {

	private mixed $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;

		$this->previous_wpdb = $wpdb ?? null;
		$wpdb                = new ActivityLearningInsightsTestWpdb(
			array(
				array(
					'id'          => 1,
					'created_at'  => '2026-06-17 10:00:00',
					'provider'    => 'chatgpt',
					'client_id'   => '',
					'client_name' => '',
					'user_id'     => null,
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
					'user_id'     => null,
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
}

/**
 * Minimal wpdb double for ActivityRepository::list().
 */
final class ActivityLearningInsightsTestWpdb {

	public string $prefix = 'wp_';

	/**
	 * @param list<array<string, mixed>> $rows Rows to return.
	 */
	public function __construct( private array $rows ) {}

	public function prepare( string $query, mixed ...$args ): string {
		unset( $args );

		return $query;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $query, $output );

		return $this->rows;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}
}

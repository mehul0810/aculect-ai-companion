<?php
/**
 * Tests for connected AI activity repository normalization.
 *
 * @package Aculect\AICompanion\Tests\Unit\Activity
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Activity;

use Aculect\AICompanion\Activity\ActivityRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies activity entries are normalized before storage.
 */
final class ActivityRepositoryTest extends TestCase {

	public function test_prepare_entry_normalizes_status_action_and_context(): void {
		$prepared = $this->invokePrivate(
			new ActivityRepository(),
			'prepare_entry',
			array(
				array(
					'provider'    => 'ChatGPT',
					'client_id'   => 'client-123',
					'client_name' => 'ChatGPT Connector',
					'user_id'     => '7',
					'action'      => 'content.update_item',
					'target_type' => 'post',
					'target_id'   => '42',
					'status'      => 'bad-status',
					'error_code'  => '<script>',
					'message'     => "Updated\npost",
					'context'     => array(
						'argument_keys'  => array( 'title', 'content' ),
						'client_secret'  => 'do-not-store',
						'authorization'  => 'Bearer secret',
						'safe_indicator' => 'kept',
					),
				),
			)
		);

		$context = json_decode( $prepared['data']['context'], true );

		self::assertSame( 'chatgpt', $prepared['data']['provider'] );
		self::assertSame( 'content.update_item', $prepared['data']['action'] );
		self::assertSame( 'success', $prepared['data']['status'] );
		self::assertSame( 7, $prepared['data']['user_id'] );
		self::assertSame( 42, $prepared['data']['target_id'] );
		self::assertSame( 'Updated post', $prepared['data']['message'] );
		self::assertSame( 'kept', $context['safe_indicator'] );
		self::assertArrayNotHasKey( 'client_secret', $context );
		self::assertArrayNotHasKey( 'authorization', $context );
	}

	public function test_where_clause_filters_by_recent_activity_range(): void {
		$where = $this->invokePrivate(
			new ActivityRepository(),
			'where_clause',
			array(
				array(
					'range' => '24h',
				),
			)
		);

		self::assertStringContainsString( 'created_at >= %s', $where['sql'] );
		self::assertCount( 1, $where['values'] );
	}

	public function test_where_clause_filters_by_sanitized_activity_search(): void {
		global $wpdb;

		$previous_wpdb = $wpdb;
		$wpdb          = new class() {
			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}
		};

		try {
			$where = $this->invokePrivate(
				new ActivityRepository(),
				'where_clause',
				array(
					array(
						'search' => ' title <script> ',
					),
				)
			);
		} finally {
			$wpdb = $previous_wpdb;
		}

		self::assertStringContainsString( 'message LIKE %s', $where['sql'] );
		self::assertCount( 7, $where['values'] );
		self::assertSame( '%title%', $where['values'][0] );
	}

	/**
	 * Invoke a private method for focused unit coverage.
	 *
	 * @param object $object    Object instance.
	 * @param string $method    Method name.
	 * @param array  $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}
}

<?php
/**
 * Activity settings payload builder tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\SettingsActivityPayloadBuilder;
use Aculect\AICompanion\Tests\Support\FakeSettingsActivityPayloadWpdb;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/FakeSettingsActivityPayloadWpdb.php';

/**
 * Verifies the extracted Activity payload remains bounded and compatible.
 */
final class SettingsActivityPayloadBuilderTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Scoped repository fixture restored in tearDown().
		$GLOBALS['wpdb'] = new FakeSettingsActivityPayloadWpdb();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the original test global.
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
	}

	public function test_empty_payload_preserves_the_compatibility_shape(): void {
		self::assertSame(
			array(
				'summary'    => array(),
				'items'      => array(),
				'total'      => 0,
				'page'       => 1,
				'perPage'    => 50,
				'totalPages' => 1,
				'filters'    => array(
					'page'      => 1,
					'action'    => '',
					'status'    => '',
					'user_id'   => 0,
					'assistant' => '',
					'search'    => '',
					'range'     => '7d',
				),
				'prevUrl'    => '',
				'nextUrl'    => '',
			),
			SettingsActivityPayloadBuilder::empty_payload()
		);
	}

	public function test_build_preserves_filter_pagination_and_url_contracts(): void {
		$payload = ( new SettingsActivityPayloadBuilder() )->build(
			array(
				'activity_page'      => '999',
				'activity_action'    => ' content.update_item ',
				'activity_status'    => 'SUCCESS',
				'activity_user'      => '7',
				'activity_assistant' => ' ChatGPT ',
				'activity_search'    => ' Draft page ',
				'activity_range'     => '30D',
			),
			'https://example.com/wp-admin/options-general.php?page=aculect-ai-companion'
		);

		self::assertSame( 120, $payload['total'] );
		self::assertSame( 3, $payload['page'] );
		self::assertSame( 50, $payload['perPage'] );
		self::assertSame( 3, $payload['totalPages'] );
		self::assertSame( 'content.update_item', $payload['filters']['action'] );
		self::assertSame( 'success', $payload['filters']['status'] );
		self::assertSame( 7, $payload['filters']['user_id'] );
		self::assertSame( 'ChatGPT', $payload['filters']['assistant'] );
		self::assertSame( 'Draft page', $payload['filters']['search'] );
		self::assertSame( '30d', $payload['filters']['range'] );
		self::assertStringContainsString( 'activity_page=2', $payload['prevUrl'] );
		self::assertStringContainsString( 'activity_status=success', $payload['prevUrl'] );
		self::assertSame( '', $payload['nextUrl'] );
		self::assertSame( 'content.update_item', $payload['items'][0]['action'] );
		self::assertSame( 120, $payload['summary']['total'] );
	}

	public function test_invalid_oversized_and_non_scalar_filters_fail_closed(): void {
		$long    = str_repeat( 'a', 1000 );
		$payload = ( new SettingsActivityPayloadBuilder() )->build(
			array(
				'activity_page'      => array( '2' ),
				'activity_action'    => $long,
				'activity_status'    => array( 'success' ),
				'activity_user'      => new \stdClass(),
				'activity_assistant' => $long,
				'activity_search'    => array( 'query' ),
				'activity_range'     => 'unsupported',
			),
			'https://example.com/wp-admin/options-general.php?page=aculect-ai-companion'
		);

		self::assertSame( 1, $payload['filters']['page'] );
		self::assertSame( '', $payload['filters']['status'] );
		self::assertSame( 0, $payload['filters']['user_id'] );
		self::assertSame( '', $payload['filters']['search'] );
		self::assertSame( '7d', $payload['filters']['range'] );
		self::assertSame( 100, strlen( $payload['filters']['action'] ) );
		self::assertSame( 100, strlen( $payload['filters']['assistant'] ) );
	}

	public function test_unicode_filters_are_character_bounded_and_urls_remain_valid(): void {
		$boundary = str_repeat( 'a', 99 ) . '😀tail';
		$payload  = ( new SettingsActivityPayloadBuilder() )->build(
			array(
				'activity_action'    => $boundary,
				'activity_assistant' => 'Claude 😀 assistant',
				'activity_search'    => 'Résumé 中文 query',
			),
			'https://example.com/wp-admin/options-general.php?page=aculect-ai-companion'
		);

		self::assertSame( str_repeat( 'a', 99 ) . '😀', $payload['filters']['action'] );
		self::assertSame( 'Claude 😀 assistant', $payload['filters']['assistant'] );
		self::assertSame( 'Résumé 中文 query', $payload['filters']['search'] );
		self::assertSame( 1, preg_match( '//u', rawurldecode( $payload['nextUrl'] ) ) );
		self::assertStringContainsString( rawurlencode( '😀' ), $payload['nextUrl'] );
	}

	public function test_invalid_utf8_text_filters_fail_closed(): void {
		$payload = ( new SettingsActivityPayloadBuilder() )->build(
			array(
				'activity_action'    => "invalid\xF0\x28\x8C\x28",
				'activity_assistant' => "invalid\xC3\x28",
				'activity_search'    => "invalid\xA0\xA1",
			),
			'https://example.com/wp-admin/options-general.php?page=aculect-ai-companion'
		);

		self::assertSame( '', $payload['filters']['action'] );
		self::assertSame( '', $payload['filters']['assistant'] );
		self::assertSame( '', $payload['filters']['search'] );
		self::assertSame( 1, preg_match( '//u', $payload['nextUrl'] ) );
	}
}

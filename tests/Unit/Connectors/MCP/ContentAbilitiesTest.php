<?php
/**
 * Tests for content ability helpers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ContentAbilities;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies content date handling stays deterministic before WordPress writes.
 */
final class ContentAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']     = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		$GLOBALS['aculect_ai_companion_test_posts']       = array(
			123 => new \WP_Post(
				array(
					'ID'           => 123,
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_title'   => 'Existing Draft',
					'post_content' => '<!-- wp:paragraph --><p>Existing content.</p><!-- /wp:paragraph -->',
					'post_excerpt' => 'Existing excerpt',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_post_meta']   = array();

		$this->registerTestBlocks();
	}

	public function test_post_date_payload_accepts_site_local_iso_date(): void {
		$payload = $this->postDatePayload( '2026-06-01T09:30:00' );

		self::assertSame( '2026-06-01 09:30:00', $payload['post_date'] );
		self::assertSame( '2026-06-01 09:30:00', $payload['post_date_gmt'] );
	}

	public function test_post_date_payload_accepts_timezone_offset_date(): void {
		$payload = $this->postDatePayload( '2026-06-01T09:30:00+05:30' );

		self::assertSame( '2026-06-01 04:00:00', $payload['post_date'] );
		self::assertSame( '2026-06-01 04:00:00', $payload['post_date_gmt'] );
	}

	public function test_post_date_payload_uses_site_timezone_for_offset_date(): void {
		update_option( 'timezone_string', 'Asia/Kolkata' );

		$payload = $this->postDatePayload( '2026-06-01T09:30:00+05:30' );

		self::assertSame( '2026-06-01 09:30:00', $payload['post_date'] );
		self::assertSame( '2026-06-01 04:00:00', $payload['post_date_gmt'] );
	}

	public function test_post_date_payload_uses_site_timezone_for_local_date(): void {
		update_option( 'timezone_string', 'Asia/Kolkata' );

		$payload = $this->postDatePayload( '2026-06-01T09:30:00' );

		self::assertSame( '2026-06-01 09:30:00', $payload['post_date'] );
		self::assertSame( '2026-06-01 04:00:00', $payload['post_date_gmt'] );
	}

	public function test_post_date_payload_rejects_invalid_dates(): void {
		$payload = $this->postDatePayload( '2026-02-31T09:30:00' );

		self::assertSame( 'invalid_date', $payload['error']['error'] );
	}

	public function test_post_date_payload_rejects_empty_dates(): void {
		$payload = $this->postDatePayload( '' );

		self::assertSame( 'invalid_date', $payload['error']['error'] );
	}

	public function test_writable_status_supports_future_and_rejects_invalid_values(): void {
		self::assertSame( 'future', $this->writableStatus( 'future' ) );
		self::assertSame( '', $this->writableStatus( 'scheduled' ) );
	}

	public function test_create_item_rejects_future_status_without_date_before_preview(): void {
		$result = ( new ContentAbilities() )->create_item(
			array(
				'title'   => 'Scheduled draft',
				'content' => '<!-- wp:paragraph --><p>Safe block content.</p><!-- /wp:paragraph -->',
				'status'  => 'future',
				'dry_run' => true,
			)
		);

		self::assertSame( 'invalid_schedule_date', $result['error'] );
		self::assertSame( 'Scheduling requires a future date. Pass date with status future.', $result['message'] );
		self::assertArrayHasKey( 'site_timezone', $result );
		self::assertArrayHasKey( 'site_current_time', $result );
	}

	public function test_update_item_rejects_future_status_with_past_date_before_preview(): void {
		update_option( 'timezone_string', 'Asia/Kolkata' );

		$result = ( new ContentAbilities() )->update_item(
			array(
				'id'      => 123,
				'status'  => 'future',
				'date'    => '2000-06-01T09:30:00+05:30',
				'dry_run' => true,
			)
		);

		self::assertSame( 'invalid_schedule_date', $result['error'] );
		self::assertSame( 'Scheduled posts require date to be in the future relative to the WordPress site timezone.', $result['message'] );
		self::assertSame( 'Asia/Kolkata', $result['site_timezone'] );
		self::assertSame( '2000-06-01 04:00:00', $result['resolved_date_gmt'] );
	}

	public function test_update_item_accepts_future_status_with_future_offset_date(): void {
		update_option( 'timezone_string', 'Asia/Kolkata' );

		$result = ( new ContentAbilities() )->update_item(
			array(
				'id'      => 123,
				'status'  => 'future',
				'date'    => '2999-06-01T09:30:00+05:30',
				'dry_run' => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertTrue( $result['dry_run'] );
		self::assertContains( 'status', array_column( $result['changes'], 'field' ) );
		self::assertContains( 'date', array_column( $result['changes'], 'field' ) );
		self::assertContains( 'date_gmt', array_column( $result['changes'], 'field' ) );
	}

	public function test_create_item_rejects_custom_html_block_content_before_write(): void {
		$result = ( new ContentAbilities() )->create_item(
			array(
				'title'   => 'Unsafe draft',
				'content' => '<!-- wp:html --><div>Raw</div><!-- /wp:html -->',
			)
		);

		self::assertSame( 'invalid_block_content', $result['error'] );
		self::assertFalse( $result['block_validation']['valid'] );
		self::assertContains( 'Never use the Custom HTML block (core/html). Use registered semantic blocks or patterns instead.', $result['warnings'] );
	}

	public function test_update_item_rejects_custom_html_block_content_before_write(): void {
		$result = ( new ContentAbilities() )->update_item(
			array(
				'id'      => 123,
				'content' => '<!-- wp:html --><div>Raw</div><!-- /wp:html -->',
			)
		);

		self::assertSame( 'invalid_block_content', $result['error'] );
		self::assertFalse( $result['block_validation']['valid'] );
		self::assertContains( 'Never use the Custom HTML block (core/html). Use registered semantic blocks or patterns instead.', $result['warnings'] );
	}

	public function test_create_item_rejects_plain_raw_html_content(): void {
		$result = ( new ContentAbilities() )->create_item(
			array(
				'title'   => 'Raw HTML',
				'content' => '<p>Raw HTML should not be saved by atomic tools.</p>',
			)
		);

		self::assertSame( 'invalid_block_content', $result['error'] );
		self::assertSame( 'Use serialized WordPress block markup, not raw HTML or plain text.', $result['message'] );
	}

	public function test_create_item_rejects_unknown_block_content(): void {
		$result = ( new ContentAbilities() )->create_item(
			array(
				'title'   => 'Unknown block',
				'content' => '<!-- wp:missing/block --><p>Unknown</p><!-- /wp:missing/block -->',
			)
		);

		self::assertSame( 'invalid_block_content', $result['error'] );
		self::assertFalse( $result['block_validation']['valid'] );
		self::assertContains( 'Block missing/block is not registered on this site.', $result['warnings'] );
	}

	public function test_create_item_dry_run_accepts_valid_serialized_block_content(): void {
		$result = ( new ContentAbilities() )->create_item(
			array(
				'title'   => 'Safe draft',
				'content' => '<!-- wp:paragraph --><p>Safe block content.</p><!-- /wp:paragraph -->',
				'dry_run' => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'content.create_item', $result['action'] );
		self::assertContains( 'content', array_column( $result['changes'], 'field' ) );
	}

	public function test_update_item_dry_run_diff_includes_unchanged_requested_fields(): void {
		$result = ( new ContentAbilities() )->update_item(
			array(
				'id'      => 123,
				'title'   => 'Existing Draft',
				'excerpt' => 'Updated excerpt',
				'dry_run' => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );

		$diff_by_field = array_column( $result['diff']['fields'], null, 'field' );
		self::assertFalse( $diff_by_field['title']['changed'] );
		self::assertSame( 'Existing Draft', $diff_by_field['title']['before']['value'] );
		self::assertSame( 'Existing Draft', $diff_by_field['title']['after']['value'] );
		self::assertTrue( $diff_by_field['excerpt']['changed'] );
		self::assertSame( 'Updated excerpt', $diff_by_field['excerpt']['after']['value'] );
		self::assertNotContains( 'title', array_column( $result['changes'], 'field' ) );
		self::assertContains( 'excerpt', array_column( $result['changes'], 'field' ) );
	}

	public function test_update_item_dry_run_diff_summarizes_content_body(): void {
		$result = ( new ContentAbilities() )->update_item(
			array(
				'id'      => 123,
				'content' => '<!-- wp:paragraph --><p>Updated content body for review.</p><!-- /wp:paragraph -->',
				'dry_run' => true,
			)
		);

		$diff_by_field = array_column( $result['diff']['fields'], null, 'field' );
		self::assertTrue( $diff_by_field['content']['changed'] );
		self::assertIsArray( $diff_by_field['content']['before']['value'] );
		self::assertSame( 1, $diff_by_field['content']['before']['value']['block_count'] );
		self::assertSame( array( 'core/paragraph' ), $diff_by_field['content']['after']['value']['blocks'] );
		self::assertStringContainsString( 'Updated content body', $diff_by_field['content']['after']['value']['summary'] );
	}

	public function test_update_item_dry_run_redacts_previous_content_fields_without_read_access(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'read_post' );

		$result = ( new ContentAbilities() )->update_item(
			array(
				'id'      => 123,
				'title'   => 'Public proposed title',
				'dry_run' => true,
			)
		);

		$diff_by_field = array_column( $result['diff']['fields'], null, 'field' );
		self::assertFalse( $diff_by_field['title']['before']['available'] );
		self::assertSame( 'not_readable', $diff_by_field['title']['before']['reason'] );
		self::assertArrayNotHasKey( 'value', $diff_by_field['title']['before'] );
		self::assertNull( $diff_by_field['title']['changed'] );

		$changes_by_field = array_column( $result['changes'], null, 'field' );
		self::assertNull( $changes_by_field['title']['from'] );
		self::assertSame( 'Public proposed title', $changes_by_field['title']['to'] );
	}

	/**
	 * Invoke the private date payload helper for focused validation.
	 *
	 * @param string $date Date argument.
	 * @return array<string, mixed>
	 */
	private function postDatePayload( string $date ): array {
		$reflection = new ReflectionMethod( ContentAbilities::class, 'post_date_payload_from_data' );
		$reflection->setAccessible( true );

		return $reflection->invoke( new ContentAbilities(), array( 'date' => $date ) );
	}

	/**
	 * Invoke the protected status helper for focused validation.
	 *
	 * @param string $status Status argument.
	 */
	private function writableStatus( string $status ): string {
		$reflection = new ReflectionMethod( ContentAbilities::class, 'writable_status' );
		$reflection->setAccessible( true );

		return (string) $reflection->invoke( new ContentAbilities(), $status );
	}

	private function registerTestBlocks(): void {
		\WP_Block_Type_Registry::get_instance()->unregister_all();
		foreach ( array( 'core/paragraph', 'core/html' ) as $name ) {
			\WP_Block_Type_Registry::get_instance()->register(
				$name,
				array(
					'title'    => $name,
					'category' => 'text',
					'supports' => array(
						'inserter' => true,
					),
				)
			);
		}
	}
}

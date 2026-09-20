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
					'post_modified_gmt' => '2026-08-26 10:00:00',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_post_meta']   = array();

		$this->registerTestBlocks();
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();

		parent::tearDown();
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

	public function test_create_item_rejects_broken_registered_block_structure(): void {
		$result = ( new ContentAbilities() )->create_item(
			array(
				'title'   => 'Broken registered block',
				'content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Hello</p><!-- /wp:group --><!-- /wp:paragraph --></div>',
			)
		);

		self::assertSame( 'invalid_block_content', $result['error'] );
		self::assertFalse( $result['block_validation']['valid'] );
		self::assertFalse( $result['block_validation']['structure']['valid'] );
		self::assertSame( 'mismatched_closing_block', $result['block_validation']['structure']['issues'][0]['code'] );
		self::assertStringContainsString( 'valid serialized block structure', $result['message'] );
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

	public function test_update_item_rejects_stale_modified_token_before_preview_or_write(): void {
		$result = ( new ContentAbilities() )->update_item(
			array(
				'id'                    => 123,
				'title'                 => 'Stale update',
				'expected_modified_gmt' => '2026-08-26 09:00:00',
				'dry_run'               => true,
			)
		);

		self::assertSame( 'conflict', $result['error'] );
		self::assertSame( '2026-08-26 10:00:00', $result['current_modified'] );
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

	public function test_get_item_includes_deterministic_block_locators(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123]->post_content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading {"level":3} --><h3>Intro</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Nested copy.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';

		$result = ( new ContentAbilities() )->get_item( 123 );

		self::assertSame( array( 0 ), $result['block_locators'][0]['path'] );
		self::assertSame( 'core/group', $result['block_locators'][0]['block_name'] );
		self::assertSame( array( 0, 0 ), $result['block_locators'][1]['path'] );
		self::assertSame( 'core/heading', $result['block_locators'][1]['block_name'] );
		self::assertSame( 'Intro', $result['block_locators'][1]['text'] );
		self::assertSame( array( 0, 1 ), $result['block_locators'][2]['path'] );
	}

	public function test_update_block_dry_run_returns_field_level_diff_without_saving(): void {
		$result = ( new ContentAbilities() )->update_block(
			array(
				'id'      => 123,
				'locator' => array( 'path' => array( 0 ) ),
				'text'    => 'Updated content.',
				'dry_run' => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'content.update_block', $result['action'] );
		self::assertSame( 123, $result['post_id'] );
		self::assertSame( array( 0 ), $result['block_locator']['path'] );
		self::assertSame( array( 'block.text' ), $result['changed_fields'] );
		self::assertSame( 'Existing content.', $result['diff']['fields'][0]['before']['value'] );
		self::assertSame( 'Updated content.', $result['diff']['fields'][0]['after']['value'] );
		self::assertStringContainsString( 'Attribute writes are deferred', $result['warnings'][0] );
		self::assertStringContainsString( 'Existing content.', $GLOBALS['aculect_ai_companion_test_posts'][123]->post_content );
	}

	public function test_update_block_rejects_invalid_locator(): void {
		$result = ( new ContentAbilities() )->update_block(
			array(
				'id'      => 123,
				'locator' => array( 'path' => array( 3 ) ),
				'text'    => 'Updated content.',
			)
		);

		self::assertSame( 'invalid_block_locator', $result['error'] );
	}

	public function test_update_block_rejects_negative_locator_path_parts(): void {
		$result = ( new ContentAbilities() )->update_block(
			array(
				'id'      => 123,
				'locator' => array( 'path' => array( -1 ) ),
				'text'    => 'Updated content.',
			)
		);

		self::assertSame( 'invalid_block_locator', $result['error'] );
	}

	public function test_update_block_rejects_unsupported_type(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123]->post_content = '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->';

		$result = ( new ContentAbilities() )->update_block(
			array(
				'id'      => 123,
				'locator' => array( 'path' => array( 0 ) ),
				'text'    => 'Updated content.',
			)
		);

		self::assertSame( 'unsupported_block_type', $result['error'] );
	}

	public function test_update_block_rejects_attribute_writes_for_beta_slice(): void {
		$result = ( new ContentAbilities() )->update_block(
			array(
				'id'      => 123,
				'locator' => array( 'path' => array( 0 ) ),
				'attrs'   => array( 'placeholder' => 'Deferred' ),
			)
		);

		self::assertSame( 'unsupported_block_attribute_update', $result['error'] );
	}

	public function test_update_block_rejects_users_without_edit_post(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_post' );

		$result = ( new ContentAbilities() )->update_block(
			array(
				'id'      => 123,
				'locator' => array( 'path' => array( 0 ) ),
				'text'    => 'Updated content.',
			)
		);

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_update_block_writes_serialized_paragraph_content(): void {
		$result = ( new ContentAbilities() )->update_block(
			array(
				'id'      => 123,
				'locator' => array( 'path' => array( 0 ) ),
				'text'    => 'Updated content.',
			)
		);

		self::assertSame( 'updated', $result['status'] );
		self::assertSame( 'content.update_block', $result['action'] );
		self::assertSame( array( 'block.text' ), $result['changed_fields'] );
		self::assertStringContainsString( '<p>Updated content.</p>', $GLOBALS['aculect_ai_companion_test_posts'][123]->post_content );
		self::assertStringNotContainsString( 'Existing content.', $GLOBALS['aculect_ai_companion_test_posts'][123]->post_content );
	}

	public function test_update_block_inserts_same_site_internal_link_into_targeted_paragraph(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123]->post_content = '<!-- wp:paragraph --><p>Existing content mentions Target Post once.</p><!-- /wp:paragraph -->';

		$result = ( new ContentAbilities() )->update_block(
			array(
				'id'            => 123,
				'locator'       => array( 'path' => array( 0 ) ),
				'internal_link' => array(
					'anchor_text' => 'Target Post',
					'url'         => 'https://example.com/?p=456',
				),
			)
		);

		self::assertSame( 'updated', $result['status'] );
		self::assertStringContainsString( '<a href="https://example.com/?p=456">Target Post</a>', $GLOBALS['aculect_ai_companion_test_posts'][123]->post_content );
		self::assertStringContainsString( '<!-- wp:paragraph -->', $GLOBALS['aculect_ai_companion_test_posts'][123]->post_content );
	}

	public function test_update_block_rejects_external_internal_link_url(): void {
		$result = ( new ContentAbilities() )->update_block(
			array(
				'id'            => 123,
				'locator'       => array( 'path' => array( 0 ) ),
				'internal_link' => array(
					'anchor_text' => 'Existing content',
					'url'         => 'https://evil.example/path',
				),
			)
		);

		self::assertSame( 'invalid_internal_link', $result['error'] );
	}

	public function test_update_block_rejects_internal_link_when_anchor_is_missing(): void {
		$result = ( new ContentAbilities() )->update_block(
			array(
				'id'            => 123,
				'locator'       => array( 'path' => array( 0 ) ),
				'internal_link' => array(
					'anchor_text' => 'Missing Anchor',
					'url'         => 'https://example.com/?p=456',
				),
			)
		);

		self::assertSame( 'internal_link_anchor_not_found', $result['error'] );
		self::assertStringNotContainsString( '<a ', $GLOBALS['aculect_ai_companion_test_posts'][123]->post_content );
	}

	public function test_update_block_writes_nested_heading_content(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123]->post_content = '<!-- wp:group --><div class="wp-block-group"><!-- wp:heading {"level":3} --><h3>Intro</h3><!-- /wp:heading --></div><!-- /wp:group -->';

		$result = ( new ContentAbilities() )->update_block(
			array(
				'id'      => 123,
				'locator' => array( 'path' => array( 0, 0 ) ),
				'text'    => 'Updated intro',
			)
		);

		self::assertSame( 'updated', $result['status'] );
		self::assertStringContainsString( '<h3>Updated intro</h3>', $GLOBALS['aculect_ai_companion_test_posts'][123]->post_content );
		self::assertStringNotContainsString( '<h3>Intro</h3>', $GLOBALS['aculect_ai_companion_test_posts'][123]->post_content );
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
		foreach ( array( 'core/paragraph', 'core/heading', 'core/group', 'core/html' ) as $name ) {
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

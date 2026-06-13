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

		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$GLOBALS['aculect_ai_companion_test_posts']   = array(
			123 => new \WP_Post(
				array(
					'ID'           => 123,
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_title'   => 'Existing Draft',
					'post_content' => '<!-- wp:paragraph --><p>Existing content.</p><!-- /wp:paragraph -->',
				)
			),
		);

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

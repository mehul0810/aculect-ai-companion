<?php
/**
 * Tests for MCP intelligence index abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\IntelligenceIndexAbilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies provider-facing intelligence index responses stay aligned with public tool names.
 */
final class IntelligenceIndexAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_posts']       = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
	}

	public function test_memory_save_dry_run_uses_registered_internal_action(): void {
		$result = ( new IntelligenceIndexAbilities() )->save_memory(
			array(
				'key'     => 'brand.voice.primary',
				'value'   => 'Use a concise, expert tone.',
				'dry_run' => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'memory.save', $result['action'] );
		self::assertSame( 'update', $result['risk_level'] );
		self::assertTrue( $result['confirmation_required'] );
		self::assertSame( 'status', $result['changes'][1]['field'] );
		self::assertSame( 'pending', $result['changes'][1]['to'] );
		self::assertStringContainsString( 'admin review', $result['warnings'][0] );
	}

	public function test_canonical_search_returns_empty_results_without_query(): void {
		$result = ( new IntelligenceIndexAbilities() )->canonical_search( array( 'query' => '' ) );

		self::assertSame( array(), $result['results'] );
	}

	public function test_canonical_fetch_returns_readable_post_document(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123] = new \WP_Post(
			array(
				'ID'           => 123,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Canonical Retrieval',
				'post_content' => '<!-- wp:paragraph --><p>Hello <strong>world</strong>.</p><!-- /wp:paragraph -->',
				'post_name'    => 'canonical-retrieval',
			)
		);

		$result = ( new IntelligenceIndexAbilities() )->canonical_fetch( array( 'id' => 'wp-post:123' ) );

		self::assertSame( 'wp-post:123', $result['id'] );
		self::assertSame( 'Canonical Retrieval', $result['title'] );
		self::assertSame( 'Hello world.', $result['text'] );
		self::assertSame( 'https://example.com/?p=123', $result['url'] );
		self::assertSame( 123, $result['metadata']['post_id'] );
		self::assertSame( 'post', $result['metadata']['post_type'] );
		self::assertSame( 'publish', $result['metadata']['status'] );

		$plain_id_result = ( new IntelligenceIndexAbilities() )->canonical_fetch( array( 'id' => '123' ) );
		self::assertSame( $result['id'], $plain_id_result['id'] );
	}

	public function test_canonical_fetch_respects_read_post_permission(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123]  = new \WP_Post(
			array(
				'ID'           => 123,
				'post_title'   => 'Private Post',
				'post_content' => 'Secret',
			)
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'read_post' );

		$result = ( new IntelligenceIndexAbilities() )->canonical_fetch( array( 'id' => 'wp-post:123' ) );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'forbidden', $result['error'] );
	}
}

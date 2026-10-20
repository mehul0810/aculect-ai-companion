<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ContentAbilities;
use Aculect\AICompanion\Connectors\MCP\IntelligenceIndexAbilities;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/site-workflow-stubs.php';

/**
 * Verify native content privacy and publication capabilities before projection or writes.
 */
final class ContentAuthorizationTest extends TestCase {

	private array $original_globals = array();

	protected function setUp(): void {
		foreach ( array( 'posts', 'options', 'denied_caps', 'capability_callback', 'post_meta' ) as $key ) {
			$name                            = 'aculect_ai_companion_test_' . $key;
			$this->original_globals[ $name ] = $GLOBALS[ $name ] ?? null;
			$GLOBALS[ $name ]                = array();
		}
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn( string $cap ): bool => 'edit_post' !== $cap;
		$protected = new class( array( 'ID' => 123, 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => 'Protected body', 'post_excerpt' => 'Protected excerpt' ) ) extends \WP_Post {
			public string $post_password = 'synthetic-password';
		};
		$GLOBALS['aculect_ai_companion_test_posts'] = array(
			123 => $protected,
			124 => new \WP_Post( array( 'ID' => 124, 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => 'Public body' ) ),
		);
	}

	protected function tearDown(): void {
		foreach ( $this->original_globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
	}

	public function test_password_protection_covers_get_locators_and_both_collection_projections(): void {
		$abilities = new ContentAbilities();
		self::assertSame( array(), $abilities->get_item( 123 ) );
		foreach ( array( 'compact', 'full' ) as $context ) {
			$result = $abilities->list_items( array( 'context' => $context ) );
			self::assertSame( array( 124 ), array_column( $result['items'], 'id' ) );
			self::assertStringNotContainsString( 'Protected', (string) wp_json_encode( $result['items'] ) );
		}
		self::assertSame( 'Public body', $abilities->get_item( 124 )['content'] );
	}

	public function test_password_protection_covers_canonical_fetch_and_live_search(): void {
		$abilities = new IntelligenceIndexAbilities();
		self::assertSame( 'forbidden', $abilities->canonical_fetch( array( 'id' => '123' ) )['error'] );
		$result = $abilities->canonical_search( array( 'query' => 'Protected' ) );
		self::assertStringNotContainsString( 'Protected body', (string) wp_json_encode( $result ) );
		self::assertStringNotContainsString( 'Protected excerpt', (string) wp_json_encode( $result ) );
	}

	public function test_editor_can_read_protected_content_but_edit_capability_does_not_replace_read(): void {
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn(): bool => true;
		self::assertSame( 'Protected body', ( new ContentAbilities() )->get_item( 123 )['content'] );
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn( string $cap ): bool => 'read_post' !== $cap;
		self::assertSame( array(), ( new ContentAbilities() )->get_item( 123 ) );
	}

	public function test_private_creation_and_transition_require_publish_capability_before_dry_run(): void {
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn( string $cap ): bool => 'publish_posts' !== $cap;
		$abilities = new ContentAbilities();
		self::assertSame( 'forbidden', $abilities->create_item( array( 'title' => 'Synthetic', 'status' => 'private', 'dry_run' => true ) )['error'] ?? '' );
		self::assertSame( 'forbidden', $abilities->update_item( array( 'id' => 124, 'status' => 'private', 'dry_run' => true ) )['error'] ?? '' );
		self::assertTrue( $abilities->create_item( array( 'title' => 'Synthetic', 'status' => 'draft', 'dry_run' => true ) )['dry_run'] ?? false );
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn(): bool => true;
		self::assertTrue( $abilities->create_item( array( 'title' => 'Synthetic', 'status' => 'private', 'dry_run' => true ) )['dry_run'] ?? false );
	}
	public function test_an_editable_private_post_can_keep_its_existing_private_status(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][124]->post_status = 'private';
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn( string $cap ): bool => 'publish_posts' !== $cap;
		$result = ( new ContentAbilities() )->update_item( array( 'id' => 124, 'status' => 'private', 'title' => 'Private edit', 'dry_run' => true ) );
		self::assertTrue( $result['dry_run'] ?? false );
		self::assertArrayNotHasKey( 'error', $result );
	}

}

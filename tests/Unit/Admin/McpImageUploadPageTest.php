<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\McpImageUploadPage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/McpImageUploadFunctions.php';

final class McpImageUploadPageTest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = tempnam( sys_get_temp_dir(), 'aculect-image-' );
		self::assertIsString( $this->tmp );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Fixed 1x1 PNG test fixture in a disposable PHP temp file.
		file_put_contents( $this->tmp, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lV8AAAAASUVORK5CYII=', true ) );
		$GLOBALS['aculect_image_upload_tmp']         = $this->tmp;
		$GLOBALS['aculect_image_upload_media_calls'] = array();
		unset( $GLOBALS['aculect_image_upload_limit'], $GLOBALS['aculect_image_upload_policy_mime'], $GLOBALS['aculect_image_upload_editor'], $GLOBALS['aculect_image_upload_media_result'] );
		$GLOBALS['aculect_ai_companion_test_capability_callback']                                       = static fn ( string $capability ): bool => 'upload_files' === $capability;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_apps_enabled'] = static fn (): bool => true;
	}

	protected function tearDown(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Delete only the tempnam fixture created by this test.
		unlink( $this->tmp );
		foreach ( array( 'aculect_image_upload_tmp', 'aculect_image_upload_media_calls', 'aculect_image_upload_limit', 'aculect_image_upload_policy_mime', 'aculect_image_upload_editor', 'aculect_image_upload_media_result', 'aculect_ai_companion_test_capability_callback' ) as $key ) {
			unset( $GLOBALS[ $key ] );
		}
		unset( $GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_apps_enabled'] );
		parent::tearDown();
	}

	/**
	 * Make a PHP upload fixture.
	 *
	 * @param string $name Original filename.
	 * @return array<string, mixed>
	 */
	private function file( string $name = 'picture.png' ): array {
		return array(
			'name'     => $name,
			'tmp_name' => $this->tmp,
			'size'     => filesize( $this->tmp ),
			'error'    => UPLOAD_ERR_OK,
		);
	}

	/**
	 * Make the upload form nonce.
	 *
	 * @return array<string, string>
	 */
	private function nonce(): array {
		return array( '_wpnonce' => wp_create_nonce( 'aculect_mcp_image_upload' ) );
	}

	public function test_registers_upload_capability_media_submenu(): void {
		$page = new McpImageUploadPage();
		$page->register_page();
		$registered = end( $GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'] );
		self::assertSame( 'upload.php', $registered['parent_slug'] );
		self::assertSame( 'upload_files', $registered['capability'] );
		self::assertSame( McpImageUploadPage::PAGE_SLUG, $registered['menu_slug'] );
	}

	public function test_disabled_apps_has_no_menu_and_cannot_upload_even_with_a_valid_nonce(): void {
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_apps_enabled'] = static fn (): bool => false;
		$page   = new McpImageUploadPage();
		$before = count( $GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'] ?? array() );
		$page->register_page();
		self::assertCount( $before, $GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'] ?? array() );
		self::assertSame( 'forbidden', $page->process_upload( $this->nonce(), $this->file() )['status'] );
		self::assertSame( array(), $GLOBALS['aculect_image_upload_media_calls'] );
	}

	public function test_success_returns_attachment_id_without_performing_follow_on_action(): void {
		$result = ( new McpImageUploadPage() )->process_upload( $this->nonce(), $this->file() );
		self::assertSame(
			array(
				'status'        => 'uploaded',
				'attachment_id' => 314,
			),
			$result
		);
		self::assertSame( array( array( 'aculect_image', 0 ) ), $GLOBALS['aculect_image_upload_media_calls'] );
	}

	public function test_denied_capability_and_invalid_nonce_never_reach_media_api(): void {
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn (): bool => false;
		self::assertSame( 'forbidden', ( new McpImageUploadPage() )->process_upload( $this->nonce(), $this->file() )['status'] );
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn (): bool => true;
		self::assertSame( 'invalid_nonce', ( new McpImageUploadPage() )->process_upload( array( '_wpnonce' => 'wrong' ), $this->file() )['status'] );
		self::assertSame( array(), $GLOBALS['aculect_image_upload_media_calls'] );
	}

	public function test_rejects_missing_failed_forged_and_oversized_uploads(): void {
		$page = new McpImageUploadPage();
		self::assertSame( 'invalid_file', $page->process_upload( $this->nonce(), null )['status'] );
		$file          = $this->file();
		$file['error'] = UPLOAD_ERR_PARTIAL;
		self::assertSame( 'upload_failed', $page->process_upload( $this->nonce(), $file )['status'] );
		$file             = $this->file();
		$file['tmp_name'] = '/tmp/not-an-upload.png';
		self::assertSame( 'invalid_file', $page->process_upload( $this->nonce(), $file )['status'] );
		$GLOBALS['aculect_image_upload_limit'] = 1;
		self::assertSame( 'invalid_size', $page->process_upload( $this->nonce(), $this->file() )['status'] );
		self::assertSame( array(), $GLOBALS['aculect_image_upload_media_calls'] );
	}

	public function test_rejects_wrong_extension_signature_and_site_policy(): void {
		$page = new McpImageUploadPage();
		self::assertSame( 'invalid_image', $page->process_upload( $this->nonce(), $this->file( 'picture.svg' ) )['status'] );
		self::assertSame( 'invalid_image', $page->process_upload( $this->nonce(), $this->file( 'picture.jpg' ) )['status'] );
		$GLOBALS['aculect_image_upload_policy_mime'] = false;
		self::assertSame( 'invalid_image', $page->process_upload( $this->nonce(), $this->file() )['status'] );
		self::assertSame( array(), $GLOBALS['aculect_image_upload_media_calls'] );
	}

	public function test_rejects_invalid_signature_and_excessive_dimensions_before_core_upload(): void {
		$page = new McpImageUploadPage();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Mutate only this disposable upload fixture.
		file_put_contents( $this->tmp, 'not an image' );
		self::assertSame( 'invalid_image', $page->process_upload( $this->nonce(), $this->file() )['status'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Fixed PNG test fixture with an oversized IHDR width.
		file_put_contents( $this->tmp, substr_replace( base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lV8AAAAASUVORK5CYII=', true ), pack( 'N', 4097 ), 16, 4 ) );
		self::assertSame( 'invalid_image', $page->process_upload( $this->nonce(), $this->file() )['status'] );
		self::assertSame( array(), $GLOBALS['aculect_image_upload_media_calls'] );
	}

	public function test_render_explains_the_id_without_claiming_a_follow_on_action(): void {
		$previous_method = $_SERVER['REQUEST_METHOD'] ?? null;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Save and restore test superglobal, not application input.
		$previous_post = $_POST;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Save and restore test superglobal, not application input.
		$previous_files = $_FILES;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $this->nonce();
		$_FILES                    = array( 'aculect_image' => $this->file() );
		ob_start();
		try {
			( new McpImageUploadPage() )->render();
			$html = ob_get_clean();
		} finally {
			if ( null === $previous_method ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $previous_method;
			}
			$_POST  = $previous_post;
			$_FILES = $previous_files;
		}
		self::assertIsString( $html );
		self::assertStringContainsString( 'Attachment ID:', $html );
		self::assertStringContainsString( '<code>314</code>', $html );
		self::assertStringContainsString( 'A later action must check your permission', $html );
		self::assertStringNotContainsString( $this->tmp, $html );
	}

	public function test_rejects_invalid_decode_and_reports_core_failure_without_claiming_success(): void {
		$GLOBALS['aculect_image_upload_editor'] = new \WP_Error( 'decode_failed' );
		$page                                   = new McpImageUploadPage();
		self::assertSame( 'invalid_image', $page->process_upload( $this->nonce(), $this->file() )['status'] );
		$GLOBALS['aculect_image_upload_editor']       = new \stdClass();
		$GLOBALS['aculect_image_upload_media_result'] = new \WP_Error( 'upload_failed' );
		self::assertSame( 'upload_failed', $page->process_upload( $this->nonce(), $this->file() )['status'] );
	}
}

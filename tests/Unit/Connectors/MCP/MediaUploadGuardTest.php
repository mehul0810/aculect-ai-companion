<?php
/**
 * Tests for assistant media upload guardrails.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\MediaUploadGuard;
use PHPUnit\Framework\TestCase;

/**
 * Verifies deterministic preflight validation for MCP media sideloads.
 */
final class MediaUploadGuardTest extends TestCase {

	private MediaUploadGuard $guard;

	/**
	 * Allowed MIME types used by tests.
	 *
	 * @var array<string, string>
	 */
	private array $allowed_mimes;

	protected function setUp(): void {
		parent::setUp();

		$this->guard         = new MediaUploadGuard();
		$this->allowed_mimes = array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'pdf'      => 'application/pdf',
		);
	}

	public function test_rejects_oversized_preflight_content_length(): void {
		$error = $this->guard->validate_preflight_headers(
			array(
				'content-length' => '2049',
				'content-type'   => 'image/png',
			),
			'image.png',
			2048,
			$this->allowed_mimes
		);

		self::assertSame( 'file_too_large', $error['code'] ?? '' );
	}

	public function test_rejects_disallowed_preflight_mime_type(): void {
		$error = $this->guard->validate_preflight_headers(
			array(
				'content-length' => '1024',
				'content-type'   => 'application/x-msdownload',
			),
			'image.png',
			2048,
			$this->allowed_mimes
		);

		self::assertSame( 'unsupported_media_type', $error['code'] ?? '' );
	}

	public function test_missing_headers_allow_capped_download_fallback(): void {
		self::assertNull(
			$this->guard->validate_preflight_headers(
				array(),
				'image.png',
				2048,
				$this->allowed_mimes
			)
		);
	}

	public function test_allows_supported_preflight_headers(): void {
		self::assertNull(
			$this->guard->validate_preflight_headers(
				array(
					'content-length' => '1024',
					'content-type'   => 'image/png; charset=binary',
				),
				'image.png',
				2048,
				$this->allowed_mimes
			)
		);
	}

	public function test_generic_content_type_requires_allowed_extension(): void {
		self::assertNull(
			$this->guard->validate_preflight_headers(
				array(
					'content-type' => 'application/octet-stream',
				),
				'image.png',
				2048,
				$this->allowed_mimes
			)
		);

		$error = $this->guard->validate_preflight_headers(
			array(
				'content-type' => 'application/octet-stream',
			),
			'image.exe',
			2048,
			$this->allowed_mimes
		);

		self::assertSame( 'unsupported_media_type', $error['code'] ?? '' );
	}
}

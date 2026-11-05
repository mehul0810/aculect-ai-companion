<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\WebMCP;

use Aculect\AICompanion\WebMCP\WebMcpRequestPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Covers public-only WebMCP request policy boundaries.
 */
final class WebMcpRequestPolicyTest extends TestCase {

	public function test_only_public_published_contexts_are_allowed(): void {
		$policy  = new WebMcpRequestPolicy();
		$context = array(
			'admin'           => false,
			'json'            => false,
			'feed'            => false,
			'password'        => false,
			'logged_in'       => false,
			'preview'         => false,
			'singular_status' => 'publish',
		);

		self::assertTrue( $policy->allows( $context ) );
		foreach ( array( 'admin', 'json', 'feed', 'password', 'logged_in', 'preview' ) as $protected_context ) {
			self::assertFalse(
				$policy->allows( array_merge( $context, array( $protected_context => true ) ) ),
				'Expected ' . $protected_context . ' requests to be denied.'
			);
		}

		foreach ( array( 'private', 'draft' ) as $status ) {
			self::assertFalse( $policy->allows( array_merge( $context, array( 'singular_status' => $status ) ) ) );
		}
	}

	public function test_non_singular_public_pages_remain_allowed(): void {
		$policy  = new WebMcpRequestPolicy();
		$context = array(
			'admin'           => false,
			'json'            => false,
			'feed'            => false,
			'password'        => false,
			'logged_in'       => false,
			'preview'         => false,
			'singular_status' => null,
		);

		self::assertTrue( $policy->allows( $context ) );
	}
}

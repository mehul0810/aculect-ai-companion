<?php
/**
 * Tests for internal-link policy defaults and sanitization.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\InternalLinkPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Verifies internal-linking guardrails stay bounded and deterministic.
 */
final class InternalLinkPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	public function test_active_policy_returns_plug_and_play_defaults(): void {
		$policy = ( new InternalLinkPolicy() )->active();

		self::assertSame( array( 'post', 'page' ), $policy['included_post_types'] );
		self::assertSame( array( 'publish' ), $policy['included_statuses'] );
		self::assertSame( 10, $policy['limits']['max_suggestions_per_source'] );
		self::assertSame( 3, $policy['limits']['max_new_links_per_source'] );
		self::assertSame( 1, $policy['limits']['max_repeated_target_links'] );
		self::assertTrue( $policy['prevent_self_links'] );
		self::assertFalse( $policy['mutation_policy']['content_mutation'] );
	}

	public function test_active_policy_sanitizes_option_payload_and_bounds_limits(): void {
		update_option(
			InternalLinkPolicy::OPTION,
			array(
				'included_post_types'   => array( 'Post', 'product', '<bad>' ),
				'included_statuses'     => array( 'publish', 'draft', 'bad status' ),
				'excluded_post_ids'     => array( -5, '17', 'not-id', 17 ),
				'excluded_url_patterns' => array( 'https://example.com/private/*', '<script>bad</script>' ),
				'limits'                => array(
					'max_suggestions_per_source' => 999,
					'max_new_links_per_source'   => 0,
					'max_repeated_target_links'  => 99,
				),
				'prevent_self_links'    => 'false',
			),
			false
		);

		$policy = ( new InternalLinkPolicy() )->active();

		self::assertSame( array( 'post', 'product', 'bad' ), $policy['included_post_types'] );
		self::assertSame( array( 'publish', 'draft', 'badstatus' ), $policy['included_statuses'] );
		self::assertSame( array( 5, 17 ), $policy['excluded_post_ids'] );
		self::assertSame( array( 'https://example.com/private/*', 'bad' ), $policy['excluded_url_patterns'] );
		self::assertSame( 20, $policy['limits']['max_suggestions_per_source'] );
		self::assertSame( 1, $policy['limits']['max_new_links_per_source'] );
		self::assertSame( 10, $policy['limits']['max_repeated_target_links'] );
		self::assertFalse( $policy['prevent_self_links'] );
	}

	public function test_filter_candidates_removes_excluded_targets_urls_statuses_and_duplicates(): void {
		update_option(
			InternalLinkPolicy::OPTION,
			array(
				'included_post_types'   => array( 'page' ),
				'included_statuses'     => array( 'publish' ),
				'excluded_post_ids'     => array( 44 ),
				'excluded_url_patterns' => array( 'https://example.com/private/*' ),
				'limits'                => array(
					'max_suggestions_per_source' => 2,
					'max_repeated_target_links'  => 1,
				),
			),
			false
		);

		$items = ( new InternalLinkPolicy() )->filter_candidates(
			11,
			array( 'id' => 11 ),
			array(
				array(
					'id'        => 11,
					'type'      => 'page',
					'status'    => 'publish',
					'title'     => 'Self Link',
					'permalink' => 'https://example.com/self/',
				),
				array(
					'id'        => 44,
					'type'      => 'page',
					'status'    => 'publish',
					'title'     => 'Excluded ID',
					'permalink' => 'https://example.com/excluded/',
				),
				array(
					'id'        => 55,
					'type'      => 'page',
					'status'    => 'draft',
					'title'     => 'Draft Target',
					'permalink' => 'https://example.com/draft/',
				),
				array(
					'id'        => 66,
					'type'      => 'page',
					'status'    => 'publish',
					'title'     => 'Private Target',
					'permalink' => 'https://example.com/private/target/',
				),
				array(
					'id'        => 77,
					'type'      => 'page',
					'status'    => 'publish',
					'title'     => 'Allowed Anchor',
					'permalink' => 'https://example.com/allowed/',
				),
				array(
					'id'        => 88,
					'type'      => 'page',
					'status'    => 'publish',
					'title'     => 'Allowed Anchor',
					'permalink' => 'https://example.com/duplicate-anchor/',
				),
				array(
					'id'        => 99,
					'type'      => 'page',
					'status'    => 'publish',
					'title'     => 'Second Allowed',
					'permalink' => 'https://example.com/second/',
				),
			)
		);

		self::assertSame( array( 77, 99 ), array_column( $items, 'id' ) );
	}
}

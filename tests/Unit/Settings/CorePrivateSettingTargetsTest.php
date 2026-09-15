<?php
/**
 * Core private-setting target allowlist and strict validation tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Settings
 */

declare(strict_types=1);
namespace Aculect\AICompanion\Tests\Unit\Settings;

use Aculect\AICompanion\Settings\CorePrivateSettingTargets;
use Aculect\AICompanion\Settings\PrivateSettingTargets;
use PHPUnit\Framework\TestCase;

final class CorePrivateSettingTargetsTest extends TestCase {
	private CorePrivateSettingTargets $targets;

	protected function setUp(): void {
		$this->targets = new CorePrivateSettingTargets();
	}

	public function test_target_specs_are_a_fixed_private_form_allowlist(): void {
		$targets = $this->targets->all();
		self::assertSame(
			array(
				'start_of_week',
				'posts_per_rss',
				'rss_use_excerpt',
				'default_comment_status',
				'default_ping_status',
				'comment_moderation',
				'thread_comments',
				'thread_comments_depth',
				'comments_per_page',
				'thumbnail_size_w',
				'thumbnail_size_h',
				'medium_size_w',
				'medium_size_h',
				'large_size_w',
				'large_size_h',
				'thumbnail_crop',
			),
			array_keys( $targets )
		);

		foreach ( $targets as $id => $target ) {
			self::assertSame( $id, $target['option'] );
			self::assertFalse( $target['secret'] );
			self::assertNotSame( '', $target['label'] );
			self::assertNotSame( '', $target['group'] );
		}
	}

	public function test_integer_targets_accept_only_canonical_values_in_range(): void {
		$ranges       = array(
			'start_of_week'         => array( 0, 6 ),
			'posts_per_rss'         => array( 1, 100 ),
			'rss_use_excerpt'       => array( 0, 1 ),
			'comment_moderation'    => array( 0, 1 ),
			'thread_comments'       => array( 0, 1 ),
			'thread_comments_depth' => array( 2, 10 ),
			'comments_per_page'     => array( 1, 100 ),
			'thumbnail_size_w'      => array( 0, 4096 ),
			'thumbnail_size_h'      => array( 0, 4096 ),
			'medium_size_w'         => array( 0, 4096 ),
			'medium_size_h'         => array( 0, 4096 ),
			'large_size_w'          => array( 0, 4096 ),
			'large_size_h'          => array( 0, 4096 ),
			'thumbnail_crop'        => array( 0, 1 ),
		);
		$noncanonical = array( '', ' 1', '1 ', "\t1", "1\n", '+1', '-1', '00', '01', '1.0', '1e2' );

		foreach ( $ranges as $id => $range ) {
			self::assertSame( $range[0], $this->targets->validate( $id, (string) $range[0] ) );
			self::assertSame( $range[1], $this->targets->validate( $id, (string) $range[1] ) );
			self::assertNull( $this->targets->validate( $id, (string) ( $range[0] - 1 ) ) );
			self::assertNull( $this->targets->validate( $id, (string) ( $range[1] + 1 ) ) );

			foreach ( $noncanonical as $value ) {
				self::assertNull( $this->targets->validate( $id, $value ), $id . ' accepted a noncanonical integer.' );
			}
		}
	}

	public function test_status_targets_accept_only_open_or_closed(): void {
		foreach ( array( 'default_comment_status', 'default_ping_status' ) as $id ) {
			self::assertSame( 'open', $this->targets->validate( $id, 'open' ) );
			self::assertSame( 'closed', $this->targets->validate( $id, 'closed' ) );
			foreach ( array( '', 'OPEN', '0', 'closed ', 'true' ) as $value ) {
				self::assertNull( $this->targets->validate( $id, $value ) );
			}
		}
	}

	public function test_media_labels_state_bounds_and_no_regeneration(): void {
		$targets = $this->targets->all();
		foreach ( array( 'thumbnail_size_w', 'thumbnail_size_h', 'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h' ) as $id ) {
			self::assertStringContainsString( '0–4096 px', $targets[ $id ]['label'] );
			self::assertStringContainsString( 'affects new uploads only', $targets[ $id ]['label'] );
			self::assertStringContainsString( 'existing images are not regenerated', $targets[ $id ]['label'] );
		}
		self::assertStringContainsString( '0=off, 1=on', $targets['thumbnail_crop']['label'] );
		self::assertStringContainsString( 'affects new uploads only', $targets['thumbnail_crop']['label'] );
		self::assertStringContainsString( 'existing images are not regenerated', $targets['thumbnail_crop']['label'] );
	}

	public function test_private_setting_targets_integrates_specs_without_opening_arbitrary_options(): void {
		$privateTargets = new PrivateSettingTargets();
		foreach ( $this->targets->all() as $id => $target ) {
			self::assertSame( $target, $privateTargets->get( $id ) );
			self::assertTrue( $privateTargets->available( $id ) );
		}

		self::assertSame( 0, $privateTargets->validate( 'start_of_week', '0' ) );
		self::assertSame( 'closed', $privateTargets->validate( 'default_comment_status', 'closed' ) );
		self::assertNull( $privateTargets->get( 'admin_email' ) );
		self::assertNull( $privateTargets->validate( 'admin_email', 'owner@example.test' ) );
	}
}

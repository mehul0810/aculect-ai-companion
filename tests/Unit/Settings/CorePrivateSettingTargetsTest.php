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
				'date_format',
				'time_format',
				'posts_per_rss',
				'rss_use_excerpt',
				'default_comment_status',
				'default_ping_status',
				'comment_moderation',
				'thread_comments',
				'thread_comments_depth',
				'comments_per_page',
				'page_comments',
				'default_comments_page',
				'comment_order',
				'close_comments_for_old_posts',
				'close_comments_days_old',
				'comment_max_links',
				'show_avatars',
				'thumbnail_size_w',
				'thumbnail_size_h',
				'medium_size_w',
				'medium_size_h',
				'large_size_w',
				'large_size_h',
				'thumbnail_crop',
				'uploads_use_yearmonth_folders',
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
			'start_of_week'                 => array( 0, 6 ),
			'posts_per_rss'                 => array( 1, 100 ),
			'rss_use_excerpt'               => array( 0, 1 ),
			'comment_moderation'            => array( 0, 1 ),
			'thread_comments'               => array( 0, 1 ),
			'thread_comments_depth'         => array( 2, 10 ),
			'comments_per_page'             => array( 1, 100 ),
			'page_comments'                 => array( 0, 1 ),
			'close_comments_for_old_posts'  => array( 0, 1 ),
			'close_comments_days_old'       => array( 1, 3650 ),
			'comment_max_links'             => array( 0, 100 ),
			'show_avatars'                  => array( 0, 1 ),
			'thumbnail_size_w'              => array( 0, 4096 ),
			'thumbnail_size_h'              => array( 0, 4096 ),
			'medium_size_w'                 => array( 0, 4096 ),
			'medium_size_h'                 => array( 0, 4096 ),
			'large_size_w'                  => array( 0, 4096 ),
			'large_size_h'                  => array( 0, 4096 ),
			'thumbnail_crop'                => array( 0, 1 ),
			'uploads_use_yearmonth_folders' => array( 0, 1 ),
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

	public function test_comment_order_and_date_time_formats_use_finite_canonical_choices(): void {
		$choices = array(
			'default_comments_page' => array( 'newest', 'oldest' ),
			'comment_order'         => array( 'asc', 'desc' ),
			'date_format'           => array( 'F j, Y', 'Y-m-d', 'm/d/Y', 'd/m/Y', 'd.m.Y' ),
			'time_format'           => array( 'g:i a', 'g:i A', 'H:i' ),
		);
		$invalid = array(
			'default_comments_page' => array( '', 'Newest', 'newest ', 'newer', '0' ),
			'comment_order'         => array( '', 'ASC', 'asc ', 'ascending', '0' ),
			'date_format'           => array( '', 'D, M j, Y', 'F j,Y', 'Y-n-j', 'm/d/y', 'Y-m-d H:i' ),
			'time_format'           => array( '', 'g:i a ', 'g:i A.m.', 'H:i:s', 'h:i A', 'g:i' ),
		);

		foreach ( $choices as $id => $values ) {
			foreach ( $values as $value ) {
				self::assertSame( $value, $this->targets->validate( $id, $value ) );
			}
			foreach ( $invalid[ $id ] as $value ) {
				self::assertNull( $this->targets->validate( $id, $value ), $id . ' accepted a noncanonical value.' );
			}
		}
	}

	public function test_new_setting_labels_disclose_finite_choices_and_upload_scope(): void {
		$targets = $this->targets->all();
		foreach ( array( 'page_comments', 'close_comments_for_old_posts', 'show_avatars', 'uploads_use_yearmonth_folders' ) as $id ) {
			self::assertStringContainsString( '0=off, 1=on', $targets[ $id ]['label'] );
		}
		self::assertStringContainsString( '1–3650 days', $targets['close_comments_days_old']['label'] );
		self::assertStringContainsString( '0–100', $targets['comment_max_links']['label'] );
		self::assertStringContainsString( 'newest or oldest', $targets['default_comments_page']['label'] );
		self::assertStringContainsString( 'asc or desc', $targets['comment_order']['label'] );
		self::assertStringContainsString( 'new uploads only', $targets['uploads_use_yearmonth_folders']['label'] );

		foreach ( array( 'F j, Y', 'Y-m-d', 'm/d/Y', 'd/m/Y', 'd.m.Y' ) as $format ) {
			self::assertStringContainsString( $format, $targets['date_format']['label'] );
		}
		foreach ( array( 'g:i a', 'g:i A', 'H:i' ) as $format ) {
			self::assertStringContainsString( $format, $targets['time_format']['label'] );
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
		self::assertSame( 1, $privateTargets->validate( 'page_comments', '1' ) );
		self::assertSame( 'oldest', $privateTargets->validate( 'default_comments_page', 'oldest' ) );
		self::assertSame( 'desc', $privateTargets->validate( 'comment_order', 'desc' ) );
		self::assertSame( 3650, $privateTargets->validate( 'close_comments_days_old', '3650' ) );
		self::assertSame( 0, $privateTargets->validate( 'comment_max_links', '0' ) );
		self::assertSame( 1, $privateTargets->validate( 'show_avatars', '1' ) );
		self::assertSame( 1, $privateTargets->validate( 'uploads_use_yearmonth_folders', '1' ) );
		self::assertSame( 'd.m.Y', $privateTargets->validate( 'date_format', 'd.m.Y' ) );
		self::assertSame( 'H:i', $privateTargets->validate( 'time_format', 'H:i' ) );
		self::assertNull( $privateTargets->get( 'admin_email' ) );
		self::assertNull( $privateTargets->validate( 'admin_email', 'owner@example.test' ) );
	}
}

<?php
/**
 * Fixed allowlist of core settings accepted by private browser input.
 *
 * @package Aculect\AICompanion\Settings
 */

declare(strict_types=1);
namespace Aculect\AICompanion\Settings;

/** Adds only bounded, explicitly typed WordPress core settings. */
final class CorePrivateSettingTargets {
	/**
	 * Core target metadata, accepted value constraints and native options.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private const TARGETS = array(
		'start_of_week'          => array(
			'option'  => 'start_of_week',
			'label'   => 'Start of week (0–6; 0=Sunday, 6=Saturday)',
			'group'   => 'General',
			'secret'  => false,
			'minimum' => 0,
			'maximum' => 6,
		),
		'posts_per_rss'          => array(
			'option'  => 'posts_per_rss',
			'label'   => 'Posts per RSS feed (1–100)',
			'group'   => 'Reading',
			'secret'  => false,
			'minimum' => 1,
			'maximum' => 100,
		),
		'rss_use_excerpt'        => array(
			'option'  => 'rss_use_excerpt',
			'label'   => 'RSS content (0=full text, 1=excerpt)',
			'group'   => 'Reading',
			'secret'  => false,
			'minimum' => 0,
			'maximum' => 1,
		),
		'default_comment_status' => array(
			'option' => 'default_comment_status',
			'label'  => 'Default comment status (open or closed)',
			'group'  => 'Discussion',
			'secret' => false,
			'values' => array( 'open', 'closed' ),
		),
		'default_ping_status'    => array(
			'option' => 'default_ping_status',
			'label'  => 'Default ping status (open or closed)',
			'group'  => 'Discussion',
			'secret' => false,
			'values' => array( 'open', 'closed' ),
		),
		'comment_moderation'     => array(
			'option'  => 'comment_moderation',
			'label'   => 'Comment moderation (0=off, 1=on)',
			'group'   => 'Discussion',
			'secret'  => false,
			'minimum' => 0,
			'maximum' => 1,
		),
		'thread_comments'        => array(
			'option'  => 'thread_comments',
			'label'   => 'Threaded comments (0=off, 1=on)',
			'group'   => 'Discussion',
			'secret'  => false,
			'minimum' => 0,
			'maximum' => 1,
		),
		'thread_comments_depth'  => array(
			'option'  => 'thread_comments_depth',
			'label'   => 'Threaded comment depth (2–10)',
			'group'   => 'Discussion',
			'secret'  => false,
			'minimum' => 2,
			'maximum' => 10,
		),
		'comments_per_page'      => array(
			'option'  => 'comments_per_page',
			'label'   => 'Comments per page (1–100)',
			'group'   => 'Discussion',
			'secret'  => false,
			'minimum' => 1,
			'maximum' => 100,
		),
		'thumbnail_size_w'       => array(
			'option'  => 'thumbnail_size_w',
			'label'   => 'Thumbnail width (0–4096 px; affects new uploads only; existing images are not regenerated)',
			'group'   => 'Media',
			'secret'  => false,
			'minimum' => 0,
			'maximum' => 4096,
		),
		'thumbnail_size_h'       => array(
			'option'  => 'thumbnail_size_h',
			'label'   => 'Thumbnail height (0–4096 px; affects new uploads only; existing images are not regenerated)',
			'group'   => 'Media',
			'secret'  => false,
			'minimum' => 0,
			'maximum' => 4096,
		),
		'medium_size_w'          => array(
			'option'  => 'medium_size_w',
			'label'   => 'Medium image width (0–4096 px; affects new uploads only; existing images are not regenerated)',
			'group'   => 'Media',
			'secret'  => false,
			'minimum' => 0,
			'maximum' => 4096,
		),
		'medium_size_h'          => array(
			'option'  => 'medium_size_h',
			'label'   => 'Medium image height (0–4096 px; affects new uploads only; existing images are not regenerated)',
			'group'   => 'Media',
			'secret'  => false,
			'minimum' => 0,
			'maximum' => 4096,
		),
		'large_size_w'           => array(
			'option'  => 'large_size_w',
			'label'   => 'Large image width (0–4096 px; affects new uploads only; existing images are not regenerated)',
			'group'   => 'Media',
			'secret'  => false,
			'minimum' => 0,
			'maximum' => 4096,
		),
		'large_size_h'           => array(
			'option'  => 'large_size_h',
			'label'   => 'Large image height (0–4096 px; affects new uploads only; existing images are not regenerated)',
			'group'   => 'Media',
			'secret'  => false,
			'minimum' => 0,
			'maximum' => 4096,
		),
		'thumbnail_crop'         => array(
			'option'  => 'thumbnail_crop',
			'label'   => 'Thumbnail crop (0=off, 1=on; affects new uploads only; existing images are not regenerated)',
			'group'   => 'Media',
			'secret'  => false,
			'minimum' => 0,
			'maximum' => 1,
		),
	);

	/**
	 * Return the fixed target definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		return self::TARGETS;
	}

	/**
	 * Find a fixed core target.
	 *
	 * @param string $id Public target identifier.
	 * @return array<string,mixed>|null
	 */
	public function get( string $id ): ?array {
		return self::TARGETS[ $id ] ?? null;
	}

	/**
	 * Validate a fixed core setting value without coercing user input.
	 *
	 * @param string $id    Fixed target identifier.
	 * @param string $value Private browser input.
	 */
	public function validate( string $id, string $value ): string|int|null {
		$target = $this->get( $id );
		if ( null === $target ) {
			return null;
		}

		$values = $target['values'] ?? null;
		if ( is_array( $values ) ) {
			return in_array( $value, $values, true ) ? $value : null;
		}

		$minimum = $target['minimum'] ?? null;
		$maximum = $target['maximum'] ?? null;
		if ( ! is_int( $minimum ) || ! is_int( $maximum ) || strlen( $value ) > strlen( (string) $maximum ) || 1 !== preg_match( '/\A(?:0|[1-9][0-9]*)\z/', $value ) ) {
			return null;
		}

		$number = (int) $value;
		return $number >= $minimum && $number <= $maximum ? $number : null;
	}
}

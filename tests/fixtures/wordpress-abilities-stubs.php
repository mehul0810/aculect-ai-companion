<?php
/**
 * WordPress Abilities API stubs for unit tests.
 *
 * @package Aculect\AICompanion\Tests\Fixtures
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- PHPUnit stubs for future WordPress core functions.
if ( ! function_exists( 'wp_register_ability_category' ) ) {
	/**
	 * Record a test WordPress Ability category registration.
	 *
	 * @param string               $slug Category slug.
	 * @param array<string, mixed> $args Category args.
	 */
	function wp_register_ability_category( string $slug, array $args ): object {
		$GLOBALS['aculect_ai_companion_test_wp_ability_categories'][] = array(
			'slug' => $slug,
			'args' => $args,
		);

		return (object) compact( 'slug', 'args' );
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * Record a test WordPress Ability registration.
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability args.
	 */
	function wp_register_ability( string $name, array $args ): object {
		$GLOBALS['aculect_ai_companion_test_wp_abilities'][] = array(
			'name' => $name,
			'args' => $args,
		);

		return (object) compact( 'name', 'args' );
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

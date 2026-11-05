<?php

declare(strict_types=1);

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( mixed ...$args ): void {
		$GLOBALS['aculect_ai_companion_test_admin_assets']['scripts'][] = $args;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( mixed ...$args ): void {
		$GLOBALS['aculect_ai_companion_test_admin_assets']['scripts'][] = $args;
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( mixed ...$args ): void {
		$GLOBALS['aculect_ai_companion_test_admin_assets']['localized'][] = $args;
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( mixed ...$args ): void {
		$GLOBALS['aculect_ai_companion_test_admin_assets']['styles'][] = $args;
	}
}

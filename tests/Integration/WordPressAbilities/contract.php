<?php

declare(strict_types=1);

/**
 * WordPress Abilities API contract smoke test.
 *
 * Run with WP-CLI inside a real WordPress environment. The unit suite uses
 * WordPress-light stubs and intentionally cannot prove registration lifecycle,
 * permission callbacks, or core schema handling.
 */

if ( ! function_exists( 'wp_get_abilities' ) ) {
	WP_CLI::log( 'SKIP: WordPress Abilities API is unavailable in this core version.' );
	exit( 0 );
}

$abilities = wp_get_abilities();
if ( ! is_array( $abilities ) ) {
	WP_CLI::error( 'wp_get_abilities() did not return an array.' );
}

$by_name = array();
foreach ( $abilities as $ability ) {
	if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
		continue;
	}

	$name = $ability->get_name();
	if ( is_string( $name ) && '' !== $name ) {
		$by_name[ $name ] = $ability;
	}
}

$expected = array(
	'aculect-ai-companion/intelligence-site-get-context',
	'aculect-ai-companion/content-search-items',
	'aculect-ai-companion/plugin-incident-list',
);

foreach ( $expected as $name ) {
	if ( ! isset( $by_name[ $name ] ) ) {
		WP_CLI::error( 'Missing expected Aculect Ability: ' . $name );
	}

	$ability = $by_name[ $name ];
	foreach ( array( 'get_input_schema', 'get_output_schema', 'get_meta', 'check_permissions', 'execute' ) as $method ) {
		if ( ! method_exists( $ability, $method ) ) {
			WP_CLI::error( sprintf( '%s is missing native method %s().', $name, $method ) );
		}
	}

	$input_schema  = $ability->get_input_schema();
	$output_schema = $ability->get_output_schema();
	$meta          = $ability->get_meta();
	if ( ! is_array( $input_schema ) || 'object' !== ( $input_schema['type'] ?? null ) || false !== ( $input_schema['additionalProperties'] ?? null ) ) {
		WP_CLI::error( $name . ' has an invalid or open input schema.' );
	}
	if ( ! is_array( $output_schema ) || 'object' !== ( $output_schema['type'] ?? null ) ) {
		WP_CLI::error( $name . ' has an invalid output schema.' );
	}
	if ( ! is_array( $meta ) || true !== ( $meta['public'] ?? $meta['show_in_rest'] ?? false ) ) {
		WP_CLI::error( $name . ' is not explicitly public.' );
	}

	try {
		$permission = $ability->check_permissions( array() );
	} catch ( Throwable ) {
		WP_CLI::error( $name . ' permission callback failed safely.' );
	}
	if ( ! is_bool( $permission ) && ! is_wp_error( $permission ) ) {
		WP_CLI::error( $name . ' returned an invalid permission result.' );
	}
}

WP_CLI::success( sprintf( 'Validated %d Aculect WordPress Abilities.', count( $expected ) ) );

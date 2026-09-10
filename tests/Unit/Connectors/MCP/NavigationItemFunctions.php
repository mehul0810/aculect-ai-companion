<?php
/**
 * Isolated native menu API doubles, loaded only in child test processes.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.ValidFunctionName, WordPress.NamingConventions.ValidVariableName, Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Single process-isolated fixture includes its native post double.
class NavigationFixturePost extends \WP_Post {
	public int $menu_order = 1;
}

function get_term( int $id, string $taxonomy ): ?\WP_Term {
	return 11 === $id && 'nav_menu' === $taxonomy ? new \WP_Term(
		array(
			'term_id'  => 11,
			'taxonomy' => 'nav_menu',
		)
	) : null;
}

function get_posts( array $args ): array {
	$GLOBALS['navigation_fixture_query'] = $args;
	return array_slice( array_values( $GLOBALS['navigation_fixture_posts'] ), 0, $args['posts_per_page'] );
}

function get_post_meta( int $id, string $key, bool $single ): mixed {
	$value = $GLOBALS['navigation_fixture_meta'][ $id ][ $key ] ?? '';
	return $single ? $value : array( $value );
}

function wp_slash( mixed $value ): mixed {
	return is_array( $value ) ? array_map( __NAMESPACE__ . '\\wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value );
}

function sanitize_html_class( string $value ): string {
	return preg_replace( '/[^A-Za-z0-9_-]/', '', $value );
}

function navigation_fixture_unslash( mixed $value ): mixed {
	return is_array( $value ) ? array_map( __NAMESPACE__ . '\\navigation_fixture_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
}

function wp_update_nav_menu_item( int $menu_id, int $item_id, array $payload ): int|\WP_Error {
	++$GLOBALS['navigation_fixture_writes'];
	$mode = $GLOBALS['navigation_fixture_mode'];
	if ( 'fail' === $mode ) {
		return new \WP_Error( 'failed' );
	}
	$payload                               = navigation_fixture_unslash( $payload );
	$GLOBALS['navigation_fixture_payload'] = $payload;
	$post                                  = $GLOBALS['navigation_fixture_posts'][ $item_id ];
	foreach ( array(
		'title'         => 'post_title',
		'position'      => 'menu_order',
		'description'   => 'post_content',
		'attr-title'    => 'post_excerpt',
		'status'        => 'post_status',
		'post-date'     => 'post_date',
		'post-date-gmt' => 'post_date_gmt',
	) as $field => $property ) {
		$post->{$property} = $payload[ 'menu-item-' . $field ];
	}
	foreach ( array(
		'parent-id' => 'menu_item_parent',
		'object-id' => 'object_id',
		'xfn'       => 'xfn',
		'url'       => 'url',
		'target'    => 'target',
		'classes'   => 'classes',
		'type'      => 'type',
		'object'    => 'object',
	) as $field => $meta ) {
		$GLOBALS['navigation_fixture_meta'][ $item_id ][ '_menu_item_' . $meta ] = $payload[ 'menu-item-' . $field ];
	}
	$GLOBALS['navigation_fixture_meta'][ $item_id ]['_menu_item_classes'] = explode( ' ', $payload['menu-item-classes'] );
	if ( 'partial' === $mode || ( 'rollback' === $mode && 1 === $GLOBALS['navigation_fixture_writes'] ) ) {
		$post->post_title = 'Unexpected hook mutation';
		throw new \RuntimeException( 'Native hook failed after persistence.' );
	}
	return $item_id;
}

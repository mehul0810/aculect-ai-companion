<?php
/**
 * Isolated WordPress API doubles for guarded editor-record tests.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);


namespace Aculect\AICompanion\Connectors\MCP;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Namespaced WordPress runtime doubles.

/**
 * Recognize namespaced API doubles while preserving the global function check.
 *
 * @param string $function Function name.
 */
function function_exists( string $function ): bool {
	$fixture_functions = array(
		'filter_block_content',
		'get_posts',
		'wp_allowed_protocols',
		'wp_check_post_lock',
		'wp_get_object_terms',
		'wp_revisions_enabled',
		'wp_revisions_to_keep',
		'wp_save_post_revision',
		'wp_slash',
		'wp_update_post',
	);

	return in_array( ltrim( $function, '\\' ), $fixture_functions, true ) || \function_exists( $function );
}

/**
 * Return a stable auth salt for editor-state HMACs.
 *
 * @param string $scheme Salt scheme.
 */
function wp_salt( string $scheme = 'auth' ): string {
	unset( $scheme );
	return 'editor-record-test-secret';
}

/**
 * Return the configured lock owner, or false when unlocked.
 *
 * @param int $post_id Post ID.
 * @return int|false
 */
function wp_check_post_lock( int $post_id ): int|false {
	$GLOBALS['editor_record_test_lock_calls'] = ( $GLOBALS['editor_record_test_lock_calls'] ?? 0 ) + 1;
	$callback                                 = $GLOBALS['editor_record_test_lock_callback'] ?? null;
	return is_callable( $callback ) ? $callback( $post_id ) : false;
}

/**
 * Report the fixture's native revision-enabled setting.
 *
 * @param \WP_Post $post Target post.
 */
function wp_revisions_enabled( \WP_Post $post ): bool {
	unset( $post );
	return (bool) ( $GLOBALS['editor_record_test_revisions_enabled'] ?? true );
}

/**
 * Return the configured native revision retention limit.
 *
 * @param \WP_Post $post Target post.
 */
function wp_revisions_to_keep( \WP_Post $post ): int {
	unset( $post );
	return (int) ( $GLOBALS['editor_record_test_revision_limit'] ?? 5 );
}

/**
 * Save an exact pre-write content revision in the fixture.
 *
 * @param int $post_id Target post ID.
 * @return int|false|\WP_Error
 */
function wp_save_post_revision( int $post_id ): int|false|\WP_Error {
	$GLOBALS['editor_record_test_revision_save_calls'] = ( $GLOBALS['editor_record_test_revision_save_calls'] ?? 0 ) + 1;
	$callback = $GLOBALS['editor_record_test_revision_callback'] ?? null;
	if ( is_callable( $callback ) ) {
		return $callback( $post_id );
	}

	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post ) {
		return false;
	}

	$revision_id = (int) ( $GLOBALS['editor_record_test_next_revision_id'] ?? 900 );
	$revision    = new \WP_Post(
		array(
			'ID'                => $revision_id,
			'post_type'         => 'revision',
			'post_status'       => 'inherit',
			'post_parent'       => $post_id,
			'post_name'         => 'revision-' . $post_id . '-' . $revision_id,
			'post_title'        => $post->post_title,
			'post_content'      => $post->post_content,
			'post_excerpt'      => $post->post_excerpt,
			'post_modified_gmt' => $post->post_modified_gmt,
		)
	);
	$GLOBALS['aculect_ai_companion_test_posts'][ $revision_id ]                      = $revision;
	$GLOBALS['aculect_ai_companion_test_post_revisions'][ $post_id ][ $revision_id ] = $revision;
	$GLOBALS['editor_record_test_next_revision_id']                                  = $revision_id + 1;

	return $revision_id;
}

/**
 * Apply WordPress-style slashes to a post payload.
 *
 * @param array|string $value Value to slash.
 * @return array|string
 */
function wp_slash( array|string $value ): array|string {
	$GLOBALS['editor_record_test_slash_calls'] = ( $GLOBALS['editor_record_test_slash_calls'] ?? 0 ) + 1;
	$slash                                     = static function ( array|string $item ) use ( &$slash ): array|string {
		if ( is_string( $item ) ) {
			return addslashes( $item );
		}

		foreach ( $item as $key => $child ) {
			$item[ $key ] = is_array( $child ) || is_string( $child ) ? $slash( $child ) : $child;
		}

		return $item;
	};

	return $slash( $value );
}

/**
 * Return filtered fixture block markup, if a test callback is configured.
 *
 * @param string       $content Raw serialized block markup.
 * @param string       $context Context name.
 * @param array<mixed> $allowed_protocols Allowed URL protocols.
 */
function filter_block_content( string $content, string $context, array $allowed_protocols ): string {
	unset( $context, $allowed_protocols );
	$callback = $GLOBALS['editor_record_test_block_filter_callback'] ?? null;
	return is_callable( $callback ) ? (string) $callback( $content ) : $content;
}

/**
 * Return a minimal safe protocol set.
 *
 * @return list<string>
 */
function wp_allowed_protocols(): array {
	return array( 'http', 'https', 'mailto', 'tel' );
}

/**
 * Record and emulate the bounded editor queries made by EditorRecordAbilities.
 *
 * @param array<string,mixed> $args Query arguments.
 * @return list<\WP_Post>
 */
function get_posts( array $args = array() ): array {
	$GLOBALS['editor_record_test_queries'][] = $args;
	$callback                                = $GLOBALS['editor_record_test_query_callback'] ?? null;
	if ( is_callable( $callback ) ) {
		return $callback( $args );
	}

	$posts    = array();
	$types    = is_array( $args['post_type'] ?? null ) ? $args['post_type'] : array( $args['post_type'] ?? '' );
	$statuses = is_array( $args['post_status'] ?? null ) ? $args['post_status'] : array( $args['post_status'] ?? '' );
	foreach ( $GLOBALS['aculect_ai_companion_test_posts'] ?? array() as $candidate ) {
		$post = $candidate instanceof \WP_Post ? $candidate : new \WP_Post( is_array( $candidate ) ? $candidate : array() );
		if ( ! in_array( $post->post_type, $types, true ) || ! in_array( $post->post_status, $statuses, true ) ) {
			continue;
		}

		$matches_taxonomy = true;
		foreach ( $args['tax_query'] ?? array() as $tax_query ) {
			$assigned = wp_get_object_terms( $post->ID, (string) ( $tax_query['taxonomy'] ?? '' ), array( 'fields' => (string) ( $tax_query['field'] ?? 'names' ) ) );
			if ( is_wp_error( $assigned ) || array_intersect( array_map( 'strval', (array) ( $tax_query['terms'] ?? array() ) ), array_map( 'strval', $assigned ) ) === array() ) {
				$matches_taxonomy = false;
				break;
			}
		}
		if ( $matches_taxonomy ) {
			$posts[] = $post;
		}
	}

	usort( $posts, static fn( \WP_Post $left, \WP_Post $right ): int => $left->ID <=> $right->ID );
	$posts = array_slice( $posts, max( 0, (int) ( $args['offset'] ?? 0 ) ) );
	$limit = (int) ( $args['posts_per_page'] ?? -1 );
	return 0 < $limit ? array_slice( $posts, 0, $limit ) : $posts;
}

	/**
	 * Resolve fixture term names for active-theme ownership checks.
	 *
	 * @param int|string          $object_id Post ID.
	 * @param string|array<mixed> $taxonomies Taxonomy names.
	 * @param array<string,mixed> $args Term query arguments.
	 * @return array<mixed>|\WP_Error
	 */
function wp_get_object_terms( int|string $object_id, string|array $taxonomies, array $args = array() ): array|\WP_Error {
	$callback = $GLOBALS['editor_record_test_terms_callback'] ?? null;
	if ( is_callable( $callback ) ) {
		$result = $callback( (int) $object_id, $taxonomies, $args );
		return is_array( $result ) || is_wp_error( $result ) ? $result : array();
	}

	$assigned = $GLOBALS['editor_record_test_terms'][ (int) $object_id ] ?? array();
	$results  = array();
	foreach ( (array) $taxonomies as $taxonomy ) {
		$terms = $assigned[ (string) $taxonomy ] ?? array();
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( 'ids' === ( $args['fields'] ?? '' ) ) {
				$results[] = is_object( $term ) ? (int) ( $term->term_id ?? 0 ) : (int) $term;
			} elseif ( 'names' === ( $args['fields'] ?? '' ) ) {
				$results[] = is_object( $term ) ? (string) ( $term->name ?? '' ) : (string) $term;
			} else {
				$results[] = $term;
			}
		}
	}
	return $results;
}

	/**
	 * Intercept native writes so tests can model errors and partial hooks.
	 *
	 * @param array<string,mixed> $postarr Slashed native update payload.
	 * @param bool                $wp_error Whether to return WordPress errors.
	 * @return int|\WP_Error
	 */
function wp_update_post( array $postarr = array(), bool $wp_error = false ): int|\WP_Error {
	$GLOBALS['editor_record_test_update_calls']      = ( $GLOBALS['editor_record_test_update_calls'] ?? 0 ) + 1;
	$GLOBALS['editor_record_test_update_payloads'][] = $postarr;
	$callback                                        = $GLOBALS['editor_record_test_update_callback'] ?? null;
	return is_callable( $callback ) ? $callback( $postarr, $wp_error ) : editor_record_test_apply_post_update( $postarr, $wp_error );
}

	/**
	 * Apply an intercepted post update through the test bootstrap's core stub.
	 *
	 * @param array<string,mixed> $postarr Slashed native update payload.
	 * @param bool                $wp_error Whether to return WordPress errors.
	 * @return int|\WP_Error
	 */
function editor_record_test_apply_post_update( array $postarr, bool $wp_error = false ): int|\WP_Error {
	foreach ( $postarr as $key => $value ) {
		if ( is_string( $value ) && str_starts_with( (string) $key, 'post_' ) ) {
			$postarr[ $key ] = stripslashes( $value );
		}
	}

	$post_id = (int) ( $postarr['ID'] ?? 0 );
	$post    = get_post( $post_id );
	if ( ! $post instanceof \WP_Post ) {
		return $wp_error ? new \WP_Error( 'not_found', 'Post not found.' ) : 0;
	}

	$updated = clone $post;
	foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
		if ( array_key_exists( $field, $postarr ) ) {
			$updated->$field = (string) $postarr[ $field ];
		}
	}

	$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = $updated;
	return $post_id;
}

/**
 * Return one valid registered paragraph block.
 *
 * @param string $text Paragraph text.
 */
function editor_record_test_block( string $text ): string {
	return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
}

/**
 * Build a user-style record with allowlisted and unrelated fields.
 *
 * @param array<string,mixed> $overrides Fixture override values.
 */
function editor_record_test_style_config( array $overrides = array() ): array {
	$background   = $overrides['background'] ?? '#eeeeee';
	$content_size = $overrides['content_size'] ?? '720px';
	$custom_css   = $overrides['custom_css'] ?? null;
	$config       = array(
		'version'                     => 3,
		'isGlobalStylesUserThemeJSON' => true,
		'styles'                      => array(
			'color'  => array(
				'background' => $background,
				'text'       => '#111111',
			),
			'blocks' => array( 'core/paragraph' => array( 'typography' => array( 'fontWeight' => '700' ) ) ),
		),
		'settings'                    => array(
			'layout' => array(
				'contentSize' => $content_size,
				'wideSize'    => '1200px',
			),
			'custom' => array( 'owner_note' => 'preserve-me' ),
		),
	);
	if ( null !== $custom_css ) {
		$config['styles']['css'] = $custom_css;
	}
	return $config;
}

/**
 * Add a native post and its assigned theme/area terms.
 *
 * @param array<string,mixed>        $fields Native post fields.
 * @param array<string,array<mixed>> $terms Taxonomy terms keyed by name.
 */
function editor_record_test_add_post( array $fields, array $terms = array() ): void {
	$fields = $fields + array(
		'post_name'         => 'editor-record-' . ( $fields['ID'] ?? 0 ),
		'post_author'       => 7,
		'post_parent'       => 44,
		'post_excerpt'      => 'Excerpt ' . ( $fields['ID'] ?? 0 ),
		'post_modified_gmt' => '2026-09-15 09:00:00',
	);
	$post   = new \WP_Post( $fields );
	$GLOBALS['aculect_ai_companion_test_posts'][ $post->ID ] = $post;
	$GLOBALS['editor_record_test_terms'][ $post->ID ]        = $terms;
}

/**
 * Add a saved revision for a seeded parent record.
 *
 * @param int                 $revision_id Revision ID.
 * @param int                 $parent_id Target post ID.
 * @param array<string,mixed> $fields Revision fields.
 * @param string              $name Revision name.
 * @param string              $type Revision post type.
 */
function editor_record_test_add_revision( int $revision_id, int $parent_id, array $fields, string $name = '', string $type = 'revision' ): void {
	$post = new \WP_Post(
		$fields + array(
			'ID'                => $revision_id,
			'post_type'         => $type,
			'post_status'       => 'inherit',
			'post_parent'       => $parent_id,
			'post_name'         => '' !== $name ? $name : 'revision-' . $parent_id . '-' . $revision_id,
			'post_title'        => 'Historical title',
			'post_content'      => editor_record_test_block( 'Historical content' ),
			'post_excerpt'      => 'Historical excerpt',
			'post_modified_gmt' => '2026-09-14 09:00:00',
		)
	);
	$GLOBALS['aculect_ai_companion_test_posts'][ $revision_id ]                        = $post;
	$GLOBALS['aculect_ai_companion_test_post_revisions'][ $parent_id ][ $revision_id ] = $post;
}

/** Seed supported editor types with foreign, ambiguous and non-editor neighbors. */
function editor_record_test_seed_records(): void {
	$style_content = (string) \wp_json_encode( editor_record_test_style_config() );
	$records       = array(
		array(
			array(
				'ID'           => 100,
				'post_type'    => 'wp_template',
				'post_status'  => 'draft',
				'post_title'   => 'Draft template',
				'post_content' => editor_record_test_block( 'Draft content' ),
			),
			array( 'wp_theme' => array( 'test-theme' ) ),
		),
		array(
			array(
				'ID'           => 101,
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_title'   => 'Published template',
				'post_content' => editor_record_test_block( 'Published content' ),
			),
			array( 'wp_theme' => array( 'test-theme' ) ),
		),
		array(
			array(
				'ID'           => 102,
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_title'   => 'Foreign template',
				'post_content' => editor_record_test_block( 'Foreign content' ),
			),
			array( 'wp_theme' => array( 'other-theme' ) ),
		),
		array(
			array(
				'ID'           => 103,
				'post_type'    => 'wp_template',
				'post_status'  => 'publish',
				'post_title'   => 'Ambiguous template',
				'post_content' => editor_record_test_block( 'Ambiguous content' ),
			),
			array( 'wp_theme' => array( 'test-theme', 'other-theme' ) ),
		),
		array(
			array(
				'ID'           => 110,
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Primary navigation',
				'post_content' => '<!-- wp:navigation-link {"label":"Home","url":"/"} /-->',
			),
			array(),
		),
		array(
			array(
				'ID'           => 120,
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_title'   => 'Custom Styles',
				'post_content' => $style_content,
			),
			array( 'wp_theme' => array( 'test-theme' ) ),
		),
		array(
			array(
				'ID'           => 130,
				'post_type'    => 'wp_template_part',
				'post_status'  => 'draft',
				'post_title'   => 'Header part',
				'post_content' => editor_record_test_block( 'Header content' ),
			),
			array(
				'wp_theme'              => array( 'test-theme' ),
				'wp_template_part_area' => array( 'header' ),
			),
		),
		array(
			array(
				'ID'           => 140,
				'post_type'    => 'post',
				'post_status'  => 'draft',
				'post_title'   => 'Ordinary post',
				'post_content' => editor_record_test_block( 'Ordinary content' ),
			),
			array(),
		),
		array(
			array(
				'ID'           => 150,
				'post_type'    => 'wp_global_styles',
				'post_status'  => 'publish',
				'post_title'   => 'Foreign styles',
				'post_content' => $style_content,
			),
			array( 'wp_theme' => array( 'other-theme' ) ),
		),
	);
	foreach ( $records as $record ) {
		editor_record_test_add_post( $record[0], $record[1] );
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

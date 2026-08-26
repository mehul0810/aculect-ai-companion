<?php

declare(strict_types=1);

/**
 * Focused write/taxonomy stubs used by unit tests.
 *
 * The main bootstrap intentionally stays WordPress-light. These callbacks are
 * kept in a separate fixture file so write-failure tests can model compensation
 * without turning the global bootstrap into another production-sized module.
 */

if ( ! function_exists( 'get_taxonomy' ) ) {
	/**
	 * Return one registered test taxonomy.
	 */
	function get_taxonomy( string $taxonomy ): ?WP_Taxonomy {
		$taxonomies = get_taxonomies( array(), 'objects' );
		$object     = $taxonomies[ $taxonomy ] ?? null;

		return $object instanceof WP_Taxonomy ? $object : null;
	}
}

if ( ! function_exists( 'is_object_in_taxonomy' ) ) {
	/**
	 * Check whether a test taxonomy is registered for a post type.
	 */
	function is_object_in_taxonomy( string $object_type, string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );

		return $object instanceof WP_Taxonomy && in_array( $object_type, $object->object_type, true );
	}
}

if ( ! function_exists( 'get_term' ) ) {
	/**
	 * Return one stored test term.
	 */
	function get_term( int|string $term_id, string $taxonomy = '' ): WP_Term|WP_Error|null {
		$term_id = absint( $term_id );
		$terms   = $GLOBALS['aculect_ai_companion_test_terms'][ $taxonomy ] ?? array();
		$term    = is_array( $terms ) ? ( $terms[ $term_id ] ?? null ) : null;
		if ( is_array( $term ) ) {
			$term = new WP_Term( $term );
		}

		return $term instanceof WP_Term ? $term : null;
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	/**
	 * Resolve a test term by slug or name.
	 */
	function get_term_by( string $field, string|int $value, string $taxonomy ): ?WP_Term {
		foreach ( (array) ( $GLOBALS['aculect_ai_companion_test_terms'][ $taxonomy ] ?? array() ) as $candidate ) {
			$term = $candidate instanceof WP_Term ? $candidate : new WP_Term( is_array( $candidate ) ? $candidate : array() );
			if ( 'slug' === $field && (string) $value === $term->slug ) {
				return $term;
			}
			if ( 'name' === $field && (string) $value === $term->name ) {
				return $term;
			}
			if ( 'id' === $field && absint( $value ) === $term->term_id ) {
				return $term;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	/**
	 * Return bounded test terms.
	 *
	 * @return list<WP_Term>|WP_Error
	 */
	function get_terms( array $args = array() ): array|WP_Error {
		$taxonomy = (string) ( $args['taxonomy'] ?? '' );
		if ( null === get_taxonomy( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
		}

		$terms = array();
		foreach ( (array) ( $GLOBALS['aculect_ai_companion_test_terms'][ $taxonomy ] ?? array() ) as $candidate ) {
			$term = $candidate instanceof WP_Term ? $candidate : new WP_Term( is_array( $candidate ) ? $candidate : array() );
			if ( ! empty( $args['hide_empty'] ) && 0 === $term->count ) {
				continue;
			}
			if ( isset( $args['search'] ) && '' !== (string) $args['search'] && ! str_contains( strtolower( $term->name ), strtolower( (string) $args['search'] ) ) ) {
				continue;
			}
			$terms[] = $term;
		}

		usort( $terms, static fn( WP_Term $a, WP_Term $b ): int => $a->term_id <=> $b->term_id );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$number = max( 0, (int) ( $args['number'] ?? 0 ) );

		return $number > 0 ? array_values( array_slice( $terms, $offset, $number ) ) : array_values( array_slice( $terms, $offset ) );
	}
}

if ( ! function_exists( 'wp_count_terms' ) ) {
	/**
	 * Count test terms using the same filters as get_terms().
	 */
	function wp_count_terms( array $args = array() ): int|WP_Error {
		$args['number'] = 0;
		$args['offset'] = 0;
		$result         = get_terms( $args );

		return is_wp_error( $result ) ? $result : count( $result );
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	/**
	 * Insert a test taxonomy term.
	 *
	 * @return array{term_id: int, term_taxonomy_id: int}|WP_Error
	 */
	function wp_insert_term( string $name, string $taxonomy, array $args = array() ): array|WP_Error {
		if ( null === get_taxonomy( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
		}

		$next_id = 1;
		$terms   = (array) ( $GLOBALS['aculect_ai_companion_test_terms'][ $taxonomy ] ?? array() );
		if ( array() !== $terms ) {
			$next_id = max( array_map( 'absint', array_keys( $terms ) ) ) + 1;
		}
		$term = new WP_Term(
			array(
				'term_id'     => $next_id,
				'name'        => $name,
				'slug'        => (string) ( $args['slug'] ?? sanitize_title( $name ) ),
				'taxonomy'    => $taxonomy,
				'description' => (string) ( $args['description'] ?? '' ),
				'parent'      => (int) ( $args['parent'] ?? 0 ),
			)
		);
		$GLOBALS['aculect_ai_companion_test_terms'][ $taxonomy ][ $next_id ] = $term;

		return array( 'term_id' => $next_id, 'term_taxonomy_id' => $next_id );
	}
}

if ( ! function_exists( 'wp_update_term' ) ) {
	/**
	 * Update a test taxonomy term.
	 *
	 * @return array{term_id: int, term_taxonomy_id: int}|WP_Error
	 */
	function wp_update_term( int $term_id, string $taxonomy, array $args = array() ): array|WP_Error {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return new WP_Error( 'not_found', 'Term not found.' );
		}

		foreach ( array( 'name', 'slug', 'description', 'parent' ) as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$term->{$field} = 'parent' === $field ? absint( $args[ $field ] ) : (string) $args[ $field];
			}
		}
		$GLOBALS['aculect_ai_companion_test_terms'][ $taxonomy ][ $term_id ] = $term;

		return array( 'term_id' => $term_id, 'term_taxonomy_id' => $term_id );
	}
}

if ( ! function_exists( 'wp_delete_term' ) ) {
	/**
	 * Delete a test taxonomy term.
	 */
	function wp_delete_term( int $term_id, string $taxonomy ): bool|WP_Error {
		if ( ! get_term( $term_id, $taxonomy ) instanceof WP_Term ) {
			return false;
		}

		unset( $GLOBALS['aculect_ai_companion_test_terms'][ $taxonomy ][ $term_id ] );

		return true;
	}
}

if ( ! function_exists( 'get_term_meta' ) ) {
	/**
	 * Return test term metadata.
	 */
	function get_term_meta( int $term_id, string $key = '', bool $single = false ): mixed {
		$meta = (array) ( $GLOBALS['aculect_ai_companion_test_term_meta'][ $term_id ] ?? array() );
		if ( '' === $key ) {
			return $meta;
		}

		$value = $meta[ $key ] ?? '';
		return $single ? $value : ( is_array( $value ) ? $value : array( $value ) );
	}
}

if ( ! function_exists( 'update_term_meta' ) ) {
	/**
	 * Store test term metadata.
	 */
	function update_term_meta( int $term_id, string $key, mixed $value ): int|bool|WP_Error {
		$callback = $GLOBALS['aculect_ai_companion_test_update_term_meta_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$result = $callback( $term_id, $key, $value );
			if ( is_int( $result ) || is_bool( $result ) || is_wp_error( $result ) ) {
				return $result;
			}
		}

		$GLOBALS['aculect_ai_companion_test_term_meta'][ $term_id ][ $key ] = $value;

		return 1;
	}
}

if ( ! function_exists( 'delete_term_meta' ) ) {
	/**
	 * Delete test term metadata.
	 */
	function delete_term_meta( int $term_id, string $key ): bool|WP_Error {
		$callback = $GLOBALS['aculect_ai_companion_test_delete_term_meta_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$result = $callback( $term_id, $key );
			if ( is_bool( $result ) || is_wp_error( $result ) ) {
				return $result;
			}
		}

		unset( $GLOBALS['aculect_ai_companion_test_term_meta'][ $term_id ][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	/**
	 * Store test taxonomy assignments, with an injectable failure callback.
	 *
	 * @return list<int>|WP_Error
	 */
	function wp_set_object_terms( int $object_id, array $terms, string $taxonomy, bool $append = false ): array|WP_Error {
		unset( $append );
		$callback = $GLOBALS['aculect_ai_companion_test_set_object_terms_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$result = $callback( $object_id, $terms, $taxonomy );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$assigned = array();
		foreach ( $terms as $term_id ) {
			$term = get_term( absint( $term_id ), $taxonomy );
			if ( $term instanceof WP_Term ) {
				$assigned[] = $term;
			}
		}
		$existing = array_filter(
			(array) ( $GLOBALS['aculect_ai_companion_test_object_terms'][ $object_id ] ?? array() ),
			static fn( WP_Term $term ): bool => $taxonomy !== $term->taxonomy
		);
		$GLOBALS['aculect_ai_companion_test_object_terms'][ $object_id ] = array_values( array_merge( $existing, $assigned ) );

		return array_map( static fn( WP_Term $term ): int => $term->term_id, $assigned );
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	/**
	 * Insert a test post, with an injectable failure callback.
	 */
	function wp_insert_post( array $postarr = array(), bool $wp_error = false ): int|WP_Error {
		$callback = $GLOBALS['aculect_ai_companion_test_wp_insert_post_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$result = $callback( $postarr, $wp_error );
			if ( is_int( $result ) || is_wp_error( $result ) ) {
				return $result;
			}
		}

		$ids    = array_map( 'absint', array_keys( (array) $GLOBALS['aculect_ai_companion_test_posts'] ) );
		$post_id = array() === $ids ? 1 : max( $ids ) + 1;
		$post    = new WP_Post( array_merge( $postarr, array( 'ID' => $post_id ) ) );
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = $post;

		return $post_id;
	}
}

if ( ! function_exists( 'wp_trash_post' ) ) {
	/**
	 * Move a test post to trash, with an injectable failure callback.
	 */
	function wp_trash_post( int $post_id ): WP_Post|false {
		$callback = $GLOBALS['aculect_ai_companion_test_wp_trash_post_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$result = $callback( $post_id );
			if ( false === $result || $result instanceof WP_Post ) {
				return $result;
			}
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$post->post_status = 'trash';
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = $post;

		return $post;
	}
}

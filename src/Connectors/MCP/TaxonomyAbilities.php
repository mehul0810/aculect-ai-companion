<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Taxonomy abilities implementation.
 */
final class TaxonomyAbilities extends AbstractAbilityService {
	public function list_taxonomies(): array {
		$taxonomies = get_taxonomies( array(), 'objects' );
		$items      = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $this->is_supported_taxonomy( $taxonomy ) ) {
				continue;
			}

			$items[] = array(
				'name'         => $taxonomy->name,
				'label'        => $taxonomy->label,
				'public'       => (bool) $taxonomy->public,
				'show_in_rest' => (bool) $taxonomy->show_in_rest,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'object_types' => array_values( array_map( 'strval', (array) $taxonomy->object_type ) ),
				'can_create'   => current_user_can( $taxonomy->cap->edit_terms ),
				'can_update'   => current_user_can( $taxonomy->cap->edit_terms ),
			);
		}

		return $items;
	}

	/**
	 * List terms in a supported taxonomy with pagination.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public function list_terms( array $args ): array {
		$taxonomy = sanitize_key( (string) ( $args['taxonomy'] ?? 'category' ) );
		$object   = get_taxonomy( $taxonomy );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );

		if ( ! $this->is_supported_taxonomy( $object ) ) {
			return $this->empty_collection( $page, $per_page );
		}

		$query = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => isset( $args['hide_empty'] ) ? (bool) $args['hide_empty'] : false,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
		);

		if ( ! empty( $args['search'] ) ) {
			$query['search'] = sanitize_text_field( (string) $args['search'] );
		}

		$terms = get_terms( $query );
		if ( is_wp_error( $terms ) ) {
			return $this->empty_collection( $page, $per_page );
		}

		$total = wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => $query['hide_empty'],
			)
		);

		return array(
			'items'    => array_map( array( $this, 'map_term' ), $terms ),
			'total'    => is_wp_error( $total ) ? count( $terms ) : (int) $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Create a term in a supported taxonomy.
	 *
	 * @param array<string, mixed> $data Term fields.
	 * @return array<string, mixed>
	 */
	public function create_term( array $data ): array {
		$taxonomy = sanitize_key( (string) ( $data['taxonomy'] ?? '' ) );
		$object   = get_taxonomy( $taxonomy );
		$name     = sanitize_text_field( (string) ( $data['name'] ?? '' ) );

		if ( ! $this->is_supported_taxonomy( $object ) || ! current_user_can( $object->cap->edit_terms ) ) {
			return $this->error( 'forbidden', 'You do not have permission to create terms in this taxonomy.' );
		}

		if ( '' === $name ) {
			return $this->error( 'invalid_term', 'Term name is required.' );
		}

		$payload = $this->term_payload( $data, $object );
		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'taxonomy.create_term',
				$data,
				array(
					'type' => $taxonomy,
					'id'   => null,
				),
				$this->term_payload_changes( array(), array_merge( $payload, array( 'name' => $name ) ) )
			);
		}

		$result = wp_insert_term( $name, $taxonomy, $payload );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message() );
		}

		$term = get_term( (int) $result['term_id'], $taxonomy );
		return $term instanceof \WP_Term ? $this->map_term( $term ) : array( 'term_id' => (int) $result['term_id'] );
	}

	/**
	 * Update a term in a supported taxonomy.
	 *
	 * @param array<string, mixed> $data Term fields.
	 * @return array<string, mixed>
	 */
	public function update_term( array $data ): array {
		$taxonomy = sanitize_key( (string) ( $data['taxonomy'] ?? '' ) );
		$term_id  = absint( $data['term_id'] ?? 0 );
		$object   = get_taxonomy( $taxonomy );

		if ( ! $this->is_supported_taxonomy( $object ) || ! current_user_can( $object->cap->edit_terms ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update terms in this taxonomy.' );
		}

		if ( 0 === $term_id || ! get_term( $term_id, $taxonomy ) ) {
			return $this->error( 'not_found', 'Term not found.' );
		}

		$payload = $this->term_payload( $data, $object );
		if ( $this->is_dry_run( $data ) ) {
			$term = get_term( $term_id, $taxonomy );
			return $this->preview_response(
				'taxonomy.update_term',
				$data,
				array(
					'type' => $taxonomy,
					'id'   => $term_id,
				),
				$term instanceof \WP_Term
					? $this->term_payload_changes(
						array(
							'name'        => $term->name,
							'slug'        => $term->slug,
							'description' => $term->description,
							'parent'      => (int) $term->parent,
						),
						$payload
					)
					: array()
			);
		}

		$result = wp_update_term( $term_id, $taxonomy, $payload );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message() );
		}

		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term ? $this->map_term( $term ) : array( 'term_id' => $term_id );
	}

	/**
	 * Assign or clear an image attachment for a taxonomy term.
	 *
	 * @param array<string, mixed> $data Term image fields.
	 * @return array<string, mixed>
	 */
	public function set_term_image( array $data ): array {
		$taxonomy = sanitize_key( (string) ( $data['taxonomy'] ?? '' ) );
		$term_id  = absint( $data['term_id'] ?? 0 );
		$object   = get_taxonomy( $taxonomy );

		if ( ! $this->is_supported_taxonomy( $object ) ) {
			return $this->error( 'invalid_taxonomy', 'Taxonomy is not available through Aculect AI Companion.' );
		}

		if ( ! current_user_can( $object->cap->edit_terms ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update terms in this taxonomy.' );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return $this->error( 'not_found', 'Term not found.' );
		}

		$meta_key = sanitize_key( (string) ( $data['meta_key'] ?? 'aculect_ai_companion_term_image_id' ) );
		if ( ! in_array( $meta_key, $this->term_image_meta_keys(), true ) ) {
			return $this->error( 'invalid_meta_key', 'Term image meta key is not allowlisted.' );
		}

		$image_id = 0;
		if ( empty( $data['clear_image'] ) ) {
			$image_id = $this->validated_image_attachment_id( $data['image_id'] ?? 0 );
			if ( is_array( $image_id ) ) {
				return $image_id;
			}
		}

		$current = absint( get_term_meta( $term_id, $meta_key, true ) );
		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'taxonomy.set_term_image',
				$data,
				array(
					'type' => $taxonomy,
					'id'   => $term_id,
				),
				array( $this->change( 'image.' . $meta_key, $current, $image_id ) )
			);
		}

		if ( 0 === $image_id ) {
			delete_term_meta( $term_id, $meta_key );
		} else {
			update_term_meta( $term_id, $meta_key, $image_id );
		}

		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term ? $this->map_term( $term ) : array( 'term_id' => $term_id );
	}
}

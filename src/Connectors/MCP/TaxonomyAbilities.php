<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Taxonomy abilities implementation.
 */
final class TaxonomyAbilities extends AbstractAbilityService {
	/**
	 * List taxonomies available to MCP tools.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list_taxonomies(): array {
		$taxonomies = get_taxonomies( array(), 'objects' );
		$items      = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy instanceof \WP_Taxonomy || ! $this->is_supported_taxonomy( $taxonomy ) ) {
				continue;
			}

			$manage_capability = $this->taxonomy_capability( $taxonomy, 'manage_terms' );
			$edit_capability   = $this->taxonomy_capability( $taxonomy, 'edit_terms' );
			$delete_capability = $this->taxonomy_capability( $taxonomy, 'delete_terms' );
			$assign_capability = $this->taxonomy_capability( $taxonomy, 'assign_terms' );

			$items[] = array(
				'name'         => $taxonomy->name,
				'label'        => $taxonomy->label,
				'public'       => (bool) $taxonomy->public,
				'show_in_rest' => (bool) $taxonomy->show_in_rest,
				'hierarchical' => (bool) $taxonomy->hierarchical,
				'object_types' => array_values( array_map( 'strval', (array) $taxonomy->object_type ) ),
				'can_create'   => null !== $manage_capability && current_user_can( $manage_capability ),
				'can_update'   => null !== $edit_capability && current_user_can( $edit_capability ),
				'can_delete'   => null !== $delete_capability && current_user_can( $delete_capability ),
				'can_assign'   => null !== $assign_capability && current_user_can( $assign_capability ),
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
			return $this->error( 'invalid_taxonomy', 'Taxonomy is not available through Aculect AI Companion.' );
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
			return $this->error( 'terms_unavailable', 'Terms could not be loaded for this taxonomy.' );
		}

		$count_query = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => $query['hide_empty'],
		);
		if ( isset( $query['search'] ) ) {
			$count_query['search'] = $query['search'];
		}

		$total = wp_count_terms( $count_query );
		if ( is_wp_error( $total ) ) {
			return array(
				'error'   => (string) $total->get_error_code(),
				'message' => 'The taxonomy total could not be calculated.',
			);
		}

		return array(
			'items'    => array_map( array( $this, 'map_term' ), $terms ),
			'total'    => (int) $total,
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

		$manage_capability = $object instanceof \WP_Taxonomy ? $this->taxonomy_capability( $object, 'manage_terms' ) : null;
		if ( ! $object instanceof \WP_Taxonomy || ! $this->is_supported_taxonomy( $object ) ) {
			return $this->error( 'invalid_taxonomy', 'Taxonomy is not available through Aculect AI Companion.' );
		}
		if ( null === $manage_capability || ! current_user_can( $manage_capability ) ) {
			return $this->error( 'forbidden', 'You do not have permission to create terms in this taxonomy.' );
		}

		if ( '' === $name ) {
			return $this->error( 'invalid_term', 'Term name is required.' );
		}

		$payload      = $this->term_payload( $data, $object );
		$parent_error = $this->validate_term_parent( $payload, $taxonomy, 0, $object );
		if ( array() !== $parent_error ) {
			return $parent_error;
		}
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
			return $this->error( (string) $result->get_error_code(), $result->get_error_message() );
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

		$edit_capability = $object instanceof \WP_Taxonomy ? $this->taxonomy_capability( $object, 'edit_terms' ) : null;
		if ( ! $object instanceof \WP_Taxonomy || ! $this->is_supported_taxonomy( $object ) ) {
			return $this->error( 'invalid_taxonomy', 'Taxonomy is not available through Aculect AI Companion.' );
		}
		if ( null === $edit_capability || ! current_user_can( $edit_capability ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update terms in this taxonomy.' );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( 0 === $term_id || ! $term instanceof \WP_Term ) {
			return $this->error( 'not_found', 'Term not found.' );
		}

		$payload      = $this->term_payload( $data, $object );
		$parent_error = $this->validate_term_parent( $payload, $taxonomy, $term_id, $object );
		if ( array() !== $parent_error ) {
			return $parent_error;
		}
		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'taxonomy.update_term',
				$data,
				array(
					'type' => $taxonomy,
					'id'   => $term_id,
				),
				$this->term_payload_changes(
					array(
						'name'        => $term->name,
						'slug'        => $term->slug,
						'description' => $term->description,
						'parent'      => (int) $term->parent,
					),
					$payload
				)
			);
		}

		$result = wp_update_term( $term_id, $taxonomy, $payload );
		if ( is_wp_error( $result ) ) {
			return $this->error( (string) $result->get_error_code(), $result->get_error_message() );
		}

		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term ? $this->map_term( $term ) : array( 'term_id' => $term_id );
	}

	/**
	 * Read one term from a supported taxonomy.
	 *
	 * @param array<string, mixed> $data Term identifier.
	 * @return array<string, mixed>
	 */
	public function get_term( array $data ): array {
		$taxonomy = sanitize_key( (string) ( $data['taxonomy'] ?? '' ) );
		$term_id  = absint( $data['term_id'] ?? 0 );
		$object   = get_taxonomy( $taxonomy );

		if ( ! $object instanceof \WP_Taxonomy || ! $this->is_supported_taxonomy( $object ) ) {
			return $this->error( 'invalid_taxonomy', 'Taxonomy is not available through Aculect AI Companion.' );
		}

		$edit_capability = $this->taxonomy_capability( $object, 'edit_terms' );
		if ( ! current_user_can( 'read' ) && ( null === $edit_capability || ! current_user_can( $edit_capability ) ) ) {
			return $this->error( 'forbidden', 'You do not have permission to read terms in this taxonomy.' );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( 0 === $term_id || ! $term instanceof \WP_Term ) {
			return $this->error( 'not_found', 'Term not found.' );
		}

		return $this->map_term( $term );
	}

	/**
	 * Delete one term from a supported taxonomy.
	 *
	 * @param array<string, mixed> $data Term identifier and safety fields.
	 * @return array<string, mixed>
	 */
	public function delete_term( array $data ): array {
		$taxonomy = sanitize_key( (string) ( $data['taxonomy'] ?? '' ) );
		$term_id  = absint( $data['term_id'] ?? 0 );
		$object   = get_taxonomy( $taxonomy );

		if ( ! $object instanceof \WP_Taxonomy || ! $this->is_supported_taxonomy( $object ) ) {
			return $this->error( 'invalid_taxonomy', 'Taxonomy is not available through Aculect AI Companion.' );
		}

		$delete_capability = $this->taxonomy_capability( $object, 'delete_terms' );
		if ( null === $delete_capability || ! current_user_can( $delete_capability ) ) {
			return $this->error( 'forbidden', 'You do not have permission to delete terms in this taxonomy.' );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( 0 === $term_id || ! $term instanceof \WP_Term ) {
			return $this->error( 'not_found', 'Term not found.' );
		}

		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'taxonomy.delete_term',
				$data,
				array(
					'type' => $taxonomy,
					'id'   => $term_id,
				),
				array(
					$this->change( 'term', $this->map_term( $term ), null ),
				),
				array( 'Deleting a term removes its assignments from content; confirm the intended term before execution.' )
			);
		}

		$result = wp_delete_term( $term_id, $taxonomy );
		if ( is_wp_error( $result ) || false === $result ) {
			return is_wp_error( $result )
				? $this->error( (string) $result->get_error_code(), $result->get_error_message() )
				: $this->error( 'delete_failed', 'Term could not be deleted.' );
		}

		return array(
			'status'   => 'deleted',
			'taxonomy' => $taxonomy,
			'term_id'  => $term_id,
		);
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

		if ( ! $object instanceof \WP_Taxonomy || ! $this->is_supported_taxonomy( $object ) ) {
			return $this->error( 'invalid_taxonomy', 'Taxonomy is not available through Aculect AI Companion.' );
		}

		$edit_capability = $this->taxonomy_capability( $object, 'edit_terms' );
		if ( null === $edit_capability || ! current_user_can( $edit_capability ) ) {
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

		// Avoid treating WordPress's "unchanged" false return as a write
		// failure when the requested value is already persisted.
		if ( $current === $image_id ) {
			return $this->map_term( $term );
		}

		if ( 0 === $image_id ) {
			$result = delete_term_meta( $term_id, $meta_key );
		} else {
			$result = update_term_meta( $term_id, $meta_key, $image_id );
		}

		if ( is_wp_error( $result ) ) {
			return $this->error( (string) $result->get_error_code(), $result->get_error_message() );
		}

		if ( false === $result ) {
			return $this->error( 'term_image_update_failed', 'The term image could not be saved.' );
		}

		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term ? $this->map_term( $term ) : array( 'term_id' => $term_id );
	}

	/**
	 * Return a taxonomy capability with a safe fallback for older/custom objects.
	 *
	 * @param \WP_Taxonomy $taxonomy Taxonomy object.
	 * @param string       $operation Capability property.
	 */
	private function taxonomy_capability( \WP_Taxonomy $taxonomy, string $operation ): ?string {
		if ( is_object( $taxonomy->cap ) && property_exists( $taxonomy->cap, $operation ) && is_scalar( $taxonomy->cap->{$operation} ) && '' !== (string) $taxonomy->cap->{$operation} ) {
			return (string) $taxonomy->cap->{$operation};
		}

		return null;
	}

	/**
	 * Validate a hierarchical term parent before WordPress writes.
	 *
	 * @param array<string, mixed> $payload  Sanitized term payload.
	 * @param string               $taxonomy Taxonomy slug.
	 * @param int                  $term_id  Current term ID for updates.
	 * @param \WP_Taxonomy         $object   Taxonomy object.
	 * @return array<string, mixed>
	 */
	private function validate_term_parent( array $payload, string $taxonomy, int $term_id, \WP_Taxonomy $object ): array {
		if ( ! $object->hierarchical || ! isset( $payload['parent'] ) ) {
			return array();
		}

		$parent = absint( $payload['parent'] );
		if ( 0 === $parent ) {
			return array();
		}

		if ( $parent === $term_id ) {
			return $this->error( 'invalid_parent', 'A taxonomy term cannot be its own parent.' );
		}

		$parent_term = get_term( $parent, $taxonomy );
		if ( ! $parent_term instanceof \WP_Term ) {
			return $this->error( 'invalid_parent', 'Parent term was not found in this taxonomy.' );
		}

		$seen   = array( $parent );
		$cursor = (int) $parent_term->parent;
		while ( 0 < $cursor ) {
			if ( $cursor === $term_id || in_array( $cursor, $seen, true ) ) {
				return $this->error( 'invalid_parent', 'The requested parent would create a taxonomy hierarchy cycle.' );
			}

			$seen[]   = $cursor;
			$ancestor = get_term( $cursor, $taxonomy );
			if ( ! $ancestor instanceof \WP_Term ) {
				break;
			}
			$cursor = (int) $ancestor->parent;
		}

		return array();
	}
}

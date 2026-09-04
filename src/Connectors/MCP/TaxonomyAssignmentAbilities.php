<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Assigns existing taxonomy terms to supported content items.
 */
final class TaxonomyAssignmentAbilities extends AbstractAbilityService {
	/**
	 * Assign or clear existing taxonomy terms on one content item.
	 *
	 * @param array<string, mixed> $data Assignment fields.
	 * @return array<string, mixed>
	 */
	public function assign_terms( array $data ): array {
		$post_id = absint( $data['post_id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return $this->error( 'not_found', 'Content item not found.' );
		}

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type instanceof \WP_Post_Type || ! $this->is_supported_post_type( $post_type ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update this content item.' );
		}

		$conflict = ContentWriteSafety::expected_modified_error( $post, $data );
		if ( array() !== $conflict ) {
			return $conflict;
		}

		$taxonomy    = sanitize_key( (string) ( $data['taxonomy'] ?? '' ) );
		$assignments = $this->taxonomy_assignments(
			array( 'taxonomies' => array( $taxonomy => $data['terms'] ?? null ) ),
			$post->post_type
		);
		if ( isset( $assignments['error'] ) ) {
			$error = $assignments['error'];
			return $this->error(
				(string) ( $error['error'] ?? 'invalid_terms' ),
				(string) ( $error['message'] ?? 'Taxonomy terms could not be resolved.' )
			);
		}

		/**
		 * Resolved taxonomy assignments.
		 *
		 * @var array<string, list<int>> $assignments
		 */
		$requested = $assignments[ $taxonomy ];
		$current   = $this->assigned_term_ids( $post_id, $taxonomy );
		if ( null !== $current['error'] ) {
			return $current['error'];
		}
		$current_ids = $current['term_ids'];

		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'taxonomy.assign_terms',
				$data,
				array(
					'type' => $post->post_type,
					'id'   => $post_id,
				),
				array( $this->change( 'taxonomies.' . $taxonomy, $current_ids, $requested ) )
			);
		}

		if ( $current_ids === $requested ) {
			return $this->assignment_response( $post_id, $taxonomy, $requested, false );
		}

		$write = $this->apply_taxonomy_assignments( $post_id, $assignments );
		if ( isset( $write['error'] ) ) {
			$error = $write['error'];
			return is_array( $error ) ? $error : $this->error( 'taxonomy_assignment_failed', 'Taxonomy terms could not be assigned.' );
		}

		$persisted = $this->assigned_term_ids( $post_id, $taxonomy );
		if ( null !== $persisted['error'] || $persisted['term_ids'] !== $requested ) {
			return $this->error( 'taxonomy_assignment_failed', 'Taxonomy terms could not be verified after the update.' );
		}

		return $this->assignment_response( $post_id, $taxonomy, $persisted['term_ids'], true );
	}

	/**
	 * Return assigned term IDs in deterministic order.
	 *
	 * @param int    $post_id  Content item ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array{term_ids: list<int>, error: array<string, string>|null}
	 */
	private function assigned_term_ids( int $post_id, string $taxonomy ): array {
		$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return array(
				'term_ids' => array(),
				'error'    => $this->error( 'taxonomy_terms_unavailable', 'Current taxonomy assignments could not be loaded.' ),
			);
		}

		$ids = array_map( 'absint', $terms );
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids );

		return array(
			'term_ids' => $ids,
			'error'    => null,
		);
	}

	/**
	 * Build the deterministic assignment result.
	 *
	 * @param int    $post_id  Content item ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param array  $term_ids Assigned term IDs.
	 * @param bool   $changed  Whether the stored assignment changed.
	 * @phpstan-param list<int> $term_ids
	 * @return array<string, mixed>
	 */
	private function assignment_response( int $post_id, string $taxonomy, array $term_ids, bool $changed ): array {
		return array(
			'status'   => 'success',
			'changed'  => $changed,
			'post_id'  => $post_id,
			'taxonomy' => $taxonomy,
			'term_ids' => $term_ids,
		);
	}
}

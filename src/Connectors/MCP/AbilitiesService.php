<?php

declare(strict_types=1);

namespace Quark\Connectors\MCP;

final class AbilitiesService {

	public function list_post_types(): array {
		$types = get_post_types( array( 'show_ui' => true ), 'objects' );
		$items = array();
		foreach ( $types as $type ) {
			$items[] = array(
				'name'   => $type->name,
				'label'  => $type->label,
				'public' => (bool) $type->public,
			);
		}
		return $items;
	}

	public function list_items( array $args ): array {
		$per_page         = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page             = max( 1, (int) ( $args['page'] ?? 1 ) );
		$post_type        = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->edit_posts ) ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'page'     => $page,
				'per_page' => $per_page,
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $args['status'] ?? array( 'publish', 'draft' ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'no_found_rows'  => false,
			)
		);

		return array(
			'items'    => array_map( array( $this, 'map_post' ), $query->posts ),
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	public function get_item( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
			return array();
		}

		return $this->map_post( $post );
	}

	public function create_draft( array $data ): array {
		$post_type        = sanitize_key( (string) ( $data['post_type'] ?? 'post' ) );
		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->create_posts ?? 'edit_posts' ) ) {
			return array( 'error' => 'forbidden' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_title'   => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
				'post_content' => wp_kses_post( (string) ( $data['content'] ?? '' ) ),
				'post_status'  => 'draft',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return array( 'error' => $post_id->get_error_message() );
		}

		return array( 'post_id' => (int) $post_id );
	}

	public function list_taxonomies(): array {
		$tax = get_taxonomies( array( 'show_ui' => true ), 'objects' );
		return array_map(
			static fn( $item ) => array(
				'name'  => $item->name,
				'label' => $item->label,
			),
			array_values( $tax )
		);
	}

	public function list_terms( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			static fn( $t ) => array(
				'id'   => $t->term_id,
				'name' => $t->name,
				'slug' => $t->slug,
			),
			$terms
		);
	}

	public function list_media( array $args ): array {
		return $this->list_items( array_merge( $args, array( 'post_type' => 'attachment' ) ) );
	}

	public function get_settings(): array {
		return array(
			'name'                => get_option( 'blogname' ),
			'description'         => get_option( 'blogdescription' ),
			'home_url'            => home_url( '/' ),
			'site_url'            => site_url( '/' ),
			'timezone'            => wp_timezone_string(),
			'locale'              => get_locale(),
			'date_format'         => get_option( 'date_format' ),
			'time_format'         => get_option( 'time_format' ),
			'permalink_structure' => (string) get_option( 'permalink_structure' ),
			'theme'               => wp_get_theme()->get( 'Name' ),
		);
	}

	private function map_post( \WP_Post $post ): array {
		return array(
			'id'           => (int) $post->ID,
			'type'         => $post->post_type,
			'title'        => get_the_title( $post ),
			'slug'         => $post->post_name,
			'status'       => $post->post_status,
			'content'      => $post->post_content,
			'excerpt'      => $post->post_excerpt,
			'author'       => (int) $post->post_author,
			'date_gmt'     => $post->post_date_gmt,
			'modified_gmt' => $post->post_modified_gmt,
		);
	}
}

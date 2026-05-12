<?php

declare(strict_types=1);

namespace Quark\Connectors\MCP;

final class AbilitiesService {

	private const DEFAULT_POST_STATUSES  = array( 'publish', 'future', 'draft', 'pending', 'private' );
	private const WRITABLE_POST_STATUSES = array( 'draft', 'pending', 'private', 'publish' );

	public function list_post_types(): array {
		$types = get_post_types( array(), 'objects' );
		$items = array();

		foreach ( $types as $type ) {
			if ( ! $this->is_supported_post_type( $type ) ) {
				continue;
			}

			$items[] = array(
				'name'         => $type->name,
				'label'        => $type->label,
				'public'       => (bool) $type->public,
				'show_in_rest' => (bool) $type->show_in_rest,
				'can_read'     => $this->can_read_post_type( $type ),
				'can_create'   => $this->can_create_post_type( $type ),
				'can_update'   => current_user_can( $type->cap->edit_posts ),
			);
		}

		return $items;
	}

	public function list_items( array $args ): array {
		$per_page         = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page             = max( 1, (int) ( $args['page'] ?? 1 ) );
		$post_type        = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $this->is_supported_post_type( $post_type_object ) || ! $this->can_read_post_type( $post_type_object ) ) {
			return $this->empty_collection( $page, $per_page );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $this->statuses_from_args( $args, 'attachment' === $post_type ? array( 'inherit' ) : self::DEFAULT_POST_STATUSES ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'no_found_rows'  => false,
				'perm'           => 'readable',
			)
		);

		$posts = array_values(
			array_filter(
				$query->posts,
				static fn( $post ): bool => $post instanceof \WP_Post && current_user_can( 'read_post', $post->ID )
			)
		);

		return array(
			'items'    => array_map( array( $this, 'map_post' ), $posts ),
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
		if ( ! $this->is_supported_post_type( $post_type_object ) || ! current_user_can( 'read_post', $post_id ) ) {
			return array();
		}

		return $this->map_post( $post );
	}

	public function create_item( array $data ): array {
		$post_type        = sanitize_key( (string) ( $data['post_type'] ?? 'post' ) );
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $this->is_supported_post_type( $post_type_object ) || ! $this->can_create_post_type( $post_type_object ) ) {
			return $this->error( 'forbidden', 'You do not have permission to create this post type.' );
		}

		$status = $this->writable_status( (string) ( $data['status'] ?? 'draft' ) );
		if ( 'publish' === $status && ! current_user_can( $post_type_object->cap->publish_posts ) ) {
			return $this->error( 'forbidden', 'You do not have permission to publish this post type.' );
		}

		$post_id = wp_insert_post(
			array_filter(
				array(
					'post_type'    => $post_type,
					'post_title'   => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
					'post_content' => wp_kses_post( (string) ( $data['content'] ?? '' ) ),
					'post_excerpt' => isset( $data['excerpt'] ) ? wp_kses_post( (string) $data['excerpt'] ) : null,
					'post_name'    => isset( $data['slug'] ) ? sanitize_title( (string) $data['slug'] ) : null,
					'post_status'  => $status,
				),
				static fn( $value ): bool => null !== $value
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $this->error( $post_id->get_error_code(), $post_id->get_error_message() );
		}

		return $this->get_item( (int) $post_id );
	}

	public function create_draft( array $data ): array {
		$data['status'] = 'draft';
		return $this->create_item( $data );
	}

	public function update_item( array $data ): array {
		$post_id = absint( $data['id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->error( 'not_found', 'Content item not found.' );
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $this->is_supported_post_type( $post_type_object ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update this content item.' );
		}

		$update = array( 'ID' => $post_id );
		if ( array_key_exists( 'title', $data ) ) {
			$update['post_title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( array_key_exists( 'content', $data ) ) {
			$update['post_content'] = wp_kses_post( (string) $data['content'] );
		}
		if ( array_key_exists( 'excerpt', $data ) ) {
			$update['post_excerpt'] = wp_kses_post( (string) $data['excerpt'] );
		}
		if ( array_key_exists( 'slug', $data ) ) {
			$update['post_name'] = sanitize_title( (string) $data['slug'] );
		}
		if ( array_key_exists( 'status', $data ) ) {
			$status = $this->writable_status( (string) $data['status'] );
			if ( 'publish' === $status && ! current_user_can( $post_type_object->cap->publish_posts ) ) {
				return $this->error( 'forbidden', 'You do not have permission to publish this post type.' );
			}
			$update['post_status'] = $status;
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message() );
		}

		return $this->get_item( $post_id );
	}

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

		$result = wp_insert_term( $name, $taxonomy, $this->term_payload( $data, $object ) );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message() );
		}

		$term = get_term( (int) $result['term_id'], $taxonomy );
		return $term instanceof \WP_Term ? $this->map_term( $term ) : array( 'term_id' => (int) $result['term_id'] );
	}

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

		$result = wp_update_term( $term_id, $taxonomy, $this->term_payload( $data, $object ) );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message() );
		}

		$term = get_term( $term_id, $taxonomy );
		return $term instanceof \WP_Term ? $this->map_term( $term ) : array( 'term_id' => $term_id );
	}

	public function list_media( array $args ): array {
		return $this->list_items( array_merge( $args, array( 'post_type' => 'attachment' ) ) );
	}

	public function upload_media( array $data ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to upload media.' );
		}

		$url = esc_url_raw( (string) ( $data['url'] ?? '' ) );
		if ( ! $this->is_public_http_url( $url ) ) {
			return $this->error( 'invalid_url', 'A public HTTP or HTTPS media URL is required.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return $this->error( $tmp->get_error_code(), $tmp->get_error_message() );
		}

		$filename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $filename || '.' === $filename || '..' === $filename ) {
			$filename = 'quark-media-upload';
		}

		$file = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $tmp,
		);

		$post_id       = absint( $data['post_id'] ?? 0 );
		$attachment_id = media_handle_sideload( $file, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return $this->error( $attachment_id->get_error_code(), $attachment_id->get_error_message() );
		}

		$update = array( 'ID' => (int) $attachment_id );
		if ( isset( $data['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( isset( $data['caption'] ) ) {
			$update['post_excerpt'] = wp_kses_post( (string) $data['caption'] );
		}
		if ( isset( $data['description'] ) ) {
			$update['post_content'] = wp_kses_post( (string) $data['description'] );
		}

		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		if ( isset( $data['alt_text'] ) ) {
			update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $data['alt_text'] ) );
		}

		$attachment = get_post( (int) $attachment_id );
		return $attachment instanceof \WP_Post ? $this->map_post( $attachment ) : array( 'id' => (int) $attachment_id );
	}

	public function list_comments( array $args ): array {
		if ( ! current_user_can( 'moderate_comments' ) && ! current_user_can( 'edit_posts' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to list comments.' );
		}

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );
		$status   = $this->comment_status( (string) ( $args['status'] ?? 'all' ), true );

		$query = array(
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'status'  => $status,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		);

		if ( ! empty( $args['post_id'] ) ) {
			$query['post_id'] = absint( $args['post_id'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$query['search'] = sanitize_text_field( (string) $args['search'] );
		}

		$comments = get_comments( $query );
		$total    = get_comments(
			array_merge(
				$query,
				array(
					'count'  => true,
					'number' => 0,
					'offset' => 0,
				)
			)
		);

		return array(
			'items'    => array_map( array( $this, 'map_comment' ), is_array( $comments ) ? $comments : array() ),
			'total'    => is_numeric( $total ) ? (int) $total : 0,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	public function get_comment( array $data ): array {
		$comment = get_comment( absint( $data['id'] ?? 0 ) );
		if ( ! $comment instanceof \WP_Comment ) {
			return $this->error( 'not_found', 'Comment not found.' );
		}

		if ( ! $this->can_read_comment( $comment ) ) {
			return $this->error( 'forbidden', 'You do not have permission to read this comment.' );
		}

		return $this->map_comment( $comment );
	}

	public function create_comment( array $data ): array {
		$post_id = absint( $data['post_id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to comment on this post.' );
		}

		$content = wp_kses_post( (string) ( $data['content'] ?? '' ) );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return $this->error( 'invalid_comment', 'Comment content is required.' );
		}

		$user        = wp_get_current_user();
		$author_name = '' !== $user->display_name ? $user->display_name : $user->user_login;
		$approved    = 'hold';
		if ( current_user_can( 'moderate_comments' ) ) {
			$approved = $this->comment_status( (string) ( $data['status'] ?? 'approve' ), false );
		}

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => $content,
				'comment_approved'     => 'approve' === $approved ? '1' : '0',
				'user_id'              => get_current_user_id(),
				'comment_author'       => sanitize_text_field( $author_name ),
				'comment_author_email' => sanitize_email( $user->user_email ),
			)
		);

		if ( ! $comment_id ) {
			return $this->error( 'comment_failed', 'Comment could not be created.' );
		}

		$comment = get_comment( (int) $comment_id );
		return $comment instanceof \WP_Comment ? $this->map_comment( $comment ) : array( 'id' => (int) $comment_id );
	}

	public function update_comment( array $data ): array {
		$comment_id = absint( $data['id'] ?? 0 );
		$comment    = get_comment( $comment_id );

		if ( ! $comment instanceof \WP_Comment ) {
			return $this->error( 'not_found', 'Comment not found.' );
		}

		if ( ! current_user_can( 'edit_comment', $comment_id ) && ! current_user_can( 'moderate_comments' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update this comment.' );
		}

		$update = array( 'comment_ID' => $comment_id );
		if ( array_key_exists( 'content', $data ) ) {
			$update['comment_content'] = wp_kses_post( (string) $data['content'] );
		}

		if ( count( $update ) > 1 && false === wp_update_comment( $update ) ) {
			return $this->error( 'comment_failed', 'Comment could not be updated.' );
		}

		if ( array_key_exists( 'status', $data ) ) {
			$status = $this->comment_status( (string) $data['status'], false );
			if ( ! wp_set_comment_status( $comment_id, $status ) ) {
				return $this->error( 'comment_status_failed', 'Comment status could not be updated.' );
			}
		}

		$comment = get_comment( $comment_id );
		return $comment instanceof \WP_Comment ? $this->map_comment( $comment ) : array( 'id' => $comment_id );
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

	public function get_site_info(): array {
		$theme = wp_get_theme();

		return array(
			'name'             => get_option( 'blogname' ),
			'description'      => get_option( 'blogdescription' ),
			'home_url'         => home_url( '/' ),
			'site_url'         => site_url( '/' ),
			'wordpress'        => array(
				'version'          => get_bloginfo( 'version' ),
				'multisite'        => is_multisite(),
				'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			),
			'php'              => array(
				'version' => PHP_VERSION,
			),
			'active_theme'     => array(
				'name'       => $theme->get( 'Name' ),
				'stylesheet' => $theme->get_stylesheet(),
				'template'   => $theme->get_template(),
				'version'    => $theme->get( 'Version' ),
			),
			'active_plugins'   => count( (array) get_option( 'active_plugins', array() ) ),
			'locale'           => get_locale(),
			'timezone'         => wp_timezone_string(),
			'rest_url'         => rest_url(),
			'abilities_api'    => function_exists( 'wp_get_abilities' ),
			'quark_version'    => QUARK_VERSION,
			'mcp_endpoint_url' => \Quark\Connectors\Helpers::mcp_resource(),
		);
	}

	public function list_plugins(): array {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to list plugins.' );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$items   = array();
		foreach ( $plugins as $file => $plugin ) {
			$items[] = array(
				'file'        => (string) $file,
				'name'        => (string) ( $plugin['Name'] ?? '' ),
				'version'     => (string) ( $plugin['Version'] ?? '' ),
				'description' => wp_strip_all_tags( (string) ( $plugin['Description'] ?? '' ) ),
				'author'      => wp_strip_all_tags( (string) ( $plugin['Author'] ?? '' ) ),
				'active'      => is_plugin_active( (string) $file ),
				'network'     => is_multisite() && is_plugin_active_for_network( (string) $file ),
			);
		}

		return array(
			'items' => $items,
			'total' => count( $items ),
		);
	}

	public function list_themes(): array {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to list themes.' );
		}

		$active = wp_get_theme();
		$items  = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$items[] = array(
				'stylesheet'  => (string) $stylesheet,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'description' => wp_strip_all_tags( $theme->get( 'Description' ) ),
				'template'    => $theme->get_template(),
				'parent'      => $theme->parent() ? $theme->parent()->get( 'Name' ) : '',
				'active'      => $active->get_stylesheet() === (string) $stylesheet,
			);
		}

		return array(
			'items' => $items,
			'total' => count( $items ),
		);
	}

	private function statuses_from_args( array $args, array $default ): array {
		$statuses = $args['status'] ?? $default;
		$statuses = is_array( $statuses ) ? $statuses : array( $statuses );
		$allowed  = get_post_stati( array(), 'names' );

		$statuses = array_values(
			array_intersect(
				array_map( 'sanitize_key', array_map( 'strval', $statuses ) ),
				array_values( $allowed )
			)
		);

		return array() === $statuses ? $default : $statuses;
	}

	private function writable_status( string $status ): string {
		$status = sanitize_key( $status );
		return in_array( $status, self::WRITABLE_POST_STATUSES, true ) ? $status : 'draft';
	}

	private function term_payload( array $data, \WP_Taxonomy $taxonomy ): array {
		$payload = array();

		if ( array_key_exists( 'name', $data ) ) {
			$payload['name'] = sanitize_text_field( (string) $data['name'] );
		}
		if ( array_key_exists( 'slug', $data ) ) {
			$payload['slug'] = sanitize_title( (string) $data['slug'] );
		}
		if ( array_key_exists( 'description', $data ) ) {
			$payload['description'] = wp_kses_post( (string) $data['description'] );
		}
		if ( $taxonomy->hierarchical && array_key_exists( 'parent', $data ) ) {
			$payload['parent'] = absint( $data['parent'] );
		}

		return $payload;
	}

	private function can_read_post_type( \WP_Post_Type $post_type ): bool {
		return current_user_can( $post_type->cap->edit_posts ) || ( $post_type->public && current_user_can( 'read' ) );
	}

	private function can_create_post_type( \WP_Post_Type $post_type ): bool {
		$capability = property_exists( $post_type->cap, 'create_posts' ) ? $post_type->cap->create_posts : $post_type->cap->edit_posts;
		return current_user_can( $capability );
	}

	private function is_supported_post_type( mixed $post_type ): bool {
		if ( ! $post_type instanceof \WP_Post_Type ) {
			return false;
		}

		if ( in_array( $post_type->name, array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request' ), true ) ) {
			return false;
		}

		return (bool) $post_type->public || (bool) $post_type->show_ui || (bool) $post_type->show_in_rest;
	}

	private function is_supported_taxonomy( mixed $taxonomy ): bool {
		if ( ! $taxonomy instanceof \WP_Taxonomy ) {
			return false;
		}

		if ( in_array( $taxonomy->name, array( 'nav_menu', 'link_category', 'post_format' ), true ) ) {
			return false;
		}

		return (bool) $taxonomy->public || (bool) $taxonomy->show_ui || (bool) $taxonomy->show_in_rest;
	}

	private function can_read_comment( \WP_Comment $comment ): bool {
		return current_user_can( 'moderate_comments' )
			|| current_user_can( 'edit_comment', (int) $comment->comment_ID )
			|| current_user_can( 'edit_post', (int) $comment->comment_post_ID );
	}

	private function comment_status( string $status, bool $allow_all ): string {
		$status  = sanitize_key( $status );
		$allowed = $allow_all ? array( 'all', 'hold', 'approve', 'spam', 'trash' ) : array( 'hold', 'approve', 'spam', 'trash' );

		return in_array( $status, $allowed, true ) ? $status : ( $allow_all ? 'all' : 'hold' );
	}

	private function is_public_http_url( string $url ): bool {
		if ( '' === $url || false === wp_http_validate_url( $url ) ) {
			return false;
		}

		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) || '' === $host ) {
			return false;
		}

		if ( in_array( strtolower( $host ), array( 'localhost', 'localhost.localdomain' ), true ) ) {
			return false;
		}

		$ips = gethostbynamel( $host );
		if ( false === $ips ) {
			$ips = array();
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$records = dns_get_record( $host, DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( isset( $record['ipv6'] ) ) {
						$ips[] = (string) $record['ipv6'];
					}
				}
			}
		}

		if ( array() === $ips ) {
			return false;
		}

		foreach ( array_unique( $ips ) as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}

		return true;
	}

	private function map_post( \WP_Post $post ): array {
		$item = array(
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
			'link'         => get_permalink( $post ),
		);

		if ( 'attachment' === $post->post_type ) {
			$item['mime_type']  = $post->post_mime_type;
			$item['source_url'] = wp_get_attachment_url( $post->ID );
			$item['alt_text']   = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
		}

		return $item;
	}

	private function map_term( \WP_Term $term ): array {
		return array(
			'id'          => (int) $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
		);
	}

	private function map_comment( \WP_Comment $comment ): array {
		return array(
			'id'          => (int) $comment->comment_ID,
			'post_id'     => (int) $comment->comment_post_ID,
			'author_name' => $comment->comment_author,
			'author_url'  => esc_url_raw( $comment->comment_author_url ),
			'user_id'     => (int) $comment->user_id,
			'content'     => $comment->comment_content,
			'status'      => wp_get_comment_status( $comment ),
			'type'        => $comment->comment_type,
			'parent'      => (int) $comment->comment_parent,
			'date_gmt'    => $comment->comment_date_gmt,
			'karma'       => (int) $comment->comment_karma,
			'link'        => get_comment_link( $comment ),
		);
	}

	private function empty_collection( int $page, int $per_page ): array {
		return array(
			'items'    => array(),
			'total'    => 0,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	private function error( string $code, string $message ): array {
		return array(
			'error'   => $code,
			'message' => $message,
		);
	}
}

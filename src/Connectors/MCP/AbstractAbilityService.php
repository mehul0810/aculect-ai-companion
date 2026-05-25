<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Shared helpers for MCP ability service classes.
 */
abstract class AbstractAbilityService {

	protected const DEFAULT_POST_STATUSES  = array( 'publish', 'future', 'draft', 'pending', 'private' );
	protected const WRITABLE_POST_STATUSES = array( 'draft', 'pending', 'private', 'publish', 'trash' );
	protected function statuses_from_args( array $args, array $default ): array {
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

	/**
	 * Restrict writes to statuses Aculect AI Companion explicitly supports.
	 *
	 * @param string $status Requested status.
	 * @return string
	 */
	protected function writable_status( string $status ): string {
		$status = sanitize_key( $status );
		return in_array( $status, self::WRITABLE_POST_STATUSES, true ) ? $status : 'draft';
	}

	/**
	 * Build a requested featured image change from content arguments.
	 *
	 * @param array<string, mixed> $data      Content fields.
	 * @param string               $post_type Target post type.
	 * @return array<string, mixed>
	 */
	protected function featured_media_change( array $data, string $post_type ): array {
		$has_featured_media = array_key_exists( 'featured_media', $data );
		$should_clear       = ! empty( $data['clear_featured_media'] );

		if ( $has_featured_media && $should_clear ) {
			return $this->error( 'invalid_featured_media', 'Provide either featured_media or clear_featured_media, not both.' );
		}

		if ( ! $has_featured_media && ! $should_clear ) {
			return array();
		}

		if ( ! post_type_supports( $post_type, 'thumbnail' ) ) {
			return $this->error( 'unsupported_featured_media', 'This post type does not support featured images.' );
		}

		if ( $should_clear ) {
			return array( 'value' => 0 );
		}

		$featured_media = $this->validated_featured_media_id( $data['featured_media'] );
		if ( is_array( $featured_media ) ) {
			return $featured_media;
		}

		return array( 'value' => $featured_media );
	}

	/**
	 * Validate an existing image attachment ID for featured image assignment.
	 *
	 * @param mixed $value Raw featured media value.
	 * @return int|array<string, mixed>
	 */
	protected function validated_featured_media_id( mixed $value ): int|array {
		$attachment_id = absint( $value );
		if ( 0 >= $attachment_id ) {
			return $this->error( 'invalid_featured_media', 'featured_media must be an existing image attachment ID.' );
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return $this->error( 'invalid_featured_media', 'Featured media must be an existing attachment.' );
		}

		if ( ! current_user_can( 'read_post', $attachment_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to use this media item.' );
		}

		if ( function_exists( 'wp_attachment_is_image' ) && ! wp_attachment_is_image( $attachment_id ) ) {
			return $this->error( 'invalid_featured_media', 'Featured media must be an image attachment.' );
		}

		return $attachment_id;
	}

	/**
	 * Build a sanitized term payload for insert/update calls.
	 *
	 * @param array<string, mixed> $data     Term fields.
	 * @param \WP_Taxonomy         $taxonomy Taxonomy object.
	 * @return array<string, mixed>
	 */
	protected function term_payload( array $data, \WP_Taxonomy $taxonomy ): array {
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

	/**
	 * Resolve requested taxonomy assignments for a post type.
	 *
	 * @param array<string, mixed> $data      Tool arguments.
	 * @param string               $post_type Post type slug.
	 * @return array<string, list<int>>|array{error: array<string, mixed>}
	 */
	protected function taxonomy_assignments( array $data, string $post_type ): array {
		if ( ! array_key_exists( 'taxonomies', $data ) ) {
			return array();
		}

		if ( ! is_array( $data['taxonomies'] ) ) {
			return array( 'error' => $this->error( 'invalid_taxonomies', 'Taxonomies must be provided as an object keyed by taxonomy slug.' ) );
		}

		$assignments = array();
		foreach ( $data['taxonomies'] as $taxonomy_name => $terms ) {
			$taxonomy_name = sanitize_key( (string) $taxonomy_name );
			$taxonomy      = get_taxonomy( $taxonomy_name );

			if ( ! $this->is_supported_taxonomy( $taxonomy ) ) {
				return array( 'error' => $this->error( 'invalid_taxonomy', 'Taxonomy is not available through Aculect AI Companion.' ) );
			}

			if ( ! is_object_in_taxonomy( $post_type, $taxonomy_name ) ) {
				return array( 'error' => $this->error( 'invalid_taxonomy', 'Taxonomy is not assigned to this post type.' ) );
			}

			if ( ! current_user_can( $taxonomy->cap->assign_terms ) ) {
				return array( 'error' => $this->error( 'forbidden', 'You do not have permission to assign terms in this taxonomy.' ) );
			}

			$resolved = $this->resolve_taxonomy_terms( $taxonomy_name, $terms );
			if ( isset( $resolved['error'] ) ) {
				return $resolved;
			}

			$assignments[ $taxonomy_name ] = $resolved;
		}

		ksort( $assignments );

		return $assignments;
	}

	/**
	 * Resolve existing term IDs or slugs for a taxonomy.
	 *
	 * @param string $taxonomy_name Taxonomy slug.
	 * @param mixed  $terms         Requested term IDs or slugs.
	 * @return list<int>|array{error: array<string, mixed>}
	 */
	protected function resolve_taxonomy_terms( string $taxonomy_name, mixed $terms ): array {
		if ( is_array( $terms ) ) {
			$candidates = array_values( $terms );
		} elseif ( is_int( $terms ) || is_string( $terms ) ) {
			$candidates = array( $terms );
		} else {
			return array( 'error' => $this->error( 'invalid_terms', 'Taxonomy terms must be existing term IDs or slugs.' ) );
		}

		$term_ids = array();
		foreach ( $candidates as $candidate ) {
			$term = null;
			if ( is_int( $candidate ) ) {
				$term = get_term( absint( $candidate ), $taxonomy_name );
			} elseif ( is_string( $candidate ) ) {
				$slug = sanitize_title( $candidate );
				$term = '' === $slug ? null : get_term_by( 'slug', $slug, $taxonomy_name );
			} else {
				return array( 'error' => $this->error( 'invalid_terms', 'Taxonomy terms must be existing term IDs or slugs.' ) );
			}

			if ( ! $term instanceof \WP_Term || $taxonomy_name !== $term->taxonomy ) {
				return array( 'error' => $this->error( 'term_not_found', 'One or more requested taxonomy terms could not be found.' ) );
			}

			$term_ids[] = (int) $term->term_id;
		}

		$term_ids = array_values( array_unique( $term_ids ) );
		sort( $term_ids );

		return $term_ids;
	}

	/**
	 * Apply resolved taxonomy assignments to a content item.
	 *
	 * @param int                      $post_id     Post ID.
	 * @param array<string, list<int>> $assignments Resolved assignments.
	 * @return array<string, mixed>
	 */
	protected function apply_taxonomy_assignments( int $post_id, array $assignments ): array {
		foreach ( $assignments as $taxonomy_name => $term_ids ) {
			$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy_name, false );
			if ( is_wp_error( $result ) ) {
				return array( 'error' => $this->error( $result->get_error_code(), $result->get_error_message() ) );
			}
		}

		return array( 'success' => true );
	}

	/**
	 * Convert taxonomy assignments into preview changes.
	 *
	 * @param array<string, list<int>> $assignments Resolved assignments.
	 * @param int                      $post_id     Existing post ID, or 0 for create.
	 * @return list<array<string, mixed>>
	 */
	protected function taxonomy_assignment_changes( array $assignments, int $post_id = 0 ): array {
		$changes = array();

		foreach ( $assignments as $taxonomy_name => $term_ids ) {
			$current = array();
			if ( $post_id > 0 ) {
				$current_terms = wp_get_object_terms(
					$post_id,
					$taxonomy_name,
					array(
						'fields'  => 'ids',
						'orderby' => 'term_id',
						'order'   => 'ASC',
					)
				);
				$current       = is_wp_error( $current_terms ) ? array() : array_values( array_map( 'intval', $current_terms ) );
				sort( $current );
			}

			$changes[] = $this->change( 'taxonomies.' . $taxonomy_name, $current, $term_ids );
		}

		return array_values( array_filter( $changes ) );
	}

	/**
	 * Check whether the current user can read a post type.
	 *
	 * @param \WP_Post_Type $post_type Post type object.
	 * @return bool
	 */
	protected function can_read_post_type( \WP_Post_Type $post_type ): bool {
		return current_user_can( $post_type->cap->edit_posts ) || ( $post_type->public && current_user_can( 'read' ) );
	}

	/**
	 * Check whether the current user can create posts for a post type.
	 *
	 * @param \WP_Post_Type $post_type Post type object.
	 * @return bool
	 */
	protected function can_create_post_type( \WP_Post_Type $post_type ): bool {
		$capability = property_exists( $post_type->cap, 'create_posts' ) ? $post_type->cap->create_posts : $post_type->cap->edit_posts;
		return current_user_can( $capability );
	}

	/**
	 * Validate whether the connected user can assign a content author.
	 *
	 * @param int           $author_id      Target author user ID.
	 * @param \WP_Post_Type $post_type      Post type object.
	 * @param int           $current_author Existing author ID for updates.
	 * @return array<string, mixed>
	 */
	protected function author_assignment_error( int $author_id, \WP_Post_Type $post_type, int $current_author = 0 ): array {
		if ( $author_id <= 0 || ! get_userdata( $author_id ) ) {
			return $this->error( 'invalid_author', 'Author user not found.' );
		}

		if ( ! user_can( $author_id, $post_type->cap->edit_posts ) ) {
			return $this->error( 'invalid_author', 'Author cannot own this post type.' );
		}

		$current_user_id       = get_current_user_id();
		$is_unchanged_author   = $current_author > 0 && $author_id === $current_author;
		$is_current_user       = $author_id === $current_user_id;
		$requires_others_posts = ! $is_current_user && ! $is_unchanged_author;

		if ( $requires_others_posts && ! current_user_can( $post_type->cap->edit_others_posts ) ) {
			return $this->error( 'forbidden', 'You do not have permission to assign this author.' );
		}

		return array();
	}

	/**
	 * Check whether a post type is safe to expose through MCP.
	 *
	 * @param mixed $post_type Candidate post type object.
	 * @return bool
	 */
	protected function is_supported_post_type( mixed $post_type ): bool {
		if ( ! $post_type instanceof \WP_Post_Type ) {
			return false;
		}

		if ( in_array( $post_type->name, array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request' ), true ) ) {
			return false;
		}

		return (bool) $post_type->public || (bool) $post_type->show_ui || (bool) $post_type->show_in_rest;
	}

	/**
	 * Check whether a taxonomy is safe to expose through MCP.
	 *
	 * @param mixed $taxonomy Candidate taxonomy object.
	 * @return bool
	 */
	protected function is_supported_taxonomy( mixed $taxonomy ): bool {
		if ( ! $taxonomy instanceof \WP_Taxonomy ) {
			return false;
		}

		if ( in_array( $taxonomy->name, array( 'nav_menu', 'link_category', 'post_format' ), true ) ) {
			return false;
		}

		return (bool) $taxonomy->public || (bool) $taxonomy->show_ui || (bool) $taxonomy->show_in_rest;
	}

	/**
	 * Check whether the current user can read a comment.
	 *
	 * @param \WP_Comment $comment Comment object.
	 * @return bool
	 */
	protected function can_read_comment( \WP_Comment $comment ): bool {
		return current_user_can( 'moderate_comments' )
			|| current_user_can( 'edit_comment', (int) $comment->comment_ID )
			|| current_user_can( 'edit_post', (int) $comment->comment_post_ID );
	}

	/**
	 * Normalize comment status arguments.
	 *
	 * @param string $status    Requested status.
	 * @param bool   $allow_all Whether the "all" status is allowed.
	 * @return string
	 */
	protected function comment_status( string $status, bool $allow_all ): string {
		$status = sanitize_key( $status );
		$status = match ( $status ) {
			'pending', 'unapproved', 'unapprove' => 'hold',
			'approved' => 'approve',
			default => $status,
		};
		$allowed = $allow_all ? array( 'all', 'hold', 'approve', 'spam', 'trash' ) : array( 'hold', 'approve', 'spam', 'trash' );

		return in_array( $status, $allowed, true ) ? $status : ( $allow_all ? 'all' : 'hold' );
	}

	/**
	 * Normalize comment date filters for WP_Comment_Query.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return list<array<string, mixed>>
	 */
	protected function comment_date_query( array $args ): array {
		$date_query = array();

		if ( ! empty( $args['date_after'] ) ) {
			$date_query['after'] = sanitize_text_field( (string) $args['date_after'] );
		}

		if ( ! empty( $args['date_before'] ) ) {
			$date_query['before'] = sanitize_text_field( (string) $args['date_before'] );
		}

		if ( array() === $date_query ) {
			return array();
		}

		$date_query['inclusive'] = true;

		return array( $date_query );
	}

	/**
	 * Normalize a bounded list of comment IDs for bulk operations.
	 *
	 * @param mixed $ids Candidate comment IDs.
	 * @return list<int>
	 */
	protected function comment_ids( mixed $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		sort( $ids );

		return array_slice( $ids, 0, 100 );
	}

	/**
	 * Validate that a remote media URL resolves to public HTTP(S) addresses.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	protected function is_public_http_url( string $url ): bool {
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

	/**
	 * Map a post object into deterministic MCP output.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	protected function map_post( \WP_Post $post ): array {
		$author = get_userdata( (int) $post->post_author );

		$item = array(
			'id'                  => (int) $post->ID,
			'type'                => $post->post_type,
			'title'               => get_the_title( $post ),
			'slug'                => $post->post_name,
			'status'              => $post->post_status,
			'content'             => $post->post_content,
			'excerpt'             => $post->post_excerpt,
			'author'              => (int) $post->post_author,
			'author_display_name' => $author instanceof \WP_User ? $author->display_name : '',
			'featured_media'      => (int) get_post_thumbnail_id( $post ),
			'date_gmt'            => $post->post_date_gmt,
			'modified_gmt'        => $post->post_modified_gmt,
			'link'                => get_permalink( $post ),
			'terms'               => $this->post_terms( $post ),
		);

		if ( 'attachment' === $post->post_type ) {
			$item['mime_type']  = $post->post_mime_type;
			$item['source_url'] = wp_get_attachment_url( $post->ID );
			$item['alt_text']   = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
		}

		return $item;
	}

	/**
	 * Return assigned terms grouped by supported taxonomy.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, list<array<string, mixed>>>
	 */
	protected function post_terms( \WP_Post $post ): array {
		$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );
		$taxonomies = array_filter( $taxonomies, array( $this, 'is_supported_taxonomy' ) );
		if ( array() === $taxonomies ) {
			return array();
		}

		$taxonomy_names = array_values(
			array_map(
				static fn( \WP_Taxonomy $taxonomy ): string => $taxonomy->name,
				$taxonomies
			)
		);
		sort( $taxonomy_names );

		$terms = wp_get_object_terms(
			$post->ID,
			$taxonomy_names,
			array(
				'orderby' => 'term_id',
				'order'   => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$grouped = array_fill_keys( $taxonomy_names, array() );
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term && isset( $grouped[ $term->taxonomy ] ) ) {
				$grouped[ $term->taxonomy ][] = $this->map_term( $term );
			}
		}

		return $grouped;
	}

	/**
	 * Map a term object into deterministic MCP output.
	 *
	 * @param \WP_Term $term Term object.
	 * @return array<string, mixed>
	 */
	protected function map_term( \WP_Term $term ): array {
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

	/**
	 * Map a comment object into deterministic MCP output.
	 *
	 * @param \WP_Comment $comment Comment object.
	 * @return array<string, mixed>
	 */
	protected function map_comment( \WP_Comment $comment ): array {
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

	/**
	 * Return a consistent empty paginated collection.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Items per page.
	 * @return array<string, mixed>
	 */
	protected function empty_collection( int $page, int $per_page ): array {
		return array(
			'items'    => array(),
			'total'    => 0,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Determine whether a tool call should only preview changes.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 */
	protected function is_dry_run( array $data ): bool {
		return ( new ToolSafety() )->is_dry_run( $data );
	}

	/**
	 * Build a deterministic dry-run response.
	 *
	 * @param string               $action   Tool action.
	 * @param array<string, mixed> $args     Tool arguments.
	 * @param array<string, mixed> $target   Target object summary.
	 * @param array<int, mixed>    $changes  Proposed changes.
	 * @param string[]             $warnings Preview warnings.
	 * @return array<string, mixed>
	 */
	protected function preview_response( string $action, array $args, array $target, array $changes, array $warnings = array() ): array {
		$safety = new ToolSafety();

		return array(
			'dry_run'               => true,
			'status'                => 'preview',
			'action'                => $action,
			'risk_level'            => $safety->risk_level( $action, $args ),
			'target'                => $target,
			'changes'               => array_values( array_filter( $changes ) ),
			'warnings'              => array_values( $warnings ),
			'confirmation_required' => $safety->requires_confirmation( $action, $args ),
		);
	}

	/**
	 * Build one field-change entry.
	 *
	 * @param string $field Field name.
	 * @param mixed  $from  Existing value.
	 * @param mixed  $to    Proposed value.
	 * @return array<string, mixed>|null
	 */
	protected function change( string $field, mixed $from, mixed $to ): ?array {
		if ( $from === $to ) {
			return null;
		}

		return array(
			'field' => $field,
			'from'  => $from,
			'to'    => $to,
		);
	}

	/**
	 * Convert a post insert/update payload into preview changes.
	 *
	 * @param array<string, mixed> $from    Current post fields.
	 * @param array<string, mixed> $payload Proposed post payload.
	 * @return list<array<string, mixed>>
	 */
	protected function post_payload_changes( array $from, array $payload ): array {
		$map     = array(
			'post_type'    => 'type',
			'post_title'   => 'title',
			'post_content' => 'content',
			'post_excerpt' => 'excerpt',
			'post_name'    => 'slug',
			'post_status'  => 'status',
			'post_author'  => 'author',
		);
		$changes = array();

		foreach ( $map as $payload_key => $field ) {
			if ( array_key_exists( $payload_key, $payload ) ) {
				$changes[] = $this->change( $field, $from[ $payload_key ] ?? null, $payload[ $payload_key ] );
			}
		}

		return array_values( array_filter( $changes ) );
	}

	/**
	 * Convert a term insert/update payload into preview changes.
	 *
	 * @param array<string, mixed> $from    Current term fields.
	 * @param array<string, mixed> $payload Proposed term payload.
	 * @return list<array<string, mixed>>
	 */
	protected function term_payload_changes( array $from, array $payload ): array {
		$changes = array();

		foreach ( array( 'name', 'slug', 'description', 'parent' ) as $field ) {
			if ( array_key_exists( $field, $payload ) ) {
				$changes[] = $this->change( $field, $from[ $field ] ?? null, $payload[ $field ] );
			}
		}

		return array_values( array_filter( $changes ) );
	}

	/**
	 * Convert media upload arguments into preview changes.
	 *
	 * @param string               $url      Remote URL.
	 * @param string               $filename Proposed filename.
	 * @param array<string, mixed> $data     Tool arguments.
	 * @return list<array<string, mixed>>
	 */
	protected function media_payload_changes( string $url, string $filename, array $data ): array {
		$changes = array(
			$this->change( 'source_url', null, $url ),
			$this->change( 'filename', null, sanitize_file_name( $filename ) ),
		);

		foreach (
			array(
				'title'       => 'title',
				'alt_text'    => 'alt_text',
				'caption'     => 'caption',
				'description' => 'description',
				'post_id'     => 'post_id',
			) as $argument => $field
		) {
			if ( array_key_exists( $argument, $data ) ) {
				$value     = 'post_id' === $argument ? absint( $data[ $argument ] ) : sanitize_text_field( (string) $data[ $argument ] );
				$changes[] = $this->change( $field, null, $value );
			}
		}

		return array_values( array_filter( $changes ) );
	}

	/**
	 * Convert media update payload into preview changes.
	 *
	 * @param \WP_Post             $attachment Existing attachment.
	 * @param array<string, mixed> $payload    Proposed update payload.
	 * @return list<array<string, mixed>>
	 */
	protected function media_update_changes( \WP_Post $attachment, array $payload ): array {
		$from = array(
			'post_title'   => $attachment->post_title,
			'post_excerpt' => $attachment->post_excerpt,
			'post_content' => $attachment->post_content,
			'post_name'    => $attachment->post_name,
			'post_parent'  => (int) $attachment->post_parent,
		);
		$map  = array(
			'post_title'   => 'title',
			'post_excerpt' => 'caption',
			'post_content' => 'description',
			'post_name'    => 'slug',
			'post_parent'  => 'post_id',
		);

		$changes = array();
		foreach ( $map as $payload_key => $field ) {
			if ( array_key_exists( $payload_key, $payload ) ) {
				$changes[] = $this->change( $field, $from[ $payload_key ], $payload[ $payload_key ] );
			}
		}

		return array_values( array_filter( $changes ) );
	}

	/**
	 * Return a structured tool error payload.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Human-readable message.
	 * @return array<string, string>
	 */
	protected function error( string $code, string $message ): array {
		return array(
			'error'   => $code,
			'message' => $message,
		);
	}
}

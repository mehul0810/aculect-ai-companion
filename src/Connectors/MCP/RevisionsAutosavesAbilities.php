<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Read-only discovery for WordPress revisions and autosaves.
 */
final class RevisionsAutosavesAbilities extends AbstractAbilityService {
	private const MAX_PER_PAGE        = 50;
	private const DEFAULT_PER_PAGE    = 20;
	private const MAX_PREVIEW_CHARS   = 500;
	private const DEFAULT_PREVIEW_LEN = 200;

	/**
	 * List bounded revision metadata for an editable content item.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_revisions( array $args ): array {
		$post_id   = absint( $args['post_id'] ?? $args['id'] ?? 0 );
		$parent    = $this->editable_supported_parent( $post_id );
		$per_page  = max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$context   = $this->preview_context( $args );
		$preview   = $this->should_include_preview( $args );
		$max_chars = $this->preview_chars( $args );

		if ( isset( $parent['error'] ) ) {
			return $parent;
		}

		/**
		 * Editable parent post.
		 *
		 * @var \WP_Post $post
		 */
		$post = $parent['post'];

		$revisions = array_values(
			array_filter(
				$this->post_revisions( $post_id ),
				fn ( \WP_Post $revision ): bool => ! $this->is_autosave( $revision )
			)
		);

		usort(
			$revisions,
			static fn ( \WP_Post $a, \WP_Post $b ): int => strcmp( $b->post_modified_gmt, $a->post_modified_gmt )
		);

		$total = count( $revisions );
		$items = array_slice( $revisions, ( $page - 1 ) * $per_page, $per_page );

		return array(
			'post_id'      => $post_id,
			'parent'       => $this->parent_summary( $post ),
			'items'        => array_map(
				fn ( \WP_Post $revision ): array => $this->map_revision( $revision, $context, $preview, $max_chars ),
				$items
			),
			'total'        => $total,
			'page'         => $page,
			'per_page'     => $per_page,
			'has_more'     => $page * $per_page < $total,
			'read_only'    => true,
			'preview'      => array(
				'included'  => $preview,
				'max_chars' => $preview ? $max_chars : 0,
			),
			'capabilities' => array(
				'can_read_parent' => current_user_can( 'read_post', $post_id ),
				'can_edit_parent' => true,
				'can_restore'     => false,
				'can_delete'      => false,
			),
		);
	}

	/**
	 * Inspect current-user autosave availability for an editable content item.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function inspect_autosaves( array $args ): array {
		$post_id   = absint( $args['post_id'] ?? $args['id'] ?? 0 );
		$parent    = $this->editable_supported_parent( $post_id );
		$context   = $this->preview_context( $args );
		$preview   = $this->should_include_preview( $args );
		$max_chars = $this->preview_chars( $args );

		if ( isset( $parent['error'] ) ) {
			return $parent;
		}

		/**
		 * Editable parent post.
		 *
		 * @var \WP_Post $post
		 */
		$post = $parent['post'];

		$autosave = function_exists( 'wp_get_post_autosave' ) ? wp_get_post_autosave( $post_id, get_current_user_id() ) : false;

		return array(
			'post_id'      => $post_id,
			'parent'       => $this->parent_summary( $post ),
			'has_autosave' => $autosave instanceof \WP_Post,
			'autosave'     => $autosave instanceof \WP_Post ? $this->map_revision( $autosave, $context, $preview, $max_chars ) : null,
			'read_only'    => true,
			'preview'      => array(
				'included'  => $preview,
				'max_chars' => $preview ? $max_chars : 0,
			),
			'capabilities' => array(
				'can_read_parent' => current_user_can( 'read_post', $post_id ),
				'can_edit_parent' => true,
				'can_restore'     => false,
				'can_delete'      => false,
			),
		);
	}

	/**
	 * Return an editable, supported parent post or a structured error.
	 *
	 * @param int $post_id Parent post ID.
	 * @return array{post:\WP_Post}|array<string, mixed>
	 */
	private function editable_supported_parent( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return $this->error( 'not_found', 'Content item not found.' );
		}

		$post_type = get_post_type_object( $post->post_type );
		if ( ! $post_type instanceof \WP_Post_Type || ! $this->is_supported_post_type( $post_type ) ) {
			return $this->error( 'unsupported_post_type', 'This post type is not available through Aculect AI Companion.' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect revisions or autosaves for this content item.' );
		}

		return array( 'post' => $post );
	}

	/**
	 * Return revisions for a parent post using WordPress core APIs.
	 *
	 * @param int $post_id Parent post ID.
	 * @return list<\WP_Post>
	 */
	private function post_revisions( int $post_id ): array {
		$revisions = function_exists( 'wp_get_post_revisions' )
			? wp_get_post_revisions( $post_id )
			: ( function_exists( 'get_post_revisions' ) ? get_post_revisions( $post_id ) : array() );

		if ( ! is_array( $revisions ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$revisions,
				static fn ( $revision ): bool => $revision instanceof \WP_Post
			)
		);
	}

	/**
	 * Check whether a revision object is an autosave.
	 *
	 * @param \WP_Post $revision Revision post.
	 */
	private function is_autosave( \WP_Post $revision ): bool {
		return function_exists( 'wp_is_post_autosave' ) && false !== wp_is_post_autosave( $revision );
	}

	/**
	 * Map a parent post into safe metadata.
	 *
	 * @param \WP_Post $post Parent post.
	 * @return array<string, mixed>
	 */
	private function parent_summary( \WP_Post $post ): array {
		return array(
			'id'           => (int) $post->ID,
			'type'         => $post->post_type,
			'title'        => get_the_title( $post ),
			'status'       => $post->post_status,
			'modified_gmt' => $post->post_modified_gmt,
		);
	}

	/**
	 * Map a revision or autosave object into bounded metadata.
	 *
	 * @param \WP_Post $revision Revision or autosave post.
	 * @param string   $context Compact or full metadata context.
	 * @param bool     $include_preview Whether a bounded content preview was explicitly requested.
	 * @param int      $max_chars Maximum preview characters.
	 * @return array<string, mixed>
	 */
	private function map_revision( \WP_Post $revision, string $context, bool $include_preview, int $max_chars ): array {
		$author = get_userdata( (int) $revision->post_author );
		$item   = array(
			'id'              => (int) $revision->ID,
			'parent_id'       => (int) $revision->post_parent,
			'type'            => $this->is_autosave( $revision ) ? 'autosave' : 'revision',
			'status'          => $revision->post_status,
			'title'           => sanitize_text_field( get_the_title( $revision ) ),
			'excerpt_summary' => $this->text_summary( $revision->post_excerpt, 160 ),
			'author'          => array(
				'id'           => (int) $revision->post_author,
				'display_name' => is_object( $author ) && isset( $author->display_name ) ? sanitize_text_field( (string) $author->display_name ) : '',
			),
			'modified_gmt'    => $revision->post_modified_gmt,
			'date_gmt'        => $revision->post_date_gmt,
			'comparison'      => array(
				'available'         => true,
				'fields'            => array( 'title', 'excerpt', 'content' ),
				'preview_available' => $include_preview,
				'body_included'     => false,
				'restore_supported' => false,
			),
		);

		if ( 'full' === $context || $include_preview ) {
			$item['content_summary'] = $this->text_summary( $revision->post_content, 220 );
		}

		if ( $include_preview ) {
			$item['content_preview'] = $this->bounded_preview( $revision->post_content, $max_chars );
		}

		return $item;
	}

	/**
	 * Return compact text summary from possibly-block HTML.
	 *
	 * @param string $text Text or markup.
	 * @param int    $max_chars Maximum returned characters.
	 */
	private function text_summary( string $text, int $max_chars ): string {
		return $this->bounded_preview( $text, $max_chars );
	}

	/**
	 * Return a bounded plain-text preview.
	 *
	 * @param string $text Text or markup.
	 * @param int    $max_chars Maximum returned characters.
	 */
	private function bounded_preview( string $text, int $max_chars ): string {
		$plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) ?? '' );

		if ( strlen( $plain ) <= $max_chars ) {
			return $plain;
		}

		return rtrim( substr( $plain, 0, $max_chars ) ) . '...';
	}

	/**
	 * Resolve preview context.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function preview_context( array $args ): string {
		return 'full' === (string) ( $args['context'] ?? 'compact' ) ? 'full' : 'compact';
	}

	/**
	 * Check whether bounded content preview was explicitly requested.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function should_include_preview( array $args ): bool {
		return true === ( $args['include_preview'] ?? false );
	}

	/**
	 * Resolve the bounded preview character limit.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function preview_chars( array $args ): int {
		return max( 1, min( self::MAX_PREVIEW_CHARS, (int) ( $args['preview_chars'] ?? self::DEFAULT_PREVIEW_LEN ) ) );
	}
}

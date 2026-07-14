<?php
/**
 * Editor-side internal link suggestions.
 *
 * @package Aculect\AICompanion\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Connectors\MCP\IntelligenceIndexAbilities;
use Aculect\AICompanion\Intelligence\ContentIndexRepository;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the read-only editor internal-link suggestion panel.
 */
final class EditorInternalLinkSuggestions {

	private const SCRIPT_HANDLE = 'aculect-ai-companion-editor-internal-link-suggestions';
	private const STYLE_HANDLE  = 'aculect-ai-companion-editor-internal-link-suggestions';
	private const ROUTE         = '/editor/internal-link-suggestions';
	private const MAX_ITEMS     = 6;

	/**
	 * Register editor hooks.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'aculect-ai-companion/v1',
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_payload' ),
				'permission_callback' => array( $this, 'can_read_payload' ),
				'args'                => array(
					'post_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( mixed $value ): bool => absint( $value ) > 0,
					),
				),
			)
		);
	}

	/**
	 * Check whether the current user can read this editor payload.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function can_read_payload( WP_REST_Request $request ): bool {
		$post_id = absint( $request->get_param( 'post_id' ) );

		return $this->can_use_editor_surface( $post_id );
	}

	/**
	 * Return the current post's internal-link suggestion payload.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function rest_payload( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( $this->payload_for_post( absint( $request->get_param( 'post_id' ) ) ) );
	}

	/**
	 * Enqueue the block editor panel assets.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_supported_editor_screen() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/js/editor-internal-link-suggestions.js',
			array( 'wp-api-fetch', 'wp-components', 'wp-data', 'wp-edit-post', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			ACULECT_AI_COMPANION_VERSION,
			true
		);
		wp_enqueue_style(
			self::STYLE_HANDLE,
			ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/css/editor-internal-link-suggestions.css',
			array( 'wp-components' ),
			ACULECT_AI_COMPANION_VERSION
		);
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'aculectAICompanionEditorLinks',
			array(
				'restUrl' => esc_url_raw( rest_url( 'aculect-ai-companion/v1' . self::ROUTE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Build a bounded read-only payload for the current editor item.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public function payload_for_post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return $this->base_payload( $post_id, 'unsupported', 'Post not found.' );
		}

		if ( ! $this->can_use_editor_surface( $post_id ) ) {
			return $this->base_payload( $post_id, 'forbidden', 'You do not have permission to view internal-link suggestions for this content.' );
		}

		$repository = new ContentIndexRepository();
		$source     = $repository->content_item( $post_id );
		$summary    = $repository->summary();

		$payload           = $this->base_payload( $post_id, 'ready', '' );
		$payload['source'] = $this->source_payload( $post, $source );
		$payload['index']  = $summary;

		if ( 0 >= (int) ( $summary['total_items'] ?? 0 ) ) {
			$payload['status']  = 'empty_index';
			$payload['message'] = 'The content intelligence index is empty. Run an index refresh before relying on editor suggestions.';
			return $payload;
		}

		if ( array() === $source ) {
			$payload['status']  = 'missing_source_index';
			$payload['message'] = 'This content is not indexed yet. Save or refresh the content intelligence index before relying on suggestions.';
			return $payload;
		}

		$result = ( new IntelligenceIndexAbilities() )->find_internal_links(
			array(
				'source_id' => $post_id,
				'limit'     => self::MAX_ITEMS,
				'status'    => 'publish',
			)
		);

		if ( isset( $result['status'] ) && 'error' === $result['status'] ) {
			$payload['status']  = sanitize_key( (string) ( $result['error'] ?? 'suggestions_unavailable' ) );
			$payload['message'] = (string) ( $result['message'] ?? 'Internal-link suggestions are unavailable for this content.' );
			return $payload;
		}

		$payload['items']              = $this->suggestion_items( (array) ( $result['items'] ?? array() ) );
		$payload['alreadyLinkedItems'] = $this->already_linked_items( $repository, $post_id );
		$payload['qualitySummary']     = (array) ( $result['quality_summary'] ?? array() );
		$payload['policy']             = (array) ( $result['policy'] ?? array() );
		$payload['apply']              = $this->apply_state();
		$payload['message']            = $this->status_message( $payload );

		if ( array() === $payload['items'] && array() === $payload['alreadyLinkedItems'] && empty( $payload['source']['stale'] ) ) {
			$payload['status'] = 'no_suggestions';
		}

		return $payload;
	}

	/**
	 * Return whether an editor screen should receive the panel assets.
	 */
	private function is_supported_editor_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return true;
		}

		$screen    = get_current_screen();
		$post_type = is_object( $screen ) && property_exists( $screen, 'post_type' ) ? (string) $screen->post_type : '';

		return '' === $post_type || $this->post_type_supports_editor( $post_type );
	}

	/**
	 * Check whether the current post can use this editor surface.
	 *
	 * @param int $post_id Post ID.
	 */
	private function can_use_editor_surface( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( ! $this->post_type_supports_editor( (string) $post->post_type ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id ) && current_user_can( 'read_post', $post_id );
	}

	/**
	 * Check whether a post type is editor-visible and content-backed.
	 *
	 * @param string $post_type Post type.
	 */
	private function post_type_supports_editor( string $post_type ): bool {
		if ( in_array( $post_type, array( '', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request' ), true ) ) {
			return false;
		}

		$type = get_post_type_object( $post_type );
		if ( ! $type || empty( $type->show_ui ) ) {
			return false;
		}

		return post_type_supports( $post_type, 'editor' );
	}

	/**
	 * Base payload shared by every state.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Payload status.
	 * @param string $message User-facing message.
	 * @return array<string, mixed>
	 */
	private function base_payload( int $post_id, string $status, string $message ): array {
		return array(
			'status'             => $status,
			'message'            => $message,
			'postId'             => absint( $post_id ),
			'readOnly'           => true,
			'items'              => array(),
			'alreadyLinkedItems' => array(),
			'index'              => array(
				'total_items'       => 0,
				'stale_items'       => 0,
				'latest_indexed_at' => '',
			),
			'source'             => array(),
			'apply'              => $this->apply_state(),
		);
	}

	/**
	 * Return source metadata for the editor panel.
	 *
	 * @param WP_Post              $post  Post object.
	 * @param array<string, mixed> $index Indexed source row.
	 * @return array<string, mixed>
	 */
	private function source_payload( WP_Post $post, array $index ): array {
		return array(
			'id'        => (int) $post->ID,
			'title'     => get_the_title( $post ),
			'postType'  => (string) $post->post_type,
			'status'    => (string) $post->post_status,
			'permalink' => esc_url_raw( (string) get_permalink( $post ) ),
			'editUrl'   => esc_url_raw( (string) get_edit_post_link( (int) $post->ID, 'raw' ) ),
			'indexed'   => array() !== $index,
			'stale'     => ! empty( $index['stale'] ),
		);
	}

	/**
	 * Normalize candidate suggestions for the editor payload.
	 *
	 * @param array<int, mixed> $items Candidate rows.
	 * @return list<array<string, mixed>>
	 */
	private function suggestion_items( array $items ): array {
		$payload = array();
		foreach ( array_slice( $items, 0, self::MAX_ITEMS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$post_id = absint( $item['post_id'] ?? 0 );
			if ( 0 >= $post_id || ! current_user_can( 'read_post', $post_id ) ) {
				continue;
			}

			$payload[] = $this->target_payload(
				$post_id,
				(string) ( $item['title'] ?? '' ),
				(string) ( $item['permalink'] ?? '' ),
				(string) ( $item['anchor_text'] ?? '' ),
				(string) ( $item['confidence'] ?? 'medium' ),
				(array) ( $item['reasons'] ?? array() ),
				(array) ( $item['warnings'] ?? array() ),
				! empty( $item['stale'] ),
				false,
				(int) ( $item['quality_score'] ?? $item['score'] ?? 0 )
			);
		}

		return $payload;
	}

	/**
	 * Return already-linked indexed targets for the current source item.
	 *
	 * @param ContentIndexRepository $repository Content index repository.
	 * @param int                    $post_id    Source post ID.
	 * @return list<array<string, mixed>>
	 */
	private function already_linked_items( ContentIndexRepository $repository, int $post_id ): array {
		$items = array();
		foreach ( array_slice( $repository->linked_target_ids( $post_id ), 0, self::MAX_ITEMS ) as $target_id ) {
			$target_id = absint( $target_id );
			if ( 0 >= $target_id || ! current_user_can( 'read_post', $target_id ) ) {
				continue;
			}

			$target = $repository->content_item( $target_id );
			if ( array() === $target ) {
				continue;
			}

			$items[] = $this->target_payload(
				$target_id,
				(string) ( $target['title'] ?? '' ),
				(string) ( $target['permalink'] ?? '' ),
				(string) ( $target['title'] ?? '' ),
				'high',
				array( 'Already linked from the current content item.' ),
				array(),
				! empty( $target['stale'] ),
				true,
				100
			);
		}

		return $items;
	}

	/**
	 * Return one normalized target row.
	 *
	 * @param int          $post_id        Target post ID.
	 * @param string       $title          Target title.
	 * @param string       $permalink      Target permalink.
	 * @param string       $anchor         Proposed anchor.
	 * @param string       $confidence     Confidence label.
	 * @param array<mixed> $reasons        Reason strings.
	 * @param array<mixed> $warnings       Warning strings.
	 * @param bool         $stale          Whether the target index row is stale.
	 * @param bool         $already_linked Whether source already links to target.
	 * @param int          $score          Quality score.
	 * @return array<string, mixed>
	 */
	private function target_payload( int $post_id, string $title, string $permalink, string $anchor, string $confidence, array $reasons, array $warnings, bool $stale, bool $already_linked, int $score ): array {
		return array(
			'postId'        => $post_id,
			'title'         => '' === $title ? sprintf( 'Post #%d', $post_id ) : $title,
			'url'           => esc_url_raw( $permalink ),
			'editUrl'       => esc_url_raw( (string) get_edit_post_link( $post_id, 'raw' ) ),
			'anchor'        => $anchor,
			'confidence'    => $confidence,
			'reason'        => implode( ' ', array_filter( array_map( 'strval', $reasons ) ) ),
			'reasons'       => array_values( array_filter( array_map( 'strval', $reasons ) ) ),
			'warnings'      => array_values( array_filter( array_map( 'sanitize_key', $warnings ) ) ),
			'stale'         => $stale,
			'alreadyLinked' => $already_linked,
			'score'         => max( 0, min( 100, $score ) ),
			'actions'       => array(
				'copyAnchor' => true,
				'openTarget' => '' !== $permalink,
				'openEdit'   => current_user_can( 'edit_post', $post_id ),
				'apply'      => false,
			),
		);
	}

	/**
	 * Return the apply workflow state for this no-write slice.
	 *
	 * @return array<string, mixed>
	 */
	private function apply_state(): array {
		return array(
			'available' => false,
			'label'     => 'Apply unavailable',
			'reason'    => 'Safe insertion is waiting on the reviewed apply workflow. Copy or open suggestions for now.',
		);
	}

	/**
	 * Return a compact state message.
	 *
	 * @param array<string, mixed> $payload Payload data.
	 */
	private function status_message( array $payload ): string {
		$source = is_array( $payload['source'] ?? null ) ? $payload['source'] : array();
		if ( ! empty( $source['stale'] ) ) {
			return 'Suggestions are available, but this content has a stale index row. Refresh before making final link decisions.';
		}

		if ( array() === (array) ( $payload['items'] ?? array() ) && array() === (array) ( $payload['alreadyLinkedItems'] ?? array() ) ) {
			return 'No internal-link suggestions are available for this content yet.';
		}

		return 'Review suggestions, copy anchors, or open targets. This panel does not write to content.';
	}
}

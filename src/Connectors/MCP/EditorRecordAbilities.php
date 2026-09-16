<?php
/**
 * Confirmed database-only Site Editor changes with native recovery points.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Owns bounded template, template-part, navigation and style-record updates. */
final class EditorRecordAbilities extends AbstractAbilityService {

	/**
	 * Discover bounded database editor IDs without creating theme overrides.
	 *
	 * @param array<string,mixed> $args Fixed type and pagination.
	 * @return array<string,mixed>
	 */
	public function list_records( array $args ): array {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error( 'forbidden', 'Site Editor permission is required.' );
		}
		$type = $args['type'] ?? null;
		$page = $args['page'] ?? 1;
		$size = $args['per_page'] ?? 20;
		if ( ! is_string( $type ) || ! in_array( $type, EditorRecordState::TYPES, true ) || ! is_int( $page ) || $page < 1 || $page > 5000 || ! is_int( $size ) || $size < 1 || $size > 50 ) {
			return $this->error( 'invalid_editor_query', 'Choose a supported type, page 1–5000 and page size 1–50.' );
		}
		try {
			$query = array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => $size + 1,
				'offset'         => ( $page - 1 ) * $size,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			);
			if ( 'wp_navigation' !== $type ) {
				$query['tax_query'] = array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => get_stylesheet(),
					),
				); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Bounded current-theme editor lookup.
			}
			$posts = get_posts( $query );
			$items = array();
			foreach ( array_slice( $posts, 0, $size ) as $post ) {
				if ( $post instanceof \WP_Post && ! isset( ( new EditorRecordState() )->load( array( 'post_id' => $post->ID ) )['error'] ) ) {
					$items[] = array(
						'post_id' => $post->ID,
						'type'    => $post->post_type,
						'title'   => $post->post_title,
						'status'  => $post->post_status,
					);
				}
			}
			return array(
				'items'                => $items,
				'page'                 => $page,
				'per_page'             => $size,
				'has_more'             => count( $posts ) > $size,
				'database_only'        => true,
				'theme_files_included' => false,
			);
		} catch ( \Throwable ) {
			return $this->error( 'editor_unavailable', 'Editor records could not be listed safely.' );
		}
	}

	/**
	 * Read editable block content or finite user-style overrides with fresh state.
	 *
	 * @param array<string,mixed> $args Target and optional revision ID.
	 * @return array<string,mixed>
	 */
	public function read( array $args ): array {
		try {
			$state = ( new EditorRecordState() )->load( $args );
			return isset( $state['error'] ) ? $state : $this->present( $state );
		} catch ( \Throwable ) {
			return $this->error( 'editor_unavailable', 'The editor record could not be inspected safely.' );
		}
	}

	/**
	 * Dispatch a fixed editor operation after authorization and state validation.
	 *
	 * @param string              $operation update_record, set_style or restore_record.
	 * @param array<string,mixed> $args Closed operation arguments.
	 * @return array<string,mixed>
	 */
	public function write( string $operation, array $args ): array {
		try {
			$state = ( new EditorRecordState() )->load( $args );
			if ( isset( $state['error'] ) ) {
				return $state;
			}
			$expected = $args['expected_state'] ?? null;
			if ( ! is_string( $expected ) || ! hash_equals( $state['expected_state'], $expected ) ) {
				return $this->error( 'stale_editor_state', 'Read this exact editor record and selected revision again before changing it.' );
			}
			$desired = $this->desired( $operation, $state, $args );
			if ( isset( $desired['error'] ) ) {
				return $desired;
			}
			$point = new NativePostRecoveryPoint();
			if ( ! $point->available( $state['post'] ) || $point->locked( $state['post']->ID ) ) {
				return $this->error( 'editor_recovery_unavailable', 'Native revisions retaining at least two versions and an unlocked editor are required.' );
			}
			if ( $this->is_dry_run( $args ) ) {
				return $this->preview_response( 'site_editor.' . $operation, $args, $this->identity( $state ), $this->summary_changes( $state['post'], $desired ), $this->warnings(), $this->diff_payload( array(), array( 'See changes for bounded field-change flags and byte counts; values are not repeated.' ) ) );
			}
			if ( $point->fields( $state['post'] ) === $desired ) {
				return array(
					'success' => true,
					'changed' => false,
					'post_id' => $state['post']->ID,
				);
			}
		} catch ( \Throwable ) {
			return $this->error( 'editor_unavailable', 'The editor change could not be validated safely.' );
		}
		return $this->persist( $args, $state, $desired );
	}

	/**
	 * Derive only explicitly supported content fields from closed inputs.
	 *
	 * @param string              $operation Fixed operation.
	 * @param array<string,mixed> $state Authorized current state.
	 * @param array<string,mixed> $args Client arguments.
	 * @return array<string,string>
	 */
	private function desired( string $operation, array $state, array $args ): array {
		$post   = $state['post'];
		$fields = ( new NativePostRecoveryPoint() )->fields( $post );
		if ( 'set_style' === $operation && 'wp_global_styles' === $post->post_type ) {
			$policy = new EditorStylePolicy();
			$config = ( new EditorRecordState() )->styles( $post->post_content );
			if ( null === $config || ! array_key_exists( 'value', $args ) || ! $policy->valid( $args['path'] ?? null, $args['value'] ) ) {
				return $this->error( 'invalid_style', 'Use an allowlisted style path and bounded scalar value, or null to remove an override.' );
			}
			if ( ! $policy->recoverable( $config, $args['path'] ) ) {
				return $this->error( 'unsupported_style_recovery', 'The existing override cannot be recovered within the supported scalar or native preset policy. Use the native Site Editor for this value.' );
			}
			$encoded = wp_json_encode( $policy->apply( $config, $args['path'], $args['value'] ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
			if ( ! is_string( $encoded ) || strlen( $encoded ) > EditorRecordState::MAX_BYTES ) {
				return $this->error( 'invalid_style', 'The updated user-style record exceeds the supported size.' );
			}
			$fields['post_content'] = $encoded;
			return $fields;
		}
		if ( 'restore_record' === $operation && $state['revision'] instanceof \WP_Post ) {
			$fields = ( new NativePostRecoveryPoint() )->fields( $state['revision'] );
			if ( 'wp_global_styles' === $post->post_type ) {
				$current    = ( new EditorRecordState() )->styles( $post->post_content );
				$historical = ( new EditorRecordState() )->styles( $fields['post_content'] );
				return null === $current || null === $historical || ! ( new EditorStylePolicy() )->restorable( $current, $historical ) ? $this->error( 'invalid_style_revision', 'The selected revision must differ only in supported scalar overrides, not other historical CSS or settings.' ) : $fields;
			}
		} elseif ( 'update_record' === $operation && 'wp_global_styles' !== $post->post_type ) {
			$changes = $args['changes'] ?? null;
			if ( ! is_array( $changes ) || array_is_list( $changes ) || array_diff( array_keys( $changes ), array( 'title', 'content' ) ) ) {
				return $this->error( 'invalid_editor_changes', 'Provide a nonempty object containing title and/or serialized block content.' );
			}
			foreach ( $changes as $key => $value ) {
				if ( ! is_string( $value ) || ( 'title' === $key && ( '' === $value || strlen( $value ) > 200 || sanitize_text_field( $value ) !== $value ) ) ) {
					return $this->error( 'invalid_editor_changes', 'Use bounded plain text for titles and strings for block content.' );
				}
				$fields[ 'post_' . $key ] = $value;
			}
		} else {
			return $this->error( 'unsupported_editor_operation', 'Choose a supported operation for this editor record type.' );
		}
		if ( ! ( new EditorContentPolicy() )->valid( $fields['post_content'], 'wp_navigation' === $post->post_type ) ) {
			return $this->error( 'invalid_editor_content', 'Use bounded registered blocks without freeform, active or Custom HTML content.' );
		}
		return $fields;
	}

	/**
	 * Save a recovery point, revalidate, write once and verify the full result.
	 *
	 * @param array<string,mixed>  $args Original arguments.
	 * @param array<string,mixed>  $state Authorized pre-write state.
	 * @param array<string,string> $desired Native fields to persist.
	 * @return array<string,mixed>
	 */
	private function persist( array $args, array $state, array $desired ): array {
		try {
			$point    = new NativePostRecoveryPoint();
			$before   = $state['post'];
			$recovery = $point->capture( $before );
			$current  = ( new EditorRecordState() )->load( $args );
			if ( is_wp_error( $recovery ) || isset( $current['error'] ) || ! hash_equals( $state['expected_state'], $current['expected_state'] ) || $point->locked( $before->ID ) ) {
				return $this->uncertain( 'Recovery state or the unchanged target could not be verified. The editor update was not attempted.' );
			}
			$result = wp_update_post( wp_slash( array( 'ID' => $before->ID ) + $desired ), true );
			if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
				wp_clean_theme_json_cache();
			}
			$after = ( new EditorRecordState() )->load( array( 'post_id' => $before->ID ) );
			if ( is_wp_error( $result ) || isset( $after['error'] ) || ! $point->matches( $recovery, $before ) || ! $this->verified( $state, $after, $desired ) ) {
				return $this->uncertain( 'The editor update could not be verified. Inspect the record and recovery revision manually; do not retry automatically.' );
			}
			return array(
				'success'              => true,
				'changed'              => true,
				'post_id'              => $before->ID,
				'recovery_revision_id' => $recovery,
				'expected_state'       => $after['expected_state'],
				'warnings'             => $this->warnings(),
			);
		} catch ( \Throwable ) {
			return $this->uncertain( 'A native editor hook failed. Inspect the current record and revisions manually; do not retry automatically.' );
		}
	}

	/**
	 * Verify preservation of identity, ownership and all intended content fields.
	 *
	 * @param array<string,mixed>  $before Previous state.
	 * @param array<string,mixed>  $after Fresh state.
	 * @param array<string,string> $desired Intended fields.
	 */
	private function verified( array $before, array $after, array $desired ): bool {
		foreach ( array( 'theme', 'terms', 'area' ) as $key ) {
			if ( $before[ $key ] !== $after[ $key ] ) {
				return false;
			}
		}
		foreach ( array( 'post_type', 'post_status', 'post_name', 'post_parent', 'post_author' ) as $key ) {
			if ( $before['post']->$key !== $after['post']->$key ) {
				return false;
			}
		}
		$actual = ( new NativePostRecoveryPoint() )->fields( $after['post'] );
		if ( 'wp_global_styles' === $before['post']->post_type ) {
			$expected_config = json_decode( $desired['post_content'], true, 32 );
			$actual_config   = json_decode( $actual['post_content'], true, 32 );
			if ( $this->canonical( $expected_config ) !== $this->canonical( $actual_config ) ) {
				return false;
			}
			unset( $desired['post_content'], $actual['post_content'] );
		}
		return $desired === $actual;
	}

	/**
	 * Canonicalize bounded decoded JSON maps without coercing scalar types.
	 *
	 * @param mixed $value Decoded native JSON.
	 * @return mixed
	 */
	private function canonical( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			if ( ! array_is_list( $value ) ) {
				ksort( $value );
			}
			foreach ( $value as $key => $child ) {
				$value[ $key ] = $this->canonical( $child );
			}
		}
		return $value;
	}

	/**
	 * Return bounded editor data, not metadata or files.
	 *
	 * @param array<string,mixed> $state Authorized state.
	 * @return array<string,mixed>
	 */
	private function present( array $state ): array {
		$post            = $state['post'];
		$result          = $this->identity( $state );
		$result['title'] = $post->post_title;
		if ( 'wp_global_styles' === $post->post_type ) {
			$config = ( new EditorRecordState() )->styles( $post->post_content );
			if ( null === $config ) {
				return $this->error( 'invalid_style_record', 'The user-style record is not valid native theme.json data.' );
			}
			$result['style_overrides'] = ( new EditorStylePolicy() )->values( $config );
		} else {
			$result['content'] = $post->post_content;
		}
		if ( $state['revision'] instanceof \WP_Post ) {
			$result['revision_id'] = $state['revision']->ID;
			$result['comparison']  = $this->summary_changes( $post, ( new NativePostRecoveryPoint() )->fields( $state['revision'] ) );
		}
		$result['warnings'] = $this->warnings();
		return $result;
	}

	/**
	 * Minimal target identity for confirmation.
	 *
	 * @param array<string,mixed> $state Authorized state.
	 * @return array<string,mixed>
	 */
	private function identity( array $state ): array {
		return array(
			'post_id'        => $state['post']->ID,
			'type'           => $state['post']->post_type,
			'status'         => $state['post']->post_status,
			'theme'          => $state['theme'],
			'expected_state' => $state['expected_state'],
		);
	}

	/**
	 * Summarize content changes without repeating potentially large markup.
	 *
	 * @param \WP_Post             $post Current native post.
	 * @param array<string,string> $desired Intended fields.
	 * @return list<array<string,mixed>>
	 */
	private function summary_changes( \WP_Post $post, array $desired ): array {
		$result = array();
		foreach ( $desired as $key => $value ) {
			$result[] = array(
				'field'          => $key,
				'changed'        => $post->$key !== $value,
				'current_bytes'  => strlen( $post->$key ),
				'proposed_bytes' => strlen( $value ),
			);
		}
		return $result;
	}

	/**
	 * Explain the recovery and live-site boundaries.
	 *
	 * @return list<string>
	 */
	private function warnings(): array {
		return array( 'Database editor records only; no theme/plugin file writes. Published records can affect the live site immediately.', 'A retained native content revision is required. Metadata, terms, status and slug are not restored.', 'Native save hooks run. State checks are optimistic, not atomic against other editors or plugins; this is not a full-site backup.' );
	}

	/**
	 * Mark any unverified persistence as terminal for gateway replay safety.
	 *
	 * @param string $message Fixed public explanation.
	 * @return array<string,mixed>
	 */
	private function uncertain( string $message ): array {
		return array(
			'error'         => 'partial_write',
			'terminal'      => true,
			'partial_write' => true,
			'status'        => 'partial_write',
			'message'       => $message,
		);
	}
}

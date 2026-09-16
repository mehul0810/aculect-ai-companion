<?php
/**
 * State and ownership checks for database-backed Site Editor records.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Never resolves paths or permits ordinary posts through the design boundary. */
final class EditorRecordState extends AbstractAbilityService {

	public const TYPES     = array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles' );
	public const MAX_BYTES = 100000;

	/**
	 * Resolve one current-theme editor record and optional content revision.
	 *
	 * @param array<string,mixed> $args Positive post_id and optional revision_id.
	 * @return array<string,mixed>
	 */
	public function load( array $args ): array {
		$id = $args['post_id'] ?? null;
		if ( ! is_int( $id ) || $id < 1 ) {
			return $this->error( 'invalid_editor_record', 'Provide a positive database post_id from Site Editor discovery.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'read_post', $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return $this->error( 'forbidden', 'Site Editor and target read/edit permissions are required.' );
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::TYPES, true ) || ! in_array( $post->post_status, array( 'publish', 'draft' ), true ) || strlen( $post->post_content ) > self::MAX_BYTES || strlen( $post->post_title ) > 1000 || strlen( $post->post_excerpt ) > self::MAX_BYTES ) {
			return $this->error( 'unsupported_editor_record', 'An existing bounded database template, template part, navigation or global-styles record is required.' );
		}
		$theme = get_stylesheet();
		$terms = 'wp_navigation' === $post->post_type ? array() : wp_get_object_terms(
			$id,
			'wp_theme',
			array(
				'fields' => 'names',
				'number' => 2,
			)
		);
		$area  = 'wp_template_part' === $post->post_type ? wp_get_object_terms(
			$id,
			'wp_template_part_area',
			array(
				'fields' => 'names',
				'number' => 2,
			)
		) : array();
		if ( ! is_string( $theme ) || '' === $theme || is_wp_error( $terms ) || is_wp_error( $area ) || ( 'wp_navigation' !== $post->post_type && array( $theme ) !== $terms ) || count( $area ) > 1 ) {
			return $this->error( 'editor_theme_mismatch', 'Only unambiguous records owned by the active theme are editable.' );
		}
		$revision = null;
		if ( array_key_exists( 'revision_id', $args ) ) {
			$revision_id = $args['revision_id'];
			$revision    = is_int( $revision_id ) && $revision_id > 0 ? get_post( $revision_id ) : null;
			if ( ! $revision instanceof \WP_Post || 'revision' !== $revision->post_type || $id !== (int) $revision->post_parent || false !== wp_is_post_autosave( $revision ) || strlen( $revision->post_content ) > self::MAX_BYTES || strlen( $revision->post_title ) > 1000 || strlen( $revision->post_excerpt ) > self::MAX_BYTES ) {
				return $this->error( 'invalid_revision', 'Choose a bounded saved revision belonging to this editor record.' );
			}
		}
		$point = new NativePostRecoveryPoint();
		$json  = wp_json_encode( array( get_current_blog_id(), $theme, $terms, $area, $id, $post->post_type, $post->post_status, $post->post_name, $post->post_author, $post->post_parent, $post->post_modified_gmt, $point->fields( $post ), $revision?->ID, $revision?->post_modified_gmt, null === $revision ? null : $point->fields( $revision ) ) );
		if ( ! is_string( $json ) ) {
			return $this->error( 'invalid_editor_state', 'Editor state could not be encoded safely.' );
		}
		return array(
			'post'           => $post,
			'theme'          => $theme,
			'terms'          => $terms,
			'area'           => $area,
			'revision'       => $revision,
			'expected_state' => hash_hmac( 'sha256', $json, wp_salt( 'auth' ) ),
		);
	}

	/**
	 * Decode a native user theme.json record without accepting arbitrary formats.
	 *
	 * @param string $content Bounded native content.
	 * @return array<string,mixed>|null
	 */
	public function styles( string $content ): ?array {
		$config = json_decode( $content, true, 32 );
		if ( ! is_array( $config ) || true !== ( $config['isGlobalStylesUserThemeJSON'] ?? null ) || ! is_int( $config['version'] ?? null ) || array_diff( array_keys( $config ), array( 'styles', 'settings', 'version', 'isGlobalStylesUserThemeJSON' ) ) ) {
			return null;
		}
		foreach ( array( 'styles', 'settings' ) as $key ) {
			if ( isset( $config[ $key ] ) && ! is_array( $config[ $key ] ) ) {
				return null;
			}
		}
		return $config;
	}
}

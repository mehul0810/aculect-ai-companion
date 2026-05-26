<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * SEO plugin content metadata abilities.
 */
final class SeoAbilities extends AbstractAbilityService {

	/**
	 * Update SEO metadata for supported SEO plugins.
	 *
	 * @param array<string, mixed> $data SEO fields.
	 * @return array<string, mixed>
	 */
	public function update_seo( array $data ): array {
		$post_id = absint( $data['id'] ?? $data['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return $this->error( 'not_found', 'Content item not found.' );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update SEO metadata for this content item.' );
		}

		$adapter = $this->selected_adapter( sanitize_key( (string) ( $data['plugin'] ?? 'auto' ) ) );
		if ( array() === $adapter ) {
			return $this->error( 'unsupported_seo_plugin', 'No supported active SEO plugin was found.' );
		}

		$payload = $this->seo_payload( $data, $adapter );
		if ( array() === $payload ) {
			return $this->error( 'invalid_seo_fields', 'Provide meta_title, meta_description, or focus_keywords.' );
		}

		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'content.update_seo',
				$data,
				array(
					'type' => $post->post_type,
					'id'   => $post_id,
				),
				$this->seo_payload_changes( $post_id, $payload )
			);
		}

		foreach ( $payload as $meta_key => $value ) {
			update_post_meta( $post_id, $meta_key, $value );
		}

		return array(
			'post_id' => $post_id,
			'plugin'  => $adapter['id'],
			'fields'  => $this->public_seo_fields( $post_id, $adapter ),
		);
	}

	/**
	 * Return the selected active SEO adapter.
	 *
	 * @param string $requested Requested adapter ID.
	 * @return array<string, mixed>
	 */
	private function selected_adapter( string $requested ): array {
		$adapters = $this->adapters();

		foreach ( $adapters as $adapter ) {
			if ( 'auto' !== $requested && $requested !== $adapter['id'] ) {
				continue;
			}

			if ( $this->is_adapter_active( $adapter ) ) {
				return $adapter;
			}
		}

		return array();
	}

	/**
	 * Supported SEO plugin adapters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function adapters(): array {
		return array(
			array(
				'id'      => 'yoast',
				'label'   => 'Yoast SEO',
				'plugins' => array( 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php' ),
				'defined' => array( 'WPSEO_VERSION' ),
				'fields'  => array(
					'meta_title'       => '_yoast_wpseo_title',
					'meta_description' => '_yoast_wpseo_metadesc',
					'focus_keywords'   => '_yoast_wpseo_focuskw',
				),
			),
			array(
				'id'      => 'rank_math',
				'label'   => 'Rank Math SEO',
				'plugins' => array( 'seo-by-rank-math/rank-math.php', 'seo-by-rank-math-pro/rank-math-pro.php' ),
				'defined' => array( 'RANK_MATH_VERSION' ),
				'fields'  => array(
					'meta_title'       => 'rank_math_title',
					'meta_description' => 'rank_math_description',
					'focus_keywords'   => 'rank_math_focus_keyword',
				),
			),
		);
	}

	/**
	 * Determine whether an SEO adapter is active.
	 *
	 * @param array<string, mixed> $adapter Adapter definition.
	 */
	private function is_adapter_active( array $adapter ): bool {
		foreach ( (array) ( $adapter['defined'] ?? array() ) as $constant ) {
			if ( is_string( $constant ) && defined( $constant ) ) {
				return true;
			}
		}

		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			$active = array();
		}

		foreach ( (array) ( $adapter['plugins'] ?? array() ) as $plugin ) {
			if ( is_string( $plugin ) && in_array( $plugin, $active, true ) ) {
				return true;
			}
		}

		if ( ! is_multisite() ) {
			return false;
		}

		$network_active = get_site_option( 'active_sitewide_plugins', array() );
		return is_array( $network_active )
			&& array() !== array_intersect( array_keys( $network_active ), (array) ( $adapter['plugins'] ?? array() ) );
	}

	/**
	 * Build sanitized SEO meta payload.
	 *
	 * @param array<string, mixed> $data    SEO fields.
	 * @param array<string, mixed> $adapter Adapter definition.
	 * @return array<string, string>
	 */
	private function seo_payload( array $data, array $adapter ): array {
		$fields  = isset( $adapter['fields'] ) && is_array( $adapter['fields'] ) ? $adapter['fields'] : array();
		$payload = array();

		foreach ( array( 'meta_title', 'meta_description', 'focus_keywords' ) as $field ) {
			if ( ! array_key_exists( $field, $data ) || empty( $fields[ $field ] ) || ! is_string( $fields[ $field ] ) ) {
				continue;
			}

			$value = $data[ $field ];
			if ( 'focus_keywords' === $field && is_array( $value ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) );
			}

			$payload[ $fields[ $field ] ] = sanitize_text_field( (string) $value );
		}

		return $payload;
	}

	/**
	 * Build dry-run changes for SEO metadata.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $payload Proposed meta payload.
	 * @return list<array<string, mixed>>
	 */
	private function seo_payload_changes( int $post_id, array $payload ): array {
		$changes = array();

		foreach ( $payload as $meta_key => $value ) {
			$changes[] = $this->change( $meta_key, get_post_meta( $post_id, $meta_key, true ), $value );
		}

		return array_values( array_filter( $changes ) );
	}

	/**
	 * Return public SEO fields for a supported adapter.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $adapter Adapter definition.
	 * @return array<string, string>
	 */
	private function public_seo_fields( int $post_id, array $adapter ): array {
		$fields = isset( $adapter['fields'] ) && is_array( $adapter['fields'] ) ? $adapter['fields'] : array();
		$result = array();

		foreach ( $fields as $field => $meta_key ) {
			if ( is_string( $field ) && is_string( $meta_key ) ) {
				$result[ $field ] = (string) get_post_meta( $post_id, $meta_key, true );
			}
		}

		return $result;
	}
}

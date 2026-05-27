<?php
/**
 * Brand profile storage and defaults.
 *
 * @package Aculect\AICompanion\Brand
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Brand;

/**
 * Stores safe brand guidance for connected assistants.
 */
final class BrandProfile {

	private const OPTION = 'aculect_ai_companion_brand_profile';

	/**
	 * Return the current saved profile fields.
	 *
	 * @return array<string, string>
	 */
	public function saved(): array {
		$stored = get_option( self::OPTION, array() );

		return $this->sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Persist brand profile fields.
	 *
	 * @param array<string, mixed> $data Raw profile fields.
	 */
	public function save( array $data ): void {
		update_option( self::OPTION, $this->sanitize( $data ), false );
	}

	/**
	 * Return admin UI data with saved overrides and detected defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function admin_payload(): array {
		return array(
			'fields'   => $this->saved(),
			'defaults' => $this->defaults(),
			'profile'  => $this->public_profile(),
		);
	}

	/**
	 * Return sanitized brand guidance for assistant tools.
	 *
	 * @return array<string, mixed>
	 */
	public function public_profile(): array {
		$saved    = $this->saved();
		$defaults = $this->defaults();

		return array(
			'site'      => array(
				'name'    => $this->value_with_source( 'site_name', $saved, $defaults ),
				'tagline' => $this->value_with_source( 'tagline', $saved, $defaults ),
			),
			'colors'    => array(
				'primary'   => $this->value_with_source( 'primary_color', $saved, $defaults ),
				'secondary' => $this->value_with_source( 'secondary_color', $saved, $defaults ),
				'accent'    => $this->value_with_source( 'accent_color', $saved, $defaults ),
			),
			'logo'      => array(
				'preference' => $this->value_with_source( 'logo_preference', $saved, $defaults ),
				'url'        => $this->value_with_source( 'logo_url', $saved, $defaults ),
			),
			'editorial' => array(
				'image_style'      => $this->value_with_source( 'image_style', $saved, $defaults ),
				'typography_notes' => $this->value_with_source( 'typography_notes', $saved, $defaults ),
				'tone'             => $this->value_with_source( 'tone', $saved, $defaults ),
				'audience'         => $this->value_with_source( 'audience', $saved, $defaults ),
				'avoid'            => $this->value_with_source( 'avoid', $saved, $defaults ),
			),
		);
	}

	/**
	 * Return field defaults detected from WordPress and the active theme.
	 *
	 * @return array<string, string>
	 */
	private function defaults(): array {
		$palette = $this->theme_palette();
		$logo    = $this->logo_url();

		return array(
			'site_name'        => sanitize_text_field( (string) get_option( 'blogname', '' ) ),
			'tagline'          => sanitize_text_field( (string) get_option( 'blogdescription', '' ) ),
			'primary_color'    => $palette[0] ?? '',
			'secondary_color'  => $palette[1] ?? '',
			'accent_color'     => $palette[2] ?? '',
			'logo_preference'  => '' !== $logo ? 'Use the site logo when it fits the layout.' : '',
			'logo_url'         => $logo,
			'image_style'      => '',
			'typography_notes' => '',
			'tone'             => '',
			'audience'         => '',
			'avoid'            => '',
		);
	}

	/**
	 * Sanitize profile fields for storage.
	 *
	 * @param array<string, mixed> $data Raw profile fields.
	 * @return array<string, string>
	 */
	private function sanitize( array $data ): array {
		$fields = array();

		foreach ( $this->field_names() as $field ) {
			$value = $data[ $field ] ?? '';

			$fields[ $field ] = match ( $field ) {
				'primary_color', 'secondary_color', 'accent_color' => $this->sanitize_color( $value ),
				'logo_url' => $this->sanitize_url( $value ),
				'image_style', 'typography_notes', 'tone', 'audience', 'avoid' => $this->sanitize_multiline( $value ),
				default => $this->sanitize_text( $value, 300 ),
			};
		}

		return $fields;
	}

	/**
	 * Return allowed field names.
	 *
	 * @return string[]
	 */
	private function field_names(): array {
		return array(
			'site_name',
			'tagline',
			'primary_color',
			'secondary_color',
			'accent_color',
			'logo_preference',
			'logo_url',
			'image_style',
			'typography_notes',
			'tone',
			'audience',
			'avoid',
		);
	}

	/**
	 * Return a value with its source marker.
	 *
	 * @param string                $field    Field name.
	 * @param array<string, string> $saved    Saved values.
	 * @param array<string, string> $defaults Detected defaults.
	 * @return array{value: string, source: string}
	 */
	private function value_with_source( string $field, array $saved, array $defaults ): array {
		if ( '' !== ( $saved[ $field ] ?? '' ) ) {
			return array(
				'value'  => $saved[ $field ],
				'source' => 'saved',
			);
		}

		if ( '' !== ( $defaults[ $field ] ?? '' ) ) {
			return array(
				'value'  => $defaults[ $field ],
				'source' => 'default',
			);
		}

		return array(
			'value'  => '',
			'source' => 'empty',
		);
	}

	/**
	 * Return active theme palette colors.
	 *
	 * @return string[]
	 */
	private function theme_palette(): array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}

		$settings = wp_get_global_settings();
		$palette  = $settings['color']['palette']['theme'] ?? array();
		if ( ! is_array( $palette ) ) {
			return array();
		}

		$colors = array();
		foreach ( $palette as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['color'] ) ) {
				continue;
			}

			$color = $this->sanitize_color( $entry['color'] );
			if ( '' !== $color ) {
				$colors[] = $color;
			}
		}

		return array_values( array_unique( $colors ) );
	}

	/**
	 * Return the site custom logo URL when available.
	 */
	private function logo_url(): string {
		if ( ! function_exists( 'get_theme_mod' ) || ! function_exists( 'wp_get_attachment_image_url' ) ) {
			return '';
		}

		$logo_id = absint( get_theme_mod( 'custom_logo' ) );
		if ( 0 === $logo_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $logo_id, 'full' );

		return is_string( $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Sanitize a hex color.
	 *
	 * @param mixed $value Raw color.
	 */
	private function sanitize_color( mixed $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return 1 === preg_match( '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value ) ? strtolower( $value ) : '';
	}

	/**
	 * Sanitize a public URL.
	 *
	 * @param mixed $value Raw URL.
	 */
	private function sanitize_url( mixed $value ): string {
		$value = is_scalar( $value ) ? esc_url_raw( (string) $value ) : '';

		return preg_match( '#^https?://#i', $value ) ? $value : '';
	}

	/**
	 * Sanitize short text.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Maximum length.
	 */
	private function sanitize_text( mixed $value, int $limit ): string {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		return substr( $value, 0, $limit );
	}

	/**
	 * Sanitize textarea-style guidance.
	 *
	 * @param mixed $value Raw value.
	 */
	private function sanitize_multiline( mixed $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );

		return substr( $value, 0, 1200 );
	}
}

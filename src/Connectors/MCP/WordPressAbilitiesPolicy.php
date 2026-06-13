<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Admin policy for exposing WordPress Abilities API registrations through MCP.
 */
final class WordPressAbilitiesPolicy {

	public const OPTION_ALLOWED_ABILITIES = 'aculect_ai_companion_allowed_wp_abilities';

	/**
	 * Return admin-facing WordPress Ability definitions.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function public_definitions(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$allowed   = $this->allowed_ids();
		$items     = array();
		$registrar = new WordPressAbilitiesRegistrar();
		foreach ( $this->abilities() as $ability ) {
			if ( ! $this->is_public( $ability ) ) {
				continue;
			}

			$id = $this->ability_name( $ability );
			if ( $registrar->is_first_party_read_intelligence( $id ) ) {
				continue;
			}

			$meta    = $this->ability_meta( $ability );
			$items[] = array(
				'id'          => $id,
				'title'       => $this->method_string( $ability, 'get_label' ),
				'description' => $this->method_string( $ability, 'get_description' ),
				'category'    => $this->method_string( $ability, 'get_category' ),
				'readOnly'    => $this->is_readonly( $meta ),
				'destructive' => $this->is_destructive( $meta ),
				'allowed'     => in_array( $id, $allowed, true ),
			);
		}

		usort(
			$items,
			static fn( array $a, array $b ): int => strcmp( (string) $a['id'], (string) $b['id'] )
		);

		return $items;
	}

	/**
	 * Return allowed public ability IDs.
	 *
	 * @return list<string>
	 */
	public function allowed_ids(): array {
		$stored = get_option( self::OPTION_ALLOWED_ABILITIES, array() );
		return is_array( $stored ) ? $this->sanitize_ids( $stored ) : array();
	}

	/**
	 * Persist allowed public WordPress Ability IDs.
	 *
	 * @param array<mixed> $ids Raw ability IDs.
	 */
	public function save_allowed_ids( array $ids ): void {
		update_option( self::OPTION_ALLOWED_ABILITIES, $this->sanitize_ids( $ids ), false );
	}

	/**
	 * Delete stored policy.
	 */
	public static function delete(): void {
		delete_option( self::OPTION_ALLOWED_ABILITIES );
	}

	/**
	 * Check whether an ability ID is allowed through Aculect policy.
	 *
	 * @param string $id Ability ID.
	 */
	public function is_allowed( string $id ): bool {
		$id = sanitize_text_field( $id );
		if ( ( new WordPressAbilitiesRegistrar() )->is_first_party_read_intelligence( $id ) ) {
			return true;
		}

		return in_array( $id, $this->allowed_ids(), true );
	}

	/**
	 * Return registered ability objects.
	 *
	 * @return list<object>
	 */
	private function abilities(): array {
		$abilities = wp_get_abilities();
		if ( ! is_array( $abilities ) ) {
			return array();
		}

		return array_values( array_filter( $abilities, 'is_object' ) );
	}

	/**
	 * Sanitize ability IDs and drop non-public unknown values when possible.
	 *
	 * @param array<mixed> $ids Raw ability IDs.
	 * @return list<string>
	 */
	private function sanitize_ids( array $ids ): array {
		$known = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			$registrar = new WordPressAbilitiesRegistrar();
			foreach ( $this->abilities() as $ability ) {
				$name = $this->ability_name( $ability );
				if ( $this->is_public( $ability ) && ! $registrar->is_first_party_read_intelligence( $name ) ) {
					$known[] = $name;
				}
			}
		}

		$ids = array_filter(
			array_map(
				static fn( mixed $id ): string => is_scalar( $id ) ? sanitize_text_field( (string) $id ) : '',
				$ids
			)
		);

		if ( array() !== $known ) {
			$ids = array_intersect( $ids, $known );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Determine whether an ability is public.
	 *
	 * @param object $ability Ability object.
	 */
	private function is_public( object $ability ): bool {
		$meta = $this->ability_meta( $ability );
		if ( isset( $meta['show_in_rest'] ) ) {
			return (bool) $meta['show_in_rest'];
		}

		return isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) && ! empty( $meta['mcp']['public'] );
	}

	/**
	 * Determine if an ability is informational.
	 *
	 * @param array<string, mixed> $meta Ability metadata.
	 */
	private function is_readonly( array $meta ): bool {
		if ( isset( $meta['readonly'] ) ) {
			return (bool) $meta['readonly'];
		}

		return isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) && ! empty( $meta['annotations']['readonly'] );
	}

	/**
	 * Determine if an ability is destructive.
	 *
	 * @param array<string, mixed> $meta Ability metadata.
	 */
	private function is_destructive( array $meta ): bool {
		if ( isset( $meta['destructive'] ) ) {
			return (bool) $meta['destructive'];
		}

		return isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) && ! empty( $meta['annotations']['destructive'] );
	}

	/**
	 * Return an ability name.
	 *
	 * @param object $ability Ability object.
	 */
	private function ability_name( object $ability ): string {
		return $this->method_string( $ability, 'get_name' );
	}

	/**
	 * Return ability metadata.
	 *
	 * @param object $ability Ability object.
	 * @return array<string, mixed>
	 */
	private function ability_meta( object $ability ): array {
		if ( ! method_exists( $ability, 'get_meta' ) ) {
			return array();
		}

		$value = $ability->get_meta();
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Call an ability getter and return a string.
	 *
	 * @param object $ability Ability object.
	 * @param string $method  Getter method.
	 */
	private function method_string( object $ability, string $method ): string {
		if ( ! method_exists( $ability, $method ) ) {
			return '';
		}

		$value = $ability->{$method}();
		return is_scalar( $value ) ? (string) $value : '';
	}
}

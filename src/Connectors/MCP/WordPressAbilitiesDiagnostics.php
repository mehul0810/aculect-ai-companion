<?php
/**
 * WordPress Abilities API diagnostics for MCP operation manifests.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Reports support-safe WordPress Abilities mirror status for MCP diagnostics.
 */
final class WordPressAbilitiesDiagnostics {

	/**
	 * Cached registered WordPress Abilities keyed by ability name.
	 *
	 * @var array<string, object>|null
	 */
	private ?array $registered = null;

	/**
	 * Cached first-party MCP module to WordPress Ability name map.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $first_party_names = null;

	/**
	 * Return diagnostic metadata for one MCP operation entry.
	 *
	 * @param string            $ability_id Internal MCP ability ID.
	 * @param AbilitiesRegistry $registry   Ability registry.
	 * @return array<string, mixed>
	 */
	public function operation_metadata( string $ability_id, AbilitiesRegistry $registry ): array {
		$module = $registry->module( $ability_id );
		$names  = $this->first_party_names();

		if ( null === $module || ! isset( $names[ $module->id() ] ) ) {
			return array(
				'mirrored'      => false,
				'api_available' => $this->api_available(),
				'status'        => 'not_mirrored',
			);
		}

		return $this->ability_status( $names[ $module->id() ] );
	}

	/**
	 * Return a support-safe runtime summary for diagnostics exports.
	 *
	 * @return array<string, mixed>
	 */
	public function runtime_context(): array {
		$names          = $this->first_party_names();
		$registered     = $this->registered_abilities();
		$registered_ids = array_keys( $registered );
		$expected_ids   = array_values( $names );
		$missing        = array_values( array_diff( $expected_ids, $registered_ids ) );
		$invalid_schema = array();

		foreach ( $expected_ids as $name ) {
			if ( isset( $registered[ $name ] ) && ! $this->schema_valid( $registered[ $name ] ) ) {
				$invalid_schema[] = $name;
			}
		}

		$blocked_public = $this->policy_blocked_public_names();

		return array(
			'api_available'                  => $this->api_available(),
			'registration_functions_present' => function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' ),
			'expected_first_party_count'     => count( $expected_ids ),
			'registered_first_party_count'   => count( array_intersect( $expected_ids, $registered_ids ) ),
			'missing_first_party_names'      => $missing,
			'schema_valid'                   => array() === $invalid_schema,
			'invalid_schema_names'           => $invalid_schema,
			'policy_blocked_public_count'    => count( $blocked_public ),
			'policy_blocked_public_names'    => array_slice( $blocked_public, 0, 25 ),
		);
	}

	/**
	 * Return status for one expected first-party WordPress Ability mirror.
	 *
	 * @param string $name WordPress Ability name.
	 * @return array<string, mixed>
	 */
	private function ability_status( string $name ): array {
		$base = array(
			'mirrored'      => true,
			'name'          => $name,
			'api_available' => $this->api_available(),
		);

		if ( ! $this->api_available() ) {
			return $base + array(
				'registered'   => false,
				'public'       => false,
				'allowed'      => false,
				'schema_valid' => false,
				'status'       => 'abilities_api_unavailable',
			);
		}

		$ability = $this->registered_abilities()[ $name ] ?? null;
		if ( null === $ability ) {
			return $base + array(
				'registered'   => false,
				'public'       => false,
				'allowed'      => false,
				'schema_valid' => false,
				'status'       => 'missing_registration',
			);
		}

		$public       = $this->is_public( $ability );
		$allowed      = ( new WordPressAbilitiesPolicy() )->is_allowed( $name );
		$schema_valid = $this->schema_valid( $ability );

		return $base + array(
			'registered'   => true,
			'public'       => $public,
			'allowed'      => $allowed,
			'schema_valid' => $schema_valid,
			'status'       => $this->status( $public, $allowed, $schema_valid ),
		);
	}

	/**
	 * Return first-party module IDs mapped to expected WordPress Ability names.
	 *
	 * @return array<string, string>
	 */
	private function first_party_names(): array {
		if ( null === $this->first_party_names ) {
			$this->first_party_names = ( new WordPressAbilitiesRegistrar() )->module_ability_names();
		}

		return $this->first_party_names;
	}

	/**
	 * Return registered WordPress Abilities keyed by name.
	 *
	 * @return array<string, object>
	 */
	private function registered_abilities(): array {
		if ( null !== $this->registered ) {
			return $this->registered;
		}

		$this->registered = array();
		if ( ! $this->api_available() ) {
			return $this->registered;
		}

		$abilities = wp_get_abilities();
		if ( ! is_array( $abilities ) ) {
			return $this->registered;
		}

		foreach ( $abilities as $ability ) {
			if ( ! is_object( $ability ) ) {
				continue;
			}

			$name = $this->method_string( $ability, 'get_name' );
			if ( '' !== $name ) {
				$this->registered[ $name ] = $ability;
			}
		}

		return $this->registered;
	}

	/**
	 * Return public abilities blocked by Aculect WordPress Abilities policy.
	 *
	 * @return list<string>
	 */
	private function policy_blocked_public_names(): array {
		$policy  = new WordPressAbilitiesPolicy();
		$blocked = array();

		foreach ( $this->registered_abilities() as $name => $ability ) {
			if ( $this->is_public( $ability ) && ! $policy->is_allowed( $name ) ) {
				$blocked[] = $name;
			}
		}

		sort( $blocked );

		return $blocked;
	}

	/**
	 * Determine whether the WordPress Abilities runtime is available.
	 */
	private function api_available(): bool {
		return function_exists( 'wp_get_abilities' );
	}

	/**
	 * Determine whether one registered ability is publicly exposed.
	 *
	 * @param object $ability Ability object.
	 */
	private function is_public( object $ability ): bool {
		$meta = $this->method_array( $ability, 'get_meta' );
		if ( isset( $meta['show_in_rest'] ) ) {
			return (bool) $meta['show_in_rest'];
		}

		return isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) && ! empty( $meta['mcp']['public'] );
	}

	/**
	 * Determine whether one registered ability has safe object schemas.
	 *
	 * @param object $ability Ability object.
	 */
	private function schema_valid( object $ability ): bool {
		return $this->object_schema( $this->method_array( $ability, 'get_input_schema' ) )
			&& $this->object_schema( $this->method_array( $ability, 'get_output_schema' ) );
	}

	/**
	 * Check an ability schema is an object schema.
	 *
	 * @param array<string, mixed> $schema Schema.
	 */
	private function object_schema( array $schema ): bool {
		$properties = $schema['properties'] ?? null;

		return 'object' === ( $schema['type'] ?? null ) && ( is_array( $properties ) || is_object( $properties ) );
	}

	/**
	 * Return a stable status value.
	 *
	 * @param bool $public       Whether the ability is public.
	 * @param bool $allowed      Whether Aculect policy allows it.
	 * @param bool $schema_valid Whether schemas are valid.
	 */
	private function status( bool $public, bool $allowed, bool $schema_valid ): string {
		if ( ! $public ) {
			return 'not_public';
		}

		if ( ! $allowed ) {
			return 'policy_blocked';
		}

		if ( ! $schema_valid ) {
			return 'schema_invalid';
		}

		return 'available';
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

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Call an ability getter and return an array.
	 *
	 * @param object $ability Ability object.
	 * @param string $method  Getter method.
	 * @return array<string, mixed>
	 */
	private function method_array( object $ability, string $method ): array {
		if ( ! method_exists( $ability, $method ) ) {
			return array();
		}

		$value = $ability->{$method}();

		return is_array( $value ) ? $value : array();
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Admin policy for exposing WordPress Abilities API registrations through MCP.
 */
final class WordPressAbilitiesPolicy {

	public const OPTION_ALLOWED_ABILITIES  = 'aculect_ai_companion_allowed_wp_abilities';
	public const OPTION_ABILITY_DECISIONS  = 'aculect_ai_companion_wp_ability_decisions';
	public const OPTION_POLICY_INITIALIZED = 'aculect_ai_companion_wp_ability_policy_initialized';

	/**
	 * Cached explicit ability decisions.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $decision_cache = null;

	private ?bool $legacy_policy_cache = null;

	/**
	 * Return admin-facing WordPress Ability definitions.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function public_definitions(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$items     = array();
		$registrar = new WordPressAbilitiesRegistrar();
		foreach ( $this->abilities() as $ability ) {
			if ( ! $this->is_public( $ability ) ) {
				continue;
			}

			$id = $this->ability_name( $ability );
			if ( $registrar->is_first_party_read_intelligence( $id ) || $registrar->is_mcp_only_intelligence( $id ) ) {
				continue;
			}

			$meta     = $this->ability_meta( $ability );
			$decision = $this->decision_for( $id );
			$items[]  = array(
				'id'             => $id,
				'title'          => $this->method_string( $ability, 'get_label' ),
				'description'    => $this->method_string( $ability, 'get_description' ),
				'category'       => $this->method_string( $ability, 'get_category' ),
				'readOnly'       => $this->is_readonly( $meta ),
				'destructive'    => $this->is_destructive( $meta ),
				'allowed'        => $this->is_allowed_ability( $ability ),
				'defaultEnabled' => $this->is_safe_default( $ability ),
				'decision'       => null === $decision ? 'default' : ( $decision ? 'enabled' : 'disabled' ),
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
		$decisions = $this->saved_decisions();
		$allowed   = array_keys( array_filter( $decisions ) );

		if ( ! $this->has_legacy_policy() ) {
			foreach ( $this->abilities() as $ability ) {
				$id = $this->ability_name( $ability );
				if ( ! array_key_exists( $id, $decisions ) && $this->is_safe_default( $ability ) ) {
					$allowed[] = $id;
				}
			}
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Return explicit administrator decisions, including temporarily unavailable abilities.
	 *
	 * @return array<string, bool>
	 */
	public function saved_decisions(): array {
		if ( null !== $this->decision_cache ) {
			return $this->decision_cache;
		}

		$stored = get_option( self::OPTION_ABILITY_DECISIONS, array() );
		if ( $this->is_policy_initialized() ) {
			$this->decision_cache = is_array( $stored ) ? $this->sanitize_decisions( $stored ) : array();
			return $this->decision_cache;
		}

		$legacy = get_option( self::OPTION_ALLOWED_ABILITIES, null );
		if ( ! is_array( $legacy ) ) {
			$this->decision_cache = array();
			return $this->decision_cache;
		}

		$allowed   = $this->sanitize_ids( $legacy );
		$decisions = array_fill_keys( $allowed, true );
		foreach ( $this->configurable_ability_ids() as $id ) {
			$decisions[ $id ] = in_array( $id, $allowed, true );
		}

		$this->decision_cache = $decisions;
		return $this->decision_cache;
	}

	/**
	 * Persist allowed public WordPress Ability IDs.
	 *
	 * @param array<mixed> $ids Raw ability IDs.
	 */
	public function save_allowed_ids( array $ids ): void {
		$allowed   = $this->sanitize_ids( $ids );
		$decisions = $this->saved_decisions();
		foreach ( $this->configurable_ability_ids() as $id ) {
			$decisions[ $id ] = in_array( $id, $allowed, true );
		}

		$this->save_decisions( $decisions );
	}

	/**
	 * Replace explicit administrator decisions.
	 *
	 * @param array<mixed> $decisions Raw ability decision map.
	 */
	public function save_decisions( array $decisions ): void {
		$decisions = $this->sanitize_decisions( $decisions );
		update_option( self::OPTION_ABILITY_DECISIONS, $decisions, false );
		update_option( self::OPTION_ALLOWED_ABILITIES, array_keys( array_filter( $decisions ) ), false );
		update_option( self::OPTION_POLICY_INITIALIZED, 1, false );
		$this->decision_cache      = $decisions;
		$this->legacy_policy_cache = false;
	}

	/**
	 * Delete stored policy.
	 */
	public static function delete(): void {
		delete_option( self::OPTION_ALLOWED_ABILITIES );
		delete_option( self::OPTION_ABILITY_DECISIONS );
		delete_option( self::OPTION_POLICY_INITIALIZED );
	}

	/**
	 * Check whether an ability ID is allowed through Aculect policy.
	 *
	 * @param string $id Ability ID.
	 */
	public function is_allowed( string $id ): bool {
		$id        = sanitize_text_field( $id );
		$registrar = new WordPressAbilitiesRegistrar();
		if ( $registrar->is_mcp_only_intelligence( $id ) ) {
			return false;
		}

		if ( $registrar->is_first_party_read_intelligence( $id ) ) {
			return false;
		}

		foreach ( $this->abilities() as $ability ) {
			if ( hash_equals( $this->ability_name( $ability ), $id ) ) {
				return $this->is_allowed_ability( $ability );
			}
		}

		return false;
	}

	/**
	 * Check one registered ability against explicit policy or the safe default.
	 *
	 * @param object $ability Ability object.
	 */
	private function is_allowed_ability( object $ability ): bool {
		$id       = $this->ability_name( $ability );
		$decision = $this->decision_for( $id );
		if ( null !== $decision ) {
			return $decision;
		}

		return ! $this->has_legacy_policy() && $this->is_safe_default( $ability );
	}

	/**
	 * Return an explicit decision or null when the safe default should apply.
	 *
	 * @param string $id Ability ID.
	 */
	private function decision_for( string $id ): ?bool {
		$decisions = $this->saved_decisions();
		return array_key_exists( $id, $decisions ) ? $decisions[ $id ] : null;
	}

	/**
	 * Check whether an older saved allowlist exists and must retain upgrade behavior.
	 */
	private function has_legacy_policy(): bool {
		if ( null === $this->legacy_policy_cache ) {
			$this->legacy_policy_cache = ! $this->is_policy_initialized()
				&& null !== get_option( self::OPTION_ALLOWED_ABILITIES, null );
		}

		return $this->legacy_policy_cache;
	}

	/**
	 * Check whether the explicit-decision storage contract has been initialized.
	 */
	private function is_policy_initialized(): bool {
		return 1 === (int) get_option( self::OPTION_POLICY_INITIALIZED, 0 );
	}

	/**
	 * Determine whether a public third-party ability is safe to enable by default.
	 *
	 * @param object $ability Ability object.
	 */
	private function is_safe_default( object $ability ): bool {
		$id        = $this->ability_name( $ability );
		$meta      = $this->ability_meta( $ability );
		$registrar = new WordPressAbilitiesRegistrar();

		return 1 === preg_match( '/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/', $id )
			&& ! $registrar->is_first_party_read_intelligence( $id )
			&& ! $registrar->is_mcp_only_intelligence( $id )
			&& $this->is_public( $ability )
			&& $this->is_explicitly_readonly( $meta )
			&& $this->is_explicitly_non_destructive( $meta )
			&& '' !== $this->method_string( $ability, 'get_label' )
			&& '' !== $this->method_string( $ability, 'get_description' )
			&& $this->valid_schema( $this->method_array( $ability, 'get_input_schema' ) )
			&& $this->valid_schema( $this->method_array( $ability, 'get_output_schema' ) )
			&& $this->has_permission_callback( $ability );
	}

	/**
	 * Check a bounded registered schema.
	 *
	 * @param array<string, mixed> $schema Ability schema.
	 */
	private function valid_schema( array $schema ): bool {
		if ( ! is_string( wp_json_encode( $schema, JSON_PRESERVE_ZERO_FRACTION ) ) ) {
			return false;
		}

		$nodes = 0;
		return $this->valid_schema_node( $schema, 0, $nodes );
	}

	/**
	 * Recursively validate the schema shapes used to decide safe defaults.
	 *
	 * @param array<string, mixed> $schema Schema node.
	 * @param int                  $depth  Current nesting depth.
	 * @param int                  $nodes  Visited node count.
	 */
	private function valid_schema_node( array $schema, int $depth, int &$nodes ): bool {
		++$nodes;
		if ( 8 < $depth || 256 < $nodes || ! isset( $schema['type'] ) || ! is_string( $schema['type'] ) ) {
			return false;
		}

		$allowed_keywords = array(
			'type',
			'title',
			'description',
			'default',
			'examples',
			'enum',
			'const',
			'properties',
			'patternProperties',
			'propertyNames',
			'required',
			'items',
			'additionalProperties',
			'allOf',
			'anyOf',
			'oneOf',
			'not',
			'minimum',
			'maximum',
			'exclusiveMinimum',
			'exclusiveMaximum',
			'multipleOf',
			'minLength',
			'maxLength',
			'pattern',
			'format',
			'minItems',
			'maxItems',
			'uniqueItems',
			'minProperties',
			'maxProperties',
		);
		if ( array() !== array_diff( array_keys( $schema ), $allowed_keywords ) ) {
			return false;
		}

		if ( ! in_array( $schema['type'], array( 'object', 'array', 'string', 'number', 'integer', 'boolean', 'null' ), true ) ) {
			return false;
		}

		foreach ( array( 'title', 'description', 'pattern', 'format' ) as $string_keyword ) {
			if ( array_key_exists( $string_keyword, $schema ) && ! is_string( $schema[ $string_keyword ] ) ) {
				return false;
			}
		}

		if ( array_key_exists( 'enum', $schema ) ) {
			if ( ! is_array( $schema['enum'] ) || ! array_is_list( $schema['enum'] ) || array() === $schema['enum'] ) {
				return false;
			}

			$enum_values = array();
			foreach ( $schema['enum'] as $enum_value ) {
				if ( ! is_scalar( $enum_value ) && null !== $enum_value ) {
					return false;
				}
				if ( is_float( $enum_value ) && ! is_finite( $enum_value ) ) {
					return false;
				}

				$encoded = is_int( $enum_value ) || is_float( $enum_value )
					? 'number:' . (string) (float) $enum_value
					: gettype( $enum_value ) . ':' . wp_json_encode( $enum_value );
				if ( ! is_string( $encoded ) || isset( $enum_values[ $encoded ] ) ) {
					return false;
				}
				$enum_values[ $encoded ] = true;
			}
		}

		if ( array_key_exists( 'examples', $schema ) && ( ! is_array( $schema['examples'] ) || ! array_is_list( $schema['examples'] ) ) ) {
			return false;
		}

		foreach ( array( 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf' ) as $numeric_keyword ) {
			if ( array_key_exists( $numeric_keyword, $schema ) && ! is_int( $schema[ $numeric_keyword ] ) && ! is_float( $schema[ $numeric_keyword ] ) ) {
				return false;
			}
		}
		if ( array_key_exists( 'multipleOf', $schema ) && 0 >= $schema['multipleOf'] ) {
			return false;
		}

		foreach ( array( 'minLength', 'maxLength', 'minItems', 'maxItems', 'minProperties', 'maxProperties' ) as $count_keyword ) {
			if ( array_key_exists( $count_keyword, $schema ) && ( ! is_int( $schema[ $count_keyword ] ) || 0 > $schema[ $count_keyword ] ) ) {
				return false;
			}
		}

		if ( array_key_exists( 'uniqueItems', $schema ) && ! is_bool( $schema['uniqueItems'] ) ) {
			return false;
		}

		if ( array_key_exists( 'pattern', $schema ) && ! $this->valid_schema_pattern( $schema['pattern'] ) ) {
			return false;
		}

		if ( array_key_exists( 'properties', $schema ) ) {
			if ( ! is_array( $schema['properties'] ) ) {
				return false;
			}

			foreach ( $schema['properties'] as $name => $property ) {
				if ( ! is_string( $name ) || '' === $name || ! is_array( $property ) || ! $this->valid_schema_node( $property, $depth + 1, $nodes ) ) {
					return false;
				}
			}
		}

		if ( array_key_exists( 'required', $schema ) ) {
			if ( ! is_array( $schema['required'] ) || ! array_is_list( $schema['required'] ) ) {
				return false;
			}

			foreach ( $schema['required'] as $required ) {
				if ( ! is_string( $required ) || '' === $required ) {
					return false;
				}
			}

			if ( count( $schema['required'] ) !== count( array_unique( $schema['required'] ) ) ) {
				return false;
			}
		}

		if ( array_key_exists( 'patternProperties', $schema ) ) {
			if ( ! is_array( $schema['patternProperties'] ) ) {
				return false;
			}

			foreach ( $schema['patternProperties'] as $pattern => $property ) {
				if ( ! is_string( $pattern ) || '' === $pattern || ! $this->valid_schema_pattern( $pattern ) || ! is_array( $property ) || ! $this->valid_schema_node( $property, $depth + 1, $nodes ) ) {
					return false;
				}
			}
		}

		if ( array_key_exists( 'propertyNames', $schema ) && ( ! is_array( $schema['propertyNames'] ) || ! $this->valid_schema_node( $schema['propertyNames'], $depth + 1, $nodes ) ) ) {
			return false;
		}

		if ( array_key_exists( 'items', $schema ) && ( ! is_array( $schema['items'] ) || ! $this->valid_schema_node( $schema['items'], $depth + 1, $nodes ) ) ) {
			return false;
		}

		if ( array_key_exists( 'additionalProperties', $schema )
			&& ! is_bool( $schema['additionalProperties'] )
			&& ( ! is_array( $schema['additionalProperties'] ) || ! $this->valid_schema_node( $schema['additionalProperties'], $depth + 1, $nodes ) ) ) {
			return false;
		}

		foreach ( array( 'allOf', 'anyOf', 'oneOf' ) as $composition ) {
			if ( ! array_key_exists( $composition, $schema ) ) {
				continue;
			}

			if ( ! is_array( $schema[ $composition ] ) || ! array_is_list( $schema[ $composition ] ) || array() === $schema[ $composition ] ) {
				return false;
			}

			foreach ( $schema[ $composition ] as $candidate ) {
				if ( ! is_array( $candidate ) || ! $this->valid_schema_node( $candidate, $depth + 1, $nodes ) ) {
					return false;
				}
			}
		}

		return ! array_key_exists( 'not', $schema ) || ( is_array( $schema['not'] ) && $this->valid_schema_node( $schema['not'], $depth + 1, $nodes ) );
	}

	/**
	 * Conservatively accept regex patterns supported by the PHP runtime.
	 *
	 * @param string $pattern JSON Schema pattern.
	 */
	private function valid_schema_pattern( string $pattern ): bool {
		$pattern = str_replace( '~', '\\~', $pattern );

		return false !== @preg_match( '~' . $pattern . '~u', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid third-party patterns must fail closed without emitting warnings.
	}

	/**
	 * Require an explicit read-only annotation for safe default activation.
	 *
	 * @param array<string, mixed> $meta Ability metadata.
	 */
	private function is_explicitly_readonly( array $meta ): bool {
		if ( array_key_exists( 'readonly', $meta ) ) {
			return true === $meta['readonly'];
		}

		return isset( $meta['annotations'] )
			&& is_array( $meta['annotations'] )
			&& array_key_exists( 'readonly', $meta['annotations'] )
			&& true === $meta['annotations']['readonly'];
	}

	/**
	 * Require an explicit non-destructive annotation for safe default activation.
	 *
	 * @param array<string, mixed> $meta Ability metadata.
	 */
	private function is_explicitly_non_destructive( array $meta ): bool {
		if ( array_key_exists( 'destructive', $meta ) ) {
			return false === $meta['destructive'];
		}

		return isset( $meta['annotations'] )
			&& is_array( $meta['annotations'] )
			&& array_key_exists( 'destructive', $meta['annotations'] )
			&& false === $meta['annotations']['destructive'];
	}

	/**
	 * Check whether the registered ability exposes a callable permission callback.
	 *
	 * @param object $ability Ability object.
	 */
	private function has_permission_callback( object $ability ): bool {
		if ( method_exists( $ability, 'get_permission_callback' ) && is_callable( $ability->get_permission_callback() ) ) {
			return true;
		}

		$meta = $this->ability_meta( $ability );
		return isset( $meta['permission_callback'] ) && is_callable( $meta['permission_callback'] );
	}

	/**
	 * Return registered ability objects.
	 *
	 * @return list<object>
	 */
	private function abilities(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$abilities = call_user_func( 'wp_get_abilities' );
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
		$known     = array();
		$registrar = new WordPressAbilitiesRegistrar();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( $this->abilities() as $ability ) {
				$name = $this->ability_name( $ability );
				if ( $this->is_public( $ability ) && ! $registrar->is_first_party_read_intelligence( $name ) && ! $registrar->is_mcp_only_intelligence( $name ) ) {
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
		$ids = array_filter(
			$ids,
			static fn( string $id ): bool => ! $registrar->is_mcp_only_intelligence( $id )
		);

		if ( array() !== $known ) {
			$ids = array_intersect( $ids, $known );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Return configurable public ability IDs.
	 *
	 * @return list<string>
	 */
	private function configurable_ability_ids(): array {
		$ids       = array();
		$registrar = new WordPressAbilitiesRegistrar();
		foreach ( $this->abilities() as $ability ) {
			$id = $this->ability_name( $ability );
			if ( $this->is_public( $ability ) && ! $registrar->is_first_party_read_intelligence( $id ) && ! $registrar->is_mcp_only_intelligence( $id ) ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Sanitize an ability decision map without requiring the provider to be active.
	 *
	 * @param array<mixed> $decisions Raw decision map.
	 * @return array<string, bool>
	 */
	private function sanitize_decisions( array $decisions ): array {
		$sanitized = array();
		foreach ( $decisions as $id => $enabled ) {
			$id = sanitize_text_field( (string) $id );
			if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/', $id ) ) {
				continue;
			}

			$sanitized[ $id ] = filter_var( $enabled, FILTER_VALIDATE_BOOLEAN );
		}

		ksort( $sanitized );
		return $sanitized;
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

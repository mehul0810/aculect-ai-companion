<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use WP_Error;
use WP_REST_Response;

/**
 * Bridges WordPress Abilities API registrations into Aculect AI Companion MCP tools.
 */
final class WordPressAbilitiesBridge {

	/**
	 * Discover registered WordPress abilities.
	 *
	 * @param array<string, mixed> $args Discovery filters.
	 * @return array<string, mixed>
	 */
	public function discover( array $args = array() ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $this->unavailable();
		}

		$search   = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$category = sanitize_key( (string) ( $args['category'] ?? '' ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );

		$items = array();
		foreach ( $this->abilities() as $ability ) {
			if ( ! $this->incident_list_discovery_allowed( $ability ) ) {
				continue;
			}

			$mapped = $this->safe_map_ability( $ability );
			if ( null === $mapped || empty( $mapped['public'] ) || empty( $mapped['allowed'] ) ) {
				continue;
			}

			if ( '' !== $category && $category !== $mapped['category'] ) {
				continue;
			}

			if ( '' !== $search ) {
				$haystack = strtolower( implode( ' ', array( $mapped['id'], $mapped['title'], $mapped['description'] ) ) );
				if ( ! str_contains( $haystack, strtolower( $search ) ) ) {
					continue;
				}
			}

			$items[] = $mapped;
		}

		$total = count( $items );
		$items = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Get full metadata for one WordPress ability.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_info( array $args ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $this->unavailable();
		}

		$ability = $this->find_ability( (string) ( $args['id'] ?? $args['name'] ?? '' ) );
		if ( null === $ability ) {
			return $this->error( 'not_found', 'WordPress ability not found.' );
		}

		if ( ! $this->is_public_ability( $ability ) ) {
			return $this->error( 'forbidden', 'This WordPress ability is not exposed for remote clients.' );
		}

		if ( ! ( new WordPressAbilitiesPolicy() )->is_allowed( $this->ability_name( $ability ) ) ) {
			return $this->error( 'blocked_by_policy', 'This WordPress ability is blocked by Aculect AI Companion policy.' );
		}

		if ( ! $this->incident_list_discovery_allowed( $ability ) ) {
			return $this->error( 'forbidden', 'You do not have permission to discover this WordPress ability.' );
		}

		$mapped = $this->safe_map_ability( $ability, true );
		return null === $mapped ? $this->error( 'ability_metadata_unavailable', 'This WordPress ability returned invalid metadata.' ) : $mapped;
	}

	/**
	 * Execute a WordPress ability through its registered lifecycle.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function run( array $args ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $this->unavailable();
		}

		$ability = $this->find_ability( (string) ( $args['id'] ?? $args['name'] ?? '' ) );
		if ( null === $ability ) {
			return $this->error( 'not_found', 'WordPress ability not found.' );
		}

		if ( ! $this->is_public_ability( $ability ) ) {
			return $this->error( 'forbidden', 'This WordPress ability is not exposed for remote clients.' );
		}

		if ( ! ( new WordPressAbilitiesPolicy() )->is_allowed( $this->ability_name( $ability ) ) ) {
			return $this->error( 'blocked_by_policy', 'This WordPress ability is blocked by Aculect AI Companion policy.' );
		}

		if ( ! method_exists( $ability, 'execute' ) ) {
			return $this->error( 'not_executable', 'This WordPress ability cannot be executed.' );
		}

		$input = isset( $args['arguments'] ) && is_array( $args['arguments'] ) ? $args['arguments'] : array();
		try {
			$result = $ability->execute( $input );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return $this->error( 'ability_execution_failed', 'The WordPress ability failed without returning a safe result.' );
		}
		if ( $result instanceof WP_Error && 'ability_invalid_permissions' === $result->get_error_code() ) {
			return $this->error( 'forbidden', 'You do not have permission to execute this WordPress ability.' );
		}

		$normalized = $this->normalize_result( $result );
		if ( ! $normalized['valid'] ) {
			return $this->error( 'invalid_ability_result', 'The WordPress ability returned data that could not be represented safely.' );
		}

		return array(
			'ability' => $this->ability_name( $ability ),
			'result'  => $normalized['value'],
		);
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

		try {
			$abilities = call_user_func( 'wp_get_abilities' );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return array();
		}
		if ( ! is_array( $abilities ) ) {
			return array();
		}

		return array_values( array_filter( $abilities, 'is_object' ) );
	}

	/**
	 * Find an ability by ID/name.
	 *
	 * @param string $name Ability name.
	 * @return object|null
	 */
	private function find_ability( string $name ): ?object {
		$name = sanitize_text_field( $name );
		if ( '' === $name ) {
			return null;
		}

		$registrar = new WordPressAbilitiesRegistrar();
		if ( $registrar->is_mcp_only_intelligence( $name ) ) {
			return null;
		}

		$first_party_name = $registrar->ability_name_for_id( $name );
		if ( '' !== $first_party_name ) {
			$name = $first_party_name;
		}

		foreach ( $this->abilities() as $ability ) {
			if ( hash_equals( $this->ability_name( $ability ), $name ) ) {
				return $ability;
			}
		}

		return null;
	}

	/**
	 * Apply the registered administrator permission when discovering incidents.
	 *
	 * @param object $ability Ability object.
	 */
	private function incident_list_discovery_allowed( object $ability ): bool {
		if ( 'aculect-ai-companion/plugin-incident-list' !== $this->ability_name( $ability ) ) {
			return true;
		}

		if ( ! method_exists( $ability, 'check_permissions' ) ) {
			return false;
		}

		try {
			return true === $ability->check_permissions( array() );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return false;
		}
	}

	/**
	 * Convert a WP_Ability-like object into a deterministic MCP payload.
	 *
	 * @param object $ability      Ability object.
	 * @param bool   $include_full Whether to include schemas and raw metadata.
	 * @return array<string, mixed>
	 */
	private function map_ability( object $ability, bool $include_full = false ): array {
		$meta = $this->ability_meta( $ability );
		$item = array(
			'id'          => $this->ability_name( $ability ),
			'title'       => $this->method_string( $ability, 'get_label' ),
			'description' => $this->method_string( $ability, 'get_description' ),
			'category'    => $this->method_string( $ability, 'get_category' ),
			'readOnly'    => $this->is_readonly( $meta ),
			'public'      => $this->is_public_ability( $ability ),
			'allowed'     => ( new WordPressAbilitiesPolicy() )->is_allowed( $this->ability_name( $ability ) ),
		);

		if ( $include_full ) {
			$item['inputSchema']  = $this->client_schema( $this->method_array( $ability, 'get_input_schema' ) );
			$item['outputSchema'] = $this->client_schema( $this->method_array( $ability, 'get_output_schema' ) );
			$item['meta']         = $meta;
		}

		return $item;
	}

	/**
	 * Map an external ability without allowing malformed plugin code to abort discovery.
	 *
	 * @param object $ability      Ability object.
	 * @param bool   $include_full Whether to include schemas and raw metadata.
	 * @return array<string, mixed>|null
	 */
	private function safe_map_ability( object $ability, bool $include_full = false ): ?array {
		try {
			$mapped = $this->map_ability( $ability, $include_full );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return null;
		}

		if ( '' === (string) ( $mapped['id'] ?? '' ) || '' === (string) ( $mapped['title'] ?? '' ) || '' === (string) ( $mapped['description'] ?? '' ) ) {
			return null;
		}

		return $mapped;
	}

	/**
	 * Return an ability name from a WP_Ability-like object.
	 *
	 * @param object $ability Ability object.
	 * @return string
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
		return $this->method_array( $ability, 'get_meta' );
	}

	/**
	 * Determine whether an ability should be exposed to remote MCP clients.
	 *
	 * @param object $ability Ability object.
	 * @return bool
	 */
	private function is_public_ability( object $ability ): bool {
		return WordPressAbilityExposure::is_public( $ability );
	}

	/**
	 * Determine if an ability is informational.
	 *
	 * @param array<string, mixed> $meta Ability metadata.
	 * @return bool
	 */
	private function is_readonly( array $meta ): bool {
		if ( isset( $meta['readonly'] ) ) {
			return (bool) $meta['readonly'];
		}

		if ( isset( $meta['annotations'] ) && is_array( $meta['annotations'] ) && array_key_exists( 'readonly', $meta['annotations'] ) ) {
			return (bool) $meta['annotations']['readonly'];
		}

		return false;
	}

	/**
	 * Prepare a registered JSON Schema for client exposure when core supports it.
	 *
	 * Registered schemas remain the canonical server-side validation contract.
	 *
	 * @param array<string, mixed> $schema Registered JSON Schema.
	 * @return array<string, mixed>
	 */
	private function client_schema( array $schema ): array {
		if ( function_exists( 'wp_prepare_json_schema_for_client' ) ) {
			return wp_prepare_json_schema_for_client( $schema );
		}

		return $schema;
	}

	/**
	 * Call an ability getter and return a string.
	 *
	 * @param object $object Ability object.
	 * @param string $method Getter method.
	 * @return string
	 */
	private function method_string( object $object, string $method ): string {
		if ( ! method_exists( $object, $method ) ) {
			return '';
		}

		try {
			$value = $object->{$method}();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return '';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Call an ability getter and return an array.
	 *
	 * @param object $object Ability object.
	 * @param string $method Getter method.
	 * @return array<string, mixed>
	 */
	private function method_array( object $object, string $method ): array {
		if ( ! method_exists( $object, $method ) ) {
			return array();
		}

		try {
			$value = $object->{$method}();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return array();
		}

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Normalize ability execution results into JSON-safe data.
	 *
	 * @param mixed $result Ability result.
	 * @return array{valid: bool, value: mixed}
	 */
	private function normalize_result( mixed $result ): array {
		if ( $result instanceof WP_Error ) {
			$result = $this->error( (string) $result->get_error_code(), $result->get_error_message() );
		}

		if ( $result instanceof WP_REST_Response ) {
			$result = $result->get_data();
		}

		$encoded = wp_json_encode( $result );
		if ( ! is_string( $encoded ) ) {
			return array(
				'valid' => false,
				'value' => null,
			);
		}

		$decoded = json_decode( $encoded, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return array(
				'valid' => false,
				'value' => null,
			);
		}

		return array(
			'valid' => true,
			'value' => $decoded,
		);
	}

	/**
	 * Return an API unavailable response.
	 *
	 * @return array<string, mixed>
	 */
	private function unavailable(): array {
		return $this->error( 'abilities_api_unavailable', 'The WordPress Abilities API is not available on this site.' );
	}

	/**
	 * Return a consistent error payload.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return array<string, string>
	 */
	private function error( string $code, string $message ): array {
		return array(
			'error'   => $code,
			'message' => $message,
		);
	}
}

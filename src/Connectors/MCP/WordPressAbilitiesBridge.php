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

		$items = array_values(
			array_filter(
				array_map( array( $this, 'map_ability' ), $this->abilities() ),
				static function ( array $ability ) use ( $search, $category ): bool {
					if ( empty( $ability['public'] ) ) {
						return false;
					}

					if ( empty( $ability['allowed'] ) ) {
						return false;
					}

					if ( '' !== $category && $category !== $ability['category'] ) {
						return false;
					}

					if ( '' === $search ) {
						return true;
					}

					$haystack = strtolower( implode( ' ', array( $ability['id'], $ability['title'], $ability['description'] ) ) );
					return str_contains( $haystack, strtolower( $search ) );
				}
			)
		);

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

		return $this->map_ability( $ability, true );
	}

	/**
	 * Execute a WordPress ability through its registered callback.
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

		$input      = isset( $args['arguments'] ) && is_array( $args['arguments'] ) ? $args['arguments'] : array();
		$permission = $this->permission_result( $ability, $input );
		if ( $permission instanceof WP_Error ) {
			return $this->error( (string) $permission->get_error_code(), $permission->get_error_message() );
		}

		if ( true !== $permission ) {
			return $this->error( 'forbidden', 'You do not have permission to execute this WordPress ability.' );
		}

		$result = $ability->execute( $input );

		return array(
			'ability' => $this->ability_name( $ability ),
			'result'  => $this->normalize_result( $result ),
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

		$abilities = call_user_func( 'wp_get_abilities' );
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

		foreach ( $this->abilities() as $ability ) {
			if ( hash_equals( $this->ability_name( $ability ), $name ) ) {
				return $ability;
			}
		}

		return null;
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
			$item['inputSchema']  = $this->method_array( $ability, 'get_input_schema' );
			$item['outputSchema'] = $this->method_array( $ability, 'get_output_schema' );
			$item['meta']         = $meta;
		}

		return $item;
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
		$meta = $this->ability_meta( $ability );
		if ( isset( $meta['show_in_rest'] ) ) {
			return (bool) $meta['show_in_rest'];
		}

		if ( isset( $meta['mcp'] ) && is_array( $meta['mcp'] ) && array_key_exists( 'public', $meta['mcp'] ) ) {
			return (bool) $meta['mcp']['public'];
		}

		return false;
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
	 * Execute the registered WordPress Ability permission callback when available.
	 *
	 * @param object               $ability Ability object.
	 * @param array<string, mixed> $input   Ability input.
	 * @return bool|WP_Error
	 */
	private function permission_result( object $ability, array $input ): bool|WP_Error {
		foreach ( array( 'check_permission', 'has_permission', 'can_execute' ) as $method ) {
			if ( method_exists( $ability, $method ) ) {
				return $this->normalize_permission_result(
					$this->call_permission_callback(
						static fn ( array $permission_input ): mixed => $ability->{$method}( $permission_input ),
						$input
					)
				);
			}
		}

		if ( method_exists( $ability, 'get_permission_callback' ) ) {
			$callback = $ability->get_permission_callback();
			if ( is_callable( $callback ) ) {
				return $this->normalize_permission_result( $this->call_permission_callback( $callback, $input ) );
			}
		}

		$meta = $this->ability_meta( $ability );
		if ( isset( $meta['permission_callback'] ) && is_callable( $meta['permission_callback'] ) ) {
			return $this->normalize_permission_result( $this->call_permission_callback( $meta['permission_callback'], $input ) );
		}

		return new WP_Error(
			'permission_callback_unavailable',
			'This WordPress ability cannot be executed because its permission callback is unavailable.'
		);
	}

	/**
	 * Call a permission callback and convert thrown failures into a safe error.
	 *
	 * @param callable             $callback Permission callback.
	 * @param array<string, mixed> $input    Ability input.
	 * @return mixed
	 */
	private function call_permission_callback( callable $callback, array $input ): mixed {
		try {
			return call_user_func( $callback, $input );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return new WP_Error(
				'permission_callback_failed',
				'The WordPress ability permission callback could not be evaluated.'
			);
		}
	}

	/**
	 * Normalize a WordPress Ability permission result.
	 *
	 * @param mixed $result Permission callback result.
	 * @return bool|WP_Error
	 */
	private function normalize_permission_result( mixed $result ): bool|WP_Error {
		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return true === $result;
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

		$value = $object->{$method}();
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

		$value = $object->{$method}();
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Normalize ability execution results into JSON-safe data.
	 *
	 * @param mixed $result Ability result.
	 * @return mixed
	 */
	private function normalize_result( mixed $result ): mixed {
		if ( $result instanceof WP_Error ) {
			return $this->error( (string) $result->get_error_code(), $result->get_error_message() );
		}

		if ( $result instanceof WP_REST_Response ) {
			return $result->get_data();
		}

		return $result;
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

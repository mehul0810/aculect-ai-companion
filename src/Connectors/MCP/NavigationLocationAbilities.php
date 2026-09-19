<?php
/**
 * Bounded, confirmation-ready classic navigation location assignment.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Reads the full native location map and changes one registered location only. */
final class NavigationLocationAbilities extends AbstractAbilityService {

	private const MAX_LOCATIONS = 100;

	/**
	 * Read the active theme, registered locations and full native menu map.
	 *
	 * @param array<string, mixed> $args Unused; the context is site-wide.
	 * @return array<string, mixed>
	 */
	public function read_context( array $args = array() ): array {
		unset( $args );
		if ( ! $this->runtime_available() ) {
			return $this->error( 'location_unavailable', 'Required native navigation APIs are unavailable.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error( 'forbidden', 'Reading native menu locations requires edit_theme_options.' );
		}

		try {
			$state = $this->state();
			if ( isset( $state['error'] ) ) {
				return $state;
			}
			return array(
				'site_id'              => $state['site_id'],
				'active_theme'         => $state['theme'],
				'registered_locations' => $state['registered_locations'],
				'location_map'         => $state['location_map'],
				'expected_state'       => $state['expected_state'],
			);
		} catch ( \Throwable ) {
			return $this->error( 'location_unavailable', 'The native location state could not be read safely.' );
		}
	}

	/**
	 * Preview or assign one registered location to an existing classic menu.
	 *
	 * @param array<string, mixed> $args Location, menu ID and state token.
	 * @return array<string, mixed>
	 */
	public function assign_location( array $args ): array {
		if ( ! $this->runtime_available() ) {
			return $this->error( 'location_unavailable', 'Required native navigation APIs are unavailable.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error( 'forbidden', 'Assigning native menu locations requires edit_theme_options.' );
		}

		$location = $args['location'] ?? null;
		$menu_id  = $args['menu_id'] ?? null;
		$expected = $args['expected_state'] ?? null;
		if ( ! is_string( $location ) || '' === $location || strlen( $location ) > 100 || sanitize_key( $location ) !== $location ) {
			return $this->error( 'invalid_location', 'Provide an exact registered location slug.' );
		}
		if ( ! is_int( $menu_id ) || $menu_id < 0 ) {
			return $this->error( 'invalid_menu', 'menu_id must be a positive integer or 0 to unassign.' );
		}
		if ( ! is_string( $expected ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) {
			return $this->error( 'invalid_state', 'Provide the expected_state returned by navigation.read_location_context.' );
		}

		try {
			$state = $this->state();
			if ( isset( $state['error'] ) ) {
				return $state;
			}
			if ( ! hash_equals( $state['expected_state'], $expected ) ) {
				return $this->error( 'stale_state', 'Theme locations or assignments changed; read and preview again.' );
			}
			if ( ! array_key_exists( $location, $state['registered_locations'] ) ) {
				return $this->error( 'invalid_location', 'The location is not registered by the active theme.' );
			}
			if ( $menu_id > 0 && ! $this->existing_menu( $menu_id ) ) {
				return $this->error( 'invalid_menu', 'menu_id must identify an existing classic navigation menu.' );
			}
			if ( $this->is_dry_run( $args ) ) {
				return $this->preview( $args, $state, $location, $menu_id );
			}
		} catch ( \Throwable ) {
			return $this->error( 'location_unavailable', 'Location assignment could not be prepared; no write was attempted.' );
		}

		return $this->persist( $args, $state, $location, $menu_id );
	}

	/**
	 * Capture an exact, bounded and keyed snapshot of native location state.
	 *
	 * @return array<string, mixed>
	 */
	private function state(): array {
		$site_id          = get_current_blog_id();
		$theme            = array(
			'stylesheet' => get_stylesheet(),
			'template'   => get_template(),
		);
		$registered       = $this->registered_locations( get_registered_nav_menus() );
		$theme_mods       = get_theme_mods();
		$raw_location_map = is_array( $theme_mods ) && array_key_exists( 'nav_menu_locations', $theme_mods ) ? $theme_mods['nav_menu_locations'] : array();
		$location_map     = $this->location_map( $raw_location_map );
		$effective_map    = $this->location_map( get_nav_menu_locations() );
		if ( ! is_int( $site_id ) || $site_id < 1 || ! is_string( $theme['stylesheet'] ) || '' === $theme['stylesheet'] || strlen( $theme['stylesheet'] ) > 128 || ! is_string( $theme['template'] ) || '' === $theme['template'] || strlen( $theme['template'] ) > 128 || false === $registered || false === $location_map || false === $effective_map || $effective_map !== $location_map ) {
			return $this->error( 'invalid_location_state', 'The active theme location state is invalid or exceeds the 100-entry bound.' );
		}

		$encoded = wp_json_encode(
			array(
				'site_id'              => $site_id,
				'theme'                => $theme,
				'registered_locations' => $registered,
				'location_map'         => $location_map,
			)
		);
		$salt    = wp_salt( 'auth' );
		if ( ! is_string( $encoded ) || ! is_string( $salt ) || '' === $salt ) {
			return $this->error( 'invalid_location_state', 'The native location state could not be keyed safely.' );
		}

		return array(
			'site_id'              => $site_id,
			'theme'                => $theme,
			'registered_locations' => $registered,
			'location_map'         => $location_map,
			'expected_state'       => hash_hmac( 'sha256', $encoded, $salt ),
		);
	}

	/**
	 * Normalize only labels while retaining every registered location key.
	 *
	 * @param mixed $value Native registered locations.
	 * @return array<string, string>|false
	 */
	private function registered_locations( mixed $value ): array|false {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_LOCATIONS ) {
			return false;
		}
		$locations = array();
		foreach ( $value as $slug => $label ) {
			if ( ! is_string( $slug ) || '' === $slug || strlen( $slug ) > 100 || sanitize_key( $slug ) !== $slug || ! is_string( $label ) || strlen( $label ) > 255 ) {
				return false;
			}
			$locations[ $slug ] = sanitize_text_field( $label );
		}
		ksort( $locations, SORT_STRING );
		return $locations;
	}

	/**
	 * Validate and retain the complete native map, including unknown locations.
	 *
	 * @param mixed $value Native nav_menu_locations value.
	 * @return array<string, int|string>|false
	 */
	private function location_map( mixed $value ): array|false {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_LOCATIONS ) {
			return false;
		}
		$map = array();
		foreach ( $value as $slug => $menu_id ) {
			if ( ! is_string( $slug ) || '' === $slug || strlen( $slug ) > 100 || sanitize_key( $slug ) !== $slug || ! $this->valid_mapped_menu_id( $menu_id ) ) {
				return false;
			}
			$map[ $slug ] = $menu_id;
		}
		ksort( $map, SORT_STRING );
		return $map;
	}

	/**
	 * Accept only native nonnegative integer menu IDs or their decimal strings.
	 *
	 * @param mixed $value Candidate native map value.
	 */
	private function valid_mapped_menu_id( mixed $value ): bool {
		if ( is_int( $value ) ) {
			return $value >= 0;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return false;
		}
		$integer = filter_var( $value, FILTER_VALIDATE_INT );
		return is_int( $integer ) && $integer >= 0 && (string) $integer === $value;
	}

	/**
	 * Build a preview that names only the location slot being displaced.
	 *
	 * @param array<string, mixed> $args Original arguments.
	 * @param array<string, mixed> $state Current state.
	 * @param string               $location Registered slug.
	 * @param int                  $menu_id Requested menu or zero.
	 * @return array<string, mixed>
	 */
	private function preview( array $args, array $state, string $location, int $menu_id ): array {
		$current = $this->mapped_menu_id( $state['location_map'][ $location ] ?? 0 );
		return $this->preview_response(
			'navigation.assign_location',
			$args,
			array(
				'site_id'           => $state['site_id'],
				'active_theme'      => $state['theme'],
				'location'          => $location,
				'location_label'    => $state['registered_locations'][ $location ],
				'current_menu_id'   => $current,
				'menu_id'           => $menu_id,
				'displaced_menu_id' => $current > 0 && $current !== $menu_id ? $current : 0,
			),
			array( $this->change( 'menu_id', $current, $menu_id ) ),
			$this->warnings()
		);
	}

	/**
	 * Recheck complete state and save one native theme-mod map exactly once.
	 *
	 * @param array<string, mixed> $args Original write arguments.
	 * @param array<string, mixed> $initial Originally inspected state.
	 * @param string               $location Registered slug.
	 * @param int                  $menu_id Requested menu or zero.
	 * @return array<string, mixed>
	 */
	private function persist( array $args, array $initial, string $location, int $menu_id ): array {
		try {
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				return $this->error( 'forbidden', 'Assigning native menu locations requires edit_theme_options.' );
			}
			$fresh = $this->state();
			if ( isset( $fresh['error'] ) ) {
				return $this->error( 'stale_state', 'Theme locations or assignments changed; read and preview again.' );
			}
			if ( ! hash_equals( $initial['expected_state'], $fresh['expected_state'] ) || ! hash_equals( $args['expected_state'], $fresh['expected_state'] ) ) {
				return $this->error( 'stale_state', 'Theme locations or assignments changed before saving; read and preview again.' );
			}
			if ( ! array_key_exists( $location, $fresh['registered_locations'] ) ) {
				return $this->error( 'stale_state', 'The location is no longer registered by the active theme.' );
			}
			if ( $menu_id > 0 && ! $this->existing_menu( $menu_id ) ) {
				return $this->error( 'invalid_menu', 'menu_id must identify an existing classic navigation menu.' );
			}
		} catch ( \Throwable ) {
			return $this->error( 'location_unavailable', 'Fresh location state could not be checked; no write was attempted.' );
		}

		$current = $this->mapped_menu_id( $fresh['location_map'][ $location ] ?? 0 );
		if ( $current === $menu_id ) {
			return array(
				'success'        => true,
				'changed'        => false,
				'site_id'        => $fresh['site_id'],
				'location'       => $location,
				'menu_id'        => $menu_id,
				'location_map'   => $fresh['location_map'],
				'expected_state' => $fresh['expected_state'],
			);
		}

		$expected_map              = $fresh['location_map'];
		$expected_map[ $location ] = $menu_id;
		ksort( $expected_map, SORT_STRING );
		try {
			set_theme_mod( 'nav_menu_locations', $expected_map );
			$after = $this->state();
		} catch ( \Throwable ) {
			return $this->uncertain();
		}
		if ( isset( $after['error'] ) ) {
			return $this->uncertain();
		}
		if ( $after['site_id'] !== $fresh['site_id'] || $after['theme'] !== $fresh['theme'] || $after['registered_locations'] !== $fresh['registered_locations'] || $after['location_map'] !== $expected_map ) {
			return $this->uncertain();
		}

		return array(
			'success'           => true,
			'changed'           => true,
			'site_id'           => $after['site_id'],
			'location'          => $location,
			'menu_id'           => $menu_id,
			'displaced_menu_id' => $current > 0 ? $current : 0,
			'location_map'      => $after['location_map'],
			'expected_state'    => $after['expected_state'],
			'warnings'          => $this->warnings(),
		);
	}

	/**
	 * Resolve an existing classic menu and defend against filtered substitutions.
	 *
	 * @param int $menu_id Menu ID.
	 */
	private function existing_menu( int $menu_id ): bool {
		$menu = wp_get_nav_menu_object( $menu_id );
		return $menu instanceof \WP_Term && 'nav_menu' === $menu->taxonomy && $menu->term_id === $menu_id;
	}

	/**
	 * Convert an already validated native map value to its semantic ID.
	 *
	 * @param mixed $value Native map value.
	 */
	private function mapped_menu_id( mixed $value ): int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			$integer = filter_var( $value, FILTER_VALIDATE_INT );
			if ( is_int( $integer ) && $integer >= 0 && (string) $integer === $value ) {
				return $integer;
			}
		}
		return 0;
	}

	/** Load the fixed native APIs required by this ability. */
	private function runtime_available(): bool {
		$required = array( 'current_user_can', 'get_registered_nav_menus', 'get_nav_menu_locations', 'get_theme_mods', 'get_stylesheet', 'get_template', 'get_current_blog_id', 'wp_salt', 'wp_json_encode', 'sanitize_key', 'sanitize_text_field', 'wp_get_nav_menu_object', 'set_theme_mod' );
		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) || ! is_callable( $function ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Disclose the narrowly scoped effect and native lifecycle behavior.
	 *
	 * @return list<string>
	 */
	private function warnings(): array {
		return array(
			'Only this registered location mapping changes. Existing menus, menu items and other registered or unregistered location mappings are preserved.',
			'Assigning a different menu displaces the current menu from this location only; no menu, item, file or theme is deleted.',
			'Use menu_id 0 to explicitly unassign this location.',
		);
	}

	/**
	 * Return a terminal result when the native setter or its hooks are uncertain.
	 *
	 * @return array<string, mixed>
	 */
	private function uncertain(): array {
		return array(
			'error'         => 'partial_write',
			'terminal'      => true,
			'status'        => 'partial_write',
			'partial_write' => true,
			'message'       => 'The native theme location write could not be verified. Inspect the full location map manually; do not retry automatically.',
		);
	}
}

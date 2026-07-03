<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Builds a bounded read-only WordPress REST/core schema manifest.
 */
final class CoreSchemaDiscovery extends AbstractAbilityService {
	private const MAX_POST_TYPES = 50;
	private const MAX_TAXONOMIES = 50;
	private const MAX_STATUSES   = 30;
	private const MAX_ROUTES     = 80;
	private const MAX_ROUTE_ARGS = 40;

	/**
	 * Return a compact core capability and REST schema manifest.
	 *
	 * @return array<string, mixed>
	 */
	public function manifest(): array {
		if ( ! current_user_can( 'read' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to discover WordPress core schema information.' );
		}

		$post_types = $this->post_types();
		$routes     = $this->rest_routes();

		return array(
			'schema_version' => '2026-07-03',
			'description'    => 'Bounded read-only WordPress REST/core schema discovery for MCP planning. Route callbacks, nonces, secrets, option values, and private plugin internals are intentionally omitted.',
			'wordpress'      => array(
				'version'   => get_bloginfo( 'version' ),
				'multisite' => is_multisite(),
				'rest_url'  => function_exists( 'rest_url' ) ? rest_url() : '',
			),
			'capabilities'   => array(
				'read'               => current_user_can( 'read' ),
				'edit_posts'         => current_user_can( 'edit_posts' ),
				'publish_posts'      => current_user_can( 'publish_posts' ),
				'upload_files'       => current_user_can( 'upload_files' ),
				'moderate_comments'  => current_user_can( 'moderate_comments' ),
				'edit_theme_options' => current_user_can( 'edit_theme_options' ),
				'manage_options'     => current_user_can( 'manage_options' ),
				'list_users'         => current_user_can( 'list_users' ),
			),
			'post_types'     => $post_types,
			'taxonomies'     => $this->taxonomies(),
			'statuses'       => $this->post_statuses(),
			'rest'           => $routes,
			'features'       => array(
				'revisions'   => $this->feature_map( $post_types, 'revisions', $routes['routes'] ),
				'autosaves'   => $this->feature_map( $post_types, 'autosaves', $routes['routes'] ),
				'site_editor' => array(
					'available'            => function_exists( 'wp_is_block_theme' ) ? (bool) wp_is_block_theme() : false,
					'can_edit_theme'       => current_user_can( 'edit_theme_options' ),
					'template_routes'      => $this->route_available( $routes['routes'], '/wp/v2/templates' ),
					'template_part_routes' => $this->route_available( $routes['routes'], '/wp/v2/template-parts' ),
					'navigation_routes'    => $this->route_available( $routes['routes'], '/wp/v2/navigation' ),
				),
			),
			'diagnostics'    => $this->diagnostics( $post_types, $routes ),
		);
	}

	/**
	 * Return bounded supported post type metadata.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function post_types(): array {
		$types = get_post_types( array(), 'objects' );
		if ( ! is_array( $types ) ) {
			return array();
		}

		$items = array();
		foreach ( $types as $type ) {
			if ( ! $type instanceof \WP_Post_Type || ! $this->include_type( $type ) ) {
				continue;
			}

			$name      = $this->string_property( $type, 'name', '' );
			$rest_base = $this->string_property( $type, 'rest_base', $name );
			$can_edit  = $this->post_type_capabilities( $type )['can_edit'];
			$features  = $this->post_type_features( $name );

			$items[] = array(
				'name'            => $name,
				'label'           => $this->string_property( $type, 'label', $name ),
				'rest_base'       => '' === $rest_base ? $name : $rest_base,
				'rest_namespace'  => $this->string_property( $type, 'rest_namespace', 'wp/v2' ),
				'public'          => $type->public,
				'show_ui'         => $type->show_ui,
				'show_in_rest'    => $type->show_in_rest,
				'hierarchical'    => $type->hierarchical,
				'capabilities'    => $this->post_type_capabilities( $type ),
				'features'        => $features,
				'taxonomies'      => $this->post_type_taxonomies( $name ),
				'editable_fields' => array(
					'title'          => $can_edit && $features['title'],
					'content'        => $can_edit && $features['editor'],
					'excerpt'        => $can_edit && $features['excerpt'],
					'slug'           => $can_edit,
					'status'         => $can_edit,
					'featured_media' => $can_edit && $features['thumbnail'],
					'author'         => $can_edit && $features['author'],
					'taxonomies'     => $can_edit,
				),
			);

			if ( count( $items ) >= self::MAX_POST_TYPES ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * Check whether a post type is safe to include in discovery.
	 *
	 * @param \WP_Post_Type $type Post type object.
	 */
	private function include_type( \WP_Post_Type $type ): bool {
		return '' !== $this->string_property( $type, 'name', '' )
			&& ( $type->show_in_rest || $type->public || $type->show_ui );
	}

	/**
	 * Return safe post type capability booleans.
	 *
	 * @param \WP_Post_Type $type Post type object.
	 * @return array<string, bool>
	 */
	private function post_type_capabilities( \WP_Post_Type $type ): array {
		$cap = $type->cap;

		return array(
			'can_read'    => $this->can_current_user( $cap->read ?? 'read' ),
			'can_create'  => $this->can_current_user( $cap->create_posts ?? ( $cap->edit_posts ?? 'edit_posts' ) ),
			'can_edit'    => $this->can_current_user( $cap->edit_posts ?? 'edit_posts' ),
			'can_publish' => $this->can_current_user( $cap->publish_posts ?? 'publish_posts' ),
			'can_delete'  => $this->can_current_user( $cap->delete_posts ?? 'delete_posts' ),
		);
	}

	/**
	 * Return supported editor/content features for a post type.
	 *
	 * @param string $post_type Post type name.
	 * @return array<string, bool>
	 */
	private function post_type_features( string $post_type ): array {
		$map = array();
		foreach ( array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'custom-fields', 'page-attributes', 'revisions' ) as $feature ) {
			$map[ $feature ] = post_type_supports( $post_type, $feature );
		}

		return $map;
	}

	/**
	 * Return taxonomies associated with a post type.
	 *
	 * @param string $post_type Post type name.
	 * @return list<string>
	 */
	private function post_type_taxonomies( string $post_type ): array {
		if ( ! function_exists( 'get_object_taxonomies' ) ) {
			return array();
		}

		$taxonomies = get_object_taxonomies( $post_type, 'names' );

		return array_values( array_map( 'strval', $taxonomies ) );
	}

	/**
	 * Return bounded taxonomy metadata.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function taxonomies(): array {
		if ( ! function_exists( 'get_taxonomies' ) ) {
			return array();
		}

		$taxonomies = get_taxonomies( array(), 'objects' );
		if ( ! is_array( $taxonomies ) ) {
			return array();
		}

		$items = array();
		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy instanceof \WP_Taxonomy ) {
				continue;
			}

			$name = $this->string_property( $taxonomy, 'name', '' );
			if ( '' === $name || ( ! $taxonomy->show_in_rest && ! $taxonomy->public && ! $taxonomy->show_ui ) ) {
				continue;
			}

			$cap = $taxonomy->cap;

			$items[] = array(
				'name'           => $name,
				'label'          => $this->string_property( $taxonomy, 'label', $name ),
				'rest_base'      => $this->string_property( $taxonomy, 'rest_base', $name ),
				'rest_namespace' => $this->string_property( $taxonomy, 'rest_namespace', 'wp/v2' ),
				'public'         => $taxonomy->public,
				'show_ui'        => $taxonomy->show_ui,
				'show_in_rest'   => $taxonomy->show_in_rest,
				'hierarchical'   => $taxonomy->hierarchical,
				'object_types'   => array_values( array_map( 'strval', $taxonomy->object_type ) ),
				'capabilities'   => array(
					'can_assign' => $this->can_current_user( $cap->assign_terms ?? 'edit_posts' ),
					'can_edit'   => $this->can_current_user( $cap->edit_terms ?? 'manage_categories' ),
					'can_manage' => $this->can_current_user( $cap->manage_terms ?? 'manage_categories' ),
				),
			);

			if ( count( $items ) >= self::MAX_TAXONOMIES ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * Return bounded post status metadata.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function post_statuses(): array {
		if ( ! function_exists( 'get_post_stati' ) ) {
			return array();
		}

		$statuses = get_post_stati( array(), 'objects' );
		if ( ! is_array( $statuses ) ) {
			return array();
		}

		$items = array();
		foreach ( $statuses as $name => $status ) {
			if ( ! is_object( $status ) ) {
				continue;
			}

			$items[] = array(
				'name'                   => (string) ( $status->name ?? $name ),
				'label'                  => (string) ( $status->label ?? $name ),
				'public'                 => (bool) ( $status->public ?? false ),
				'private'                => (bool) ( $status->private ?? false ),
				'protected'              => (bool) ( $status->protected ?? false ),
				'internal'               => (bool) ( $status->internal ?? false ),
				'show_in_admin_all_list' => (bool) ( $status->show_in_admin_all_list ?? false ),
			);

			if ( count( $items ) >= self::MAX_STATUSES ) {
				break;
			}
		}

		return $items;
	}

	/**
	 * Return bounded REST route metadata without callbacks.
	 *
	 * @return array{available: bool, namespaces: list<string>, routes: list<array<string, mixed>>, total: int, truncated: bool}
	 */
	private function rest_routes(): array {
		if ( ! function_exists( 'rest_get_server' ) ) {
			return array(
				'available'  => false,
				'namespaces' => array(),
				'routes'     => array(),
				'total'      => 0,
				'truncated'  => false,
			);
		}

		$server = rest_get_server();
		$raw    = method_exists( $server, 'get_routes' ) ? $server->get_routes() : array();
		$routes = array();

		foreach ( $raw as $route => $endpoints ) {
			$route = (string) $route;
			if ( ! $this->include_route( $route ) ) {
				continue;
			}

			$routes[] = array(
				'route'     => $route,
				'namespace' => $this->route_namespace( $route ),
				'methods'   => $this->route_methods( is_array( $endpoints ) ? $endpoints : array() ),
				'args'      => $this->route_args( is_array( $endpoints ) ? $endpoints : array() ),
			);
		}

		usort( $routes, static fn( array $a, array $b ): int => strcmp( (string) $a['route'], (string) $b['route'] ) );

		$total     = count( $routes );
		$truncated = $total > self::MAX_ROUTES;
		$routes    = array_slice( $routes, 0, self::MAX_ROUTES );

		return array(
			'available'  => true,
			'namespaces' => array_values( array_unique( array_column( $routes, 'namespace' ) ) ),
			'routes'     => $routes,
			'total'      => $total,
			'truncated'  => $truncated,
		);
	}

	/**
	 * Check whether a REST route is relevant to core planning.
	 *
	 * @param string $route REST route.
	 */
	private function include_route( string $route ): bool {
		foreach ( array( '/wp/v2', '/wp-site-health/v1', '/wp-block-editor/v1', '/wp-pattern-directory/v1' ) as $prefix ) {
			if ( str_starts_with( $route, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the REST namespace for a route.
	 *
	 * @param string $route REST route.
	 */
	private function route_namespace( string $route ): string {
		$parts = array_values( array_filter( explode( '/', trim( $route, '/' ) ), static fn( string $part ): bool => '' !== $part ) );

		return count( $parts ) >= 2 ? $parts[0] . '/' . $parts[1] : '';
	}

	/**
	 * Return distinct HTTP methods for a route.
	 *
	 * @param array<mixed> $endpoints Route endpoint definitions.
	 * @return list<string>
	 */
	private function route_methods( array $endpoints ): array {
		$methods = array();
		foreach ( $endpoints as $endpoint ) {
			if ( is_array( $endpoint ) && isset( $endpoint['methods'] ) ) {
				$methods = array_merge( $methods, $this->normalize_methods( $endpoint['methods'] ) );
			}
		}

		$methods = array_values( array_unique( $methods ) );
		sort( $methods );

		return $methods;
	}

	/**
	 * Return distinct argument names exposed by a route.
	 *
	 * @param array<mixed> $endpoints Route endpoint definitions.
	 * @return list<string>
	 */
	private function route_args( array $endpoints ): array {
		$args = array();
		foreach ( $endpoints as $endpoint ) {
			if ( is_array( $endpoint ) && isset( $endpoint['args'] ) && is_array( $endpoint['args'] ) ) {
				$args = array_merge( $args, array_map( 'strval', array_keys( $endpoint['args'] ) ) );
			}
		}

		$args = array_values( array_unique( $args ) );
		sort( $args );

		return array_slice( $args, 0, self::MAX_ROUTE_ARGS );
	}

	/**
	 * Normalize REST method definitions.
	 *
	 * @param mixed $methods Route methods.
	 * @return list<string>
	 */
	private function normalize_methods( mixed $methods ): array {
		if ( is_string( $methods ) ) {
			$methods = preg_split( '/[\s,|]+/', $methods );
		}

		$normalized = array();
		foreach ( is_array( $methods ) ? $methods : array() as $method => $enabled ) {
			if ( is_string( $method ) && is_bool( $enabled ) ) {
				if ( $enabled ) {
					$normalized[] = strtoupper( $method );
				}
			} elseif ( is_scalar( $enabled ) ) {
				$normalized[] = strtoupper( (string) $enabled );
			}
		}

		return array_values( array_filter( $normalized, static fn( string $method ): bool => '' !== $method ) );
	}

	/**
	 * Return per-post-type revisions/autosaves availability.
	 *
	 * @param list<array<string, mixed>> $post_types Post type metadata.
	 * @param string                     $feature Feature name.
	 * @param list<array<string, mixed>> $routes REST route metadata.
	 * @return array<string, array<string, bool>>
	 */
	private function feature_map( array $post_types, string $feature, array $routes ): array {
		$map = array();
		foreach ( $post_types as $post_type ) {
			$name = (string) ( $post_type['name'] ?? '' );
			$base = (string) ( $post_type['rest_base'] ?? $name );
			if ( '' === $name ) {
				continue;
			}

			$map[ $name ] = array(
				'supports'   => 'revisions' === $feature ? post_type_supports( $name, 'revisions' ) : post_type_supports( $name, 'editor' ),
				'rest_route' => $this->route_available( $routes, '/wp/v2/' . $base . '/(?P<parent>[\\d]+)/' . $feature ),
			);
		}

		return $map;
	}

	/**
	 * Return whether a route collection includes a route prefix or exact route.
	 *
	 * @param list<array<string, mixed>> $routes REST route metadata.
	 * @param string                     $needle Route needle.
	 */
	private function route_available( array $routes, string $needle ): bool {
		foreach ( $routes as $route ) {
			$value = (string) ( $route['route'] ?? '' );
			if ( $value === $needle || str_starts_with( $value, $needle ) || str_contains( $value, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return diagnostics about unavailable or version-dependent surfaces.
	 *
	 * @param list<array<string, mixed>>                                                                                        $post_types Post type metadata.
	 * @param array{available: bool, namespaces: list<string>, routes: list<array<string, mixed>>, total: int, truncated: bool} $routes REST route metadata.
	 * @return list<array<string, mixed>>
	 */
	private function diagnostics( array $post_types, array $routes ): array {
		$diagnostics = array();
		if ( false === $routes['available'] ) {
			$diagnostics[] = array(
				'id'       => 'rest_runtime_unavailable',
				'severity' => 'warning',
				'message'  => 'WordPress REST server discovery is unavailable in this runtime.',
			);
		}
		if ( array() === $routes['routes'] ) {
			$diagnostics[] = array(
				'id'       => 'no_core_rest_routes',
				'severity' => 'warning',
				'message'  => 'No bounded WordPress core REST routes were discovered.',
			);
		}
		if ( array() !== array_filter( $post_types, static fn( array $type ): bool => false === (bool) ( $type['show_in_rest'] ?? false ) ) ) {
			$diagnostics[] = array(
				'id'       => 'post_types_hidden_from_rest',
				'severity' => 'info',
				'message'  => 'Some visible post types are not exposed in REST and may need admin UI or custom-tool support.',
			);
		}
		if ( ! $this->route_available( $routes['routes'], '/wp/v2/templates' ) ) {
			$diagnostics[] = array(
				'id'       => 'site_editor_routes_unavailable',
				'severity' => 'info',
				'message'  => 'Site Editor template routes were not discovered; this can be theme or WordPress-version dependent.',
			);
		}
		if ( true === $routes['truncated'] ) {
			$diagnostics[] = array(
				'id'       => 'rest_routes_truncated',
				'severity' => 'info',
				'message'  => 'REST route output was truncated to keep discovery bounded.',
				'total'    => $routes['total'],
				'limit'    => self::MAX_ROUTES,
			);
		}

		return $diagnostics;
	}

	/**
	 * Safely read a string property from a WordPress object.
	 *
	 * @param object $object Object.
	 * @param string $property Property name.
	 * @param string $default Default value.
	 */
	private function string_property( object $object, string $property, string $default ): string {
		$value = $object->{$property} ?? $default;

		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * Check a scalar capability value.
	 *
	 * @param mixed $capability Capability name.
	 */
	private function can_current_user( mixed $capability ): bool {
		return is_scalar( $capability ) && '' !== (string) $capability && current_user_can( (string) $capability );
	}
}

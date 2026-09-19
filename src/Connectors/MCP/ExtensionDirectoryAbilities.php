<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Closure;
use Throwable;

/**
 * Bounded directory discovery and manual native ZIP-upload handoffs.
 */
final class ExtensionDirectoryAbilities extends AbstractAbilityService {

	public function __construct( private readonly ?Closure $query = null ) {}

	/**
	 * Search public directory metadata without installing executable code.
	 *
	 * @param string               $kind Plugin or theme.
	 * @param array<string, mixed> $args Search arguments.
	 * @return array<string, mixed>
	 */
	public function search( string $kind, array $args ): array {
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || ! current_user_can( 'install_' . $kind . 's' ) ) {
			return $this->error( 'forbidden', 'Directory discovery requires installation permission.' );
		}
		$query = $args['search'] ?? null;
		$page  = $args['page'] ?? 1;
		$limit = $args['per_page'] ?? 20;
		if ( ! is_string( $query ) || '' === trim( $query ) || strlen( $query ) > 120 || ! is_int( $page ) || $page < 1 || $page > 100 || ! is_int( $limit ) || $limit < 1 || $limit > 30 ) {
			return $this->error( 'invalid_search', 'Provide search text up to 120 bytes, page 1–100, and per_page 1–30.' );
		}
		try {
			$response = $this->query_directory( $kind, sanitize_text_field( $query ), $page, $limit );
		} catch ( Throwable ) {
			return $this->error( 'directory_unavailable', 'The WordPress.org directory could not be queried.' );
		}
		$rows = $this->field( $response, $kind . 's' );
		if ( is_wp_error( $response ) || ! is_array( $rows ) ) {
			return $this->error( 'directory_unavailable', 'The WordPress.org directory returned no usable results.' );
		}
		$items = array();
		foreach ( array_slice( $rows, 0, $limit ) as $row ) {
			$slug = $this->field( $row, 'slug' );
			if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,99}$/D', $slug ) ) {
				continue;
			}
			$item = array(
				'slug' => $slug,
				'url'  => 'https://wordpress.org/' . $kind . 's/' . $slug . '/',
			);
			foreach ( array( 'name', 'version', 'requires', 'requires_php', 'tested', 'short_description' ) as $key ) {
				$value        = $this->field( $row, $key );
				$item[ $key ] = is_string( $value ) ? mb_substr( wp_strip_all_tags( $value ), 0, 'short_description' === $key ? 300 : 120 ) : '';
			}
			$items[] = $item;
		}
		$pages = $this->field( $this->field( $response, 'info' ), 'pages' );
		$pages = is_int( $pages ) && $pages >= 0 ? min( 100, $pages ) : null;
		return array(
			'status'        => 'ok',
			'source'        => 'wordpress.org',
			'content_trust' => 'untrusted_directory_metadata',
			'items'         => $items,
			'page'          => $page,
			'per_page'      => $limit,
			'returned'      => count( $items ),
			'total_pages'   => $pages,
			'next_page'     => null !== $pages && $page < $pages ? $page + 1 : null,
			'read_only'     => true,
		);
	}

	/**
	 * Return a native upload screen; no file or credential crosses MCP.
	 *
	 * @param string $kind Plugin or theme.
	 * @return array<string, mixed>
	 */
	public function upload( string $kind ): array {
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || ! current_user_can( 'upload_' . $kind . 's' ) || ! current_user_can( 'install_' . $kind . 's' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to upload this extension type.' );
		}
		if ( is_multisite() || ! is_ssl() || ! wp_is_file_mod_allowed( 'aculect_extension_upload' ) ) {
			return $this->error( 'upload_unavailable', 'Manual uploads require HTTPS, a single site, and allowed file modifications.' );
		}
		return array(
			'status'       => 'manual_action_required',
			'mode'         => 'native_wordpress_upload',
			'admin_url'    => admin_url( 'plugin' === $kind ? 'plugin-install.php?tab=upload' : 'theme-install.php?upload', 'https' ),
			'instructions' => 'The user must open WordPress, choose a trusted ZIP, and confirm installation there. Do not send ZIP contents, private download URLs, license keys, or filesystem credentials through AI chat. This handoff does not install or verify a package; WordPress owns upload validation, replacement confirmation, and results.',
			'changed'      => false,
		);
	}

	/**
	 * Query core APIs with bounded fields, leaving remote data untrusted.
	 *
	 * @param string $kind Plugin or theme.
	 * @param string $query Search text.
	 * @param int    $page Page number.
	 * @param int    $limit Result limit.
	 * @return mixed
	 */
	private function query_directory( string $kind, string $query, int $page, int $limit ): mixed {
		$args = array(
			'search'   => $query,
			'page'     => $page,
			'per_page' => $limit,
			'fields'   => array(
				'sections'    => false,
				'banners'     => false,
				'icons'       => false,
				'screenshots' => false,
				'reviews'     => false,
			),
		);
		if ( null !== $this->query ) {
			return ( $this->query )( $kind, $args );
		}
		$function = 'plugin' === $kind ? 'plugins_api' : 'themes_api';
		if ( ! function_exists( $function ) ) {
			require_once ABSPATH . 'wp-admin/includes/' . ( 'plugin' === $kind ? 'plugin-install.php' : 'theme.php' );
		}
		return 'plugin' === $kind ? plugins_api( 'query_plugins', $args ) : themes_api( 'query_themes', $args );
	}

	/**
	 * Read only ordinary API maps; never invoke object magic accessors.
	 *
	 * @param mixed  $value Remote value.
	 * @param string $key Field name.
	 * @return mixed
	 */
	private function field( mixed $value, string $key ): mixed {
		return is_array( $value ) ? ( $value[ $key ] ?? null ) : ( $value instanceof \stdClass ? ( get_object_vars( $value )[ $key ] ?? null ) : null );
	}
}

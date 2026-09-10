<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Compares bounded installed-file batches with fixed WordPress.org manifests.
 */
final class IntegrityChecksumAbilities extends AbstractAbilityService {

	/**
	 * Check one page of manifest-listed core files without changing them.
	 *
	 * @param array<string,mixed> $args Tool arguments.
	 * @return array<string,mixed>
	 */
	public function core( array $args ): array {
		if ( ! current_user_can( 'update_core' ) || ( is_multisite() && ! is_super_admin() ) ) {
			return $this->error( 'forbidden', 'Core update administration permission is required.' );
		}
		global $wp_version;
		$version = is_string( $wp_version ) ? $wp_version : '';
		$locale  = get_locale();
		if ( ! preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/D', $version ) || ! preg_match( '/^[a-zA-Z_]{2,20}$/D', $locale ) ) {
			return $this->error( 'checksums_unavailable', 'This installed core version or locale has no supported stable checksum identity.' );
		}
		$url      = 'https://api.wordpress.org/core/checksums/1.0/?' . http_build_query(
			array(
				'version' => $version,
				'locale'  => $locale,
			),
			'',
			'&'
		);
		$manifest = $this->manifest( $url );
		if ( isset( $manifest['error'] ) ) {
			return $manifest;
		}
		$checksums = $manifest['checksums'] ?? null;
		if ( ! is_array( $checksums ) ) {
			return $this->error( 'checksums_unavailable', 'Official core checksums were not available.' );
		}
		// Bundled themes and plugins have separate ownership; never inspect configuration.
		$checksums = array_filter( $checksums, static fn ( $key ): bool => is_string( $key ) && ! str_starts_with( $key, 'wp-content/' ) && ! str_starts_with( $key, 'wp-config' ), ARRAY_FILTER_USE_KEY );
		return $this->compare( $checksums, ABSPATH, $args, 'core', $version );
	}

	/**
	 * Check a selected installed WordPress.org plugin, not arbitrary paths.
	 *
	 * @param array<string,mixed> $args Tool arguments.
	 * @return array<string,mixed>
	 */
	public function plugin( array $args ): array {
		if ( ! current_user_can( 'update_plugins' ) || ( is_multisite() && ! is_super_admin() ) ) {
			return $this->error( 'forbidden', 'Plugin update administration permission is required.' );
		}
		$file = $args['plugin'] ?? null;
		if ( ! is_string( $file ) || ! preg_match( '~^([a-z0-9-]+)/[a-zA-Z0-9_.-]+\.php$~D', $file, $match ) ) {
			return $this->error( 'invalid_plugin', 'Select an installed directory-based plugin by its exact plugin file.' );
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		$plugin  = $plugins[ $file ] ?? null;
		if ( ! is_array( $plugin ) ) {
			return $this->error( 'not_found', 'The selected plugin is not installed.' );
		}
		$slug    = $match[1];
		$version = $plugin['Version'] ?? null;
		$uri     = $plugin['UpdateURI'] ?? '';
		if ( ! is_string( $version ) || ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/D', $version ) || ! is_string( $uri ) || ( '' !== $uri && ! preg_match( '~^https?://wordpress\.org/plugins/' . preg_quote( $slug, '~' ) . '/?$~D', $uri ) ) ) {
			return $this->error( 'checksums_unsupported', 'Plugins with an external update identity or unsupported version cannot be compared with WordPress.org. Custom and premium packages are not treated as verified.' );
		}
		$manifest = $this->manifest( 'https://downloads.wordpress.org/plugin-checksums/' . $slug . '/' . rawurlencode( $version ) . '.json' );
		if ( isset( $manifest['error'] ) ) {
			return $manifest;
		}
		if ( ( $manifest['plugin'] ?? null ) !== $slug || ( $manifest['version'] ?? null ) !== $version || ! is_array( $manifest['files'] ?? null ) ) {
			return $this->error( 'invalid_manifest', 'The checksum manifest does not match the installed plugin identity.' );
		}
		$checksums = array();
		foreach ( $manifest['files'] as $path => $hashes ) {
			$checksums[ $path ] = is_array( $hashes ) ? ( $hashes['md5'] ?? null ) : null;
		}
		$root = defined( 'WP_PLUGIN_DIR' ) ? (string) constant( 'WP_PLUGIN_DIR' ) : ABSPATH . 'wp-content/plugins';
		return $this->compare( $checksums, $root . '/' . $slug, $args, $file, $version );
	}

	/**
	 * Fetch JSON from a caller-independent official endpoint without redirects.
	 *
	 * @param string $url Fixed official manifest endpoint.
	 * @return array<string,mixed>
	 */
	private function manifest( string $url ): array {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 1048577,
				'sslverify'           => true,
				'cookies'             => array(),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $this->error( 'checksums_unavailable', 'The official checksum service is unavailable or has no manifest for this version.' );
		}
		$body = wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > 1048576 ) {
			return $this->error( 'manifest_too_large', 'The checksum manifest exceeds the 1 MiB limit.' );
		}
		$data = json_decode( $body, true, 16 );
		return is_array( $data ) ? $data : $this->error( 'invalid_manifest', 'The checksum service returned an invalid manifest.' );
	}

	/**
	 * Validate a complete bounded manifest before reading a selected batch.
	 *
	 * @param array<mixed>        $checksums Relative path to MD5 map.
	 * @param string              $root Installed root.
	 * @param array<string,mixed> $args Pagination.
	 * @param string              $target Installed component.
	 * @param string              $version Installed version.
	 * @return array<string,mixed>
	 */
	private function compare( array $checksums, string $root, array $args, string $target, string $version ): array {
		$page  = $args['page'] ?? 1;
		$limit = $args['per_page'] ?? 25;
		if ( ! is_int( $page ) || $page < 1 || $page > 5000 || ! is_int( $limit ) || $limit < 1 || $limit > 50 ) {
			return $this->error( 'invalid_pagination', 'Use page 1–5000 and per_page 1–50.' );
		}
		if ( array() === $checksums || count( $checksums ) > 20000 ) {
			return $this->error( 'invalid_manifest', 'The manifest is empty or exceeds the file-count limit.' );
		}
		foreach ( $checksums as $path => $hash ) {
			if ( ! is_string( $path ) || ! ChecksumFileInspector::valid_path( $path ) || ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{32}$/Di', $hash ) ) {
				return $this->error( 'invalid_manifest', 'The manifest contains an unsafe path or unsupported checksum.' );
			}
		}
		ksort( $checksums, SORT_STRING );
		$inspector = new ChecksumFileInspector( $root );
		$items     = array();
		foreach ( array_slice( $checksums, ( $page - 1 ) * $limit, $limit, true ) as $path => $hash ) {
			$items[] = $inspector->inspect( $path, $hash );
		}
		return array(
			'status'         => 'success',
			'read_only'      => true,
			'target'         => $target,
			'version'        => $version,
			'page'           => $page,
			'per_page'       => $limit,
			'total'          => count( $checksums ),
			'has_more'       => $page * $limit < count( $checksums ),
			'items'          => $items,
			'manifest_state' => hash( 'sha256', (string) wp_json_encode( $checksums ) ),
			'limitations'    => 'Only manifest-listed files in this page were compared. No unknown-file scan, malware verdict, repair, deletion, or whole-installation pass is implied. Keep the same manifest_state while paging.',
		);
	}
}

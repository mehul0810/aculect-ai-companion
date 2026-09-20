<?php
/**
 * Fixed, privacy-aware projection of native Site Health information.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Closure;
use Throwable;

/**
 * Excludes filesystem paths, identities, arbitrary constants and plugin sections.
 */
final class SiteHealthInfo {

	private const FIELDS = array(
		'wp-core'      => array( 'version', 'multisite', 'environment_type' ),
		'wp-server'    => array( 'php_version', 'php_sapi', 'max_input_variables', 'time_limit', 'memory_limit', 'max_input_time', 'upload_max_filesize', 'php_post_max_size' ),
		'wp-database'  => array( 'extension', 'server_version', 'client_version' ),
		'wp-constants' => array( 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'WP_DEBUG', 'WP_DEBUG_DISPLAY', 'WP_DEBUG_LOG', 'SCRIPT_DEBUG', 'WP_CACHE' ),
	);

	public function __construct( private readonly ?Closure $reader = null ) {}

	/**
	 * Return only reviewed scalar fields from one fixed native section.
	 *
	 * @param array<string, mixed> $args Fixed section selector.
	 * @return array<string, mixed>
	 */
	public function read( array $args ): array {
		if ( ! current_user_can( 'view_site_health_checks' ) ) {
			return array( 'error' => 'forbidden' );
		}
		$section = $args['section'] ?? null;
		if ( ! is_string( $section ) || ! isset( self::FIELDS[ $section ] ) || array_diff( array_keys( $args ), array( 'section' ) ) ) {
			return array( 'error' => 'invalid_section' );
		}
		try {
			$data = null !== $this->reader ? ( $this->reader )() : $this->native_data();
		} catch ( Throwable $error ) {
			return array( 'error' => 'native_info_unavailable' );
		}
		if ( ! is_array( $data ) || ! is_array( $data[ $section ] ?? null ) ) {
			return array( 'error' => 'native_info_unavailable' );
		}
		$group = $data[ $section ];
		$items = array();
		if ( ( ! array_key_exists( 'private', $group ) || false === $group['private'] ) && is_array( $group['fields'] ?? null ) ) {
			foreach ( self::FIELDS[ $section ] as $key ) {
				$field = $group['fields'][ $key ] ?? null;
				if ( ! is_array( $field ) || ( array_key_exists( 'private', $field ) && false !== $field['private'] ) ) {
					continue;
				}
				$value = $field['value'] ?? null;
				// WP_DEBUG_LOG can contain a private filename rather than a boolean.
				if ( 'WP_DEBUG_LOG' === $key && ! is_bool( $value ) ) {
					continue;
				}
				if ( is_bool( $value ) || is_int( $value ) || ( is_string( $value ) && strlen( $value ) <= 256 ) ) {
					$items[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
				}
			}
		}
		return array(
			'status'                  => 'ready',
			'section'                 => $section,
			'fields'                  => $items,
			'coverage'                => 'fixed_allowlist_not_full_debug_dump',
			'private_fields_included' => false,
		);
	}

	/**
	 * Load the native reader from fixed core paths only.
	 *
	 * @return mixed Native data or unavailable result.
	 */
	private function native_data(): mixed {
		if ( ! defined( 'ABSPATH' ) ) {
			return null;
		}
		foreach ( array( 'admin.php', 'class-wp-site-health.php', 'class-wp-debug-data.php' ) as $file ) {
			$path = ABSPATH . 'wp-admin/includes/' . $file;
			if ( ! is_file( $path ) ) {
				return null;
			}
			require_once $path;
		}
		return \WP_Debug_Data::debug_data();
	}
}

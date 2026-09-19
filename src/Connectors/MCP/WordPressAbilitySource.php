<?php
/**
 * Conservative provenance checks for WordPress abilities.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Classifies native Core without trusting provider-controlled metadata. */
final class WordPressAbilitySource {

	/**
	 * Core currently exposes no callback getter, so inspect its protected fields.
	 * If that internal contract changes, fail closed to administrator opt-in.
	 *
	 * @param object $ability Registered ability.
	 */
	public function is_core( object $ability ): bool {
		if ( 'WP_Ability' !== get_class( $ability ) || ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) {
			return false;
		}
		try {
			if ( ! str_starts_with( $ability->get_name(), 'core/' ) ) {
				return false;
			}
			$root = realpath( ABSPATH . WPINC );
			if ( false === $root ) {
				return false;
			}
			foreach ( array( 'execute_callback', 'permission_callback' ) as $field ) {
				$property = new \ReflectionProperty( $ability, $field );
				$callback = $property->getValue( $ability );
				if ( ! is_callable( $callback ) ) {
					return false;
				}
				$reflection = new \ReflectionFunction( \Closure::fromCallable( $callback ) );
				$file       = $reflection->getFileName();
				$path       = is_string( $file ) ? realpath( $file ) : false;
				if ( false === $path || ( $root . '/abilities.php' !== $path && ! str_starts_with( $path, $root . '/abilities/' ) ) ) {
					return false;
				}
			}
			return true;
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * Return a namespace label, not a verified plugin brand.
	 *
	 * @param string $id Registered ID.
	 */
	public function provider( string $id ): string {
		return explode( '/', $id, 2 )[0];
	}
}

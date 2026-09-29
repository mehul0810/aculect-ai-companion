<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Support;

use Aculect\AICompanion\Connectors\MCP\Skills\CustomSkillOptionStore;

/**
 * In-memory adapter that models WordPress site-local option tables.
 */
final class ScopedSkillOptions implements CustomSkillOptionStore {

	/**
	 * Site-local option values.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static array $sites = array();

	/**
	 * Whether this adapter should simulate failed option updates.
	 *
	 * @var bool
	 */
	public static bool $fail_updates = false;

	/**
	 * Reset all simulated site option tables.
	 */
	public static function reset(): void {
		self::$sites        = array();
		self::$fail_updates = false;
	}

	/**
	 * Read a value from the current simulated site's option table.
	 *
	 * @param string $name Option name.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public function get( string $name, mixed $default = false ): mixed {
		$site = get_current_blog_id();
		return array_key_exists( $name, self::$sites[ $site ] ?? array() ) ? self::$sites[ $site ][ $name ] : $default;
	}

	/**
	 * Add a value only when absent for this simulated site.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Option value.
	 */
	public function add( string $name, mixed $value ): bool {
		$site = get_current_blog_id();
		if ( array_key_exists( $name, self::$sites[ $site ] ?? array() ) ) {
			return false;
		}
		self::$sites[ $site ][ $name ] = $value;
		return true;
	}

	/**
	 * Update a value in the current simulated site.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Option value.
	 */
	public function update( string $name, mixed $value ): bool {
		if ( self::$fail_updates ) {
			return false;
		}
		$site = get_current_blog_id();
		if ( ( self::$sites[ $site ][ $name ] ?? null ) === $value ) {
			return false;
		}
		self::$sites[ $site ][ $name ] = $value;
		return true;
	}

	/**
	 * Delete a value from the current simulated site.
	 *
	 * @param string $name Option name.
	 */
	public function delete( string $name ): bool {
		$site = get_current_blog_id();
		if ( ! array_key_exists( $name, self::$sites[ $site ] ?? array() ) ) {
			return false;
		}
		unset( self::$sites[ $site ][ $name ] );
		return true;
	}
}

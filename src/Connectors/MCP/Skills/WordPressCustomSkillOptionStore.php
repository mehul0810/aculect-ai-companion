<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Skills;

/**
 * WordPress option adapter. `get_option()` keeps records isolated per site.
 */
final class WordPressCustomSkillOptionStore implements CustomSkillOptionStore {

	/**
	 * Read a site-local option.
	 *
	 * @param string $name Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( string $name, mixed $default = false ): mixed {
		return get_option( $name, $default );
	}

	/**
	 * Add a non-autoloaded option when absent.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Option value.
	 */
	public function add( string $name, mixed $value ): bool {
		return add_option( $name, $value, '', false );
	}

	/**
	 * Update a non-autoloaded option.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Option value.
	 */
	public function update( string $name, mixed $value ): bool {
		return update_option( $name, $value, false );
	}

	/**
	 * Delete an option.
	 *
	 * @param string $name Option name.
	 */
	public function delete( string $name ): bool {
		return delete_option( $name );
	}
}

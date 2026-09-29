<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Skills;

/**
 * Site option boundary used by the custom Skill store.
 */
interface CustomSkillOptionStore {

	/**
	 * Read a site-local option.
	 *
	 * @param string $name Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public function get( string $name, mixed $default = false ): mixed;

	/**
	 * Add an option only when it does not already exist.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Option value.
	 */
	public function add( string $name, mixed $value ): bool;

	/**
	 * Update a non-autoloaded option.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Option value.
	 */
	public function update( string $name, mixed $value ): bool;

	/**
	 * Delete an option.
	 *
	 * @param string $name Option name.
	 */
	public function delete( string $name ): bool;
}

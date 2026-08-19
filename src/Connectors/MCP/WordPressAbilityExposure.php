<?php
/**
 * WordPress Ability client-exposure helpers.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Resolves an Ability's public client-exposure contract fail closed.
 *
 * WordPress 7.1 resolves its high-level `meta.public` setting into
 * `meta.show_in_rest`. The direct fallback keeps compatible Ability-like
 * objects truthful without changing an explicit REST exposure decision.
 */
final class WordPressAbilityExposure {

	/**
	 * Determine whether an Ability is exposed to remote clients.
	 *
	 * @param object $ability Ability object.
	 */
	public static function is_public( object $ability ): bool {
		if ( ! method_exists( $ability, 'get_meta' ) ) {
			return false;
		}

		try {
			$meta = $ability->get_meta();
		} catch ( \Throwable ) {
			return false;
		}

		if ( ! is_array( $meta ) ) {
			return false;
		}

		if ( array_key_exists( 'show_in_rest', $meta ) ) {
			return true === $meta['show_in_rest'];
		}

		if ( array_key_exists( 'public', $meta ) ) {
			return true === $meta['public'];
		}

		return isset( $meta['mcp'] )
			&& is_array( $meta['mcp'] )
			&& array_key_exists( 'public', $meta['mcp'] )
			&& true === $meta['mcp']['public'];
	}
}

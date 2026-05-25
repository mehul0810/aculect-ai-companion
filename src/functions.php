<?php
/**
 * Global template helpers for Aculect AI Companion.
 *
 * @package Aculect_AI_Companion
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'aculect_ai_companion_connection_entry' ) ) {
	/**
	 * Render the role-aware AI Companion connection entry point.
	 */
	function aculect_ai_companion_connection_entry(): string {
		return ( new \Aculect\AICompanion\Connectors\MCP\RoleConnectionEntryPoint() )->render();
	}
}

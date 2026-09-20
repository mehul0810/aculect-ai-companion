<?php
/**
 * Fixed user privacy refusals without user lookup or mutation.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Returns only policy text, never requested user data or supplied arguments. */
final class UserPrivacyPolicy {

	/**
	 * Name safe reads and policy-only refusals in capability discovery.
	 *
	 * @return array<string, string>
	 */
	public static function operations(): array {
		return array(
			'current_access' => 'users.current_access',
			'roles_summary'  => 'users.roles_summary',
			'list_safe'      => 'users.list_safe',
			'delete_user'    => 'users.delete_user',
			'read_sensitive' => 'users.read_sensitive',
		);
	}

	/**
	 * Refuse user deletion regardless of role, confirmation or target existence.
	 *
	 * @return array<string, mixed>
	 */
	public static function deletion(): array {
		return array(
			'error'     => 'user_deletion_not_allowed',
			'message'   => 'For privacy and safety, deleting users through AI tools is not allowed.',
			'allowed'   => false,
			'read_only' => true,
			'terminal'  => true,
		);
	}

	/**
	 * Refuse retrieval without querying whether any target user or field exists.
	 *
	 * @return array<string, mixed>
	 */
	public static function sensitive_data(): array {
		return array(
			'error'     => 'sensitive_user_information_not_allowed',
			'message'   => 'For privacy concerns, sensitive user information is not retrieved or shown through AI tools or in chat.',
			'allowed'   => false,
			'read_only' => true,
			'terminal'  => true,
		);
	}
}

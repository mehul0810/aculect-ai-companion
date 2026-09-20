<?php
/**
 * Discoverable policy refusals for prohibited user operations.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\UserPrivacyPolicy;

/** These are read-only refusal endpoints, not latent privileged actions. */
final class UserPrivacyAbilityModules {

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * No target or field values are needed to explain an unconditional policy.
	 *
	 * @return list<AbilityModuleInterface>
	 */
	public function all(): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
			'required'             => array(),
		);
		return array(
			$this->factory->create( 'users.delete_user', 'User Deletion Is Not Allowed', 'Always refuses: for privacy and safety, users cannot be deleted through AI tools. Does not accept user data, look up a user or perform deletion, even with administrator privileges.', 'User Privacy', 'content:read', true, $schema, static fn (): array => UserPrivacyPolicy::deletion() ),
			$this->factory->create( 'users.read_sensitive', 'Sensitive User Information Is Private', 'Always refuses retrieval of sensitive user information for privacy. No email, password, token, session, reset key or private user metadata is retrieved or shown in chat. Do not send sensitive data as arguments.', 'User Privacy', 'content:read', true, $schema, static fn (): array => UserPrivacyPolicy::sensitive_data() ),
		);
	}
}

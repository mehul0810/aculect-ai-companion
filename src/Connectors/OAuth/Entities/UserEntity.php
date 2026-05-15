<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Entities;

use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * League OAuth user entity backed by a WordPress user ID.
 */
final class UserEntity implements UserEntityInterface {

	/**
	 * Create a user entity.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function __construct( private readonly int $user_id ) {
	}

	/**
	 * Return the WordPress user ID as a string identifier.
	 */
	public function getIdentifier(): string {
		return (string) $this->user_id;
	}
}

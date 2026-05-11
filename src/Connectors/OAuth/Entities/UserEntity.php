<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Entities;

use League\OAuth2\Server\Entities\UserEntityInterface;

final class UserEntity implements UserEntityInterface {

	public function __construct( private readonly int $user_id ) {
	}

	public function getIdentifier(): string {
		return (string) $this->user_id;
	}
}

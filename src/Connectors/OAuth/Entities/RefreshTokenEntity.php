<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Entities;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

/**
 * League OAuth refresh-token entity used by Quark.
 */
final class RefreshTokenEntity implements RefreshTokenEntityInterface {

	use EntityTrait;
	use RefreshTokenTrait;
}

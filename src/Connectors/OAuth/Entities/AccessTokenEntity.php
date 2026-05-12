<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Entities;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * League OAuth access-token entity used by Quark.
 */
final class AccessTokenEntity implements AccessTokenEntityInterface {

	use AccessTokenTrait;
	use EntityTrait;
	use TokenEntityTrait;
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Entities;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * League OAuth authorization-code entity used by Aculect AI Companion.
 */
final class AuthCodeEntity implements AuthCodeEntityInterface {

	use AuthCodeTrait;
	use EntityTrait;
	use TokenEntityTrait;
}

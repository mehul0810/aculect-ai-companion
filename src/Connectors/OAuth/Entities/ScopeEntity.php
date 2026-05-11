<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Entities;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

final class ScopeEntity implements ScopeEntityInterface {

	use EntityTrait;
	use ScopeTrait;

	public function __construct( string $identifier ) {
		$this->setIdentifier( $identifier );
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this->getIdentifier();
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Entities;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;

/**
 * League OAuth scope entity for Aculect AI Companion connector scopes.
 */
final class ScopeEntity implements ScopeEntityInterface {

	use EntityTrait;
	use ScopeTrait;

	/**
	 * Create a scope entity.
	 *
	 * @param string $identifier Scope identifier.
	 */
	public function __construct( string $identifier ) {
		$this->setIdentifier( $identifier );
	}

	/**
	 * Serialize the scope as its identifier.
	 *
	 * @return string
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this->getIdentifier();
	}
}

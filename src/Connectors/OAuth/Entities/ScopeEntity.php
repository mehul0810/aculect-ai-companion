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
	 * @throws \InvalidArgumentException When the scope identifier is empty.
	 */
	public function __construct( string $identifier ) {
		$identifier = trim( $identifier );
		if ( '' === $identifier ) {
			throw new \InvalidArgumentException( 'OAuth scope identifier cannot be empty.' );
		}

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

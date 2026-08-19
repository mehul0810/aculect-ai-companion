<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;

/**
 * Prevents credential persistence for a client from another canonical issuer.
 */
final class BoundClientGuard {

	/**
	 * Assert that League is issuing through a current repository-hydrated client.
	 *
	 * @param ClientEntityInterface $client OAuth client attached to a credential.
	 * @throws \UnexpectedValueException When the client is not bound to the current issuer.
	 */
	public static function assert_current( ClientEntityInterface $client ): void {
		if ( ! $client instanceof ClientEntity || ! IssuerBinding::matches( $client->getIssuerHash() ) ) {
			throw new \UnexpectedValueException( 'OAuth credential client is not bound to the current issuer.' );
		}
	}
}

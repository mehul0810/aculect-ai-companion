<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Repositories;

use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use Aculect\AICompanion\Connectors\OAuth\RedirectUriPolicy;
use Aculect\AICompanion\Connectors\OAuth\RequestContext;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

/**
 * Adapts stored OAuth clients for request-local authorization validation.
 */
final class ContextAwareClientRepository implements ClientRepositoryInterface {

	private ClientRepository $clients;

	/**
	 * Initialize the adapter around the durable DCR repository.
	 *
	 * @param ClientRepository|null $clients Durable OAuth client repository.
	 */
	public function __construct( ?ClientRepository $clients = null ) {
		$this->clients = $clients ?? new ClientRepository();
	}

	/**
	 * Return a client entity with an approved loopback port variant when needed.
	 *
	 * @param string $clientIdentifier OAuth client ID.
	 */
	public function getClientEntity( string $clientIdentifier ): ?ClientEntityInterface {
		$client = $this->clients->getClientEntity( $clientIdentifier );
		if ( ! $client instanceof ClientEntity ) {
			return $client;
		}

		$approved_redirect_uri = RequestContext::approved_redirect_uri();
		if ( '' === $approved_redirect_uri || ! RedirectUriPolicy::allows( $client->getRedirectUri(), $approved_redirect_uri ) ) {
			return $client;
		}

		$redirect_uris = $client->getRedirectUri();
		$redirect_uris = is_array( $redirect_uris ) ? $redirect_uris : array( $redirect_uris );
		if ( ! in_array( $approved_redirect_uri, $redirect_uris, true ) ) {
			$redirect_uris[] = $approved_redirect_uri;
			$client->setRedirectUri( $redirect_uris );
		}

		return $client;
	}

	/**
	 * Delegate OAuth client authentication to durable DCR storage.
	 *
	 * @param string      $clientIdentifier OAuth client ID.
	 * @param string|null $clientSecret     Presented client secret.
	 * @param string|null $grantType        Requested grant type.
	 */
	public function validateClient( string $clientIdentifier, ?string $clientSecret, ?string $grantType ): bool {
		return $this->clients->validateClient( $clientIdentifier, $clientSecret, $grantType );
	}
}

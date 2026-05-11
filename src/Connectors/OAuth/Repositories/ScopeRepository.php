<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Repositories;

use Quark\Connectors\Helpers;
use Quark\Connectors\OAuth\Entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

final class ScopeRepository implements ScopeRepositoryInterface {

	public function getScopeEntityByIdentifier( string $identifier ): ?ScopeEntityInterface {
		return in_array( $identifier, Helpers::supported_scopes(), true ) ? new ScopeEntity( $identifier ) : null;
	}

	public function finalizeScopes(
		array $scopes,
		string $grantType,
		ClientEntityInterface $clientEntity,
		?string $userIdentifier = null,
		?string $authCodeId = null
	): array {
		unset( $grantType, $clientEntity, $userIdentifier, $authCodeId );

		if ( array() === $scopes ) {
			return array( new ScopeEntity( 'content:read' ) );
		}

		return array_values( array_filter( $scopes, static fn( $scope ): bool => $scope instanceof ScopeEntityInterface ) );
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Repositories;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\Entities\ScopeEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

/**
 * Validates OAuth scopes against Aculect AI Companion's supported connector scopes.
 */
final class ScopeRepository implements ScopeRepositoryInterface {

	/**
	 * Return a supported scope entity by identifier.
	 *
	 * @param string $identifier Scope identifier.
	 * @return ScopeEntityInterface|null
	 */
	public function getScopeEntityByIdentifier( string $identifier ): ?ScopeEntityInterface {
		return in_array( $identifier, Helpers::supported_scopes(), true ) ? new ScopeEntity( $identifier ) : null;
	}

	/**
	 * Finalize requested scopes and apply the default read scope.
	 *
	 * @param array                 $scopes         Scope entities.
	 * @param string                $grantType      Grant type.
	 * @param ClientEntityInterface $clientEntity   OAuth client.
	 * @param string|null           $userIdentifier WordPress user ID.
	 * @param string|null           $authCodeId     Authorization code ID.
	 * @return ScopeEntityInterface[]
	 */
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

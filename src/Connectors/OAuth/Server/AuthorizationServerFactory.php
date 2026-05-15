<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Server;

use DateInterval;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AuthCodeRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\RefreshTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ScopeRepository;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;

/**
 * Creates the configured OAuth authorization server singleton.
 */
final class AuthorizationServerFactory {

	private static ?AuthorizationServer $instance = null;

	/**
	 * Return an authorization server configured for auth-code and refresh grants.
	 */
	public static function create(): AuthorizationServer {
		if ( self::$instance instanceof AuthorizationServer ) {
			return self::$instance;
		}

		$client_repository    = new ClientRepository();
		$access_repository    = new AccessTokenRepository();
		$scope_repository     = new ScopeRepository();
		$auth_code_repository = new AuthCodeRepository();
		$refresh_repository   = new RefreshTokenRepository();

		$server = new AuthorizationServer(
			$client_repository,
			$access_repository,
			$scope_repository,
			new CryptKey( KeyManager::private_key(), null, false ),
			KeyManager::encryption_key()
		);

		$auth_code_grant = new AuthCodeGrant(
			$auth_code_repository,
			$refresh_repository,
			new DateInterval( 'PT10M' )
		);
		$auth_code_grant->setRefreshTokenTTL( new DateInterval( 'P30D' ) );
		$server->enableGrantType( $auth_code_grant, new DateInterval( 'PT1H' ) );

		$refresh_grant = new RefreshTokenGrant( $refresh_repository );
		$refresh_grant->setRefreshTokenTTL( new DateInterval( 'P30D' ) );
		$server->enableGrantType( $refresh_grant, new DateInterval( 'PT1H' ) );

		self::$instance = $server;
		return $server;
	}
}

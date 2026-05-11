<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Server;

use DateInterval;
use Quark\Connectors\OAuth\Repositories\AccessTokenRepository;
use Quark\Connectors\OAuth\Repositories\AuthCodeRepository;
use Quark\Connectors\OAuth\Repositories\ClientRepository;
use Quark\Connectors\OAuth\Repositories\RefreshTokenRepository;
use Quark\Connectors\OAuth\Repositories\ScopeRepository;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;

final class AuthorizationServerFactory {

	private static ?AuthorizationServer $instance = null;

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

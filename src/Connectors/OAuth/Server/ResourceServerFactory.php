<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Server;

use Quark\Connectors\OAuth\Repositories\AccessTokenRepository;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\ResourceServer;

final class ResourceServerFactory {

	private static ?ResourceServer $instance = null;

	public static function create(): ResourceServer {
		if ( self::$instance instanceof ResourceServer ) {
			return self::$instance;
		}

		self::$instance = new ResourceServer(
			new AccessTokenRepository(),
			new CryptKey( KeyManager::public_key(), null, false )
		);

		return self::$instance;
	}
}

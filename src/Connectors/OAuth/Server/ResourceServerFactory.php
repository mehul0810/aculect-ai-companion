<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Server;

use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\ResourceServer;

/**
 * Creates the OAuth resource server used to validate bearer tokens.
 */
final class ResourceServerFactory {

	private static ?ResourceServer $instance = null;

	/**
	 * Return the configured resource server singleton.
	 */
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

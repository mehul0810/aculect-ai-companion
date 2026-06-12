<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers;

/**
 * Optional provider contract for Dynamic Client Registration attribution.
 */
interface ProviderMatcherInterface {

	/**
	 * Return whether the DCR metadata belongs to this provider.
	 *
	 * @param string   $client_name   Client display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 */
	public function matches_client( string $client_name, array $redirect_uris ): bool;
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers;

use Aculect\AICompanion\Connectors\Providers\ChatGPT\Provider as ChatGPTProvider;
use Aculect\AICompanion\Connectors\Providers\Claude\Provider as ClaudeProvider;
use Aculect\AICompanion\Connectors\Providers\Codex\Provider as CodexProvider;
use Aculect\AICompanion\Connectors\Providers\Generic\Provider as GenericProvider;

/**
 * Central registry for supported AI client providers.
 */
final class ProviderRegistry {

	private const FALLBACK_PROVIDER_ID = 'mcp';

	/**
	 * Return registered provider definitions.
	 *
	 * @return list<ProviderInterface>
	 */
	public function providers(): array {
		$providers = array(
			new ClaudeProvider(),
			new ChatGPTProvider(),
			new CodexProvider(),
			new GenericProvider(),
		);

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$providers = apply_filters( 'aculect-ai-companion/connectors/providers', $providers, $this );

		if ( ! is_array( $providers ) ) {
			return array( new GenericProvider() );
		}

		$valid = array_values(
			array_filter(
				$providers,
				static fn( mixed $provider ): bool => $provider instanceof ProviderInterface
			)
		);

		return array() === $valid ? array( new GenericProvider() ) : $valid;
	}

	/**
	 * Return provider setup definitions for admin UI payloads.
	 *
	 * @param string $mcp_url Canonical MCP endpoint URL.
	 * @return list<array<string, mixed>>
	 */
	public function setup_definitions( string $mcp_url ): array {
		return array_map(
			static function ( ProviderInterface $provider ) use ( $mcp_url ): array {
				return array(
					'id'                 => $provider->id(),
					'label'              => $provider->label(),
					'description'        => $provider->description(),
					'primaryActionUrl'   => $provider->primary_action_url(),
					'primaryActionLabel' => $provider->primary_action_label(),
					'setupSections'      => $provider->setup_sections( $mcp_url ),
				);
			},
			$this->providers()
		);
	}

	/**
	 * Infer the provider id from DCR metadata.
	 *
	 * @param string   $client_name   Client display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 */
	public function detect_provider_id( string $client_name, array $redirect_uris ): string {
		foreach ( $this->providers() as $provider ) {
			if ( self::FALLBACK_PROVIDER_ID === $provider->id() ) {
				continue;
			}

			if ( $provider instanceof ProviderMatcherInterface && $provider->matches_client( $client_name, $redirect_uris ) ) {
				return $provider->id();
			}
		}

		return self::FALLBACK_PROVIDER_ID;
	}
}

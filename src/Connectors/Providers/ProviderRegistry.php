<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers;

use Aculect\AICompanion\Connectors\Providers\ChatGPT\Provider as ChatGPTProvider;
use Aculect\AICompanion\Connectors\Providers\Claude\Provider as ClaudeProvider;
use Aculect\AICompanion\Connectors\Providers\Codex\Provider as CodexProvider;
use Aculect\AICompanion\Connectors\Providers\Cursor\Provider as CursorProvider;
use Aculect\AICompanion\Connectors\Providers\Generic\Provider as GenericProvider;
use Aculect\AICompanion\Connectors\Providers\Gemini\Provider as GeminiProvider;
use Aculect\AICompanion\Connectors\Providers\Grok\Provider as GrokProvider;

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
			new ChatGPTProvider(),
			new CodexProvider(),
			new ClaudeProvider(),
			new GeminiProvider(),
			new CursorProvider(),
			new GrokProvider(),
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
		$tool_filtering = new ClientToolFilterGuidance();

		return array_map(
			static function ( ProviderInterface $provider ) use ( $mcp_url, $tool_filtering ): array {
				$brand = self::brand_for_provider( $provider );

				$definition = array(
					'id'                 => $provider->id(),
					'label'              => $provider->label(),
					'description'        => $provider->description(),
					'brandName'          => $brand['name'],
					'brandUrl'           => $brand['url'],
					'primaryActionUrl'   => $provider->primary_action_url(),
					'primaryActionLabel' => $provider->primary_action_label(),
					'setupSections'      => $provider->setup_sections( $mcp_url ),
				);

				if ( $provider instanceof ProviderWizardInterface ) {
					$definition['wizard'] = $provider->setup_wizard( $mcp_url );
				}

				if ( $tool_filtering->supports_provider( $provider->id() ) ) {
					$definition['toolFiltering'] = $tool_filtering->section_for_provider( $provider->id() );
				}

				return $definition;
			},
			$this->providers()
		);
	}

	/**
	 * Return brand attribution metadata for the provider selector.
	 *
	 * @param ProviderInterface $provider Provider instance.
	 * @return array{name: string, url: string}
	 */
	private static function brand_for_provider( ProviderInterface $provider ): array {
		switch ( $provider->id() ) {
			case 'chatgpt':
			case 'codex':
				return array(
					'name' => 'OpenAI',
					'url'  => 'https://openai.com/',
				);
			case 'claude':
				return array(
					'name' => 'Anthropic',
					'url'  => 'https://www.anthropic.com/',
				);
			case 'gemini':
				return array(
					'name' => 'Google',
					'url'  => 'https://gemini.google.com/',
				);
			case 'cursor':
				return array(
					'name' => 'Cursor',
					'url'  => 'https://cursor.com/',
				);
			case 'grok':
				return array(
					'name' => 'xAI',
					'url'  => 'https://x.ai/',
				);
			case self::FALLBACK_PROVIDER_ID:
				return array(
					'name' => '',
					'url'  => '',
				);
			default:
				return array(
					'name' => $provider->label(),
					'url'  => $provider->primary_action_url(),
				);
		}
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

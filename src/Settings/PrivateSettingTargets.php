<?php
/**
 * Fixed settings supported by private browser input.
 *
 * @package Aculect\AICompanion\Settings
 */

declare(strict_types=1);
namespace Aculect\AICompanion\Settings;

/** Never accepts an arbitrary option name from a client. */
final class PrivateSettingTargets {
	public function __construct( private readonly CorePrivateSettingTargets $coreTargets = new CorePrivateSettingTargets() ) {}

	/**
	 * Return fixed settings and their native storage mapping.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all(): array {
		return array_merge(
			array(
				'site_title'        => array(
					'option' => 'blogname',
					'label'  => 'Site title',
					'group'  => 'General',
					'secret' => false,
				),
				'tagline'           => array(
					'option' => 'blogdescription',
					'label'  => 'Tagline',
					'group'  => 'General',
					'secret' => false,
				),
				'timezone'          => array(
					'option' => 'timezone_string',
					'label'  => 'Timezone (for example Asia/Kolkata)',
					'group'  => 'General',
					'secret' => false,
				),
				'posts_per_page'    => array(
					'option' => 'posts_per_page',
					'label'  => 'Posts per page (1–100)',
					'group'  => 'Reading',
					'secret' => false,
				),
				'default_category'  => array(
					'option' => 'default_category',
					'label'  => 'Default category ID',
					'group'  => 'Writing',
					'secret' => false,
				),
				'openai_api_key'    => array(
					'option'   => 'connectors_ai_openai_api_key',
					'label'    => 'OpenAI API key',
					'group'    => 'Connectors',
					'secret'   => true,
					'provider' => 'openai',
				),
				'anthropic_api_key' => array(
					'option'   => 'connectors_ai_anthropic_api_key',
					'label'    => 'Anthropic API key',
					'group'    => 'Connectors',
					'secret'   => true,
					'provider' => 'anthropic',
				),
				'google_api_key'    => array(
					'option'   => 'connectors_ai_google_api_key',
					'label'    => 'Google API key',
					'group'    => 'Connectors',
					'secret'   => true,
					'provider' => 'google',
				),
			),
			$this->coreTargets->all()
		);
	}

	/**
	 * Find a fixed target.
	 *
	 * @param string $id Public target identifier.
	 * @return array<string,mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Availability reveals no credential values or fragments.
	 *
	 * @param string $id Public target identifier.
	 */
	public function available( string $id ): bool {
		$target = $this->get( $id );
		if ( null === $target ) {
			return false;
		}
		if ( ! $target['secret'] ) {
			return true;
		}
		if ( ! function_exists( 'wp_is_connector_registered' ) || ! function_exists( 'wp_get_connector' ) || ! wp_is_connector_registered( $target['provider'] ) || ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return false;
		}
		$connector = wp_get_connector( $target['provider'] );
		$auth      = is_array( $connector ) ? ( $connector['authentication'] ?? array() ) : array();
		if ( ! is_array( $auth ) || 'api_key' !== ( $auth['method'] ?? '' ) || ( $auth['setting_name'] ?? '' ) !== $target['option'] ) {
			return false;
		}
		foreach ( array( 'constant_name', 'env_var_name' ) as $source ) {
			$name = $auth[ $source ] ?? '';
			if ( ! is_string( $name ) || ( '' !== $name && ( 'constant_name' === $source ? defined( $name ) : false !== getenv( $name ) ) ) ) {
				return false;
			}
		}
		try {
			return \WordPress\AiClient\AiClient::defaultRegistry()->hasProvider( $target['provider'] );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Return a validated native option value, or null without echoing input.
	 *
	 * @param string $id Fixed target identifier.
	 * @param string $value Private browser input.
	 */
	public function validate( string $id, string $value ): string|int|null {
		if ( strlen( $value ) > 4096 || ! $this->available( $id ) ) {
			return null;
		}
		$target = $this->get( $id );
		if ( null === $target ) {
			return null;
		}
		if ( $target['secret'] ) {
			if ( '' === $value || preg_match( '/[\s\x00-\x1f\x7f]/', $value ) ) {
				return null;
			}
			try {
				$client         = '\WordPress\AiClient\AiClient';
				$authentication = '\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication';
				if ( ! class_exists( $client ) || ! class_exists( $authentication ) ) {
					return null;
				}
				$registry = $client::defaultRegistry();
				$registry->setProviderRequestAuthentication( $target['provider'], new $authentication( $value ) );
				return $registry->isProviderConfigured( $target['provider'] ) ? $value : null;
			} catch ( \Throwable ) {
				return null;
			}
		}
		if ( in_array( $id, array( 'posts_per_page', 'default_category' ), true ) ) {
			if ( ! preg_match( '/^[1-9][0-9]{0,8}$/', $value ) ) {
				return null;
			}
			$number = (int) $value;
			return 'posts_per_page' === $id ? ( $number <= 100 ? $number : null ) : ( term_exists( $number, 'category' ) ? $number : null );
		}
		if ( 'timezone' === $id ) {
			return in_array( $value, timezone_identifiers_list(), true ) ? $value : null;
		}
		if ( null !== $this->coreTargets->get( $id ) ) {
			return $this->coreTargets->validate( $id, $value );
		}
		// Core stores blogname/blogdescription HTML-escaped; compare that native form after saving.
		$sanitized = esc_html( sanitize_text_field( $value ) );
		return strlen( $value ) <= 300 && ( 'tagline' === $id || '' !== trim( $sanitized ) ) ? $sanitized : null;
	}
}

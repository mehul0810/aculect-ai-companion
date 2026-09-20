<?php
/**
 * Isolated settings boundary doubles; never reads real credentials.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

// Test doubles mirror external SDK names and group isolated namespaces in one fixture.
// phpcs:disable Universal.Namespaces, Universal.Files.SeparateFunctionsFromOO, Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, Generic.CodeAnalysis.UnusedFunctionParameter

namespace Aculect\AICompanion\Settings {
	function is_ssl(): bool {
		return $GLOBALS['private_settings_ssl'] ?? false; }
	function wp_salt( string $scheme ): string {
		return 'fixture-' . $scheme; }
	function get_user_meta( int $user, string $key, bool $single ): mixed {
		return $GLOBALS['private_settings_meta'][ $user ][ $key ] ?? ''; }
	function update_user_meta( int $user, string $key, mixed $value, mixed $previous = '' ): bool {
		$current = get_user_meta( $user, $key, true );
		if ( '' !== $previous && $current !== $previous ) {
			return false; }
		$GLOBALS['private_settings_meta'][ $user ][ $key ] = $value;
		return true;
	}
	function function_exists( string $name ): bool {
		return in_array( $name, array( 'wp_is_connector_registered', 'wp_get_connector' ), true ) ? true : \function_exists( $name );
	}
	function wp_is_connector_registered( string $id ): bool {
		return in_array( $id, array( 'openai', 'anthropic', 'google' ), true ); }
	function wp_get_connector( string $id ): array {
		return array(
			'authentication' => array(
				'method'       => 'api_key',
				'setting_name' => $GLOBALS['private_settings_override_option'] ?? 'connectors_ai_' . $id . '_api_key',
			),
		); }
}

namespace WordPress\AiClient {
	final class AiClient {
		public static function defaultRegistry(): object {
			return new FixtureRegistry(); }
	}
	final class FixtureRegistry {
		public function hasProvider( string $id ): bool {
			return ! empty( $GLOBALS['private_settings_provider'] ); }
		public function setProviderRequestAuthentication( string $id, object $authentication ): void {}
		public function isProviderConfigured( string $id ): bool {
			if ( ! empty( $GLOBALS['private_settings_provider_throws'] ) ) {
				throw new \RuntimeException( 'fixture-sensitive-exception' ); }
			return ! empty( $GLOBALS['private_settings_key_valid'] );
		}
	}
}

namespace WordPress\AiClient\Providers\Http\DTO {
	final class ApiKeyRequestAuthentication {
		public function __construct( string $key ) {}
	}
}

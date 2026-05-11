<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\UserDefined;

use Quark\Connectors\ChatGPT\Helper;

final class ClientCredentials
{
    public const OPTION_SETTINGS = 'quark_chatgpt_oauth_settings';
    public const AUTH_CLIENT_SECRET_POST = 'client_secret_post';
    public const SCOPES = 'content:read content:draft';

    private const LEGACY_DCR_OPTION = 'quark_oauth_clients';
    private const DEFAULT_SETTINGS = [
        'client_id' => '',
        'client_secret' => '',
    ];

    public function settings(): array
    {
        $raw = get_option(self::OPTION_SETTINGS, []);
        $raw = is_array($raw) ? $raw : [];

        $settings = [
            'client_id' => sanitize_text_field((string) ($raw['client_id'] ?? $raw['manual_client_id'] ?? '')),
            'client_secret' => sanitize_text_field((string) ($raw['client_secret'] ?? $raw['manual_client_secret'] ?? '')),
        ];

        if ('' === $settings['client_id'] || '' === $settings['client_secret']) {
            $settings = $this->new_credentials();
        }

        if ($raw !== $settings) {
            update_option(self::OPTION_SETTINGS, $settings, false);
        }
        if (false !== get_option(self::LEGACY_DCR_OPTION, false)) {
            delete_option(self::LEGACY_DCR_OPTION);
        }

        return array_merge(self::DEFAULT_SETTINGS, $settings);
    }

    public function regenerate_credentials(): array
    {
        $settings = $this->new_credentials();
        update_option(self::OPTION_SETTINGS, $settings, false);

        return $settings;
    }

    public function find_client(string $client_id): array
    {
        $settings = $this->settings();
        if (! hash_equals((string) $settings['client_id'], $client_id)) {
            return [];
        }

        return [
            'client_id' => $client_id,
            'redirect_uris' => [],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => self::AUTH_CLIENT_SECRET_POST,
            'scope' => self::SCOPES,
        ];
    }

    public function client_redirect_allowed(array $client, string $redirect_uri): bool
    {
        unset($client);

        return Helper::is_chatgpt_redirect($redirect_uri);
    }

    public function verify_token_endpoint_auth(string $client_id, string $client_secret, string $authorization_header): bool
    {
        if ('' === $client_id) {
            $client_id = $this->client_id_from_authorization_header($authorization_header);
        }

        if ([] === $this->find_client($client_id)) {
            return false;
        }

        $authorization_client_id = $this->client_id_from_authorization_header($authorization_header);
        if ('' !== $authorization_client_id && ! hash_equals($client_id, $authorization_client_id)) {
            return false;
        }

        $authorization_secret = $this->client_secret_from_authorization_header($authorization_header);
        if ('' !== $authorization_secret) {
            $client_secret = $authorization_secret;
        }

        $settings = $this->settings();

        return '' !== $client_secret && hash_equals((string) $settings['client_secret'], $client_secret);
    }

    public function client_id_from_authorization_header(string $authorization_header): string
    {
        $credentials = $this->basic_credentials_from_header($authorization_header);

        return (string) ($credentials['client_id'] ?? '');
    }

    private function new_credentials(): array
    {
        return [
            'client_id' => 'quark_' . wp_generate_password(24, false, false),
            'client_secret' => wp_generate_password(40, false, false),
        ];
    }

    private function client_secret_from_authorization_header(string $authorization_header): string
    {
        $credentials = $this->basic_credentials_from_header($authorization_header);

        return (string) ($credentials['client_secret'] ?? '');
    }

    private function basic_credentials_from_header(string $authorization_header): array
    {
        if (! str_starts_with(strtolower($authorization_header), 'basic ')) {
            return [];
        }

        $decoded = base64_decode(trim(substr($authorization_header, 6)), true);
        if (! is_string($decoded) || ! str_contains($decoded, ':')) {
            return [];
        }

        [$client_id, $client_secret] = explode(':', $decoded, 2);

        return [
            'client_id' => rawurldecode($client_id),
            'client_secret' => rawurldecode($client_secret),
        ];
    }
}

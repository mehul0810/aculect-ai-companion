<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\Auth;

final class OAuthClientRegistry
{
    private const LEGACY_DCR_OPTION = 'quark_oauth_clients';
    public const OPTION_SETTINGS = 'quark_chatgpt_oauth_settings';
    public const AUTH_CLIENT_SECRET_POST = 'client_secret_post';

    private const DEFAULT_SETTINGS = [
        'manual_client_id' => '',
        'manual_client_secret' => '',
        'manual_token_endpoint_auth_method' => self::AUTH_CLIENT_SECRET_POST,
    ];

    public function settings(): array
    {
        delete_option(self::LEGACY_DCR_OPTION);

        $settings = get_option(self::OPTION_SETTINGS, []);
        $settings = array_merge(self::DEFAULT_SETTINGS, is_array($settings) ? $settings : []);

        if ('' === $settings['manual_client_id'] || '' === $settings['manual_client_secret']) {
            $settings = $this->generate_credentials();
        }

        return $settings;
    }

    public function regenerate_credentials(): array
    {
        return $this->generate_credentials();
    }

    public function find_client(string $client_id): array
    {
        $settings = $this->settings();

        if (! hash_equals((string) $settings['manual_client_id'], $client_id)) {
            return [];
        }

        return [
            'client_id' => $client_id,
            'redirect_uris' => [],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => self::AUTH_CLIENT_SECRET_POST,
            'scope' => 'content:read content:draft',
            'manual' => true,
        ];
    }

    public function client_redirect_allowed(array $client, string $redirect_uri): bool
    {
        if (empty($client['manual'])) {
            return false;
        }

        return $this->is_chatgpt_redirect($redirect_uri);
    }

    public function verify_token_endpoint_auth(string $client_id, string $client_secret, string $authorization_header): bool
    {
        unset($authorization_header);

        $client = $this->find_client($client_id);
        if ([] === $client) {
            return false;
        }

        $settings = $this->settings();

        return hash_equals((string) $settings['manual_client_secret'], $client_secret);
    }

    public function client_id_from_authorization_header(string $authorization_header): string
    {
        return '';
    }

    public function is_chatgpt_redirect(string $uri): bool
    {
        $parts = wp_parse_url($uri);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        return 'https' === $scheme
            && 'chatgpt.com' === $host
            && (
                str_starts_with($path, '/connector/oauth/')
                || '/connector_platform_oauth_redirect' === $path
            );
    }

    private function generate_credentials(): array
    {
        delete_option(self::LEGACY_DCR_OPTION);

        $settings = [
            'manual_client_id' => 'quark_' . wp_generate_password(24, false, false),
            'manual_client_secret' => wp_generate_password(40, false, false),
            'manual_token_endpoint_auth_method' => self::AUTH_CLIENT_SECRET_POST,
        ];

        update_option(self::OPTION_SETTINGS, $settings, false);

        return $settings;
    }
}

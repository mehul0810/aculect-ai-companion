<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\Auth;

final class OAuthClientRegistry
{
    private const DCR_OPTION = 'quark_oauth_clients';
    public const OPTION_SETTINGS = 'quark_chatgpt_oauth_settings';
    public const MODE_USER_DEFINED = 'user_defined';
    public const MODE_DCR = 'dcr';
    public const AUTH_NONE = 'none';
    public const AUTH_CLIENT_SECRET_POST = 'client_secret_post';

    private const DEFAULT_SETTINGS = [
        'registration_method' => self::MODE_USER_DEFINED,
        'manual_client_id' => '',
        'manual_client_secret' => '',
        'manual_token_endpoint_auth_method' => self::AUTH_CLIENT_SECRET_POST,
    ];

    public function settings(): array
    {
        $settings = get_option(self::OPTION_SETTINGS, []);
        $settings = array_merge(self::DEFAULT_SETTINGS, is_array($settings) ? $settings : []);
        $settings['registration_method'] = $this->valid_registration_method((string) $settings['registration_method']);

        if ('' === $settings['manual_client_id'] || '' === $settings['manual_client_secret']) {
            $settings = $this->generate_credentials($settings);
        }

        return $settings;
    }

    public function registration_methods(): array
    {
        return [
            self::MODE_USER_DEFINED => 'User-Defined OAuth Client',
            self::MODE_DCR => 'Dynamic Client Registration (DCR)',
        ];
    }

    public function save_registration_method(string $method): array
    {
        $settings = $this->settings();
        $settings['registration_method'] = $this->valid_registration_method($method);
        update_option(self::OPTION_SETTINGS, $settings, false);

        return $settings;
    }

    public function regenerate_credentials(): array
    {
        return $this->generate_credentials($this->settings());
    }

    public function find_client(string $client_id): array
    {
        $settings = $this->settings();

        if (self::MODE_USER_DEFINED === $settings['registration_method']) {
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

        $clients = $this->dcr_clients();
        if (! isset($clients[$client_id]) || ! is_array($clients[$client_id])) {
            return [];
        }

        return $clients[$client_id];
    }

    public function client_redirect_allowed(array $client, string $redirect_uri): bool
    {
        if (! empty($client['manual'])) {
            return $this->is_chatgpt_redirect($redirect_uri);
        }

        $redirect_uris = array_filter(array_map('strval', (array) ($client['redirect_uris'] ?? [])));

        return in_array($redirect_uri, $redirect_uris, true);
    }

    public function register_client(array $payload): array
    {
        $settings = $this->settings();
        if (self::MODE_DCR !== $settings['registration_method']) {
            return [];
        }

        $redirect_uris = $this->sanitize_redirect_uris($payload['redirect_uris'] ?? []);
        if ([] === $redirect_uris) {
            return [];
        }

        $grant_types = $this->sanitize_supported_values(
            $payload['grant_types'] ?? ['authorization_code', 'refresh_token'],
            ['authorization_code', 'refresh_token']
        );
        $response_types = $this->sanitize_supported_values(
            $payload['response_types'] ?? ['code'],
            ['code']
        );
        $auth_method = sanitize_key((string) ($payload['token_endpoint_auth_method'] ?? self::AUTH_NONE));
        if (self::AUTH_NONE !== $auth_method) {
            return [];
        }

        $scope = $this->sanitize_scope((string) ($payload['scope'] ?? 'content:read content:draft'));
        if ('' === $scope || [] === $grant_types || [] === $response_types) {
            return [];
        }

        $client_id = 'quark_dcr_' . wp_generate_password(32, false, false);
        $client = [
            'client_id' => $client_id,
            'client_id_issued_at' => time(),
            'client_name' => sanitize_text_field((string) ($payload['client_name'] ?? 'ChatGPT')),
            'redirect_uris' => $redirect_uris,
            'grant_types' => $grant_types,
            'response_types' => $response_types,
            'token_endpoint_auth_method' => self::AUTH_NONE,
            'scope' => $scope,
            'manual' => false,
        ];

        $clients = $this->dcr_clients();
        $clients[$client_id] = $client;
        update_option(self::DCR_OPTION, $clients, false);

        return $client;
    }

    public function verify_token_endpoint_auth(string $client_id, string $client_secret, string $authorization_header): bool
    {
        if ('' === $client_id) {
            $client_id = $this->client_id_from_authorization_header($authorization_header);
        }
        $client = $this->find_client($client_id);
        if ([] === $client) {
            return false;
        }

        $authorization_client_id = $this->client_id_from_authorization_header($authorization_header);
        if ('' !== $authorization_client_id && ! hash_equals($client_id, $authorization_client_id)) {
            return false;
        }

        $auth_method = (string) ($client['token_endpoint_auth_method'] ?? self::AUTH_NONE);
        if (self::AUTH_NONE === $auth_method) {
            return '' === $client_secret && '' === $this->client_secret_from_authorization_header($authorization_header);
        }

        $settings = $this->settings();
        $authorization_secret = $this->client_secret_from_authorization_header($authorization_header);
        if ('' !== $authorization_secret) {
            $client_secret = $authorization_secret;
        }

        return hash_equals((string) $settings['manual_client_secret'], $client_secret);
    }

    public function client_id_from_authorization_header(string $authorization_header): string
    {
        $credentials = $this->basic_credentials_from_header($authorization_header);

        return (string) ($credentials['client_id'] ?? '');
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

    private function generate_credentials(array $settings): array
    {
        $settings = array_merge($settings, [
            'manual_client_id' => 'quark_' . wp_generate_password(24, false, false),
            'manual_client_secret' => wp_generate_password(40, false, false),
            'manual_token_endpoint_auth_method' => self::AUTH_CLIENT_SECRET_POST,
        ]);

        update_option(self::OPTION_SETTINGS, $settings, false);

        return $settings;
    }

    private function dcr_clients(): array
    {
        $clients = get_option(self::DCR_OPTION, []);

        return is_array($clients) ? $clients : [];
    }

    private function sanitize_redirect_uris($redirect_uris): array
    {
        $redirect_uris = is_array($redirect_uris) ? $redirect_uris : [];
        $sanitized = [];

        foreach ($redirect_uris as $uri) {
            $uri = esc_url_raw((string) $uri);
            if ('' !== $uri && $this->is_chatgpt_redirect($uri)) {
                $sanitized[] = $uri;
            }
        }

        return array_values(array_unique($sanitized));
    }

    private function sanitize_supported_values($values, array $allowed): array
    {
        $values = is_array($values) ? $values : [];
        $sanitized = [];

        foreach ($values as $value) {
            $value = sanitize_key((string) $value);
            if (in_array($value, $allowed, true)) {
                $sanitized[] = $value;
            }
        }

        return array_values(array_unique($sanitized));
    }

    private function sanitize_scope(string $scope): string
    {
        $requested = preg_split('/\s+/', trim($scope)) ?: [];
        $allowed = ['content:read', 'content:draft'];
        $sanitized = [];

        foreach ($requested as $item) {
            if (in_array($item, $allowed, true)) {
                $sanitized[] = $item;
            }
        }

        return implode(' ', array_values(array_unique($sanitized)));
    }

    private function valid_registration_method(string $method): string
    {
        return in_array($method, [self::MODE_USER_DEFINED, self::MODE_DCR], true)
            ? $method
            : self::MODE_USER_DEFINED;
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

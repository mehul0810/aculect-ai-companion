<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\Auth;

final class OAuthClientRegistry
{
    public const OPTION_DCR_CLIENTS = 'quark_oauth_clients';
    public const OPTION_SETTINGS = 'quark_chatgpt_oauth_settings';
    public const MODE_USER_DEFINED = 'user_defined';
    public const MODE_DCR = 'dcr';
    public const MODE_CMID = 'cmid';
    public const AUTH_NONE = 'none';
    public const AUTH_CLIENT_SECRET_POST = 'client_secret_post';
    public const AUTH_CLIENT_SECRET_BASIC = 'client_secret_basic';
    public const DCR_TTL = DAY_IN_SECONDS;

    private const DEFAULT_SETTINGS = [
        'registration_method' => self::MODE_DCR,
        'manual_client_id' => '',
        'manual_client_secret_hash' => '',
        'manual_client_secret_preview' => '',
        'manual_token_endpoint_auth_method' => self::AUTH_CLIENT_SECRET_POST,
        'cmid_url' => 'https://openai.com/chatgpt.json',
    ];

    public function settings(): array
    {
        $settings = get_option(self::OPTION_SETTINGS, []);
        return array_merge(self::DEFAULT_SETTINGS, is_array($settings) ? $settings : []);
    }

    public function save_settings(array $data): void
    {
        $current = $this->settings();
        $method = sanitize_key((string) ($data['registration_method'] ?? $current['registration_method']));
        if (! in_array($method, [self::MODE_USER_DEFINED, self::MODE_DCR, self::MODE_CMID], true)) {
            $method = self::MODE_DCR;
        }

        $auth_method = sanitize_key((string) ($data['manual_token_endpoint_auth_method'] ?? $current['manual_token_endpoint_auth_method']));
        if (! in_array($auth_method, [self::AUTH_NONE, self::AUTH_CLIENT_SECRET_POST, self::AUTH_CLIENT_SECRET_BASIC], true)) {
            $auth_method = self::AUTH_CLIENT_SECRET_POST;
        }

        $secret = (string) ($data['manual_client_secret'] ?? '');
        $settings = [
            'registration_method' => $method,
            'manual_client_id' => sanitize_text_field((string) ($data['manual_client_id'] ?? $current['manual_client_id'])),
            'manual_client_secret_hash' => $current['manual_client_secret_hash'],
            'manual_client_secret_preview' => $current['manual_client_secret_preview'],
            'manual_token_endpoint_auth_method' => $auth_method,
            'cmid_url' => esc_url_raw((string) ($data['cmid_url'] ?? $current['cmid_url'])),
        ];

        if ('' !== $secret) {
            $settings['manual_client_secret_hash'] = wp_hash_password($secret);
            $settings['manual_client_secret_preview'] = substr($secret, 0, 2) . str_repeat('*', max(0, strlen($secret) - 6)) . substr($secret, -4);
        }

        update_option(self::OPTION_SETTINGS, $settings, false);
    }

    public function find_client(string $client_id): array
    {
        $settings = $this->settings();
        if (
            self::MODE_USER_DEFINED === $settings['registration_method']
            && hash_equals((string) $settings['manual_client_id'], $client_id)
        ) {
            return [
                'client_id' => $client_id,
                'redirect_uris' => [],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'token_endpoint_auth_method' => (string) $settings['manual_token_endpoint_auth_method'],
                'scope' => 'content:read content:draft',
                'manual' => true,
            ];
        }

        if (
            self::MODE_CMID === $settings['registration_method']
            && hash_equals((string) $settings['cmid_url'], $client_id)
        ) {
            return [
                'client_id' => $client_id,
                'redirect_uris' => [],
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'token_endpoint_auth_method' => self::AUTH_NONE,
                'scope' => 'content:read content:draft',
                'manual' => false,
                'cmid' => true,
            ];
        }

        $clients = $this->cleanup_expired_dcr_clients();
        if (isset($clients[$client_id]) && is_array($clients[$client_id])) {
            return array_merge(['client_id' => $client_id, 'manual' => false], $clients[$client_id]);
        }

        return [];
    }

    public function cleanup_expired_dcr_clients(): array
    {
        $clients = get_option(self::OPTION_DCR_CLIENTS, []);
        if (! is_array($clients) || [] === $clients) {
            return [];
        }

        $now = time();
        $changed = false;

        foreach ($clients as $client_id => $client) {
            if (! is_array($client)) {
                unset($clients[$client_id]);
                $changed = true;
                continue;
            }

            $issued_at = (int) ($client['client_id_issued_at'] ?? 0);
            if ($issued_at > 0 && ($issued_at + self::DCR_TTL) < $now) {
                unset($clients[$client_id]);
                $changed = true;
            }
        }

        if ($changed) {
            update_option(self::OPTION_DCR_CLIENTS, $clients, false);
        }

        return $clients;
    }

    public function client_redirect_allowed(array $client, string $redirect_uri): bool
    {
        if (! empty($client['manual']) || ! empty($client['cmid'])) {
            return $this->is_chatgpt_redirect($redirect_uri);
        }

        $allowed = $client['redirect_uris'] ?? [];
        return is_array($allowed) && in_array($redirect_uri, $allowed, true);
    }

    public function verify_token_endpoint_auth(string $client_id, string $client_secret, string $authorization_header): bool
    {
        $basic_client_id = '';
        $basic_client_secret = '';
        if (str_starts_with(strtolower($authorization_header), 'basic ')) {
            [$basic_client_id, $basic_client_secret] = $this->credentials_from_basic_header($authorization_header);
            if ('' === $client_id) {
                $client_id = $basic_client_id;
            }
        }

        $client = $this->find_client($client_id);
        if ([] === $client) {
            return false;
        }

        $method = (string) ($client['token_endpoint_auth_method'] ?? self::AUTH_NONE);
        if (self::AUTH_NONE === $method) {
            return true;
        }

        if (self::AUTH_CLIENT_SECRET_BASIC === $method) {
            $client_id = $basic_client_id;
            $client_secret = $basic_client_secret;
        }

        if (self::AUTH_CLIENT_SECRET_POST !== $method && self::AUTH_CLIENT_SECRET_BASIC !== $method) {
            return false;
        }

        $settings = $this->settings();
        if (empty($client['manual']) || ! hash_equals((string) $settings['manual_client_id'], $client_id)) {
            return false;
        }

        $hash = (string) $settings['manual_client_secret_hash'];
        return '' !== $hash && '' !== $client_secret && wp_check_password($client_secret, $hash);
    }

    public function client_id_from_authorization_header(string $authorization_header): string
    {
        [$client_id] = $this->credentials_from_basic_header($authorization_header);
        return $client_id;
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

    private function credentials_from_basic_header(string $authorization_header): array
    {
        if (! str_starts_with(strtolower($authorization_header), 'basic ')) {
            return ['', ''];
        }

        $decoded = base64_decode(trim(substr($authorization_header, 6)), true);
        if (! is_string($decoded) || ! str_contains($decoded, ':')) {
            return ['', ''];
        }

        return array_map('rawurldecode', explode(':', $decoded, 2));
    }
}

<?php

declare(strict_types=1);

namespace Quark\Auth;

final class Access
{
    private const OPTION_CODES = 'quark_oauth_codes';
    private const OPTION_TOKENS = 'quark_oauth_tokens';

    public function issue_code(int $user_id, string $client_id, string $scope, string $code_challenge, string $resource): string
    {
        $code = wp_generate_uuid4() . wp_generate_uuid4();
        $items = get_option(self::OPTION_CODES, []);
        $items[$code] = [
            'user_id' => $user_id,
            'client_id' => $client_id,
            'scope' => $scope,
            'code_challenge' => $code_challenge,
            'resource' => $resource,
            'expires' => time() + 300,
        ];
        update_option(self::OPTION_CODES, $items, false);

        return $code;
    }

    public function exchange_code(string $code, string $client_id, string $code_verifier, string $resource): array
    {
        $codes = get_option(self::OPTION_CODES, []);
        if (! is_array($codes) || ! isset($codes[$code])) {
            return [];
        }

        $record = $codes[$code];
        unset($codes[$code]);
        update_option(self::OPTION_CODES, $codes, false);

        if (
            (int) $record['expires'] < time() ||
            $record['client_id'] !== $client_id ||
            (string) ($record['resource'] ?? '') !== $resource
        ) {
            return [];
        }

        $hash = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
        if (! hash_equals((string) $record['code_challenge'], $hash)) {
            return [];
        }

        return $this->issue_tokens((int) $record['user_id'], (string) $record['scope'], $resource, $client_id);
    }

    public function issue_tokens(int $user_id, string $scope, string $resource, string $client_id): array
    {
        $access_token = wp_generate_uuid4() . wp_generate_uuid4();
        $refresh_token = wp_generate_uuid4() . wp_generate_uuid4();
        $tokens = get_option(self::OPTION_TOKENS, []);
        $tokens[hash('sha256', $access_token)] = [
            'user_id' => $user_id,
            'client_id' => $client_id,
            'scope' => $scope,
            'resource' => $resource,
            'refresh_hash' => hash('sha256', $refresh_token),
            'expires' => time() + HOUR_IN_SECONDS,
        ];
        update_option(self::OPTION_TOKENS, $tokens, false);

        return [
            'access_token' => $access_token,
            'refresh_token' => $refresh_token,
            'token_type' => 'Bearer',
            'expires_in' => HOUR_IN_SECONDS,
            'scope' => $scope,
            'resource' => $resource,
        ];
    }

    public function refresh(string $refresh_token, string $resource, string $client_id): array
    {
        $tokens = get_option(self::OPTION_TOKENS, []);
        if (! is_array($tokens)) {
            return [];
        }

        $target_hash = hash('sha256', $refresh_token);
        foreach ($tokens as $access_hash => $record) {
            if (($record['refresh_hash'] ?? '') !== $target_hash) {
                continue;
            }

            unset($tokens[$access_hash]);
            update_option(self::OPTION_TOKENS, $tokens, false);

            if (
                (string) ($record['resource'] ?? '') !== $resource
                || (string) ($record['client_id'] ?? '') !== $client_id
            ) {
                return [];
            }

            return $this->issue_tokens((int) $record['user_id'], (string) $record['scope'], $resource, $client_id);
        }

        return [];
    }

    public function user_from_bearer(string $access_token): int
    {
        $context = $this->context_from_bearer($access_token);
        return (int) ($context['user_id'] ?? 0);
    }

    public function context_from_bearer(string $access_token): array
    {
        $tokens = get_option(self::OPTION_TOKENS, []);
        $key = hash('sha256', $access_token);
        if (! is_array($tokens) || ! isset($tokens[$key])) {
            return [];
        }

        $record = $tokens[$key];
        if ((int) $record['expires'] < time()) {
            unset($tokens[$key]);
            update_option(self::OPTION_TOKENS, $tokens, false);
            return [];
        }

        return [
            'user_id' => (int) $record['user_id'],
            'client_id' => (string) ($record['client_id'] ?? ''),
            'scope' => (string) ($record['scope'] ?? ''),
            'scopes' => preg_split('/\s+/', trim((string) ($record['scope'] ?? ''))) ?: [],
            'resource' => (string) ($record['resource'] ?? ''),
            'expires' => (int) ($record['expires'] ?? 0),
        ];
    }

    public function has_active_tokens(): bool
    {
        $tokens = get_option(self::OPTION_TOKENS, []);
        if (! is_array($tokens) || [] === $tokens) {
            return false;
        }

        $now = time();
        foreach ($tokens as $key => $record) {
            if ((int) ($record['expires'] ?? 0) >= $now) {
                return true;
            }

            unset($tokens[$key]);
        }

        update_option(self::OPTION_TOKENS, $tokens, false);
        return false;
    }

    public function revoke_all_tokens(): void
    {
        update_option(self::OPTION_TOKENS, [], false);
        update_option(self::OPTION_CODES, [], false);
    }
}

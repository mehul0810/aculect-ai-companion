<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\Auth;

use Quark\Auth\Access;

final class OAuthWebFlow
{
    private const SUPPORTED_SCOPES = ['content:read', 'content:draft'];

    public function build_authorize_context(array $params): array
    {
        $client_id = (string) ($params['client_id'] ?? '');
        $redirect_uri = (string) ($params['redirect_uri'] ?? '');
        $response_type = (string) ($params['response_type'] ?? 'code');
        $state = (string) ($params['state'] ?? '');
        $scope = (string) ($params['scope'] ?? 'content:read content:draft');
        $resource = (string) ($params['resource'] ?? rest_url('quark/v1/mcp'));
        $code_challenge = (string) ($params['code_challenge'] ?? '');
        $code_challenge_method = (string) ($params['code_challenge_method'] ?? '');

        $registry = new OAuthClientRegistry();
        $client = $registry->find_client($client_id);
        $uses_pkce = '' !== $code_challenge;

        if (
            '' === $client_id ||
            '' === $redirect_uri ||
            'code' !== $response_type ||
            '' === $state ||
            rest_url('quark/v1/mcp') !== $resource ||
            ! $this->has_supported_scopes($scope) ||
            ($uses_pkce && 'S256' !== $code_challenge_method) ||
            [] === $client
        ) {
            return ['valid' => false];
        }

        if (! $registry->client_redirect_allowed($client, $redirect_uri)) {
            return ['valid' => false];
        }

        return [
            'valid' => true,
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'state' => $state,
            'scope' => $scope,
            'response_type' => $response_type,
            'code_challenge' => $code_challenge,
            'resource' => $resource,
        ];
    }

    public function handle_authorize(): void
    {
        if (! is_user_logged_in()) {
            wp_die('You must be logged in to authorize this request.', 401);
        }

        check_admin_referer('quark_oauth_authorize');

        $context = $this->build_authorize_context($_POST);
        if (! $context['valid']) {
            wp_die('Invalid OAuth authorization request.', 400);
        }

        $action = (string) ($_POST['decision'] ?? 'deny');
        if ('approve' !== $action) {
            $this->redirect_with_error((string) $context['redirect_uri'], (string) $context['state'], 'access_denied');
        }

        $code = (new Access())->issue_code(
            get_current_user_id(),
            (string) $context['client_id'],
            (string) $context['scope'],
            (string) $context['code_challenge'],
            (string) $context['resource']
        );

        $this->redirect_with_code(
            (string) $context['redirect_uri'],
            (string) $context['state'],
            $code
        );
    }

    private function has_supported_scopes(string $scope): bool
    {
        $requested = preg_split('/\s+/', trim($scope)) ?: [];
        if ([] === $requested) {
            return false;
        }

        foreach ($requested as $item) {
            if (! in_array($item, self::SUPPORTED_SCOPES, true)) {
                return false;
            }
        }

        return true;
    }

    private function redirect_with_code(string $redirect_uri, string $state, string $code): void
    {
        $url = add_query_arg([
            'code' => $code,
            'state' => $state,
        ], $redirect_uri);
        wp_redirect(esc_url_raw($url), 302, 'Quark OAuth');
        exit;
    }

    private function redirect_with_error(string $redirect_uri, string $state, string $error): void
    {
        $url = add_query_arg([
            'error' => $error,
            'state' => $state,
        ], $redirect_uri);
        wp_redirect(esc_url_raw($url), 302, 'Quark OAuth');
        exit;
    }
}

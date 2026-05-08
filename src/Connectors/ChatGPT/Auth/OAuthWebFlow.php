<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\Auth;

use Quark\Auth\Access;

final class OAuthWebFlow
{
    private const OPTION_CLIENTS = 'quark_oauth_clients';

    public function build_authorize_context(array $params): array
    {
        $client_id = (string) ($params['client_id'] ?? '');
        $redirect_uri = (string) ($params['redirect_uri'] ?? '');
        $state = (string) ($params['state'] ?? '');
        $scope = (string) ($params['scope'] ?? 'content:read content:draft');
        $resource = (string) ($params['resource'] ?? rest_url('quark/v1/mcp'));
        $code_challenge = (string) ($params['code_challenge'] ?? '');
        $code_challenge_method = (string) ($params['code_challenge_method'] ?? 'S256');

        $clients = get_option(self::OPTION_CLIENTS, []);

        if (
            '' === $client_id ||
            '' === $redirect_uri ||
            '' === $state ||
            '' === $code_challenge ||
            'S256' !== $code_challenge_method ||
            ! is_array($clients) ||
            ! isset($clients[$client_id])
        ) {
            return ['valid' => false];
        }

        $client = $clients[$client_id];
        $allowed = $client['redirect_uris'] ?? [];
        if (! is_array($allowed) || ! in_array($redirect_uri, $allowed, true)) {
            return ['valid' => false];
        }

        return [
            'valid' => true,
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'state' => $state,
            'scope' => $scope,
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

    public function redirect_with_code(string $redirect_uri, string $state, string $code): void
    {
        $url = add_query_arg([
            'code' => $code,
            'state' => $state,
        ], $redirect_uri);
        wp_safe_redirect($url, 302, 'Quark OAuth');
        exit;
    }

    public function redirect_with_error(string $redirect_uri, string $state, string $error): void
    {
        $url = add_query_arg([
            'error' => $error,
            'state' => $state,
        ], $redirect_uri);
        wp_safe_redirect($url, 302, 'Quark OAuth');
        exit;
    }
}

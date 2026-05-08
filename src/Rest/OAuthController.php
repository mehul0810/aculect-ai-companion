<?php

declare(strict_types=1);

namespace Quark\Rest;

use Quark\Auth\Access;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class OAuthController
{
    private const OPTION_CLIENTS = 'quark_oauth_clients';

    public function register_routes(): void
    {
        register_rest_route('quark/v1', '/oauth/register', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'register_client'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('quark/v1', '/oauth/authorize', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'authorize'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('quark/v1', '/oauth/token', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'token'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function register_client(WP_REST_Request $request): WP_REST_Response
    {
        $redirect_uri = (string) $request->get_param('redirect_uri');
        if ('' === $redirect_uri) {
            return new WP_REST_Response(['error' => 'redirect_uri is required'], 400);
        }

        $client_id = 'quark_' . wp_generate_password(20, false, false);
        $clients = get_option(self::OPTION_CLIENTS, []);
        $clients[$client_id] = ['redirect_uri' => $redirect_uri];
        update_option(self::OPTION_CLIENTS, $clients, false);

        return new WP_REST_Response(['client_id' => $client_id], 201);
    }

    public function authorize(WP_REST_Request $request): WP_REST_Response
    {
        if (! is_user_logged_in()) {
            auth_redirect();
            exit;
        }

        $client_id = (string) $request->get_param('client_id');
        $redirect_uri = (string) $request->get_param('redirect_uri');
        $state = (string) $request->get_param('state');
        $scope = (string) ($request->get_param('scope') ?: 'content:read content:draft');
        $code_challenge = (string) $request->get_param('code_challenge');

        $clients = get_option(self::OPTION_CLIENTS, []);
        if (! isset($clients[$client_id]) || $clients[$client_id]['redirect_uri'] !== $redirect_uri) {
            return new WP_REST_Response(['error' => 'invalid_client'], 400);
        }
        if ('' === $state || '' === $code_challenge) {
            return new WP_REST_Response(['error' => 'invalid_request'], 400);
        }

        $code = (new Access())->issue_code(get_current_user_id(), $client_id, $scope, $code_challenge);
        return new WP_REST_Response(['code' => $code, 'state' => $state], 200);
    }

    public function token(WP_REST_Request $request): WP_REST_Response
    {
        $grant_type = (string) $request->get_param('grant_type');
        $access = new Access();
        if ('authorization_code' === $grant_type) {
            $tokens = $access->exchange_code(
                (string) $request->get_param('code'),
                (string) $request->get_param('client_id'),
                (string) $request->get_param('code_verifier')
            );
            return new WP_REST_Response($tokens ?: ['error' => 'invalid_grant'], $tokens ? 200 : 400);
        }

        if ('refresh_token' === $grant_type) {
            $tokens = $access->refresh((string) $request->get_param('refresh_token'));
            return new WP_REST_Response($tokens ?: ['error' => 'invalid_grant'], $tokens ? 200 : 400);
        }

        return new WP_REST_Response(['error' => 'unsupported_grant_type'], 400);
    }
}

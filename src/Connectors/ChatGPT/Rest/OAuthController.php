<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\Rest;

use Quark\Auth\Access;
use Quark\Connectors\ChatGPT\Auth\OAuthWebFlow;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class OAuthController
{
    private const OPTION_CLIENTS = 'quark_oauth_clients';

    public function register_routes(): void
    {
        register_rest_route('quark/v1', '/.well-known/oauth-protected-resource', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'protected_resource_metadata'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('quark/v1', '/.well-known/oauth-authorization-server', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'oauth_metadata'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('quark/v1', '/.well-known/openid-configuration', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'openid_metadata'],
            'permission_callback' => '__return_true',
        ]);
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

    public function oauth_metadata(): WP_REST_Response
    {
        $issuer = $this->issuer();

        return new WP_REST_Response([
            'issuer' => $issuer,
            'authorization_endpoint' => rest_url('quark/v1/oauth/authorize'),
            'token_endpoint' => rest_url('quark/v1/oauth/token'),
            'registration_endpoint' => rest_url('quark/v1/oauth/register'),
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['content:read', 'content:draft'],
        ], 200);
    }

    public function protected_resource_metadata(): WP_REST_Response
    {
        return new WP_REST_Response([
            'resource' => rest_url('quark/v1/mcp'),
            'authorization_servers' => [untrailingslashit(rest_url('quark/v1'))],
            'scopes_supported' => ['content:read', 'content:draft'],
            'resource_documentation' => 'https://github.com/mehul0810/quark',
        ], 200);
    }

    public function openid_metadata(): WP_REST_Response
    {
        $issuer = $this->issuer();

        return new WP_REST_Response([
            'issuer' => $issuer,
            'authorization_endpoint' => rest_url('quark/v1/oauth/authorize'),
            'token_endpoint' => rest_url('quark/v1/oauth/token'),
            'registration_endpoint' => rest_url('quark/v1/oauth/register'),
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['content:read', 'content:draft'],
        ], 200);
    }

    public function register_client(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            return new WP_REST_Response(['error' => 'invalid_client_metadata'], 400);
        }

        $redirect_uris = $payload['redirect_uris'] ?? [];
        if (! is_array($redirect_uris) || [] === $redirect_uris) {
            return new WP_REST_Response(['error' => 'invalid_redirect_uri'], 400);
        }

        $normalized = [];
        foreach ($redirect_uris as $uri) {
            $value = esc_url_raw((string) $uri);
            if ('' === $value) {
                continue;
            }
            $normalized[] = $value;
        }

        if ([] === $normalized) {
            return new WP_REST_Response(['error' => 'invalid_redirect_uri'], 400);
        }

        $client_id = 'quark_' . wp_generate_password(20, false, false);
        $clients = get_option(self::OPTION_CLIENTS, []);
        $clients[$client_id] = [
            'redirect_uris' => array_values(array_unique($normalized)),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
        update_option(self::OPTION_CLIENTS, $clients, false);

        return new WP_REST_Response([
            'client_id' => $client_id,
            'client_id_issued_at' => time(),
            'redirect_uris' => $clients[$client_id]['redirect_uris'],
            'grant_types' => $clients[$client_id]['grant_types'],
            'response_types' => $clients[$client_id]['response_types'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    public function authorize(WP_REST_Request $request): WP_REST_Response
    {
        if (! is_user_logged_in()) {
            auth_redirect();
            exit;
        }

        $context = (new OAuthWebFlow())->build_authorize_context($request->get_params());
        if (! $context['valid']) {
            return new WP_REST_Response(['error' => 'invalid_client'], 400);
        }

        $consent_url = add_query_arg([
            'page' => 'quark',
            'view' => 'oauth-consent',
            'client_id' => (string) $context['client_id'],
            'redirect_uri' => (string) $context['redirect_uri'],
            'state' => (string) $context['state'],
            'scope' => (string) $context['scope'],
            'code_challenge' => (string) $context['code_challenge'],
            'code_challenge_method' => 'S256',
            'resource' => (string) ($request->get_param('resource') ?: rest_url('quark/v1/mcp')),
        ], admin_url('options-general.php'));

        return new WP_REST_Response(['consent_url' => $consent_url], 200);
    }

    public function token(WP_REST_Request $request): WP_REST_Response
    {
        $grant_type = (string) $request->get_param('grant_type');
        $access = new Access();
        if ('authorization_code' === $grant_type) {
            $resource = (string) ($request->get_param('resource') ?: rest_url('quark/v1/mcp'));
            $tokens = $access->exchange_code(
                (string) $request->get_param('code'),
                (string) $request->get_param('client_id'),
                (string) $request->get_param('code_verifier'),
                $resource
            );
            return new WP_REST_Response($tokens ?: ['error' => 'invalid_grant'], $tokens ? 200 : 400);
        }

        if ('refresh_token' === $grant_type) {
            $resource = (string) ($request->get_param('resource') ?: rest_url('quark/v1/mcp'));
            $tokens = $access->refresh((string) $request->get_param('refresh_token'), $resource);
            return new WP_REST_Response($tokens ?: ['error' => 'invalid_grant'], $tokens ? 200 : 400);
        }

        return new WP_REST_Response(['error' => 'unsupported_grant_type'], 400);
    }

    private function issuer(): string
    {
        return untrailingslashit(rest_url('quark/v1'));
    }
}

<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\Rest;

use Quark\Auth\Access;
use Quark\Connectors\ChatGPT\Auth\OAuthClientRegistry;
use Quark\Connectors\ChatGPT\Auth\OAuthWebFlow;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class OAuthController
{
    private const RESOURCE_METADATA = 'oauth-protected-resource';
    private const AUTHORIZATION_METADATA = 'oauth-authorization-server';
    private const SUPPORTED_SCOPES = ['content:read', 'content:draft'];

    public function add_rewrite_rules(): void
    {
        add_rewrite_rule(
            '^\.well-known/(oauth-protected-resource|oauth-authorization-server)/?$',
            'index.php?quark_well_known=$matches[1]',
            'top'
        );
    }

    public function render_well_known_metadata(): void
    {
        $document = (string) get_query_var('quark_well_known');
        if ('' === $document) {
            $path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $document = is_string($path) && preg_match('#/\.well-known/(oauth-protected-resource|oauth-authorization-server)/?$#', $path, $matches)
                ? (string) $matches[1]
                : '';
        }

        if ('' === $document) {
            return;
        }

        $response = match ($document) {
            self::RESOURCE_METADATA => $this->protected_resource_metadata(),
            self::AUTHORIZATION_METADATA => $this->oauth_metadata(),
            default => null,
        };

        if (! $response instanceof WP_REST_Response) {
            return;
        }

        status_header($response->get_status());
        nocache_headers();
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        echo wp_json_encode($response->get_data(), JSON_UNESCAPED_SLASHES);
        exit;
    }

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
        $registry = new OAuthClientRegistry();
        $settings = $registry->settings();
        $metadata = [
            'issuer' => $this->issuer(),
            'authorization_endpoint' => rest_url('quark/v1/oauth/authorize'),
            'token_endpoint' => rest_url('quark/v1/oauth/token'),
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'token_endpoint_auth_methods_supported' => [
                OAuthClientRegistry::AUTH_NONE,
                OAuthClientRegistry::AUTH_CLIENT_SECRET_POST,
                OAuthClientRegistry::AUTH_CLIENT_SECRET_BASIC,
            ],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['content:read', 'content:draft'],
            'resource_indicators_supported' => true,
        ];

        if (OAuthClientRegistry::MODE_DCR === $settings['registration_method']) {
            $metadata['registration_endpoint'] = rest_url('quark/v1/oauth/register');
        }

        return new WP_REST_Response($metadata, 200);
    }

    public function protected_resource_metadata(): WP_REST_Response
    {
        return new WP_REST_Response([
            'resource' => rest_url('quark/v1/mcp'),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => ['content:read', 'content:draft'],
            'resource_documentation' => 'https://github.com/mehul0810/quark',
            'token_endpoint_auth_methods_supported' => [
                OAuthClientRegistry::AUTH_NONE,
                OAuthClientRegistry::AUTH_CLIENT_SECRET_POST,
                OAuthClientRegistry::AUTH_CLIENT_SECRET_BASIC,
            ],
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
            if ('' === $value || ! (new OAuthClientRegistry())->is_chatgpt_redirect($value)) {
                continue;
            }
            $normalized[] = $value;
        }

        if ([] === $normalized) {
            return new WP_REST_Response(['error' => 'invalid_redirect_uri'], 400);
        }

        $scope = sanitize_text_field((string) ($payload['scope'] ?? 'content:read content:draft'));
        if (! $this->has_supported_scopes($scope)) {
            return new WP_REST_Response(['error' => 'invalid_scope'], 400);
        }

        $client_id = 'quark_' . wp_generate_password(20, false, false);
        $clients = get_option(OAuthClientRegistry::OPTION_DCR_CLIENTS, []);
        $clients[$client_id] = [
            'redirect_uris' => array_values(array_unique($normalized)),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'client_name' => sanitize_text_field((string) ($payload['client_name'] ?? 'ChatGPT')),
            'scope' => $scope,
        ];
        update_option(OAuthClientRegistry::OPTION_DCR_CLIENTS, $clients, false);

        return new WP_REST_Response([
            'client_id' => $client_id,
            'client_id_issued_at' => time(),
            'redirect_uris' => $clients[$client_id]['redirect_uris'],
            'grant_types' => $clients[$client_id]['grant_types'],
            'response_types' => $clients[$client_id]['response_types'],
            'token_endpoint_auth_method' => 'none',
            'client_name' => $clients[$client_id]['client_name'],
            'scope' => $clients[$client_id]['scope'],
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
            'resource' => (string) $context['resource'],
        ], admin_url('options-general.php'));

        wp_safe_redirect($consent_url, 302, 'Quark OAuth');
        exit;
    }

    public function token(WP_REST_Request $request): WP_REST_Response
    {
        $grant_type = (string) $request->get_param('grant_type');
        $client_id = (string) $request->get_param('client_id');
        $registry = new OAuthClientRegistry();
        if ('' === $client_id) {
            $client_id = $registry->client_id_from_authorization_header((string) $request->get_header('authorization'));
        }

        if (! $registry->verify_token_endpoint_auth(
            $client_id,
            (string) $request->get_param('client_secret'),
            (string) $request->get_header('authorization')
        )) {
            return new WP_REST_Response(['error' => 'invalid_client'], 401);
        }

        $access = new Access();
        if ('authorization_code' === $grant_type) {
            $resource = (string) $request->get_param('resource');
            if (rest_url('quark/v1/mcp') !== $resource) {
                return new WP_REST_Response(['error' => 'invalid_target'], 400);
            }

            $tokens = $access->exchange_code(
                (string) $request->get_param('code'),
                $client_id,
                (string) $request->get_param('code_verifier'),
                $resource
            );
            return new WP_REST_Response($tokens ?: ['error' => 'invalid_grant'], $tokens ? 200 : 400);
        }

        if ('refresh_token' === $grant_type) {
            $resource = (string) $request->get_param('resource');
            if (rest_url('quark/v1/mcp') !== $resource) {
                return new WP_REST_Response(['error' => 'invalid_target'], 400);
            }

            $tokens = $access->refresh((string) $request->get_param('refresh_token'), $resource);
            return new WP_REST_Response($tokens ?: ['error' => 'invalid_grant'], $tokens ? 200 : 400);
        }

        return new WP_REST_Response(['error' => 'unsupported_grant_type'], 400);
    }

    private function issuer(): string
    {
        return untrailingslashit(home_url('/'));
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
}

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
    private const RESOURCE_METADATA = 'oauth-protected-resource';
    private const AUTHORIZATION_METADATA = 'oauth-authorization-server';
    private const OPENID_METADATA = 'openid-configuration';

    public function add_rewrite_rules(): void
    {
        add_rewrite_rule(
            '^\.well-known/(oauth-protected-resource|oauth-authorization-server|openid-configuration)/?$',
            'index.php?quark_well_known=$matches[1]',
            'top'
        );
    }

    public function render_well_known_metadata(): void
    {
        $document = (string) get_query_var('quark_well_known');
        if ('' === $document) {
            $path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            $document = is_string($path) && preg_match('#/\.well-known/(oauth-protected-resource|oauth-authorization-server|openid-configuration)/?$#', $path, $matches)
                ? (string) $matches[1]
                : '';
        }

        if ('' === $document) {
            return;
        }

        $response = match ($document) {
            self::RESOURCE_METADATA => $this->protected_resource_metadata(),
            self::AUTHORIZATION_METADATA => $this->oauth_metadata(),
            self::OPENID_METADATA => $this->openid_metadata(),
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
        return new WP_REST_Response([
            'issuer' => $this->issuer(),
            'authorization_endpoint' => rest_url('quark/v1/oauth/authorize'),
            'token_endpoint' => rest_url('quark/v1/oauth/token'),
            'registration_endpoint' => rest_url('quark/v1/oauth/register'),
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['content:read', 'content:draft'],
            'resource_indicators_supported' => true,
        ], 200);
    }

    public function protected_resource_metadata(): WP_REST_Response
    {
        return new WP_REST_Response([
            'resource' => rest_url('quark/v1/mcp'),
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => ['content:read', 'content:draft'],
            'resource_documentation' => 'https://github.com/mehul0810/quark',
            'token_endpoint_auth_methods_supported' => ['none'],
        ], 200);
    }

    public function openid_metadata(): WP_REST_Response
    {
        return new WP_REST_Response([
            'issuer' => $this->issuer(),
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
            'resource_indicators_supported' => true,
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
            'client_name' => sanitize_text_field((string) ($payload['client_name'] ?? 'ChatGPT')),
            'scope' => sanitize_text_field((string) ($payload['scope'] ?? 'content:read content:draft')),
        ];
        update_option(self::OPTION_CLIENTS, $clients, false);

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
            'resource' => (string) ($request->get_param('resource') ?: rest_url('quark/v1/mcp')),
        ], admin_url('options-general.php'));

        wp_safe_redirect($consent_url, 302, 'Quark OAuth');
        exit;
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
        return untrailingslashit(home_url('/'));
    }
}

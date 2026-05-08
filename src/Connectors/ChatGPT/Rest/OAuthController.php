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
    private const AUTHORIZE_QUERY_VAR = 'quark_oauth_authorize';

    public function add_rewrite_rules(): void
    {
        add_rewrite_rule(
            '^\.well-known/oauth-protected-resource(/.+)?/?$',
            'index.php?quark_well_known=' . self::RESOURCE_METADATA . '&quark_well_known_resource_path=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^\.well-known/oauth-authorization-server/?$',
            'index.php?quark_well_known=' . self::AUTHORIZATION_METADATA,
            'top'
        );

    }

    public function render_well_known_metadata(): void
    {
        $document = (string) get_query_var('quark_well_known');
        $resource_path = (string) get_query_var('quark_well_known_resource_path');

        if ('' === $document) {
            $path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            if (is_string($path)) {
                if (preg_match('#/\.well-known/oauth-protected-resource(?P<resource_path>/.+)?/?$#', $path, $matches)) {
                    $document = self::RESOURCE_METADATA;
                    $resource_path = (string) ($matches['resource_path'] ?? '');
                } elseif (preg_match('#/\.well-known/oauth-authorization-server/?$#', $path)) {
                    $document = self::AUTHORIZATION_METADATA;
                }
            }
        }

        if ('' === $document) {
            return;
        }

        $response = match ($document) {
            self::RESOURCE_METADATA => $this->protected_resource_metadata($resource_path),
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
        $metadata = [
            'issuer' => $this->issuer(),
            'authorization_endpoint' => $this->authorization_endpoint(),
            'token_endpoint' => rest_url('quark/v1/oauth/token'),
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'token_endpoint_auth_methods_supported' => $this->supported_token_endpoint_auth_methods(),
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['content:read', 'content:draft'],
            'resource_indicators_supported' => true,
            'protected_resources' => [rest_url('quark/v1/mcp')],
        ];

        return new WP_REST_Response($metadata, 200);
    }

    public function protected_resource_metadata(string $requested_resource_path = ''): WP_REST_Response
    {
        $resource = rest_url('quark/v1/mcp');
        $resource_path = (string) wp_parse_url($resource, PHP_URL_PATH);

        if ('' !== $requested_resource_path && $resource_path !== untrailingslashit($requested_resource_path)) {
            return new WP_REST_Response(['error' => 'invalid_target'], 404);
        }

        return new WP_REST_Response([
            'resource' => $resource,
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => ['content:read', 'content:draft'],
            'resource_documentation' => 'https://github.com/mehul0810/quark',
            'token_endpoint_auth_methods_supported' => $this->supported_token_endpoint_auth_methods(),
        ], 200);
    }

    public function authorize(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_params();
        $target = add_query_arg(
            array_map(
                static fn ($value): string => is_scalar($value) ? (string) $value : '',
                $params
            ),
            $this->authorization_endpoint()
        );

        wp_safe_redirect($target, 302, 'Quark OAuth');
        exit;
    }

    public function maybe_render_browser_authorize(): void
    {
        $is_authorize_request = '1' === (string) get_query_var(self::AUTHORIZE_QUERY_VAR)
            || '1' === (string) ($_GET[self::AUTHORIZE_QUERY_VAR] ?? '');

        if (! $is_authorize_request) {
            return;
        }

        if (! is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($this->current_request_url()), 302, 'Quark OAuth');
            exit;
        }

        $context = (new OAuthWebFlow())->build_authorize_context(wp_unslash($_GET));
        if (! $context['valid']) {
            wp_die('Invalid OAuth authorization request.', 400);
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

            $tokens = $access->refresh((string) $request->get_param('refresh_token'), $resource, $client_id);
            return new WP_REST_Response($tokens ?: ['error' => 'invalid_grant'], $tokens ? 200 : 400);
        }

        return new WP_REST_Response(['error' => 'unsupported_grant_type'], 400);
    }

    private function issuer(): string
    {
        return untrailingslashit(home_url('/'));
    }

    public function authorization_endpoint(): string
    {
        return add_query_arg(self::AUTHORIZE_QUERY_VAR, '1', home_url('/'));
    }

    public function protected_resource_metadata_url(?string $resource = null): string
    {
        $resource = $resource ?: rest_url('quark/v1/mcp');
        $parts = wp_parse_url($resource);

        if (! is_array($parts)) {
            return home_url('/.well-known/' . self::RESOURCE_METADATA);
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = untrailingslashit((string) ($parts['path'] ?? ''));

        return $scheme . '://' . $host . $port . '/.well-known/' . self::RESOURCE_METADATA . $path;
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

    private function supported_token_endpoint_auth_methods(): array
    {
        return [OAuthClientRegistry::AUTH_CLIENT_SECRET_POST];
    }

    private function current_request_url(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return home_url($uri);
    }
}

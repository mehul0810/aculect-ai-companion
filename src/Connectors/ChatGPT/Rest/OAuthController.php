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
    private const TOKEN_QUERY_VAR = 'quark_oauth_token';
    private const REGISTER_QUERY_VAR = 'quark_oauth_register';

    public function add_rewrite_rules(): void
    {
        add_rewrite_rule(
            '^\.well-known/oauth-protected-resource(/.+)?/?$',
            'index.php?quark_well_known=' . self::RESOURCE_METADATA . '&quark_well_known_resource_path=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^\.well-known/oauth-authorization-server(/.+)?/?$',
            'index.php?quark_well_known=' . self::AUTHORIZATION_METADATA . '&quark_well_known_issuer_path=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^oauth/authorize/?$',
            'index.php?' . self::AUTHORIZE_QUERY_VAR . '=1',
            'top'
        );

        add_rewrite_rule(
            '^oauth/token/?$',
            'index.php?' . self::TOKEN_QUERY_VAR . '=1',
            'top'
        );

        add_rewrite_rule(
            '^oauth/register/?$',
            'index.php?' . self::REGISTER_QUERY_VAR . '=1',
            'top'
        );
    }

    public function render_well_known_metadata(): void
    {
        $document = (string) get_query_var('quark_well_known');
        $resource_path = (string) get_query_var('quark_well_known_resource_path');
        $issuer_path = (string) get_query_var('quark_well_known_issuer_path');

        if ('' === $document) {
            $path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            if (is_string($path)) {
                if (preg_match('#/\.well-known/oauth-protected-resource(?P<resource_path>/.+)?/?$#', $path, $matches)) {
                    $document = self::RESOURCE_METADATA;
                    $resource_path = (string) ($matches['resource_path'] ?? '');
                } elseif (preg_match('#/\.well-known/oauth-authorization-server(?P<issuer_path>/.+)?/?$#', $path, $matches)) {
                    $document = self::AUTHORIZATION_METADATA;
                    $issuer_path = (string) ($matches['issuer_path'] ?? '');
                }
            }
        }

        if ('' === $document) {
            return;
        }

        $response = match ($document) {
            self::RESOURCE_METADATA => $this->protected_resource_metadata($resource_path),
            self::AUTHORIZATION_METADATA => $this->oauth_metadata($issuer_path),
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
        register_rest_route('quark/v1', '/mcp/.well-known/oauth-authorization-server', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'resource_oauth_metadata'],
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
        register_rest_route('quark/v1', '/oauth/register', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'register_client'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function oauth_metadata($requested_issuer_path = ''): WP_REST_Response
    {
        if ($requested_issuer_path instanceof WP_REST_Request) {
            $requested_issuer_path = '';
        }

        $settings = (new OAuthClientRegistry())->settings();
        $issuer = $this->issuer_for_path((string) $requested_issuer_path);
        $metadata = [
            'issuer' => $issuer,
            'authorization_endpoint' => $this->authorization_endpoint($issuer),
            'token_endpoint' => $this->token_endpoint(),
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'token_endpoint_auth_methods_supported' => $this->supported_token_endpoint_auth_methods(),
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => ['content:read', 'content:draft'],
            'resource_indicators_supported' => true,
            'protected_resources' => [rest_url('quark/v1/mcp')],
        ];

        if (OAuthClientRegistry::MODE_DCR === $settings['registration_method']) {
            $metadata['registration_endpoint'] = $this->registration_endpoint();
        }

        return new WP_REST_Response($metadata, 200);
    }

    public function resource_oauth_metadata($request = null): WP_REST_Response
    {
        unset($request);

        return $this->oauth_metadata($this->resource_path());
    }

    public function protected_resource_metadata(string $requested_resource_path = ''): WP_REST_Response
    {
        $resource = $this->resource_issuer();
        $resource_path = $this->resource_path();

        if ('' !== $requested_resource_path && $resource_path !== untrailingslashit($requested_resource_path)) {
            return new WP_REST_Response(['error' => 'invalid_target'], 404);
        }

        return new WP_REST_Response([
            'resource' => $resource,
            'authorization_servers' => [$this->resource_issuer()],
            'scopes_supported' => ['content:read', 'content:draft'],
            'resource_documentation' => 'https://github.com/mehul0810/quark',
            'token_endpoint_auth_methods_supported' => $this->supported_token_endpoint_auth_methods(),
        ], 200);
    }

    public function register_client(WP_REST_Request $request): WP_REST_Response
    {
        if (! $this->check_rate_limit('dcr:' . $this->rate_limit_identity($request), 120, HOUR_IN_SECONDS)) {
            return $this->oauth_error(
                'slow_down',
                'Too many dynamic client registration attempts. Try again later.',
                429
            );
        }

        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            return $this->oauth_error(
                'invalid_client_metadata',
                'Dynamic client registration requires a JSON object request body.',
                400
            );
        }

        $client = (new OAuthClientRegistry())->register_client($payload);
        if ([] === $client) {
            return $this->oauth_error(
                'invalid_client_metadata',
                'Dynamic client registration is disabled or the client metadata is not supported.',
                400
            );
        }

        $response = $client;
        unset($response['manual']);

        return new WP_REST_Response($response, 201);
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
        $path = $this->current_request_path();
        $is_authorize_request = '1' === (string) get_query_var(self::AUTHORIZE_QUERY_VAR)
            || '1' === (string) ($_GET[self::AUTHORIZE_QUERY_VAR] ?? '')
            || '/oauth/authorize' === $path;

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
            'quark_oauth_issuer' => (string) $context['issuer'],
        ], admin_url('options-general.php'));

        wp_safe_redirect($consent_url, 302, 'Quark OAuth');
        exit;
    }

    public function maybe_handle_oauth_endpoint_aliases(): void
    {
        $path = $this->current_request_path();
        $is_token_request = '1' === (string) get_query_var(self::TOKEN_QUERY_VAR) || '/oauth/token' === $path;
        $is_register_request = '1' === (string) get_query_var(self::REGISTER_QUERY_VAR) || '/oauth/register' === $path;

        if (! $is_token_request && ! $is_register_request) {
            return;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ('POST' !== $method) {
            $this->send_json_response($this->oauth_error(
                'invalid_request',
                'This OAuth endpoint requires POST.',
                405
            ));
        }

        $request = $this->request_from_current_http($is_token_request ? '/quark/v1/oauth/token' : '/quark/v1/oauth/register');
        $this->send_json_response($is_token_request ? $this->token($request) : $this->register_client($request));
    }

    public function token(WP_REST_Request $request): WP_REST_Response
    {
        $grant_type = (string) $request->get_param('grant_type');
        $client_id = (string) $request->get_param('client_id');
        $registry = new OAuthClientRegistry();
        if ('' === $client_id) {
            $client_id = $registry->client_id_from_authorization_header((string) $request->get_header('authorization'));
        }

        $rate_limit_key = '' !== $client_id ? $client_id : $this->rate_limit_identity($request);
        if (! $this->check_rate_limit($rate_limit_key, 60, MINUTE_IN_SECONDS)) {
            return $this->oauth_error(
                'slow_down',
                'Too many authentication attempts. Try again later.',
                429
            );
        }

        if (! $registry->verify_token_endpoint_auth(
            $client_id,
            (string) $request->get_param('client_secret'),
            (string) $request->get_header('authorization')
        )) {
            return $this->oauth_error(
                'invalid_client',
                'Client authentication failed. Verify the configured client ID and client secret.',
                401
            );
        }

        $access = new Access();
        if ('authorization_code' === $grant_type) {
            $resource = $this->resource_from_request($request);
            if (rest_url('quark/v1/mcp') !== $resource) {
                return $this->oauth_error(
                    'invalid_target',
                    'The requested resource does not match this MCP server.',
                    400
                );
            }

            $tokens = $access->exchange_code(
                (string) $request->get_param('code'),
                $client_id,
                (string) $request->get_param('code_verifier'),
                $resource,
                (string) $request->get_param('redirect_uri')
            );
            return new WP_REST_Response(
                $tokens ?: $this->oauth_error_payload('invalid_grant', 'Authorization code exchange failed.'),
                $tokens ? 200 : 400
            );
        }

        if ('refresh_token' === $grant_type) {
            $resource = $this->resource_from_request($request);
            if (rest_url('quark/v1/mcp') !== $resource) {
                return $this->oauth_error(
                    'invalid_target',
                    'The requested resource does not match this MCP server.',
                    400
                );
            }

            $tokens = $access->refresh((string) $request->get_param('refresh_token'), $resource, $client_id);
            return new WP_REST_Response(
                $tokens ?: $this->oauth_error_payload('invalid_grant', 'Refresh token exchange failed.'),
                $tokens ? 200 : 400
            );
        }

        return $this->oauth_error(
            'unsupported_grant_type',
            'Only authorization_code and refresh_token grants are supported.',
            400
        );
    }

    private function issuer(): string
    {
        return untrailingslashit(home_url('/'));
    }

    public function authorization_endpoint(?string $issuer = null): string
    {
        return add_query_arg([
            'quark_oauth_issuer' => $issuer ?: $this->resource_issuer(),
        ], home_url('/oauth/authorize'));
    }

    public function token_endpoint(): string
    {
        return home_url('/oauth/token');
    }

    public function registration_endpoint(): string
    {
        return home_url('/oauth/register');
    }

    public function resource_issuer(): string
    {
        return rest_url('quark/v1/mcp');
    }

    public function resource_authorization_metadata_url(): string
    {
        return home_url('/.well-known/' . self::AUTHORIZATION_METADATA . $this->resource_path());
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

    private function resource_path(): string
    {
        return untrailingslashit((string) wp_parse_url($this->resource_issuer(), PHP_URL_PATH));
    }

    private function issuer_for_path(string $requested_issuer_path): string
    {
        $requested_issuer_path = untrailingslashit($requested_issuer_path);

        return $this->resource_path() === $requested_issuer_path ? $this->resource_issuer() : $this->issuer();
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
        $settings = (new OAuthClientRegistry())->settings();

        return OAuthClientRegistry::MODE_DCR === $settings['registration_method']
            ? [OAuthClientRegistry::AUTH_NONE]
            : [OAuthClientRegistry::AUTH_CLIENT_SECRET_POST];
    }

    private function resource_from_request(WP_REST_Request $request): string
    {
        $resource = (string) $request->get_param('resource');
        if ('' === $resource) {
            $resource = (string) $request->get_param('audience');
        }

        return '' !== $resource ? untrailingslashit($resource) : rest_url('quark/v1/mcp');
    }

    private function current_request_url(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        return home_url($uri);
    }

    private function current_request_path(): string
    {
        $path = wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (! is_string($path)) {
            return '';
        }

        return untrailingslashit($path);
    }

    private function request_from_current_http(string $route): WP_REST_Request
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $request = new WP_REST_Request($method, $route);

        foreach ($this->current_http_headers() as $name => $value) {
            $request->set_header($name, $value);
        }

        $request->set_query_params(wp_unslash($_GET));

        if (! empty($_POST)) {
            $request->set_body_params(wp_unslash($_POST));
        }

        $body = file_get_contents('php://input');
        if (is_string($body) && '' !== $body) {
            $request->set_body($body);
        }

        return $request;
    }

    private function current_http_headers(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if ('CONTENT_TYPE' === $key || 'CONTENT_LENGTH' === $key) {
                $name = str_replace('_', '-', strtolower($key));
                $headers[$name] = $value;
                continue;
            }

            if (! str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $name = str_replace('_', '-', strtolower(substr($key, 5)));
            $headers[$name] = $value;
        }

        return $headers;
    }

    private function send_json_response(WP_REST_Response $response): void
    {
        status_header($response->get_status());
        nocache_headers();
        foreach ($response->get_headers() as $name => $value) {
            header($name . ': ' . $value);
        }
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        echo wp_json_encode($response->get_data(), JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function check_rate_limit(string $identifier, int $max_attempts, int $ttl): bool
    {
        if ('' === $identifier) {
            $identifier = 'anonymous';
        }

        $cache_key = 'quark_oauth_rate_' . md5($identifier);
        $attempts = (int) get_transient($cache_key);

        if ($attempts >= $max_attempts) {
            return false;
        }

        set_transient($cache_key, $attempts + 1, $ttl);

        return true;
    }

    private function rate_limit_identity(WP_REST_Request $request): string
    {
        $ip_headers = [
            (string) $request->get_header('cf-connecting-ip'),
            (string) $request->get_header('x-real-ip'),
            (string) $request->get_header('x-forwarded-for'),
            isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '',
        ];

        foreach ($ip_headers as $header) {
            $candidate = sanitize_text_field(trim(explode(',', $header)[0]));
            if ('' !== $candidate) {
                return $candidate;
            }
        }

        return 'anonymous';
    }

    private function oauth_error(string $error, string $description, int $status): WP_REST_Response
    {
        return new WP_REST_Response(
            $this->oauth_error_payload($error, $description),
            $status
        );
    }

    private function oauth_error_payload(string $error, string $description): array
    {
        return [
            'error' => $error,
            'error_description' => $description,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\UserDefined;

use Quark\Auth\Access;
use Quark\Connectors\ChatGPT\Helper;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class OAuthController
{
    private const SUPPORTED_SCOPES = ['content:read', 'content:draft'];

    public function add_rewrite_rules(): void
    {
        add_rewrite_rule(
            '^\.well-known/oauth-protected-resource(/.+)?/?$',
            'index.php?quark_well_known=' . Helper::PROTECTED_RESOURCE_METADATA . '&quark_well_known_resource_path=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^\.well-known/oauth-authorization-server(/.+)?/?$',
            'index.php?quark_well_known=' . Helper::AUTHORIZATION_METADATA . '&quark_well_known_issuer_path=$matches[1]',
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
                    $document = Helper::PROTECTED_RESOURCE_METADATA;
                    $resource_path = (string) ($matches['resource_path'] ?? '');
                } elseif (preg_match('#/\.well-known/oauth-authorization-server(?P<issuer_path>/.+)?/?$#', $path, $matches)) {
                    $document = Helper::AUTHORIZATION_METADATA;
                    $issuer_path = (string) ($matches['issuer_path'] ?? '');
                }
            }
        }

        if ('' === $document) {
            return;
        }

        $response = match ($document) {
            Helper::PROTECTED_RESOURCE_METADATA => $this->protected_resource_metadata($resource_path),
            Helper::AUTHORIZATION_METADATA => $this->oauth_metadata($issuer_path),
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
    }

    public function oauth_metadata($requested_issuer_path = ''): WP_REST_Response
    {
        if ($requested_issuer_path instanceof WP_REST_Request) {
            $requested_issuer_path = '';
        }

        $resource = $this->resource_issuer();

        return new WP_REST_Response([
            'issuer' => $this->issuer_for_path((string) $requested_issuer_path),
            'authorization_endpoint' => $this->authorization_endpoint(),
            'token_endpoint' => $this->token_endpoint(),
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'response_types_supported' => ['code'],
            'token_endpoint_auth_methods_supported' => [ClientCredentials::AUTH_CLIENT_SECRET_POST],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => self::SUPPORTED_SCOPES,
            'resource_indicators_supported' => true,
            'protected_resources' => [$resource],
        ], 200);
    }

    public function resource_oauth_metadata($request = null): WP_REST_Response
    {
        unset($request);

        return $this->oauth_metadata(Helper::resource_path());
    }

    public function protected_resource_metadata(string $requested_resource_path = ''): WP_REST_Response
    {
        $resource = $this->resource_issuer();
        $resource_path = Helper::resource_path();

        if ('' !== $requested_resource_path && $resource_path !== untrailingslashit($requested_resource_path)) {
            return new WP_REST_Response(['error' => 'invalid_target'], 404);
        }

        return new WP_REST_Response([
            'resource' => $resource,
            'authorization_servers' => [$resource],
            'scopes_supported' => self::SUPPORTED_SCOPES,
            'resource_documentation' => 'https://github.com/mehul0810/quark',
            'token_endpoint_auth_methods_supported' => [ClientCredentials::AUTH_CLIENT_SECRET_POST],
        ], 200);
    }

    public function authorize(WP_REST_Request $request): WP_REST_Response
    {
        $params = $this->scalar_params($request->get_params());

        if (! is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($this->authorization_request_url($params)), 302, 'Quark OAuth');
            exit;
        }

        $context = (new OAuthWebFlow())->build_authorize_context($params);
        if (! $context['valid']) {
            wp_die('Invalid OAuth authorization request.', 400);
        }

        wp_safe_redirect($this->consent_url($context), 302, 'Quark OAuth');
        exit;
    }

    public function token(WP_REST_Request $request): WP_REST_Response
    {
        $grant_type = (string) $request->get_param('grant_type');
        $client_id = (string) $request->get_param('client_id');
        $credentials = new ClientCredentials();
        if ('' === $client_id) {
            $client_id = $credentials->client_id_from_authorization_header((string) $request->get_header('authorization'));
        }

        $rate_limit_key = '' !== $client_id ? $client_id : $this->rate_limit_identity($request);
        if (! $this->check_rate_limit($rate_limit_key, 60, MINUTE_IN_SECONDS)) {
            return $this->oauth_error(
                'slow_down',
                'Too many authentication attempts. Try again later.',
                429
            );
        }

        if (! $credentials->verify_token_endpoint_auth(
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
            if ($this->resource_issuer() !== $resource) {
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
            return $this->token_response(
                $tokens ?: $this->oauth_error_payload('invalid_grant', 'Authorization code exchange failed.'),
                $tokens ? 200 : 400
            );
        }

        if ('refresh_token' === $grant_type) {
            $resource = $this->resource_from_request($request);
            if ($this->resource_issuer() !== $resource) {
                return $this->oauth_error(
                    'invalid_target',
                    'The requested resource does not match this MCP server.',
                    400
                );
            }

            $tokens = $access->refresh((string) $request->get_param('refresh_token'), $resource, $client_id);
            return $this->token_response(
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

    public function authorization_endpoint(): string
    {
        return rest_url('quark/v1/oauth/authorize');
    }

    public function token_endpoint(): string
    {
        return rest_url('quark/v1/oauth/token');
    }

    public function resource_issuer(): string
    {
        return Helper::mcp_resource();
    }

    public function resource_authorization_metadata_url(): string
    {
        return Helper::authorization_metadata_url();
    }

    public function protected_resource_metadata_url(?string $resource = null): string
    {
        return Helper::protected_resource_metadata_url($resource);
    }

    private function issuer(): string
    {
        return untrailingslashit(home_url('/'));
    }

    private function issuer_for_path(string $requested_issuer_path): string
    {
        $requested_issuer_path = untrailingslashit($requested_issuer_path);

        return Helper::resource_path() === $requested_issuer_path ? $this->resource_issuer() : $this->issuer();
    }

    private function scalar_params(array $params): array
    {
        return array_map(
            static fn ($value): string => is_scalar($value) ? (string) $value : '',
            $params
        );
    }

    private function authorization_request_url(array $params): string
    {
        return add_query_arg($this->scalar_params($params), $this->authorization_endpoint());
    }

    private function consent_url(array $context): string
    {
        return add_query_arg([
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
    }

    private function resource_from_request(WP_REST_Request $request): string
    {
        $resource = (string) $request->get_param('resource');
        if ('' === $resource) {
            $resource = (string) $request->get_param('audience');
        }

        return '' !== $resource ? Helper::normalize_resource($resource) : $this->resource_issuer();
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
        $response = new WP_REST_Response(
            $this->oauth_error_payload($error, $description),
            $status
        );
        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');

        return $response;
    }

    private function token_response(array $payload, int $status): WP_REST_Response
    {
        $response = new WP_REST_Response($payload, $status);
        $response->header('Cache-Control', 'no-store');
        $response->header('Pragma', 'no-cache');

        return $response;
    }

    private function oauth_error_payload(string $error, string $description): array
    {
        return [
            'error' => $error,
            'error_description' => $description,
        ];
    }
}

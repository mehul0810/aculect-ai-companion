<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT;

final class Helper
{
    public const REST_NAMESPACE = 'quark/v1';
    public const MCP_ROUTE = 'quark/v1/mcp';
    public const AUTHORIZATION_METADATA = 'oauth-authorization-server';
    public const PROTECTED_RESOURCE_METADATA = 'oauth-protected-resource';

    public static function mcp_resource(): string
    {
        return self::normalize_resource(rest_url(self::MCP_ROUTE));
    }

    public static function resource_path(): string
    {
        return untrailingslashit((string) wp_parse_url(self::mcp_resource(), PHP_URL_PATH));
    }

    public static function authorization_metadata_url(): string
    {
        return home_url('/.well-known/' . self::AUTHORIZATION_METADATA . self::resource_path());
    }

    public static function protected_resource_metadata_url(?string $resource = null): string
    {
        $resource = self::normalize_resource($resource ?: self::mcp_resource());
        $parts = wp_parse_url($resource);

        if (! is_array($parts)) {
            return home_url('/.well-known/' . self::PROTECTED_RESOURCE_METADATA);
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = untrailingslashit((string) ($parts['path'] ?? ''));

        return $scheme . '://' . $host . $port . '/.well-known/' . self::PROTECTED_RESOURCE_METADATA . $path;
    }

    public static function normalize_resource(string $resource): string
    {
        return untrailingslashit(esc_url_raw($resource));
    }

    public static function is_chatgpt_redirect(string $uri): bool
    {
        $parts = wp_parse_url($uri);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        return 'https' === $scheme
            && 'chatgpt.com' === $host
            && (
                str_starts_with($path, '/connector/oauth/')
                || '/connector_platform_oauth_redirect' === $path
            );
    }
}

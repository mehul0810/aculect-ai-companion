<?php

declare(strict_types=1);

namespace Quark;

use Quark\Rest\McpController;
use Quark\Rest\ContentController;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate(): void
    {
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function boot(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        (new McpController())->register_routes();
        (new ContentController())->register_routes();
    }
}

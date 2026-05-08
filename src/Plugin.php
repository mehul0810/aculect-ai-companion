<?php

declare(strict_types=1);

namespace Quark;

use Quark\Connectors\ChatGPT\Admin\SettingsPage;
use Quark\Connectors\ChatGPT\Auth\OAuthWebFlow;
use Quark\Connectors\ChatGPT\Rest\ContentController;
use Quark\Connectors\ChatGPT\Rest\McpController;
use Quark\Connectors\ChatGPT\Rest\OAuthController;

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
        (new OAuthController())->add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }

    public function boot(): void
    {
        add_action('init', [$this, 'register_well_known_routes']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'render_well_known_metadata']);
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_menu', [$this, 'register_admin']);
        add_action('admin_post_quark_oauth_authorize', [$this, 'handle_oauth_authorize']);
        add_action('admin_post_quark_mark_connected', [$this, 'handle_mark_connected']);
        add_action('admin_post_quark_revoke_connection', [$this, 'handle_revoke_connection']);
        add_action('admin_post_quark_save_advanced', [$this, 'handle_save_advanced']);
        add_action('admin_post_quark_save_chatgpt_oauth', [$this, 'handle_save_chatgpt_oauth']);
    }

    public function register_routes(): void
    {
        (new OAuthController())->register_routes();
        (new McpController())->register_routes();
        (new ContentController())->register_routes();
    }

    public function register_well_known_routes(): void
    {
        (new OAuthController())->add_rewrite_rules();
    }

    public function register_query_vars(array $vars): array
    {
        $vars[] = 'quark_well_known';
        $vars[] = 'quark_well_known_resource_path';
        return $vars;
    }

    public function render_well_known_metadata(): void
    {
        (new OAuthController())->render_well_known_metadata();
    }

    public function register_admin(): void
    {
        (new SettingsPage())->register();
    }

    public function handle_oauth_authorize(): void
    {
        (new OAuthWebFlow())->handle_authorize();
    }

    public function handle_mark_connected(): void
    {
        (new SettingsPage())->handle_mark_connected();
    }

    public function handle_revoke_connection(): void
    {
        (new SettingsPage())->handle_revoke_connection();
    }

    public function handle_save_advanced(): void
    {
        (new SettingsPage())->handle_save_advanced();
    }

    public function handle_save_chatgpt_oauth(): void
    {
        (new SettingsPage())->handle_save_chatgpt_oauth();
    }
}

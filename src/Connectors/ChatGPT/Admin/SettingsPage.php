<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\Admin;

use Quark\Auth\Access;

final class SettingsPage
{
    private const OPTION_CONNECTION_STATE = 'quark_chatgpt_connection_state';
    private const OPTION_REMOVE_DATA_ON_UNINSTALL = 'quark_remove_data_on_uninstall';
    private const ASSET_HANDLE = 'quark-settings-app';
    private const STYLE_HANDLE = 'quark-settings-style';

    public function register(): void
    {
        add_options_page('Quark Settings', 'Quark', 'manage_options', 'quark', [$this, 'render']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function handle_mark_connected(): void
    {
        $this->guard_action('quark_mark_connected');
        update_option(self::OPTION_CONNECTION_STATE, ['active' => true, 'updated_at' => time()], false);
        wp_safe_redirect(add_query_arg(['page' => 'quark', 'provider' => 'chatgpt', 'connected' => '1'], admin_url('options-general.php')));
        exit;
    }

    public function handle_revoke_connection(): void
    {
        $this->guard_action('quark_revoke_connection');
        (new Access())->revoke_all_tokens();
        update_option(self::OPTION_CONNECTION_STATE, ['active' => false, 'updated_at' => time()], false);
        wp_safe_redirect(add_query_arg(['page' => 'quark', 'provider' => 'chatgpt', 'revoked' => '1'], admin_url('options-general.php')));
        exit;
    }

    public function handle_save_advanced(): void
    {
        $this->guard_action('quark_save_advanced');
        $enabled = isset($_POST['remove_data_on_uninstall']) && '1' === (string) $_POST['remove_data_on_uninstall'];
        update_option(self::OPTION_REMOVE_DATA_ON_UNINSTALL, $enabled ? '1' : '0', false);
        wp_safe_redirect(add_query_arg(['page' => 'quark', 'advanced_saved' => '1'], admin_url('options-general.php')));
        exit;
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $view = isset($_GET['view']) ? sanitize_key((string) $_GET['view']) : 'settings';
        echo '<div class="wrap">';

        if ('oauth-consent' === $view) {
            $this->render_oauth_consent();
        } else {
            $this->render_settings();
        }

        echo '</div>';
    }

    private function render_settings(): void
    {
        echo '<div id="quark-settings-app-root" class="quark-settings-app-root"></div>';
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if ('settings_page_quark' !== $hook_suffix) {
            return;
        }

        $asset_path = QUARK_PLUGIN_DIR . 'build/index.asset.php';
        $script_url = QUARK_PLUGIN_URL . 'build/index.js';
        $asset = file_exists($asset_path)
            ? require $asset_path
            : ['dependencies' => ['wp-element', 'wp-components'], 'version' => QUARK_VERSION];

        wp_register_script(
            self::ASSET_HANDLE,
            $script_url,
            $asset['dependencies'],
            (string) $asset['version'],
            true
        );
        wp_enqueue_script(self::ASSET_HANDLE);
        wp_enqueue_style('wp-components');
        $style_path = QUARK_PLUGIN_DIR . 'build/style-index.css';
        if (file_exists($style_path)) {
            wp_enqueue_style(
                self::STYLE_HANDLE,
                QUARK_PLUGIN_URL . 'build/style-index.css',
                [],
                (string) $asset['version']
            );
        }

        wp_localize_script(self::ASSET_HANDLE, 'quarkSettingsData', [
            'version' => QUARK_VERSION,
            'isConnected' => $this->is_chatgpt_connected(),
            'status' => isset($_GET['connected']) ? 'connected' : (isset($_GET['revoked']) ? 'revoked' : ''),
            'advancedSaved' => isset($_GET['advanced_saved']) ? '1' : '0',
            'removeDataOnUninstall' => $this->remove_data_on_uninstall_enabled(),
            'createAppUrl' => 'https://chatgpt.com/apps#settings/Connectors',
            'configFields' => [
                [
                    'key' => 'app_name',
                    'label' => 'App Name',
                    'value' => 'Quark',
                ],
                [
                    'key' => 'mcp_server_url',
                    'label' => 'MCP Server URL',
                    'value' => rest_url('quark/v1/mcp'),
                ],
                [
                    'key' => 'oauth_authorization_endpoint',
                    'label' => 'OAuth Authorization Endpoint',
                    'value' => rest_url('quark/v1/oauth/authorize'),
                ],
                [
                    'key' => 'oauth_token_endpoint',
                    'label' => 'OAuth Token Endpoint',
                    'value' => rest_url('quark/v1/oauth/token'),
                ],
                [
                    'key' => 'oauth_dynamic_client_registration_endpoint',
                    'label' => 'OAuth Dynamic Client Registration Endpoint',
                    'value' => rest_url('quark/v1/oauth/register'),
                ],
                [
                    'key' => 'oauth_metadata_url',
                    'label' => 'OAuth Authorization Server Metadata URL',
                    'value' => rest_url('quark/v1/.well-known/oauth-authorization-server'),
                ],
                [
                    'key' => 'oauth_protected_resource_metadata_url',
                    'label' => 'OAuth Protected Resource Metadata URL',
                    'value' => rest_url('quark/v1/.well-known/oauth-protected-resource'),
                ],
                [
                    'key' => 'resource',
                    'label' => 'Resource',
                    'value' => rest_url('quark/v1/mcp'),
                ],
                [
                    'key' => 'openid_metadata_url',
                    'label' => 'OpenID Configuration URL',
                    'value' => rest_url('quark/v1/.well-known/openid-configuration'),
                ],
                [
                    'key' => 'pkce_method',
                    'label' => 'PKCE Code Challenge Method',
                    'value' => 'S256',
                ],
                [
                    'key' => 'scopes',
                    'label' => 'Scopes',
                    'value' => 'content:read content:draft',
                ],
            ],
            'copyAll' => wp_json_encode([
                'name' => 'Quark',
                'mcp_server_url' => rest_url('quark/v1/mcp'),
                'oauth_authorization_endpoint' => rest_url('quark/v1/oauth/authorize'),
                'oauth_token_endpoint' => rest_url('quark/v1/oauth/token'),
                'oauth_dynamic_client_registration_endpoint' => rest_url('quark/v1/oauth/register'),
                'oauth_metadata_url' => rest_url('quark/v1/.well-known/oauth-authorization-server'),
                'oauth_protected_resource_metadata_url' => rest_url('quark/v1/.well-known/oauth-protected-resource'),
                'resource' => rest_url('quark/v1/mcp'),
                'openid_metadata_url' => rest_url('quark/v1/.well-known/openid-configuration'),
                'pkce_method' => 'S256',
                'scopes' => 'content:read content:draft',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'actions' => [
                'adminPostUrl' => admin_url('admin-post.php'),
                'markConnectedAction' => 'quark_mark_connected',
                'revokeAction' => 'quark_revoke_connection',
                'saveAdvancedAction' => 'quark_save_advanced',
                'markConnectedNonce' => wp_create_nonce('quark_mark_connected'),
                'revokeNonce' => wp_create_nonce('quark_revoke_connection'),
                'saveAdvancedNonce' => wp_create_nonce('quark_save_advanced'),
            ],
            'changelog' => $this->load_changelog(),
        ]);
    }

    private function load_changelog(): array
    {
        $file = QUARK_PLUGIN_DIR . 'changelog.json';
        if (! file_exists($file)) {
            return [];
        }

        $json = file_get_contents($file);
        if (false === $json || '' === $json) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function remove_data_on_uninstall_enabled(): bool
    {
        return '1' === (string) get_option(self::OPTION_REMOVE_DATA_ON_UNINSTALL, '0');
    }

    private function is_chatgpt_connected(): bool
    {
        $state = get_option(self::OPTION_CONNECTION_STATE, ['active' => false]);
        $marked_active = is_array($state) && ! empty($state['active']);
        return $marked_active || (new Access())->has_active_tokens();
    }

    private function render_oauth_consent(): void
    {
        $client_id = sanitize_text_field((string) ($_GET['client_id'] ?? ''));
        $redirect_uri = esc_url_raw((string) ($_GET['redirect_uri'] ?? ''));
        $state = sanitize_text_field((string) ($_GET['state'] ?? ''));
        $scope = sanitize_text_field((string) ($_GET['scope'] ?? ''));
        $code_challenge = sanitize_text_field((string) ($_GET['code_challenge'] ?? ''));
        $code_challenge_method = sanitize_text_field((string) ($_GET['code_challenge_method'] ?? 'S256'));
        $resource = esc_url_raw((string) ($_GET['resource'] ?? rest_url('quark/v1/mcp')));

        echo '<h1>Quark OAuth Consent</h1>';
        echo '<p>Approve this request to connect ChatGPT to your WordPress account.</p>';
        echo '<table class="widefat striped" style="max-width:1100px;margin-bottom:20px"><tbody>';
        echo '<tr><td><strong>Client ID</strong></td><td><code>' . esc_html($client_id) . '</code></td></tr>';
        echo '<tr><td><strong>Redirect URI</strong></td><td><code>' . esc_html($redirect_uri) . '</code></td></tr>';
        echo '<tr><td><strong>Requested Scope</strong></td><td><code>' . esc_html($scope) . '</code></td></tr>';
        echo '<tr><td><strong>Resource</strong></td><td><code>' . esc_html($resource) . '</code></td></tr>';
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('quark_oauth_authorize');
        echo '<input type="hidden" name="action" value="quark_oauth_authorize" />';
        echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '" />';
        echo '<input type="hidden" name="redirect_uri" value="' . esc_attr($redirect_uri) . '" />';
        echo '<input type="hidden" name="state" value="' . esc_attr($state) . '" />';
        echo '<input type="hidden" name="scope" value="' . esc_attr($scope) . '" />';
        echo '<input type="hidden" name="code_challenge" value="' . esc_attr($code_challenge) . '" />';
        echo '<input type="hidden" name="code_challenge_method" value="' . esc_attr($code_challenge_method) . '" />';
        echo '<input type="hidden" name="resource" value="' . esc_attr($resource) . '" />';
        submit_button('Approve', 'primary', 'decision', false, ['value' => 'approve']);
        echo ' ';
        submit_button('Deny', 'secondary', 'decision', false, ['value' => 'deny']);
        echo '</form>';
    }

    private function guard_action(string $nonce_action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer($nonce_action);
    }

}

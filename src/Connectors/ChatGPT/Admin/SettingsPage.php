<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\Admin;

use Quark\Auth\Access;
use Quark\Connectors\ChatGPT\Auth\OAuthClientRegistry;

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

    public function handle_save_chatgpt_oauth(): void
    {
        $this->guard_action('quark_save_chatgpt_oauth');
        (new OAuthClientRegistry())->regenerate_credentials();
        (new Access())->revoke_all_tokens();
        update_option(self::OPTION_CONNECTION_STATE, ['active' => false, 'updated_at' => time()], false);
        wp_safe_redirect(add_query_arg(['page' => 'quark', 'oauth_saved' => '1'], admin_url('options-general.php')));
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

    public function enqueue_assets($hook_suffix): void
    {
        if ('settings_page_quark' !== (string) $hook_suffix) {
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
            'oauthSaved' => isset($_GET['oauth_saved']) ? '1' : '0',
            'removeDataOnUninstall' => $this->remove_data_on_uninstall_enabled(),
            'createAppUrl' => 'https://chatgpt.com/apps#settings/Connectors',
            'chatgptFormSections' => $this->chatgpt_form_sections(),
            'copyAll' => wp_json_encode($this->chatgpt_copy_fields(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'actions' => [
                'adminPostUrl' => admin_url('admin-post.php'),
                'markConnectedAction' => 'quark_mark_connected',
                'revokeAction' => 'quark_revoke_connection',
                'saveAdvancedAction' => 'quark_save_advanced',
                'saveOauthAction' => 'quark_save_chatgpt_oauth',
                'markConnectedNonce' => wp_create_nonce('quark_mark_connected'),
                'revokeNonce' => wp_create_nonce('quark_revoke_connection'),
                'saveAdvancedNonce' => wp_create_nonce('quark_save_advanced'),
                'saveOauthNonce' => wp_create_nonce('quark_save_chatgpt_oauth'),
            ],
            'changelog' => $this->load_changelog(),
        ]);
    }

    private function chatgpt_form_sections(): array
    {
        $settings = (new OAuthClientRegistry())->settings();
        $oauth = new \Quark\Connectors\ChatGPT\Rest\OAuthController();
        $sections = [
            [
                'key' => 'basic',
                'title' => 'Basic Connector Fields',
                'description' => 'Paste these into the main ChatGPT create-app form.',
                'fields' => [
                    [
                        'key' => 'app_name',
                        'label' => 'Name',
                        'value' => 'Quark',
                    ],
                    [
                        'key' => 'mcp_server_url',
                        'label' => 'MCP Server URL',
                        'value' => rest_url('quark/v1/mcp'),
                    ],
                    [
                        'key' => 'authentication',
                        'label' => 'Authentication',
                        'value' => 'OAuth',
                    ],
                    [
                        'key' => 'oauth_client_type',
                        'label' => 'OAuth Client Type',
                        'value' => 'User-Defined OAuth Client',
                    ],
                ],
            ],
            [
                'key' => 'advanced_oauth',
                'title' => 'Advanced OAuth Settings',
                'description' => 'Use these values in ChatGPT Advanced OAuth settings when fields are shown manually.',
                'fields' => [
                    [
                        'key' => 'authorization_server_base',
                        'label' => 'Authorization Server Base',
                        'value' => untrailingslashit(home_url('/')),
                    ],
                    [
                        'key' => 'resource',
                        'label' => 'Resource',
                        'value' => rest_url('quark/v1/mcp'),
                    ],
                    [
                        'key' => 'oauth_authorization_endpoint',
                        'label' => 'Authorization Endpoint',
                        'value' => $oauth->authorization_endpoint(),
                    ],
                    [
                        'key' => 'oauth_token_endpoint',
                        'label' => 'Token Endpoint',
                        'value' => rest_url('quark/v1/oauth/token'),
                    ],
                    [
                        'key' => 'token_endpoint_auth_method',
                        'label' => 'Token Endpoint Auth Method',
                        'value' => OAuthClientRegistry::AUTH_CLIENT_SECRET_POST,
                    ],
                    [
                        'key' => 'oauth_metadata_url',
                        'label' => 'Authorization Server Metadata URL',
                        'value' => home_url('/.well-known/oauth-authorization-server'),
                    ],
                    [
                        'key' => 'oauth_protected_resource_metadata_url',
                        'label' => 'Protected Resource Metadata URL',
                        'value' => $oauth->protected_resource_metadata_url(),
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
            ],
            [
                'key' => 'user_defined_client',
                'title' => 'User-Defined OAuth Client',
                'description' => 'Quark generates these credentials automatically. Copy them into ChatGPT exactly once when creating the app.',
                'fields' => [
                    [
                        'key' => 'client_id',
                        'label' => 'Client ID',
                        'value' => (string) $settings['manual_client_id'],
                    ],
                    [
                        'key' => 'client_secret',
                        'label' => 'Client Secret',
                        'value' => (string) $settings['manual_client_secret'],
                        'displayType' => 'password',
                    ],
                ],
            ],
        ];

        return $sections;
    }

    private function chatgpt_copy_fields(): array
    {
        $fields = [];

        foreach ($this->chatgpt_form_sections() as $section) {
            foreach ((array) ($section['fields'] ?? []) as $field) {
                $fields[(string) $field['key']] = (string) ($field['value'] ?? '');
            }
        }

        return $fields;
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

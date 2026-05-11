<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\Admin;

use Quark\Auth\Access;
use Quark\Connectors\ChatGPT\Helper;
use Quark\Connectors\ChatGPT\UserDefined\ClientCredentials;
use Quark\Connectors\ChatGPT\UserDefined\OAuthController;

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

    public function handle_regenerate_chatgpt_credentials(): void
    {
        $this->guard_action('quark_regenerate_chatgpt_credentials');
        (new ClientCredentials())->regenerate_credentials();
        (new Access())->revoke_all_tokens();
        update_option(self::OPTION_CONNECTION_STATE, ['active' => false, 'updated_at' => time()], false);
        wp_safe_redirect(add_query_arg(['page' => 'quark', 'credentials_regenerated' => '1'], admin_url('options-general.php')));
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
            'credentialsRegenerated' => isset($_GET['credentials_regenerated']) ? '1' : '0',
            'removeDataOnUninstall' => $this->remove_data_on_uninstall_enabled(),
            'createAppUrl' => 'https://chatgpt.com/apps#settings/Connectors',
            'chatgptFormSections' => $this->chatgpt_form_sections(),
            'copyAll' => wp_json_encode($this->chatgpt_copy_fields(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'actions' => [
                'adminPostUrl' => admin_url('admin-post.php'),
                'markConnectedAction' => 'quark_mark_connected',
                'revokeAction' => 'quark_revoke_connection',
                'saveAdvancedAction' => 'quark_save_advanced',
                'regenerateCredentialsAction' => 'quark_regenerate_chatgpt_credentials',
                'markConnectedNonce' => wp_create_nonce('quark_mark_connected'),
                'revokeNonce' => wp_create_nonce('quark_revoke_connection'),
                'saveAdvancedNonce' => wp_create_nonce('quark_save_advanced'),
                'regenerateCredentialsNonce' => wp_create_nonce('quark_regenerate_chatgpt_credentials'),
            ],
            'changelog' => $this->load_changelog(),
        ]);
    }

    private function render_settings(): void
    {
        echo '<div id="quark-settings-app-root" class="quark-settings-app-root"></div>';
    }

    private function chatgpt_form_sections(): array
    {
        $credentials = (new ClientCredentials())->settings();
        $oauth = new OAuthController();
        $site_name = trim((string) get_bloginfo('name'));
        $app_name = '' === $site_name ? 'Quark' : 'Quark - ' . $site_name;

        return [
            [
                'key' => 'app_setup',
                'title' => 'App Setup',
                'description' => 'Paste these values into the main ChatGPT create-app form.',
                'fields' => [
                    [
                        'key' => 'app_name',
                        'label' => 'Name',
                        'value' => $app_name,
                    ],
                    [
                        'key' => 'mcp_server_url',
                        'label' => 'MCP Server URL',
                        'value' => Helper::mcp_resource(),
                    ],
                    [
                        'key' => 'mcp_protocol',
                        'label' => 'MCP Protocol',
                        'value' => 'Streamable HTTP',
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
                'key' => 'oauth_client',
                'title' => 'OAuth Client',
                'description' => 'Quark generates one static OAuth client for ChatGPT. Regenerate only when you want to recreate the ChatGPT app connection.',
                'fields' => [
                    [
                        'key' => 'client_id',
                        'label' => 'Client ID',
                        'value' => (string) $credentials['client_id'],
                    ],
                    [
                        'key' => 'client_secret',
                        'label' => 'Client Secret',
                        'value' => (string) $credentials['client_secret'],
                        'displayType' => 'password',
                    ],
                    [
                        'key' => 'token_endpoint_auth_method',
                        'label' => 'Token Endpoint Auth Method',
                        'value' => ClientCredentials::AUTH_CLIENT_SECRET_POST,
                    ],
                ],
            ],
            [
                'key' => 'oauth_endpoints',
                'title' => 'OAuth Endpoints',
                'description' => 'Use these values in ChatGPT advanced OAuth settings when it does not auto-discover them from the MCP server.',
                'fields' => [
                    [
                        'key' => 'authorization_server_base',
                        'label' => 'Authorization Server Base',
                        'value' => $oauth->resource_issuer(),
                    ],
                    [
                        'key' => 'resource',
                        'label' => 'Resource',
                        'value' => $oauth->resource_issuer(),
                    ],
                    [
                        'key' => 'oauth_authorization_endpoint',
                        'label' => 'Authorization Endpoint',
                        'value' => $oauth->authorization_endpoint(),
                    ],
                    [
                        'key' => 'oauth_token_endpoint',
                        'label' => 'Token Endpoint',
                        'value' => $oauth->token_endpoint(),
                    ],
                    [
                        'key' => 'pkce_method',
                        'label' => 'PKCE Code Challenge Method',
                        'value' => 'S256',
                    ],
                    [
                        'key' => 'scopes',
                        'label' => 'Scopes',
                        'value' => ClientCredentials::SCOPES,
                    ],
                ],
            ],
        ];
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
        $resource = esc_url_raw((string) ($_GET['resource'] ?? Helper::mcp_resource()));
        $issuer = esc_url_raw(rawurldecode((string) ($_GET['quark_oauth_issuer'] ?? Helper::mcp_resource())));

        echo '<h1 class="quark-oauth-consent-title">Quark OAuth Consent</h1>';
        echo '<p class="quark-oauth-consent-copy">Approve this request to connect ChatGPT to your WordPress account.</p>';
        echo '<table class="widefat striped quark-oauth-consent-table"><tbody>';
        echo '<tr><td><strong>Client ID</strong></td><td><code>' . esc_html($client_id) . '</code></td></tr>';
        echo '<tr><td><strong>Redirect URI</strong></td><td><code>' . esc_html($redirect_uri) . '</code></td></tr>';
        echo '<tr><td><strong>Requested Scope</strong></td><td><code>' . esc_html($scope) . '</code></td></tr>';
        echo '<tr><td><strong>Resource</strong></td><td><code>' . esc_html($resource) . '</code></td></tr>';
        echo '<tr><td><strong>Issuer</strong></td><td><code>' . esc_html($issuer) . '</code></td></tr>';
        echo '</tbody></table>';

        echo '<form class="quark-oauth-consent-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('quark_oauth_authorize');
        echo '<input type="hidden" name="action" value="quark_oauth_authorize" />';
        echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '" />';
        echo '<input type="hidden" name="redirect_uri" value="' . esc_attr($redirect_uri) . '" />';
        echo '<input type="hidden" name="state" value="' . esc_attr($state) . '" />';
        echo '<input type="hidden" name="scope" value="' . esc_attr($scope) . '" />';
        echo '<input type="hidden" name="code_challenge" value="' . esc_attr($code_challenge) . '" />';
        echo '<input type="hidden" name="code_challenge_method" value="' . esc_attr($code_challenge_method) . '" />';
        echo '<input type="hidden" name="resource" value="' . esc_attr($resource) . '" />';
        echo '<input type="hidden" name="quark_oauth_issuer" value="' . esc_attr($issuer) . '" />';
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

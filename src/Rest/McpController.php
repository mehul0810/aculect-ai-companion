<?php

declare(strict_types=1);

namespace Quark\Rest;

use Quark\Auth\Access;
use WP_REST_Server;
use WP_REST_Request;

final class McpController
{
    public function register_routes(): void
    {
        register_rest_route('quark/v1', '/mcp', [
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        $method = $body['method'] ?? '';

        switch ($method) {
            case 'tools/list':
                return $this->list_tools();

            case 'tools/call':
                $user_id = $this->authenticate($request);
                if ($user_id < 1) {
                    return ['error' => 'Unauthorized'];
                }
                wp_set_current_user($user_id);
                return $this->call_tool($body['params'] ?? []);
        }

        return [
            'error' => 'Invalid MCP method'
        ];
    }

    private function list_tools(): array
    {
        return [
            'tools' => [
                [
                    'name' => 'site.list_post_types',
                    'description' => 'List readable post types'
                ],
                [
                    'name' => 'content.list_items',
                    'description' => 'List content items with pagination'
                ],
                [
                    'name' => 'content.get_item',
                    'description' => 'Read one content item by ID'
                ],
                ['name' => 'content.create_draft', 'description' => 'Create a draft content item'],
                ['name' => 'taxonomy.list_taxonomies', 'description' => 'List taxonomies'],
                ['name' => 'taxonomy.list_terms', 'description' => 'List terms in a taxonomy'],
                ['name' => 'media.list_items', 'description' => 'List media items'],
                ['name' => 'site.get_settings', 'description' => 'Read safe site settings'],
            ]
        ];
    }

    private function call_tool(array $params): array
    {
        $tool = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        switch ($tool) {
            case 'site.list_post_types': return (new ContentController())->list_post_types();
            case 'content.list_items': return (new ContentController())->list_items($args);
            case 'content.get_item': return (new ContentController())->get_item($args);
            case 'content.create_draft': return (new ContentController())->create_draft($args);
            case 'taxonomy.list_taxonomies': return (new ContentController())->list_taxonomies();
            case 'taxonomy.list_terms': return (new ContentController())->list_terms($args);
            case 'media.list_items': return (new ContentController())->list_media($args);
            case 'site.get_settings': return (new ContentController())->get_settings();
        }

        return [
            'error' => 'Unknown tool'
        ];
    }

    private function authenticate(WP_REST_Request $request): int
    {
        $header = (string) $request->get_header('authorization');
        if (! str_starts_with(strtolower($header), 'bearer ')) {
            return 0;
        }
        $token = trim(substr($header, 7));
        if ('' === $token) {
            return 0;
        }
        return (new Access())->user_from_bearer($token);
    }
}

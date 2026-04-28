<?php

declare(strict_types=1);

namespace Quark\Rest;

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
                    'name' => 'content.create_post',
                    'description' => 'Create a new WordPress post'
                ],
                [
                    'name' => 'content.update_post',
                    'description' => 'Update an existing post'
                ],
                [
                    'name' => 'content.audit_posts',
                    'description' => 'Audit existing content'
                ]
            ]
        ];
    }

    private function call_tool(array $params): array
    {
        $tool = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        switch ($tool) {
            case 'content.create_post':
                return (new ContentController())->create_post($args);

            case 'content.update_post':
                return (new ContentController())->update_post($args);

            case 'content.audit_posts':
                return (new ContentController())->audit_posts();
        }

        return [
            'error' => 'Unknown tool'
        ];
    }
}

<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\Rest;

use Quark\Auth\Access;
use WP_REST_Server;
use WP_REST_Request;

final class McpController
{
    public function register_routes(): void
    {
        register_rest_route('quark/v1', '/mcp', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'describe'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('quark/v1', '/mcp', [
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle_rpc'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function describe(): array
    {
        return [
            'name' => 'Quark MCP',
            'protocol' => 'mcp',
            'version' => QUARK_VERSION,
            'transport' => 'http-jsonrpc',
            'auth' => 'oauth2.1',
            'endpoints' => [
                'rpc' => rest_url('quark/v1/mcp'),
            ],
        ];
    }

    public function handle_rpc(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();
        if (! is_array($body)) {
            return $this->rpc_error(null, -32600, 'Invalid Request');
        }

        $id = $body['id'] ?? null;
        $method = $body['method'] ?? '';

        switch ($method) {
            case 'initialize':
                return $this->rpc_result($id, [
                    'protocolVersion' => '2024-11-05',
                    'serverInfo' => [
                        'name' => 'Quark MCP',
                        'version' => QUARK_VERSION,
                    ],
                    'capabilities' => [
                        'tools' => new \stdClass(),
                    ],
                ]);

            case 'tools/list':
                return $this->rpc_result($id, $this->list_tools());

            case 'tools/call':
                $user_id = $this->authenticate($request);
                if ($user_id < 1) {
                    return $this->rpc_error($id, -32001, 'Unauthorized', [
                        '_meta' => [
                            'mcp/www_authenticate' => 'Bearer resource_metadata="' . rest_url('quark/v1/.well-known/oauth-protected-resource') . '"',
                        ],
                    ]);
                }
                wp_set_current_user($user_id);
                return $this->rpc_result($id, [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => wp_json_encode($this->call_tool($body['params'] ?? [])),
                        ],
                    ],
                ]);
        }

        return $this->rpc_error($id, -32601, 'Method not found');
    }

    private function list_tools(): array
    {
        return [
            'tools' => [
                [
                    'name' => 'site.list_post_types',
                    'description' => 'List readable post types',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                    'annotations' => ['readOnlyHint' => true],
                ],
                [
                    'name' => 'content.list_items',
                    'description' => 'List content items with pagination',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'post_type' => ['type' => 'string'],
                            'status' => ['type' => ['string', 'array']],
                            'page' => ['type' => 'integer'],
                            'per_page' => ['type' => 'integer'],
                        ],
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                [
                    'name' => 'content.get_item',
                    'description' => 'Read one content item by ID',
                    'inputSchema' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                [
                    'name' => 'content.create_draft',
                    'description' => 'Create a draft content item',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'post_type' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                        ],
                    ],
                    'annotations' => ['readOnlyHint' => false],
                ],
                [
                    'name' => 'taxonomy.list_taxonomies',
                    'description' => 'List taxonomies',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                    'annotations' => ['readOnlyHint' => true],
                ],
                [
                    'name' => 'taxonomy.list_terms',
                    'description' => 'List terms in a taxonomy',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => ['taxonomy' => ['type' => 'string']],
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                [
                    'name' => 'media.list_items',
                    'description' => 'List media items',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'page' => ['type' => 'integer'],
                            'per_page' => ['type' => 'integer'],
                        ],
                    ],
                    'annotations' => ['readOnlyHint' => true],
                ],
                [
                    'name' => 'site.get_settings',
                    'description' => 'Read safe site settings',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                    'annotations' => ['readOnlyHint' => true],
                ],
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

    private function rpc_result($id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    private function rpc_error($id, int $code, string $message, array $data = []): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ([] !== $data) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }
}

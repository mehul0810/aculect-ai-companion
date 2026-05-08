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
        $resource_metadata_url = home_url('/.well-known/oauth-protected-resource');
        $mcp_url = rest_url('quark/v1/mcp');

        return [
            'name' => 'Quark MCP',
            'protocol' => 'mcp',
            'version' => QUARK_VERSION,
            'transport' => 'http-jsonrpc',
            'auth' => 'oauth2.1',
            'authentication' => [
                'type' => 'oauth2.1',
                'resource' => $mcp_url,
                'resource_metadata_url' => $resource_metadata_url,
            ],
            'endpoints' => [
                'rpc' => $mcp_url,
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
                $resource_metadata_url = home_url('/.well-known/oauth-protected-resource');
                $mcp_url = rest_url('quark/v1/mcp');
                return $this->rpc_result($id, [
                    'protocolVersion' => '2024-11-05',
                    'serverInfo' => [
                        'name' => 'Quark MCP',
                        'version' => QUARK_VERSION,
                    ],
                    'capabilities' => [
                        'tools' => new \stdClass(),
                        'authentication' => [
                            'type' => 'oauth2.1',
                            'resource' => $mcp_url,
                            'resource_metadata_url' => $resource_metadata_url,
                        ],
                    ],
                ]);

            case 'tools/list':
                return $this->rpc_result($id, $this->list_tools());

            case 'tools/call':
                $tool = (string) (($body['params']['name'] ?? ''));
                if (! $this->is_known_tool($tool)) {
                    return $this->tool_error_result($id, 'Unknown tool.');
                }

                $auth = $this->authenticate($request, $tool);
                if (empty($auth['user_id'])) {
                    return $this->auth_required_result($id);
                }
                wp_set_current_user((int) $auth['user_id']);
                return $this->rpc_result($id, [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => (string) wp_json_encode($this->call_tool($body['params'] ?? [])),
                        ],
                    ],
                ]);
        }

        return $this->rpc_error($id, -32601, 'Method not found');
    }

    private function list_tools(): array
    {
        $read_security = $this->security_schemes(['content:read']);
        $draft_security = $this->security_schemes(['content:draft']);

        return [
            'tools' => [
                [
                    'name' => 'site.list_post_types',
                    'title' => 'List Post Types',
                    'description' => 'List readable post types',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                    'securitySchemes' => $read_security,
                    'annotations' => ['readOnlyHint' => true],
                ],
                [
                    'name' => 'content.list_items',
                    'title' => 'List Content Items',
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
                    'securitySchemes' => $read_security,
                    'annotations' => ['readOnlyHint' => true],
                ],
                [
                    'name' => 'content.get_item',
                    'title' => 'Get Content Item',
                    'description' => 'Read one content item by ID',
                    'inputSchema' => [
                        'type' => 'object',
                        'required' => ['id'],
                        'properties' => ['id' => ['type' => 'integer']],
                    ],
                    'securitySchemes' => $read_security,
                    'annotations' => ['readOnlyHint' => true],
                ],
                [
                    'name' => 'content.create_draft',
                    'title' => 'Create Draft',
                    'description' => 'Create a draft content item',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'post_type' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'content' => ['type' => 'string'],
                        ],
                    ],
                    'securitySchemes' => $draft_security,
                    'annotations' => ['readOnlyHint' => false],
                ],
                [
                    'name' => 'taxonomy.list_taxonomies',
                    'title' => 'List Taxonomies',
                    'description' => 'List taxonomies',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                    'securitySchemes' => $read_security,
                    'annotations' => ['readOnlyHint' => true],
                ],
                [
                    'name' => 'taxonomy.list_terms',
                    'title' => 'List Terms',
                    'description' => 'List terms in a taxonomy',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => ['taxonomy' => ['type' => 'string']],
                    ],
                    'securitySchemes' => $read_security,
                    'annotations' => ['readOnlyHint' => true],
                ],
                [
                    'name' => 'media.list_items',
                    'title' => 'List Media Items',
                    'description' => 'List media items',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'page' => ['type' => 'integer'],
                            'per_page' => ['type' => 'integer'],
                        ],
                    ],
                    'securitySchemes' => $read_security,
                    'annotations' => ['readOnlyHint' => true],
                ],
                [
                    'name' => 'site.get_settings',
                    'title' => 'Get Site Settings',
                    'description' => 'Read safe site settings',
                    'inputSchema' => ['type' => 'object', 'properties' => []],
                    'securitySchemes' => $read_security,
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

    private function is_known_tool(string $tool): bool
    {
        return in_array($tool, [
            'site.list_post_types',
            'content.list_items',
            'content.get_item',
            'content.create_draft',
            'taxonomy.list_taxonomies',
            'taxonomy.list_terms',
            'media.list_items',
            'site.get_settings',
        ], true);
    }

    private function authenticate(WP_REST_Request $request, string $tool): array
    {
        $header = (string) $request->get_header('authorization');
        if (! str_starts_with(strtolower($header), 'bearer ')) {
            return [];
        }
        $token = trim(substr($header, 7));
        if ('' === $token) {
            return [];
        }

        $context = (new Access())->context_from_bearer($token);
        if (empty($context['user_id'])) {
            return [];
        }

        if (rest_url('quark/v1/mcp') !== ($context['resource'] ?? '')) {
            return [];
        }

        $required_scopes = $this->required_scopes($tool);
        $token_scopes = array_filter(array_map('strval', $context['scopes'] ?? []));
        foreach ($required_scopes as $scope) {
            if (! in_array($scope, $token_scopes, true)) {
                return [];
            }
        }

        return $context;
    }

    private function required_scopes(string $tool): array
    {
        if ('content.create_draft' === $tool) {
            return ['content:draft'];
        }

        return ['content:read'];
    }

    private function security_schemes(array $scopes): array
    {
        return [
            [
                'type' => 'oauth2',
                'scopes' => $scopes,
            ],
        ];
    }

    private function auth_required_result($id): array
    {
        return $this->rpc_result($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Authentication required.',
                ],
            ],
            '_meta' => [
                'mcp/www_authenticate' => [
                    'Bearer resource_metadata="' . home_url('/.well-known/oauth-protected-resource') . '", error="insufficient_scope", error_description="Authorize Quark to continue"',
                ],
            ],
            'isError' => true,
        ]);
    }

    private function tool_error_result($id, string $message): array
    {
        return $this->rpc_result($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $message,
                ],
            ],
            'isError' => true,
        ]);
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

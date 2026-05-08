<?php

declare(strict_types=1);

namespace Quark\Connectors\ChatGPT\Rest;

use Quark\Connectors\ChatGPT\Abilities\AbilitiesService;

final class ContentController
{
    public function register_routes(): void
    {
        // Internal MCP tools only.
    }

    public function list_post_types(): array
    {
        return ['items' => (new AbilitiesService())->list_post_types()];
    }

    public function list_items(array $data): array
    {
        return (new AbilitiesService())->list_items($data);
    }

    public function get_item(array $data): array
    {
        return (new AbilitiesService())->get_item((int) ($data['id'] ?? 0));
    }

    public function create_draft(array $data): array
    {
        return (new AbilitiesService())->create_draft($data);
    }

    public function list_taxonomies(): array
    {
        return ['items' => (new AbilitiesService())->list_taxonomies()];
    }

    public function list_terms(array $data): array
    {
        return ['items' => (new AbilitiesService())->list_terms((string) ($data['taxonomy'] ?? 'category'))];
    }

    public function list_media(array $data): array
    {
        return (new AbilitiesService())->list_media($data);
    }

    public function get_settings(): array
    {
        return (new AbilitiesService())->get_settings();
    }
}

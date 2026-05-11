<?php

declare(strict_types=1);

namespace Quark\Connectors\Providers;

interface ProviderInterface {

	public function id(): string;

	public function label(): string;

	public function description(): string;

	public function primary_action_url(): string;

	public function setup_steps( string $mcp_url ): array;

	public function copy_fields( string $mcp_url ): array;
}

<?php

declare(strict_types=1);

namespace Quark\Connectors\Providers;

interface ProviderInterface {

	public function id(): string;

	public function label(): string;

	public function description(): string;

	public function primary_action_url(): string;

	public function primary_action_label(): string;

	public function setup_sections( string $mcp_url ): array;
}

# AI Client Provider Registry

Provider setup cards and Dynamic Client Registration attribution are centralized in `ProviderRegistry`.

To add first-party or extension-provided client metadata:

1. Implement `ProviderInterface` for setup labels, links, instructions, and copy fields.
2. Implement `ProviderMatcherInterface` when DCR client metadata should be attributed to the provider.
3. Register the provider with the `aculect-ai-companion/connectors/providers` filter.

Example:

```php
add_filter(
	'aculect-ai-companion/connectors/providers',
	static function ( array $providers ): array {
		$providers[] = new Example_AI_Client_Provider();
		return $providers;
	}
);
```

Provider ids should be lowercase slugs using letters, numbers, underscores, or hyphens. Unknown standards-compliant MCP clients fall back to the built-in `mcp` provider.

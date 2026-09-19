<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use WP_REST_Request;

/**
 * Authenticated, transport-normalized input for one MCP ability execution.
 *
 * The REST controller constructs this value after its protocol and OAuth
 * boundary has completed. The gateway does not read controller request state.
 */
final readonly class AbilityExecutionRequest {

	/**
	 * Create normalized input for one authenticated tool call.
	 *
	 * @param array<string, mixed> $params Tool-call parameters.
	 * @param array<string, mixed> $auth Authenticated OAuth context.
	 * @param WP_REST_Request|null $rest_request Optional transport request for diagnostic context.
	 */
	public function __construct(
		public array $params,
		public array $auth,
		public ?WP_REST_Request $rest_request = null
	) {}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Activity\ActivityLogger;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use Aculect\AICompanion\Diagnostics\Logger;
use Closure;
use WP_REST_Request;

/**
 * Executes MCP abilities behind one policy-preserving boundary.
 *
 * MCP transport adapts the outcome this class returns into protocol-specific
 * JSON-RPC responses. Resolution, authorization, safety controls, dispatch,
 * and execution observability deliberately stay together so another caller
 * cannot accidentally bypass a controller-only policy step.
 */
final class AbilityExecutionGateway {

	public const OUTCOME_SUCCESS        = 'success';
	public const OUTCOME_INVALID_PARAMS = 'invalid_params';
	public const OUTCOME_UNKNOWN_TOOL   = 'unknown_tool';
	public const OUTCOME_TOOL_ERROR     = 'tool_error';
	public const OUTCOME_AUTH_CHALLENGE = 'auth_challenge';

	private AbilitiesRegistry $registry;
	private IntelligenceRegistry $intelligence;
	private McpInputValidator $input_validator;
	private ToolSafety $safety;

	/**
	 * Create Activity Logger instances for best-effort execution observability.
	 *
	 * @var Closure(): ActivityLogger
	 */
	private Closure $activity_logger_factory;

	/**
	 * Create diagnostic loggers for protocol-compatible diagnostics.
	 *
	 * @var Closure(): Logger
	 */
	private Closure $diagnostic_logger_factory;

	/**
	 * Construct a gateway with overridable policy and observability collaborators.
	 *
	 * @param AbilitiesRegistry|null         $registry Ability registry.
	 * @param IntelligenceRegistry|null      $intelligence Intelligence registry.
	 * @param McpInputValidator|null         $input_validator Input validator.
	 * @param ToolSafety|null                $safety Write-safety coordinator.
	 * @param Closure(): ActivityLogger|null $activity_logger_factory Activity logger factory.
	 * @param Closure(): Logger|null         $diagnostic_logger_factory Diagnostic logger factory.
	 */
	public function __construct(
		?AbilitiesRegistry $registry = null,
		?IntelligenceRegistry $intelligence = null,
		?McpInputValidator $input_validator = null,
		?ToolSafety $safety = null,
		?Closure $activity_logger_factory = null,
		?Closure $diagnostic_logger_factory = null
	) {
		$this->registry                  = $registry ?? new AbilitiesRegistry();
		$this->intelligence              = $intelligence ?? new IntelligenceRegistry();
		$this->input_validator           = $input_validator ?? new McpInputValidator();
		$this->safety                    = $safety ?? new ToolSafety();
		$this->activity_logger_factory   = $activity_logger_factory ?? static fn (): ActivityLogger => new ActivityLogger();
		$this->diagnostic_logger_factory = $diagnostic_logger_factory ?? static fn (): Logger => new Logger();
	}

	/**
	 * Execute an already-authenticated tools/call request.
	 *
	 * @param AbilityExecutionRequest $request Normalized authenticated request.
	 * @return AbilityExecutionOutcome Transport-neutral outcome.
	 */
	public function execute( AbilityExecutionRequest $request ): AbilityExecutionOutcome {
		$actor_id = (int) ( $request->auth['user_id'] ?? 0 );
		if ( 0 >= $actor_id ) {
			return $this->unavailable_authenticated_actor_outcome();
		}

		$previous_actor_id = $this->current_wordpress_user_id();
		if ( null === $previous_actor_id || ! $this->set_wordpress_user_id( $actor_id ) || $actor_id !== $this->current_wordpress_user_id() ) {
			if ( null !== $previous_actor_id ) {
				$this->set_wordpress_user_id( $previous_actor_id );
			}

			return $this->unavailable_authenticated_actor_outcome();
		}

		try {
			return AbilityExecutionOutcome::from_array( $this->execute_params( $request->params, $request->auth, $request->rest_request ) );
		} finally {
			$this->set_wordpress_user_id( $previous_actor_id );
		}
	}

	/**
	 * Return a fail-closed outcome when the OAuth user cannot become the actor.
	 */
	private function unavailable_authenticated_actor_outcome(): AbilityExecutionOutcome {
		return new AbilityExecutionOutcome(
			self::OUTCOME_TOOL_ERROR,
			array( 'message' => 'The authenticated WordPress user is unavailable.' )
		);
	}

	/**
	 * Return the current WordPress principal ID, or null when it cannot be read.
	 */
	private function current_wordpress_user_id(): ?int {
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return null;
		}

		return (int) \get_current_user_id();
	}

	/**
	 * Establish a WordPress principal only through the core runtime API.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private function set_wordpress_user_id( int $user_id ): bool {
		if ( ! function_exists( 'wp_set_current_user' ) ) {
			return false;
		}

		\wp_set_current_user( $user_id );

		return true;
	}

	/**
	 * Execute normalized input while keeping the public gateway contract typed.
	 *
	 * @param array<string, mixed> $params Tool-call parameters.
	 * @param array<string, mixed> $auth Authenticated OAuth context.
	 * @param WP_REST_Request|null $request Optional transport request for diagnostic context.
	 * @return array<string, mixed> Transport-neutral outcome data.
	 */
	private function execute_params( array $params, array $auth, ?WP_REST_Request $request = null ): array {
		$requested_tool       = (string) ( $params['name'] ?? '' );
		$tool                 = $this->intelligence->internal_id( $requested_tool );
		$is_intelligence_tool = $this->intelligence->is_known( $tool );
		if ( ! $is_intelligence_tool ) {
			$tool = $this->registry->internal_id( $requested_tool );
		}

		$raw_arguments = $params['arguments'] ?? array();
		if ( ! is_array( $raw_arguments ) ) {
			return $this->invalid_params( 'invalid_argument_type', 'Tool arguments must be a JSON object.' );
		}

		$args         = $raw_arguments;
		$module       = $is_intelligence_tool ? $this->intelligence->module( $tool ) : $this->registry->module( $tool );
		$input_schema = null === $module ? array() : self::input_schema_for_module( $module );
		$input_error  = $this->input_validator->arguments_error( $args, $input_schema, $tool );
		if ( null !== $input_error ) {
			return $this->invalid_params( $input_error['code'], $input_error['message'] );
		}

		$risk  = self::tool_risk_level( $tool, $args );
		$timer = microtime( true );
		$this->record_timeline_event(
			'tool_call_start',
			array(
				'method'         => 'tools/call',
				'tool'           => $tool,
				'status'         => 'started',
				'risk_level'     => $risk,
				'target_summary' => $this->timeline_target_summary( $tool, $args ),
			),
			$auth
		);

		$error = $is_intelligence_tool
			? $this->intelligence_tool_call_error( $tool, (int) ( $auth['user_id'] ?? 0 ), self::profile_context_from_auth( $auth ) )
			: $this->tool_call_error( $tool, (int) ( $auth['user_id'] ?? 0 ), self::profile_context_from_auth( $auth ) );

		if ( '' !== $error ) {
			return $this->policy_outcome( $error, $tool, $args, $auth, $timer, $request );
		}

		if ( $this->is_access_paused( (int) ( $auth['user_id'] ?? 0 ) ) ) {
			return $this->policy_outcome( 'access_paused', $tool, $args, $auth, $timer, $request );
		}

		$required = $is_intelligence_tool ? $this->intelligence->required_scopes( $tool ) : $this->registry->required_scopes( $tool );
		if ( ! $this->has_scopes( (array) ( $auth['scopes'] ?? array() ), $required ) ) {
			return $this->policy_outcome( 'insufficient_scope', $tool, $args, $auth, $timer, $request, $required );
		}

		$execution              = $this->execute_tool_with_safety( $tool, $args, $is_intelligence_tool, $auth );
		$result                 = $execution['result'];
		$args                   = $execution['args'];
		$trusted_write_executed = $execution['trusted_write_executed'];

		$activity_auth = $auth;
		if ( $trusted_write_executed ) {
			$activity_auth['write_permission_used'] = true;
			$this->record_diagnostic(
				'info',
				'mcp.trusted_write',
				'MCP write tool executed through a trusted connection.',
				array_merge(
					$this->log_context( 'tools/call', (string) ( $auth['provider'] ?? '' ), '', $tool ),
					array(
						'access_level'             => (string) ( $auth['access_level'] ?? '' ),
						'write_permission_enabled' => true,
					)
				),
				$request,
				200
			);
		}

		$this->record_tool_activity( $tool, $args, $result, $activity_auth );
		$this->record_timeline_event(
			isset( $result['error'] ) ? 'error' : 'tool_call_end',
			array(
				'method'         => 'tools/call',
				'tool'           => $tool,
				'status'         => isset( $result['error'] ) ? 'error' : 'success',
				'error_code'     => isset( $result['error'] ) && is_scalar( $result['error'] ) ? (string) $result['error'] : '',
				'duration_ms'    => $this->duration_ms( $timer ),
				'risk_level'     => $risk,
				'target_summary' => $this->timeline_target_summary( $tool, $args, $result ),
			),
			$activity_auth
		);

		return array(
			'type'   => self::OUTCOME_SUCCESS,
			'result' => $result,
		);
	}

	/**
	 * Return the effective input schema used by execution and descriptors.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 * @return array<string, mixed>
	 */
	public static function input_schema_for_module( AbilityModuleInterface $module ): array {
		$schema = $module->input_schema();

		if ( 'plugin.incident.report' !== $module->id() ) {
			return $schema;
		}

		$properties                       = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$properties['dry_run']            = array(
			'type'        => 'boolean',
			'description' => 'Preview the sanitized incident draft without storing it.',
		);
		$properties['confirmation_token'] = array(
			'type'        => 'string',
			'description' => 'Confirmation token from a previous preview of the same incident report.',
		);
		$properties['idempotency_key']    = array(
			'type'        => 'string',
			'description' => 'Optional stable key that makes retried report submissions replay-safe.',
		);
		$schema['properties']             = $properties;

		return $schema;
	}

	/**
	 * Return the risk level used by execution and tool descriptors.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<string, mixed> $args Tool arguments.
	 */
	public static function tool_risk_level( string $tool, array $args ): string {
		if ( 'plugin.incident.report' === $tool ) {
			return 'update';
		}

		return ( new ToolSafety() )->risk_level( $tool, $args );
	}

	/**
	 * Return profile selection context from OAuth token metadata.
	 *
	 * @param array<string, mixed> $auth OAuth context.
	 * @return array<string, mixed>
	 */
	public static function profile_context_from_auth( array $auth ): array {
		$context = array();
		foreach (
			array(
				'connection_profile'     => $auth['profile'] ?? $auth['provider_profile'] ?? '',
				'role_default_profile'   => $auth['role_default_profile'] ?? '',
				'global_default_profile' => $auth['global_default_profile'] ?? '',
			) as $key => $value
		) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$context[ $key ] = (string) $value;
			}
		}

		return $context;
	}

	/**
	 * Return a tools/call policy block result before dispatch, if any.
	 *
	 * @param string               $tool            Internal ability ID.
	 * @param int                  $user_id         WordPress user ID.
	 * @param array<string, mixed> $profile_context Profile selection context.
	 */
	private function tool_call_error( string $tool, int $user_id = 0, array $profile_context = array() ): string {
		if ( ! $this->registry->is_known( $tool ) ) {
			return 'unknown_tool';
		}

		$is_policy_managed = ! $this->registry->is_derived_workflow( $tool ) && ! $this->registry->is_core_default( $tool ) && ! $this->registry->is_always_on_write_intelligence( $tool );
		$role_policy       = new RoleAbilitiesPolicy();
		$availability      = new McpToolAvailability();

		if ( $is_policy_managed && ! $this->registry->is_enabled( $tool ) ) {
			return 'tool_disabled';
		}

		if ( $is_policy_managed && ! $role_policy->is_allowed_for_user( $tool, $user_id, $this->registry ) ) {
			return 'tool_forbidden_for_role';
		}

		if ( ! $availability->capabilities_available( $tool ) ) {
			return 'tool_forbidden_by_capability';
		}

		$profiles           = new McpToolProfiles();
		$profile_resolution = $profiles->resolve_for_user( $user_id, $this->registry, $profile_context );
		if ( ! $profiles->allows_ability( $tool, $profile_resolution['profile'], $this->registry ) ) {
			return 'tool_hidden_by_profile';
		}

		foreach ( $this->registry->dependency_ids( $tool ) as $dependency_id ) {
			$is_dependency_policy_managed = ! $this->registry->is_derived_workflow( $dependency_id ) && ! $this->registry->is_core_default( $dependency_id ) && ! $this->registry->is_always_on_write_intelligence( $dependency_id );

			if ( $is_dependency_policy_managed && ! $this->registry->is_enabled( $dependency_id ) ) {
				return 'tool_disabled';
			}

			if ( $is_dependency_policy_managed && ! $role_policy->is_allowed_for_user( $dependency_id, $user_id, $this->registry ) ) {
				return 'tool_forbidden_for_role';
			}

			if ( ! $availability->capabilities_available( $dependency_id ) ) {
				return 'tool_forbidden_by_capability';
			}

			if ( ! $profiles->allows_ability( $dependency_id, $profile_resolution['profile'], $this->registry ) ) {
				return 'tool_hidden_by_profile';
			}
		}

		return '';
	}

	/**
	 * Determine whether MCP tool calls are paused globally or for one user.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public function is_access_paused( int $user_id = 0 ): bool {
		return AccessLockdown::is_paused() || UserAccessControl::is_paused( $user_id );
	}

	/**
	 * Execute one tool through dry-run, confirmation, and replay controls.
	 *
	 * @param string               $tool                 Internal ability ID.
	 * @param array<string, mixed> $args                 Tool arguments.
	 * @param bool                 $is_intelligence_tool Whether this is an internal intelligence tool.
	 * @param array<string, mixed> $auth                 OAuth context.
	 * @return array{result: array<string, mixed>, args: array<string, mixed>, trusted_write_executed: bool}
	 */
	private function execute_tool_with_safety( string $tool, array $args, bool $is_intelligence_tool, array $auth ): array {
		$is_incident_report         = $is_intelligence_tool && 'plugin.incident.report' === $tool && ! $this->intelligence->is_read_only( $tool );
		$is_write_tool              = $is_incident_report || ( ! $is_intelligence_tool && ! $this->registry->is_read_only( $tool ) );
		$requires_confirmation      = $this->safety->requires_confirmation( $tool, $args );
		$has_confirmation_token     = $is_write_tool && $this->safety->has_confirmation_token( $args );
		$is_dry_run                 = $is_write_tool && $this->safety->is_dry_run( $args ) && ! $has_confirmation_token;
		$write_permission_unblocked = $is_write_tool && $this->write_permission_unblocks_tool( $tool, $auth, $is_intelligence_tool );
		$replay                     = $is_write_tool && ! $is_dry_run
			? ( $this->safety->confirmation_replay( $tool, $args, $auth ) ?? $this->safety->idempotent_replay( $tool, $args, $auth ) )
			: null;
		$trusted_write_executed     = false;
		$confirmation_validated     = $is_write_tool
			&& ! $is_dry_run
			&& null === $replay
			&& ! $write_permission_unblocked
			&& $requires_confirmation
			&& $this->confirmation_token_validated( $tool, $args, $auth );
		$invalid_confirmation       = $has_confirmation_token
			&& ! $is_dry_run
			&& null === $replay
			&& ! $write_permission_unblocked
			&& $requires_confirmation
			&& ! $confirmation_validated;
		$needs_confirmation_gate    = $is_write_tool
			&& ! $is_dry_run
			&& null === $replay
			&& ! $write_permission_unblocked
			&& $requires_confirmation
			&& ! $has_confirmation_token
			&& ! $confirmation_validated;

		if ( null !== $replay ) {
			$result = $replay;
		} elseif ( $is_dry_run ) {
			$result = $this->execute_tool( $tool, $args, $is_intelligence_tool, $auth );
			if ( ! isset( $result['error'] ) ) {
				if ( $write_permission_unblocked ) {
					$result = $this->write_permission_preview_payload( $result );
				} elseif ( $requires_confirmation ) {
					$result = $this->add_confirmation_metadata( $result, $tool, $args, $auth );
				}
			}
		} elseif ( $invalid_confirmation ) {
			$result = $this->invalid_confirmation_payload( $tool, $args, $auth );
		} elseif ( $needs_confirmation_gate ) {
			$preview_args            = $this->safety->strip_control_args( $args );
			$preview_args['dry_run'] = true;
			$preview                 = $this->execute_tool( $tool, $preview_args, $is_intelligence_tool, $auth );
			$result                  = isset( $preview['error'] )
				? $preview
				: $this->confirmation_required_payload( $tool, $preview_args, $auth, $preview );
		} else {
			$exec_args = $is_write_tool ? $this->safety->strip_control_args( $args ) : $args;
			$result    = $this->execute_tool( $tool, $exec_args, $is_intelligence_tool, $auth );
			if ( $is_write_tool && ! isset( $result['error'] ) ) {
				$trusted_write_executed = $write_permission_unblocked;
				if ( $trusted_write_executed ) {
					$result = $this->trusted_write_result_payload( $result, $auth );
				}
				$this->safety->remember_write_result( $tool, $args, $auth, $result );
			}
			$args = $exec_args;
		}

		return array(
			'result'                 => $result,
			'args'                   => $args,
			'trusted_write_executed' => $trusted_write_executed,
		);
	}

	/**
	 * Execute a resolved module without allowing callers to bypass the gateway.
	 *
	 * @param string               $tool Internal tool ID.
	 * @param array<string, mixed> $args Tool arguments.
	 * @param bool                 $is_intelligence_tool Whether to use the intelligence registry.
	 * @param array<string, mixed> $auth OAuth context.
	 * @return array<string, mixed>
	 */
	private function execute_tool( string $tool, array $args, bool $is_intelligence_tool, array $auth ): array {
		return $is_intelligence_tool
			? $this->intelligence->execute( $tool, $args, $this->intelligence_source_from_auth( $auth ) )
			: $this->registry->execute( $tool, $args );
	}

	/**
	 * Convert a policy denial into an execution outcome while preserving logs.
	 *
	 * @param string               $error           Policy failure code.
	 * @param string               $tool            Internal tool ID.
	 * @param array<string, mixed> $args            Tool arguments.
	 * @param array<string, mixed> $auth            OAuth context.
	 * @param float                $timer           Start timestamp.
	 * @param WP_REST_Request|null $request         Optional request for diagnostics.
	 * @param string[]             $required_scopes Required scopes for a challenge.
	 * @return array<string, mixed>
	 */
	private function policy_outcome( string $error, string $tool, array $args, array $auth, float $timer, ?WP_REST_Request $request, array $required_scopes = array() ): array {
		$messages = array(
			'unknown_tool'                 => 'Unknown tool.',
			'tool_disabled'                => 'This ability is disabled in Aculect AI Companion settings.',
			'tool_forbidden_for_role'      => 'This ability is not available for the connected WordPress role.',
			'tool_hidden_by_profile'       => 'This ability is hidden by the selected MCP tool profile.',
			'tool_forbidden_by_capability' => 'This ability is not available for the connected WordPress capabilities.',
			'access_paused'                => 'AI access is paused in Aculect AI Companion settings.',
			'insufficient_scope'           => 'The connection token does not include every required OAuth scope.',
		);
		$message  = $messages[ $error ] ?? 'Tool execution is not available.';
		$activity = array(
			'status'  => 'error',
			'error'   => $error,
			'message' => $message,
		);
		if ( 'insufficient_scope' === $error ) {
			$activity['required_scopes'] = $required_scopes;
		}

		$this->record_blocked_timeline_event( $tool, $args, $auth, $error, $timer );
		$this->record_tool_activity( $tool, $args, $activity, $auth );

		if ( 'unknown_tool' === $error ) {
			$this->record_diagnostic(
				'warning',
				'mcp.unknown_tool',
				'MCP tool call referenced an unknown tool.',
				$this->log_context( 'tools/call', (string) ( $auth['provider'] ?? '' ), $error, $tool ),
				$request,
				200
			);
			return array( 'type' => self::OUTCOME_UNKNOWN_TOOL );
		}

		if ( 'insufficient_scope' === $error ) {
			$this->record_diagnostic(
				'warning',
				'mcp.insufficient_scope',
				'MCP tool call did not include every required OAuth scope.',
				$this->log_context( 'tools/call', (string) ( $auth['provider'] ?? '' ), $error, $tool, $required_scopes ),
				$request,
				403
			);
			return array(
				'type'            => self::OUTCOME_AUTH_CHALLENGE,
				'required_scopes' => $required_scopes,
			);
		}

		$event_messages = array(
			'tool_disabled'                => array( 'mcp.tool_disabled', 'MCP tool call referenced a disabled tool.', 200 ),
			'tool_forbidden_for_role'      => array( 'mcp.tool_forbidden_for_role', 'MCP tool call was blocked by role ability policy.', 200 ),
			'tool_hidden_by_profile'       => array( 'mcp.tool_hidden_by_profile', 'MCP tool call was blocked by the selected tool profile.', 200 ),
			'tool_forbidden_by_capability' => array( 'mcp.tool_forbidden_by_capability', 'MCP tool call was blocked by WordPress capabilities.', 200 ),
			'access_paused'                => array( 'mcp.access_paused', 'MCP tool call was blocked because AI access is paused.', 423 ),
		);
		$event          = $event_messages[ $error ] ?? array( 'mcp.tool_blocked', 'MCP tool call was blocked.', 200 );
		$log_tool       = 'access_paused' === $error ? '' : $tool;
		$this->record_diagnostic(
			'warning',
			(string) $event[0],
			(string) $event[1],
			$this->log_context( 'tools/call', (string) ( $auth['provider'] ?? '' ), $error, $log_tool ),
			$request,
			(int) $event[2]
		);

		return array(
			'type'    => self::OUTCOME_TOOL_ERROR,
			'message' => $message,
		);
	}

	/**
	 * Create an invalid-parameters outcome.
	 *
	 * @param string $code Machine-readable validation code.
	 * @param string $message Public validation message.
	 * @return array<string, mixed>
	 */
	private function invalid_params( string $code, string $message ): array {
		return array(
			'type'    => self::OUTCOME_INVALID_PARAMS,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Return an intelligence-tool block reason before dispatch.
	 *
	 * @param string               $tool            Internal intelligence ID.
	 * @param int                  $user_id         WordPress user ID.
	 * @param array<string, mixed> $profile_context Profile selection context.
	 */
	private function intelligence_tool_call_error( string $tool, int $user_id, array $profile_context ): string {
		if ( ! ( new McpToolAvailability() )->capabilities_available( $tool ) ) {
			return 'tool_forbidden_by_capability';
		}

		$profiles = new McpToolProfiles();
		$profile  = $profiles->resolve_for_user( $user_id, $this->registry, $profile_context )['profile'];
		$module   = $this->intelligence->module( $tool );
		if ( null !== $module && ! $profiles->allows_ability( $tool, $profile, $this->registry, $module ) ) {
			return 'tool_hidden_by_profile';
		}

		return '';
	}

	/**
	 * Check whether a token includes every required scope.
	 *
	 * @param string[] $token_scopes Granted token scopes.
	 * @param string[] $required Required scopes.
	 */
	private function has_scopes( array $token_scopes, array $required ): bool {
		$token_scopes = array_map( 'strval', $token_scopes );
		foreach ( $required as $scope ) {
			if ( ! in_array( $scope, $token_scopes, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Determine whether a trusted connection can execute a write directly.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<string, mixed> $auth OAuth context.
	 * @param bool                 $is_intelligence_tool Whether the tool belongs to intelligence.
	 */
	private function write_permission_unblocks_tool( string $tool, array $auth, bool $is_intelligence_tool ): bool {
		$enabled   = in_array( $auth['write_permission_enabled'] ?? false, array( true, 1, '1' ), true )
			|| ConnectionAccessLevel::allows_direct_write( (string) ( $auth['access_level'] ?? '' ) );
		$read_only = $is_intelligence_tool ? $this->intelligence->is_read_only( $tool ) : $this->registry->is_read_only( $tool );

		return ! $read_only && $enabled;
	}

	/**
	 * Add direct-write policy metadata to a safe preview result.
	 *
	 * @param array<string, mixed> $result Preview result.
	 * @return array<string, mixed>
	 */
	private function write_permission_preview_payload( array $result ): array {
		$result['confirmation_required']    = false;
		$result['confirmation_policy']      = 'trusted_connection_direct_write';
		$result['write_permission_enabled'] = true;
		unset( $result['confirmation_token'], $result['confirmation_expires_in'], $result['confirmation_instructions'] );

		return $result;
	}

	/**
	 * Add direct-write policy metadata to a completed write result.
	 *
	 * @param array<string, mixed> $result Tool result.
	 * @param array<string, mixed> $auth OAuth context.
	 * @return array<string, mixed>
	 */
	private function trusted_write_result_payload( array $result, array $auth ): array {
		$result['confirmation_required']    = false;
		$result['confirmation_policy']      = 'trusted_connection_direct_write';
		$result['write_permission_enabled'] = true;
		$result['access_level']             = ConnectionAccessLevel::normalize( (string) ( $auth['access_level'] ?? '' ) );
		unset( $result['confirmation_token'], $result['confirmation_expires_in'], $result['confirmation_instructions'] );

		return $result;
	}

	/**
	 * Verify that a write confirmation token belongs to this exact call.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<string, mixed> $args Tool arguments.
	 * @param array<string, mixed> $auth OAuth context.
	 */
	private function confirmation_token_validated( string $tool, array $args, array $auth ): bool {
		$validated = $this->safety->validate_confirmation_token( $tool, $args, $auth );
		if ( $validated ) {
			$this->record_timeline_event(
				'confirmation_validated',
				array(
					'method'              => 'tools/call',
					'tool'                => $tool,
					'status'              => 'validated',
					'confirmation_policy' => 'token',
					'risk_level'          => self::tool_risk_level( $tool, $args ),
					'target_summary'      => $this->timeline_target_summary( $tool, $args ),
				),
				$auth
			);
		}

		return $validated;
	}

	/**
	 * Attach a reusable confirmation token to a dry-run preview.
	 *
	 * @param array<string, mixed> $result Preview result.
	 * @param string               $tool Internal ability ID.
	 * @param array<string, mixed> $args Tool arguments.
	 * @param array<string, mixed> $auth OAuth context.
	 * @return array<string, mixed>
	 */
	private function add_confirmation_metadata( array $result, string $tool, array $args, array $auth ): array {
		$result['confirmation_required']     = true;
		$result['confirmation_token']        = $this->safety->issue_confirmation_token( $tool, $args, $auth );
		$result['confirmation_expires_in']   = $this->safety->confirmation_ttl();
		$result['confirmation_instructions'] = 'Repeat the same tool call with confirmation_token before it expires to apply these changes.';
		$this->record_timeline_event(
			'confirmation_issued',
			array(
				'method'              => 'tools/call',
				'tool'                => $tool,
				'status'              => 'issued',
				'confirmation_policy' => 'dry_run_preview',
				'risk_level'          => self::tool_risk_level( $tool, $args ),
				'target_summary'      => $this->timeline_target_summary( $tool, $args, $result ),
			),
			$auth
		);

		return $result;
	}

	/**
	 * Build a deterministic response for an invalid write confirmation token.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<string, mixed> $args Tool arguments.
	 * @param array<string, mixed> $auth OAuth context.
	 * @return array<string, mixed>
	 */
	private function invalid_confirmation_payload( string $tool, array $args, array $auth ): array {
		$this->record_timeline_event(
			'blocked_by',
			array(
				'method'         => 'tools/call',
				'tool'           => $tool,
				'status'         => 'blocked',
				'blocked_by'     => 'invalid_confirmation_token',
				'error_code'     => 'invalid_confirmation_token',
				'risk_level'     => self::tool_risk_level( $tool, $args ),
				'target_summary' => $this->timeline_target_summary( $tool, $args ),
			),
			$auth
		);

		return array(
			'status'                => 'blocked',
			'error'                 => 'invalid_confirmation_token',
			'message'               => 'The confirmation token is invalid, expired, or does not match this tool call.',
			'confirmation_required' => true,
			'action'                => $tool,
			'risk_level'            => self::tool_risk_level( $tool, $args ),
			'next_actions'          => array( 'Repeat the call without confirmation_token to request a new preview and token.' ),
		);
	}

	/**
	 * Build a confirmation-required result from a safe write preview.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<string, mixed> $args Preview arguments.
	 * @param array<string, mixed> $auth OAuth context.
	 * @param array<string, mixed> $preview Dry-run preview.
	 * @return array<string, mixed>
	 */
	private function confirmation_required_payload( string $tool, array $args, array $auth, array $preview ): array {
		$this->record_timeline_event(
			'confirmation_issued',
			array(
				'method'              => 'tools/call',
				'tool'                => $tool,
				'status'              => 'issued',
				'confirmation_policy' => 'required_before_write',
				'risk_level'          => self::tool_risk_level( $tool, $args ),
				'target_summary'      => $this->timeline_target_summary( $tool, $args, $preview ),
			),
			$auth
		);

		return array(
			'status'                    => 'confirmation_required',
			'confirmation_required'     => true,
			'confirmation_token'        => $this->safety->issue_confirmation_token( $tool, $args, $auth ),
			'confirmation_expires_in'   => $this->safety->confirmation_ttl(),
			'confirmation_instructions' => 'Repeat the same tool call with confirmation_token before it expires to apply these changes.',
			'action'                    => $tool,
			'risk_level'                => self::tool_risk_level( $tool, $args ),
			'preview'                   => $preview,
		);
	}

	/**
	 * Derive a support-safe intelligence execution source from token context.
	 *
	 * @param array<string, mixed> $auth OAuth context.
	 * @return array<string, mixed>
	 */
	private function intelligence_source_from_auth( array $auth ): array {
		return array(
			'provider'    => (string) ( $auth['provider'] ?? 'mcp' ),
			'client_id'   => (string) ( $auth['client_id'] ?? '' ),
			'client_name' => (string) ( $auth['client_name'] ?? '' ),
			'user_id'     => (int) ( $auth['user_id'] ?? 0 ),
		);
	}

	/**
	 * Record activity without making activity storage part of request success.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<string, mixed> $args Tool arguments.
	 * @param array<string, mixed> $result Tool result.
	 * @param array<string, mixed> $auth OAuth context.
	 */
	private function record_tool_activity( string $tool, array $args, array $result, array $auth ): void {
		try {
			( $this->activity_logger_factory )()->record_tool_call( $tool, $args, $result, $auth );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}

	/**
	 * Record timeline activity without making activity storage part of request success.
	 *
	 * @param string               $event Support-safe timeline event name.
	 * @param array<string, mixed> $metadata Support-safe metadata.
	 * @param array<string, mixed> $auth OAuth context.
	 */
	private function record_timeline_event( string $event, array $metadata, array $auth ): void {
		try {
			( $this->activity_logger_factory )()->record_timeline_event( $event, $metadata, $auth );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}

	/**
	 * Record a policy-block event without making timeline storage authoritative.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<string, mixed> $args Tool arguments.
	 * @param array<string, mixed> $auth OAuth context.
	 * @param string               $blocked_by Policy error code.
	 * @param float                $started_at Execution start timestamp.
	 */
	private function record_blocked_timeline_event( string $tool, array $args, array $auth, string $blocked_by, float $started_at ): void {
		$this->record_timeline_event(
			'blocked_by',
			array(
				'method'         => 'tools/call',
				'tool'           => $tool,
				'status'         => 'blocked',
				'blocked_by'     => $blocked_by,
				'error_code'     => $blocked_by,
				'duration_ms'    => $this->duration_ms( $started_at ),
				'risk_level'     => self::tool_risk_level( $tool, $args ),
				'target_summary' => $this->timeline_target_summary( $tool, $args ),
			),
			$auth
		);
	}

	/**
	 * Create a diagnostic logger for the current execution.
	 *
	 * @return Logger
	 */
	private function diagnostic_logger(): Logger {
		return ( $this->diagnostic_logger_factory )();
	}

	/**
	 * Record a diagnostic event without changing the request outcome.
	 *
	 * @param string               $level Diagnostic severity.
	 * @param string               $event Event name.
	 * @param string               $message Human-readable summary.
	 * @param array<string, mixed> $context Support-safe event context.
	 * @param WP_REST_Request|null $request Optional REST request.
	 * @param int                  $status HTTP status.
	 */
	private function record_diagnostic( string $level, string $event, string $message, array $context, ?WP_REST_Request $request, int $status ): void {
		try {
			$logger = $this->diagnostic_logger();
			if ( 'info' === $level ) {
				$logger->info( $event, $message, $context, $request, $status );
				return;
			}

			$logger->warning( $event, $message, $context, $request, $status );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}

	/**
	 * Build the existing support-safe diagnostic context shape.
	 *
	 * @param string   $method RPC method.
	 * @param string   $provider OAuth provider.
	 * @param string   $error_code Machine-readable error code.
	 * @param string   $tool Internal ability ID.
	 * @param string[] $required_scopes Required scopes.
	 * @return array<string, mixed>
	 */
	private function log_context( string $method, string $provider = '', string $error_code = '', string $tool = '', array $required_scopes = array() ): array {
		$context = array(
			'provider'   => $provider,
			'rpc_method' => $method,
			'tool'       => $tool,
		);
		if ( '' !== $error_code ) {
			$context['error_code'] = $error_code;
		}
		if ( array() !== $required_scopes ) {
			$context['required_scopes'] = array_values( array_map( 'strval', $required_scopes ) );
		}

		return $context;
	}

	/**
	 * Calculate a non-negative execution duration.
	 *
	 * @param float $started_at Execution start timestamp.
	 * @return int
	 */
	private function duration_ms( float $started_at ): int {
		return max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
	}

	/**
	 * Create the bounded support-safe target summary used by activity events.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<string, mixed> $args Tool arguments.
	 * @param array<string, mixed> $result Optional result.
	 */
	private function timeline_target_summary( string $tool, array $args, array $result = array() ): string {
		$parts = array( $tool );
		foreach ( array( 'id', 'post_id', 'term_id', 'suggestion_id', 'type', 'post_type', 'taxonomy', 'status' ) as $key ) {
			$value = $result[ $key ] ?? $args[ $key ] ?? null;
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$parts[] = $key . ':' . ( is_numeric( $value ) ? (string) absint( $value ) : sanitize_key( (string) $value ) );
			}
		}

		return substr( implode( ' ', array_filter( $parts ) ), 0, 160 );
	}
}

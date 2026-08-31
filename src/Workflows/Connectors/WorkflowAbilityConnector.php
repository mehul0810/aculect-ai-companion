<?php
/**
 * Public, policy-preserving connector for custom content workflows.
 *
 * @package Aculect\AICompanion\Workflows\Connectors
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Connectors;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterRegistry;
use Aculect\AICompanion\Workflows\Authorization\WorkflowRoleAccessPolicy;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRecord;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRepository;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRepositoryInterface;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRepositoryException;
use Aculect\AICompanion\Workflows\Execution\WorkflowAuditStore;
use Aculect\AICompanion\Workflows\Execution\WorkflowAuditStoreInterface;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunExecutionResult;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunRecord;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunStore;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunStoreInterface;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunner;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunnerException;
use Aculect\AICompanion\Workflows\Execution\WorkflowStepRecord;
use Aculect\AICompanion\Workflows\Execution\WorkflowStepState;
use Aculect\AICompanion\Workflows\Connectors\WorkflowAbilitySupport;
use Aculect\AICompanion\Workflows\Execution\WorkflowApprovalTokenStore;
use Aculect\AICompanion\Workflows\Planning\WorkflowApprovalEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowDryRun;
use Aculect\AICompanion\Workflows\Planning\WorkflowExecutionEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowAvailabilitySnapshot;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanReadinessEvaluator;
use Aculect\AICompanion\Workflows\Planning\WorkflowReadinessEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;
use Closure;
use Throwable;
use stdClass;

/**
 * Composes the workflow definition, planning, runner, and audit boundaries.
 *
 * The connector deliberately accepts no caller-supplied authentication. It
 * reads the request-local context established by AbilityExecutionGateway and
 * passes that same context to native adapters, so global policy, role policy,
 * OAuth scopes, WordPress capabilities, and write safety remain authoritative.
 */
final class WorkflowAbilityConnector {

	private WorkflowDefinitionRepositoryInterface $definitions;
	private WorkflowAdapterRegistry $adapters;
	private WorkflowRunStoreInterface $runs;
	private WorkflowAuditStoreInterface $audit;
	private WorkflowPlanBuilder $plans;
	private WorkflowPlanReadinessEvaluator $readiness;
	private WorkflowApprovalService $approvals;
	private WorkflowRoleAccessPolicy $role_access;
	private WorkflowPublishedWorkflowLister $workflow_lister;

	/**
	 * Provides the authenticated request context for connector calls.
	 *
	 * @var Closure(): array<string, mixed>
	 */
	private Closure $auth_provider;

	/**
	 * Create a connector with replaceable storage/composition collaborators.
	 *
	 * @param WorkflowDefinitionRepositoryInterface|null $definitions Definition repository.
	 * @param WorkflowAdapterRegistry|null               $adapters Closed adapter registry.
	 * @param WorkflowRunStoreInterface|null             $runs Durable run store.
	 * @param WorkflowAuditStoreInterface|null           $audit Durable audit store.
	 * @param WorkflowPlanBuilder|null                   $plans Deterministic plan builder.
	 * @param WorkflowPlanReadinessEvaluator|null        $readiness Readiness evaluator.
	 * @param Closure|null                               $auth_provider Request auth provider.
	 * @param WorkflowApprovalTokenStore|null            $approval_tokens Server approval store.
	 * @param WorkflowRoleAccessPolicy|null              $role_access Workflow role policy.
	 */
	public function __construct(
		?WorkflowDefinitionRepositoryInterface $definitions = null,
		?WorkflowAdapterRegistry $adapters = null,
		?WorkflowRunStoreInterface $runs = null,
		?WorkflowAuditStoreInterface $audit = null,
		?WorkflowPlanBuilder $plans = null,
		?WorkflowPlanReadinessEvaluator $readiness = null,
		?Closure $auth_provider = null,
		?WorkflowApprovalTokenStore $approval_tokens = null,
		?WorkflowRoleAccessPolicy $role_access = null
	) {
		$this->definitions     = $definitions ?? new WorkflowDefinitionRepository();
		$this->adapters        = $adapters ?? WorkflowAdapterRegistry::from_catalog();
		$this->runs            = $runs ?? new WorkflowRunStore();
		$this->audit           = $audit ?? new WorkflowAuditStore();
		$this->plans           = $plans ?? new WorkflowPlanBuilder();
		$this->readiness       = $readiness ?? new WorkflowPlanReadinessEvaluator();
		$this->auth_provider   = $auth_provider ?? static fn (): array => AbilityExecutionGateway::current_request_auth();
		$this->approvals       = new WorkflowApprovalService( $approval_tokens ?? new WorkflowApprovalTokenStore() );
		$this->role_access     = $role_access ?? new WorkflowRoleAccessPolicy();
		$this->workflow_lister = new WorkflowPublishedWorkflowLister( $this->definitions, $this->role_access );
	}

	/**
	 * List published custom workflows and fixed workflow guides.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int|string, mixed>
	 */
	public function list_workflows( array $args ): array {
		$auth = $this->auth();
		if ( null === $auth ) {
			return $this->auth_error();
		}
		try {
			return $this->workflow_lister->list( $args, $auth );
		} catch ( WorkflowDefinitionRepositoryException ) {
			return $this->error( 'workflow_storage_unavailable', 'Workflow definitions are temporarily unavailable.' );
		} catch ( Throwable ) {
			return $this->error( 'workflow_storage_unavailable', 'Workflow definitions are temporarily unavailable.' );
		}
	}

	/**
	 * Read one published custom workflow without exposing static step arguments.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int|string, mixed>
	 */
	public function get( array $args ): array {
		$auth = $this->auth();
		if ( null === $auth ) {
			return $this->auth_error();
		}
		$id = WorkflowAbilitySupport::identifier( $args['workflow_id'] ?? $args['id'] ?? '' );
		if ( '' === $id ) {
			return $this->error( 'invalid_workflow_id', 'Provide a workflow_id returned by content_workflow_list.' );
		}

		try {
			$record = isset( $args['version'] ) && is_int( $args['version'] )
				? $this->definitions->get( $id, $args['version'] )
				: $this->definitions->get_published( $id );
		} catch ( WorkflowDefinitionRepositoryException ) {
			return $this->error( 'workflow_storage_unavailable', 'Workflow definitions are temporarily unavailable.' );
		} catch ( Throwable ) {
			return $this->error( 'workflow_storage_unavailable', 'Workflow definitions are temporarily unavailable.' );
		}

		if ( null === $record || ! WorkflowAbilitySupport::is_published( $record ) ) {
			return $this->error( 'workflow_not_found', 'No published workflow exists for that ID.' );
		}
		if ( ! $this->role_allowed( $record, $auth ) ) {
			return $this->error( 'workflow_not_found', 'No published workflow exists for that ID.' );
		}

		return array(
			'status'   => 'ok',
			'workflow' => $this->safe_definition( $record ),
			'bounded'  => true,
		);
	}

	/**
	 * Prepare and persist a workflow run, returning missing input paths.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int|string, mixed>
	 */
	public function prepare( array $args ): array {
		$auth = $this->auth();
		if ( null === $auth ) {
			return $this->auth_error();
		}
		$record = $this->published_record( $args );
		if ( is_array( $record ) ) {
			return $record;
		}
		$input = $this->input( $args['input'] ?? null );
		if ( is_array( $input ) ) {
			return $input;
		}

		try {
			$plan   = $this->plans->build( $record->definition(), $input );
			$runner = $this->runner();
			$run    = $runner->create( $record, $plan, $input, $auth['user_id'] );
		} catch ( WorkflowRunnerException $exception ) {
			return $this->runner_error( $exception );
		} catch ( Throwable ) {
			return $this->error( 'workflow_prepare_failed', 'The workflow could not be prepared.' );
		}

		return array(
			'status'       => 'ok',
			'run'          => $this->run_payload( $run, $plan ),
			'plan'         => $this->plan_payload( $plan ),
			'bounded'      => true,
			'next_actions' => $this->prepare_actions( $plan ),
		);
	}

	/**
	 * Build a deterministic dry-run for a prepared run.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int|string, mixed>
	 */
	public function dry_run( array $args ): array {
		$auth = $this->auth();
		if ( null === $auth ) {
			return $this->auth_error();
		}
		$context = $this->run_context( $args );
		if ( isset( $context['error'] ) ) {
			/* @var array<string,mixed> $context */
			return $context;
		}
		[ $run, $record, $plan ] = $context;
		if ( WorkflowRunState::WAITING_FOR_INPUT === $run->state() ) {
			return $this->input_required( $run, $plan );
		}

		try {
			$updated = $this->runner()->build_dry_run( $run->run_id(), $plan, $auth['user_id'] );
		} catch ( WorkflowRunnerException $exception ) {
			return $this->runner_error( $exception );
		} catch ( Throwable ) {
			return $this->error( 'workflow_dry_run_failed', 'The workflow dry-run could not be built.' );
		}

		return $this->dry_run_payload(
			$updated,
			$plan,
			$auth,
			'Review the planned steps and provide approval evidence before content_workflow_execute.'
		);
	}

	/**
	 * Return an execute preview without changing durable run state.
	 *
	 * The shared MCP safety schema exposes dry_run on write modules. An execute
	 * preview must therefore stop before runner transitions or native dispatch;
	 * callers can use content_workflow_dry_run when they intentionally want to
	 * persist the dry-run-ready lifecycle state.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int|string, mixed>
	 */
	public function preview_execute( array $args ): array {
		$auth = $this->auth();
		if ( null === $auth ) {
			return $this->auth_error();
		}
		$context = $this->run_context( $args );
		if ( isset( $context['error'] ) ) {
			/* @var array<string,mixed> $context */
			return $context;
		}
		[ $run, , $plan ] = $context;
		if ( $run->state()->is_terminal() ) {
			return $this->terminal_payload( $run, $plan );
		}
		if ( WorkflowRunState::WAITING_FOR_INPUT === $run->state() ) {
			return $this->input_required( $run, $plan );
		}

		return $this->dry_run_payload(
			$run,
			$plan,
			$auth,
			'Review this non-mutating preview, then call content_workflow_execute without dry_run to advance the run.'
		);
	}

	/**
	 * Build a dry-run response from an exact plan and run record.
	 *
	 * @param WorkflowRunRecord   $run         Run record to project.
	 * @param WorkflowPlan        $plan        Exact pinned plan.
	 * @param array<string,mixed> $auth       Auth context.
	 * @param string              $next_action Bounded follow-up instruction.
	 * @return array<string,mixed>
	 */
	private function dry_run_payload( WorkflowRunRecord $run, WorkflowPlan $plan, array $auth, string $next_action ): array {
		$payload = array(
			'status'       => 'ok',
			'run'          => $this->run_payload( $run, $plan ),
			'dry_run'      => WorkflowDryRun::from_plan( $plan )->to_array(),
			'readiness'    => $this->readiness_payload( $plan, $auth ),
			'risks'        => $this->risk_payload( $plan ),
			'bounded'      => true,
			'next_actions' => array( $next_action ),
		);
		if ( array() !== $plan->approval_gate_step_ids() ) {
			$payload = $this->approvals->add_token_payload( $payload, $run->run_id(), $plan, $auth );
		}

		return $payload;
	}

	/**
	 * Start or advance one approved workflow step.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int|string, mixed>
	 */
	public function execute( array $args ): array {
		$auth = $this->auth();
		if ( null === $auth ) {
			return $this->auth_error();
		}
		if ( true === ( $args['dry_run'] ?? false ) ) {
			return $this->preview_execute( $args );
		}
		$context = $this->run_context( $args );
		if ( isset( $context['error'] ) ) {
			/* @var array<string,mixed> $context */
			return $context;
		}
		[ $run, $record, $plan ] = $context;
		if ( $run->state()->is_terminal() ) {
			return $this->terminal_payload( $run, $plan );
		}
		if ( WorkflowRunState::WAITING_FOR_INPUT === $run->state() ) {
			return $this->input_required( $run, $plan );
		}

		$readiness = $this->readiness_evidence( $plan, $auth );
		if ( null !== ( $readiness['error'] ?? null ) ) {
			return $readiness['error'];
		}
		$evidence = $readiness['evidence'] ?? null;
		if ( ! $evidence instanceof WorkflowReadinessEvidence ) {
			return $this->error( 'workflow_readiness_unavailable', 'Workflow requirements could not be evaluated.' );
		}
		$approval = $this->approval( $args['approval'] ?? null, $plan, $run->run_id(), $auth );
		if ( is_array( $approval ) ) {
			return $this->error( (string) ( $approval['error'] ?? 'invalid_approval' ), (string) ( $approval['message'] ?? 'Approval evidence is invalid.' ) );
		}

		try {
			$runner = $this->runner();
			$run    = $this->start_if_needed( $runner, $run, $plan, $evidence, $auth['user_id'], $approval );
		} catch ( WorkflowRunnerException $exception ) {
			if ( 'approval_required' === $exception->error_code() ) {
				return $this->approval_required( $run, $plan, $auth );
			}
			return $this->runner_error( $exception );
		} catch ( Throwable ) {
			return $this->error( 'workflow_start_failed', 'The workflow could not be started.' );
		}

		if ( WorkflowRunState::WAITING_FOR_APPROVAL === $run->state() ) {
			return $this->approval_required( $run, $plan, $auth );
		}

		try {
			$progress = $this->runner()->execute_next( $record, $run->run_id(), $plan, $auth, $auth['user_id'] );
		} catch ( WorkflowRunnerException $exception ) {
			return $this->runner_error( $exception );
		} catch ( Throwable ) {
			return $this->error( 'workflow_execution_failed', 'The workflow step could not be executed.' );
		}

		return $this->execution_payload( $progress, $plan );
	}

	/**
	 * Resume a run from missing input, approval, or a running step.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int|string, mixed>
	 */
	public function resume( array $args ): array {
		$auth = $this->auth();
		if ( null === $auth ) {
			return $this->auth_error();
		}
		if ( true === ( $args['dry_run'] ?? false ) ) {
			return $this->preview_execute( $args );
		}
		$context = $this->run_context( $args, true );
		if ( isset( $context['error'] ) ) {
			/* @var array<string,mixed> $context */
			return $context;
		}
		[ $run, $record, $plan ] = $context;
		$runner                  = $this->runner();

		try {
			if ( WorkflowRunState::WAITING_FOR_INPUT === $run->state() ) {
				$input = $this->input( $args['input'] ?? null );
				if ( is_array( $input ) ) {
					return $input;
				}
				$plan = $this->plans->build( $record->definition(), $input );
				$run  = $runner->resume_with_input( $run->run_id(), $plan, $input, $auth['user_id'] );
			}

			if ( WorkflowRunState::PREPARED === $run->state() || WorkflowRunState::DRY_RUN_READY === $run->state() || WorkflowRunState::WAITING_FOR_APPROVAL === $run->state() ) {
				$readiness = $this->readiness_evidence( $plan, $auth );
				if ( null !== ( $readiness['error'] ?? null ) ) {
					return $readiness['error'];
				}
				$approval = $this->approval( $args['approval'] ?? null, $plan, $run->run_id(), $auth );
				if ( is_array( $approval ) ) {
					return $this->error( (string) ( $approval['error'] ?? 'invalid_approval' ), (string) ( $approval['message'] ?? 'Approval evidence is invalid.' ) );
				}
				if ( ! isset( $readiness['evidence'] ) || ! $readiness['evidence'] instanceof WorkflowReadinessEvidence ) {
					return $this->error( 'workflow_readiness_unavailable', 'Workflow requirements could not be evaluated.' );
				}
				$run = $this->start_if_needed( $runner, $run, $plan, $readiness['evidence'], $auth['user_id'], $approval );
			}

			if ( WorkflowRunState::RUNNING === $run->state() ) {
				return $this->execution_payload( $runner->execute_next( $record, $run->run_id(), $plan, $auth, $auth['user_id'] ), $plan );
			}
		} catch ( WorkflowRunnerException $exception ) {
			return $this->runner_error( $exception );
		} catch ( Throwable ) {
			return $this->error( 'workflow_resume_failed', 'The workflow could not be resumed.' );
		}

		return array(
			'status'  => 'ok',
			'run'     => $this->run_payload( $run, $plan ),
			'plan'    => $this->plan_payload( $plan ),
			'bounded' => true,
		);
	}

	/**
	 * Cancel a run before execution or at a caller-proven safe boundary.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int|string, mixed>
	 */
	public function cancel( array $args ): array {
		$auth = $this->auth();
		if ( null === $auth ) {
			return $this->auth_error();
		}
		$context = $this->run_context( $args, true );
		if ( isset( $context['error'] ) ) {
			/* @var array<string,mixed> $context */
			return $context;
		}
		[ $run, , $plan ] = $context;
		if ( true === ( $args['dry_run'] ?? false ) ) {
			return $this->cancel_preview( $run, $plan );
		}
		$evidence = null;
		if ( WorkflowRunState::RUNNING === $run->state() ) {
			if ( true !== ( $args['safe_stop'] ?? false ) ) {
				return $this->error( 'cancel_not_allowed', 'Set safe_stop after the current step reaches a safe boundary.' );
			}
			try {
				foreach ( $this->runs->steps( $run->run_id() ) as $step ) {
					if ( $step instanceof WorkflowStepRecord && WorkflowStepState::RUNNING === $step->state() ) {
						return $this->error( 'cancel_not_allowed', 'The run still has a claimed step; wait for its safe boundary before cancelling.' );
					}
				}
				$evidence = new WorkflowExecutionEvidence( $plan->hash(), 'cancelled', 'safe_stop', true );
			} catch ( Throwable ) {
				return $this->error( 'cancel_not_allowed', 'The run is not at a proven safe boundary.' );
			}
		}

		try {
			$cancelled = $this->runner()->cancel( $run->run_id(), $plan, $auth['user_id'], $evidence );
		} catch ( WorkflowRunnerException $exception ) {
			return $this->runner_error( $exception );
		} catch ( Throwable ) {
			return $this->error( 'workflow_cancel_failed', 'The workflow could not be cancelled.' );
		}

		return array(
			'status'  => 'ok',
			'run'     => $this->run_payload( $cancelled, $plan ),
			'bounded' => true,
		);
	}

	/**
	 * Return a bounded status snapshot for a run.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int|string, mixed>
	 */
	public function status( array $args ): array {
		$context = $this->run_context( $args, true );
		if ( isset( $context['error'] ) ) {
			/* @var array<string,mixed> $context */
			return $context;
		}
		[ $run, , $plan ] = $context;

		return array(
			'status'  => 'ok',
			'run'     => $this->run_payload( $run, $plan ),
			'bounded' => true,
		);
	}

	/**
	 * Return run status, steps, and summary-only audit events.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<int|string, mixed>
	 */
	public function result( array $args ): array {
		$context = $this->run_context( $args, true );
		if ( isset( $context['error'] ) ) {
			/* @var array<string,mixed> $context */
			return $context;
		}
		[ $run, , $plan ] = $context;
		try {
			$events = array_map( static fn ( object $event ): array => $event->to_array(), $this->audit->for_run( $run->run_id() ) );
		} catch ( Throwable ) {
			$events = array();
		}

		return array(
			'status'  => 'ok',
			'run'     => $this->run_payload( $run, $plan ),
			'audit'   => array_slice( $events, 0, 100 ),
			'bounded' => true,
		);
	}

	/**
	 * Return a workflow runner using the explicit catalog and durable stores.
	 */
	private function runner(): WorkflowRunner {
		return new WorkflowRunner( $this->runs, $this->adapters, audit: $this->audit );
	}

	/**
	 * Return authenticated request context or null when called outside the gateway.
	 *
	 * @return array<string, mixed>|null
	 */
	private function auth(): ?array {
		try {
			$auth = ( $this->auth_provider )();
		} catch ( Throwable ) {
			return null;
		}
		if ( ! is_array( $auth ) || (int) ( $auth['user_id'] ?? 0 ) < 1 ) {
			return null;
		}

		$auth['user_id'] = (int) $auth['user_id'];
		return $auth;
	}

	/**
	 * Check the stored workflow role narrowing against the trusted request actor.
	 *
	 * The role policy is an additional narrowing layer. Native ability policy,
	 * OAuth scopes, WordPress capabilities, ownership, and approval evidence are
	 * still evaluated by their existing boundaries after this check.
	 *
	 * @param WorkflowDefinitionRecord $record Published workflow record.
	 * @param array<string,mixed>      $auth   Authenticated request context.
	 */
	private function role_allowed( WorkflowDefinitionRecord $record, array $auth ): bool {
		return $this->role_access->is_allowed( $record->allowed_roles(), $auth );
	}

	/**
	 * Load an allowed published definition.
	 *
	 * @param array<string, mixed> $args Connector arguments.
	 * @return WorkflowDefinitionRecord|array<string, mixed>
	 */
	private function published_record( array $args ): WorkflowDefinitionRecord|array {
		$id = WorkflowAbilitySupport::identifier( $args['workflow_id'] ?? $args['id'] ?? '' );
		if ( '' === $id ) {
			return $this->error( 'invalid_workflow_id', 'Provide a workflow_id returned by content_workflow_list.' );
		}

		try {
			$record = isset( $args['version'] ) && is_int( $args['version'] )
				? $this->definitions->get( $id, $args['version'] )
				: $this->definitions->get_published( $id );
		} catch ( WorkflowDefinitionRepositoryException ) {
			return $this->error( 'workflow_storage_unavailable', 'Workflow definitions are temporarily unavailable.' );
		} catch ( Throwable ) {
			return $this->error( 'workflow_storage_unavailable', 'Workflow definitions are temporarily unavailable.' );
		}

		if ( null === $record || ! WorkflowAbilitySupport::is_published( $record ) ) {
			return $this->error( 'workflow_not_found', 'No published workflow exists for that ID.' );
		}
		$auth = $this->auth();
		if ( null === $auth || ! $this->role_allowed( $record, $auth ) ) {
			return $this->error( 'workflow_not_found', 'No published workflow exists for that ID.' );
		}

		return $record;
	}

	/**
	 * Load a run and rebuild its exact plan from caller-provided input.
	 *
	 * Run payloads intentionally do not return input. Calls that need planning
	 * must supply the same input object, which is checked against the encrypted
	 * store-bound hash by the runner/store fence.
	 *
	 * @param array<string, mixed> $args Connector arguments.
	 * @param bool                 $allow_missing_input Whether to return an incomplete plan.
	 * @return array{0:WorkflowRunRecord,1:WorkflowDefinitionRecord,2:WorkflowPlan}|array<string,mixed>
	 */
	private function run_context( array $args, bool $allow_missing_input = false ): array {
		$auth = $this->auth();
		if ( null === $auth ) {
			return $this->auth_error();
		}
		$run_id = WorkflowAbilitySupport::run_id( $args['run_id'] ?? $args['id'] ?? '' );
		if ( '' === $run_id ) {
			return $this->error( 'invalid_run_id', 'Provide the run_id returned by content_workflow_prepare.' );
		}
		try {
			$run = $this->runs->get( $run_id );
		} catch ( Throwable ) {
			return $this->error( 'workflow_storage_unavailable', 'Workflow runs are temporarily unavailable.' );
		}
		if ( null === $run ) {
			return $this->error( 'run_not_found', 'No workflow run exists for that run_id.' );
		}
		if ( ! WorkflowAbilitySupport::can_view( $run, $auth ) ) {
			return $this->error( 'run_forbidden', 'This workflow run is not available to the connected user.' );
		}

		try {
			$record = $this->definitions->get( $run->workflow_id(), $run->workflow_version(), true );
		} catch ( Throwable ) {
			return $this->error( 'workflow_storage_unavailable', 'Workflow definitions are temporarily unavailable.' );
		}
		if ( null === $record ) {
			return $this->error( 'workflow_definition_missing', 'The pinned workflow definition is no longer available.' );
		}
		if ( ! $this->role_allowed( $record, $auth ) ) {
			return $this->error( 'workflow_forbidden', 'This workflow is not available to the connected user.' );
		}
		$input = $this->input( $args['input'] ?? null );
		if ( is_array( $input ) ) {
			if ( $allow_missing_input && 'input_required' === ( $input['error'] ?? '' ) ) {
				if ( $this->runs instanceof WorkflowRunStore ) {
					try {
						$stored_input = $this->runs->input( $run_id );
						if ( $stored_input instanceof WorkflowInputContract ) {
							$stored_plan = $this->plans->build( $record->definition(), $stored_input );

							return array( $run, $record, $stored_plan );
						}
					} catch ( Throwable ) {
						return $this->error( 'workflow_storage_unavailable', 'Workflow run input is temporarily unavailable.' );
					}
				}
				try {
					$empty_plan = $this->plans->build( $record->definition(), WorkflowInputContract::from_value( (object) array() ) );
					return array( $run, $record, $empty_plan );
				} catch ( Throwable ) {
					return $this->error( 'workflow_input_invalid', 'The pinned workflow definition could not be planned.' );
				}
			}
			return $input;
		}

		try {
			$plan = $this->plans->build( $record->definition(), $input );
		} catch ( Throwable ) {
			return $this->error( 'workflow_input_invalid', 'The supplied input does not match the pinned workflow definition.' );
		}
		if ( ! $allow_missing_input && ! hash_equals( $run->plan_hash(), $plan->hash() ) && WorkflowRunState::WAITING_FOR_INPUT !== $run->state() ) {
			return $this->error( 'plan_binding_mismatch', 'The supplied input does not match the pinned workflow run.' );
		}

		return array( $run, $record, $plan );
	}

	/**
	 * Build readiness evidence after policy and capability filtering.
	 *
	 * @param WorkflowPlan        $plan Exact plan.
	 * @param array<string,mixed> $auth Auth context.
	 * @return array{evidence?:WorkflowReadinessEvidence,error?:array<string,mixed>|null}
	 */
	private function readiness_evidence( WorkflowPlan $plan, array $auth ): array {
		try {
			$availability = $this->available_bindings( $auth );
			$evidence     = $this->readiness->evaluate( $plan, $availability );
		} catch ( Throwable ) {
			return array( 'error' => $this->error( 'workflow_readiness_unavailable', 'Workflow requirements could not be evaluated.' ) );
		}
		if ( null !== $evidence->requirements_error() ) {
			return array(
				'error' => $this->error(
					'workflow_requirements_blocked',
					'One or more workflow requirements are unavailable.',
					array(
						'missing_bindings'  => $evidence->missing_bindings(),
						'missing_abilities' => $evidence->missing_abilities(),
					)
				),
			);
		}

		return array(
			'evidence' => $evidence,
			'error'    => null,
		);
	}

	/**
	 * Return exact adapter bindings allowed for this authenticated context.
	 *
	 * @param array<string,mixed> $auth Auth context.
	 */
	private function available_bindings( array $auth ): WorkflowAvailabilitySnapshot {
		$registry = new AbilitiesRegistry();
		$scopes   = isset( $auth['scopes'] ) && is_array( $auth['scopes'] ) ? $auth['scopes'] : null;
		$allowed  = null;
		try {
			$policy  = ( new McpToolAvailability() )->ability_policy_for_user( (int) $auth['user_id'], $registry, $scopes );
			$allowed = array_fill_keys( array_values( (array) ( $policy['exposed_ability_ids'] ?? array() ) ), true );
		} catch ( Throwable ) {
			$allowed = null;
		}

		$bindings = array();
		foreach ( $this->adapters->descriptors() as $descriptor ) {
			$internal = WorkflowAbilitySupport::internal_ability_id( $descriptor->ability_id() );
			$module   = $registry->module( $internal );
			if ( null === $module || null === $allowed || ! isset( $allowed[ $internal ] ) ) {
				continue;
			}
			if ( ! ( new McpToolAvailability() )->capabilities_available( $internal ) ) {
				continue;
			}
			$bindings[] = array(
				'adapter_id'      => $descriptor->adapter_id(),
				'adapter_version' => $descriptor->adapter_version(),
				'ability_id'      => $descriptor->ability_id(),
				'kind'            => $descriptor->kind(),
			);
		}

		return WorkflowAvailabilitySnapshot::from_value(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => $bindings,
			)
		);
	}

	/**
	 * Start a prepared or approval-waiting run when evidence is complete.
	 *
	 * @param WorkflowRunner                $runner Runner service.
	 * @param WorkflowRunRecord             $run Run record.
	 * @param WorkflowPlan                  $plan Exact workflow plan.
	 * @param WorkflowReadinessEvidence     $readiness Readiness evidence.
	 * @param int                           $actor_id Acting user ID.
	 * @param WorkflowApprovalEvidence|null $approval Approval evidence.
	 * @return WorkflowRunRecord Updated run record.
	 */
	private function start_if_needed( WorkflowRunner $runner, WorkflowRunRecord $run, WorkflowPlan $plan, WorkflowReadinessEvidence $readiness, int $actor_id, ?WorkflowApprovalEvidence $approval ): WorkflowRunRecord {
		if ( WorkflowRunState::PREPARED === $run->state() && array() !== $plan->approval_gate_step_ids() ) {
			$run = $runner->build_dry_run( $run->run_id(), $plan, $actor_id );
			$run = $runner->request_approval( $run->run_id(), $plan, $actor_id );
		}
		if ( WorkflowRunState::DRY_RUN_READY === $run->state() && array() !== $plan->approval_gate_step_ids() ) {
			$run = $runner->request_approval( $run->run_id(), $plan, $actor_id );
		}
		if ( in_array( $run->state(), array( WorkflowRunState::PREPARED, WorkflowRunState::DRY_RUN_READY, WorkflowRunState::WAITING_FOR_APPROVAL ), true ) ) {
			$run = $runner->start( $run->run_id(), $plan, $readiness, $actor_id, $approval );
		}

		return $run;
	}

	/**
	 * Parse caller-supplied approval and bind it to the exact plan.
	 *
	 * @param mixed               $value Approval object.
	 * @param WorkflowPlan        $plan Exact plan.
	 * @param string              $run_id Durable run identifier.
	 * @param array<string,mixed> $auth Authenticated actor context.
	 * @return WorkflowApprovalEvidence|array<string,mixed>|null
	 */
	private function approval( mixed $value, WorkflowPlan $plan, string $run_id, array $auth ): WorkflowApprovalEvidence|array|null {
		return $this->approvals->parse( $value, $plan, $run_id, $auth );
	}

	/**
	 * Convert one runner tick to a public-safe payload.
	 *
	 * @param WorkflowRunExecutionResult $result Runner result.
	 * @param WorkflowPlan               $plan Exact workflow plan.
	 * @return array<string,mixed>
	 */
	private function execution_payload( WorkflowRunExecutionResult $result, WorkflowPlan $plan ): array {
		$payload = array(
			'status'     => 'ok',
			'run'        => $this->run_payload( $result->run(), $plan ),
			'progressed' => $result->progressed(),
			'bounded'    => true,
		);
		if ( null !== $result->step() ) {
			$payload['step'] = $result->step()->to_array();
		}
		if ( null !== $result->adapter_result() ) {
			$payload['step_result'] = array(
				'status' => $result->adapter_result()->succeeded() ? 'succeeded' : 'failed',
				'code'   => $result->adapter_result()->code(),
			);
		}

		return $payload;
	}

	/**
	 * Return a bounded run payload.
	 *
	 * @param WorkflowRunRecord $run  Run record.
	 * @param WorkflowPlan      $plan Exact workflow plan.
	 * @return array<string,mixed>
	 */
	private function run_payload( WorkflowRunRecord $run, WorkflowPlan $plan ): array {
		try {
			$steps = array_map( static fn ( WorkflowStepRecord $step ): array => $step->to_array(), $this->runs->steps( $run->run_id() ) );
		} catch ( Throwable ) {
			$steps = array();
		}

		$payload          = $run->to_array();
		$payload['steps'] = $steps;
		if ( hash_equals( $run->plan_hash(), $plan->hash() ) ) {
			$payload['plan'] = $this->plan_payload( $plan );
		}

		return $payload;
	}

	/**
	 * Return a public-safe plan payload.
	 *
	 * @param WorkflowPlan $plan Exact workflow plan.
	 * @return array<string,mixed>
	 */
	private function plan_payload( WorkflowPlan $plan ): array {
		$identity = $plan->identity();
		$steps    = array();
		foreach ( (array) ( $identity['steps'] ?? array() ) as $raw ) {
			$step = WorkflowAbilitySupport::map( $raw );
			if ( null === $step ) {
				continue;
			}
			$steps[] = array(
				'step_id'         => (string) ( $step['step_id'] ?? '' ),
				'adapter_id'      => (string) ( $step['adapter_id'] ?? '' ),
				'adapter_version' => (int) ( $step['adapter_version'] ?? 0 ),
				'ability_id'      => (string) ( $step['ability_id'] ?? '' ),
				'kind'            => (string) ( $step['kind'] ?? '' ),
				'depends_on'      => array_values( (array) ( $step['depends_on'] ?? array() ) ),
			);
		}

		return array(
			'plan_hash'              => $plan->hash(),
			'definition_checksum'    => $plan->definition_checksum(),
			'definition_revision'    => $plan->definition_revision(),
			'normalized_input_hash'  => $plan->input_hash(),
			'missing_input_paths'    => $plan->missing_paths(),
			'invalid_input_paths'    => $plan->invalid_paths(),
			'ability_requirements'   => $identity['ability_requirements'],
			'adapter_requirements'   => $identity['adapter_requirements'],
			'approval_gate_step_ids' => $plan->approval_gate_step_ids(),
			'validation_rule_ids'    => $identity['validation_rule_ids'],
			'steps'                  => $steps,
			'requires_approval'      => array() !== $plan->approval_gate_step_ids(),
		);
	}

	/**
	 * Return a public-safe workflow definition.
	 *
	 * @param WorkflowDefinitionRecord $record Published definition record.
	 * @return array<string,mixed>
	 */
	private function safe_definition( WorkflowDefinitionRecord $record ): array {
		$value = $record->definition()->to_array();
		$steps = array();
		foreach ( (array) ( $value['steps'] ?? array() ) as $raw ) {
			$step = WorkflowAbilitySupport::map( $raw );
			if ( null !== $step ) {
				$steps[] = array(
					'step_id'         => (string) ( $step['step_id'] ?? '' ),
					'adapter_id'      => (string) ( $step['adapter_id'] ?? '' ),
					'adapter_version' => (int) ( $step['adapter_version'] ?? 0 ),
					'ability_id'      => (string) ( $step['ability_id'] ?? '' ),
					'kind'            => (string) ( $step['kind'] ?? '' ),
					'depends_on'      => array_values( (array) ( $step['depends_on'] ?? array() ) ),
				);
			}
		}
		$value['steps'] = $steps;
		unset( $value['status'], $value['created_by'], $value['updated_by'] );

		return array(
			'workflow_id'       => $record->workflow_id(),
			'workflow_version'  => (int) ( $value['workflow_version'] ?? $record->latest_version() ),
			'catalog_status'    => $record->status(),
			'published_version' => $record->published_version(),
			'template_id'       => $record->template_id(),
			'template_version'  => $record->template_version(),
			'definition'        => $value,
			'checksum'          => $record->definition()->checksum(),
		);
	}

	/**
	 * Return readiness details for a plan.
	 *
	 * @param WorkflowPlan        $plan Exact workflow plan.
	 * @param array<string,mixed> $auth Auth context.
	 * @return array<string,mixed>
	 */
	private function readiness_payload( WorkflowPlan $plan, array $auth ): array {
		$result = $this->readiness_evidence( $plan, $auth );
		if ( null !== ( $result['error'] ?? null ) ) {
			return array(
				'status' => 'blocked',
				'error'  => $result['error']['error'] ?? 'workflow_requirements_blocked',
			);
		}
		$evidence = $result['evidence'] ?? null;
		if ( ! $evidence instanceof WorkflowReadinessEvidence ) {
			return array(
				'status' => 'blocked',
				'error'  => 'workflow_readiness_unavailable',
			);
		}

		return array(
			'status'             => 'ready',
			'missing_bindings'   => $evidence->missing_bindings(),
			'missing_abilities'  => $evidence->missing_abilities(),
			'validation_checked' => null === $evidence->validation_error_for( $plan ),
		);
	}

	/**
	 * Return bounded risk details for a plan.
	 *
	 * @param WorkflowPlan $plan Exact workflow plan.
	 * @return array<string,mixed>
	 */
	private function risk_payload( WorkflowPlan $plan ): array {
		$counts = array(
			'read'     => 0,
			'proposal' => 0,
			'write'    => 0,
		);
		foreach ( (array) ( $plan->identity()['steps'] ?? array() ) as $raw ) {
			$step = WorkflowAbilitySupport::map( $raw );
			$kind = null === $step ? '' : (string) ( $step['kind'] ?? '' );
			if ( isset( $counts[ $kind ] ) ) {
				++$counts[ $kind ];
			}
		}

		return array(
			'step_counts'          => $counts,
			'approval_required'    => array() !== $plan->approval_gate_step_ids(),
			'write_steps'          => array_values( array_filter( array_keys( $counts ), fn ( string $kind ): bool => 'write' === $kind && $counts[ $kind ] > 0 ) ),
			'no_publish_or_delete' => true,
		);
	}

	/**
	 * Return next actions for a prepared plan.
	 *
	 * @param WorkflowPlan $plan Exact workflow plan.
	 * @return list<string>
	 */
	private function prepare_actions( WorkflowPlan $plan ): array {
		if ( array() !== $plan->missing_paths() ) {
			return array( 'Provide the missing input paths and call content_workflow_resume.' );
		}
		if ( array() !== $plan->invalid_paths() ) {
			return array( 'Correct the invalid input paths before resubmitting the workflow.' );
		}

		return array( 'Call content_workflow_dry_run, then content_workflow_execute after reviewing any approval gates.' );
	}

	/**
	 * Return an approval-required response.
	 *
	 * @param WorkflowRunRecord   $run  Run record.
	 * @param WorkflowPlan        $plan Exact workflow plan.
	 * @param array<string,mixed> $auth Authenticated actor context.
	 * @return array<string,mixed>
	 */
	private function approval_required( WorkflowRunRecord $run, WorkflowPlan $plan, array $auth ): array {
		$payload = array(
			'status'            => 'blocked',
			'error'             => 'approval_required',
			'message'           => 'Explicit approval is required for the planned write steps.',
			'run'               => $this->run_payload( $run, $plan ),
			'approval_gate_ids' => $plan->approval_gate_step_ids(),
			'dry_run'           => WorkflowDryRun::from_plan( $plan )->to_array(),
			'bounded'           => true,
		);

		return $this->approvals->add_token_payload( $payload, $run->run_id(), $plan, $auth );
	}

	/**
	 * Return a non-mutating cancellation projection.
	 *
	 * @param WorkflowRunRecord $run  Durable run record.
	 * @param WorkflowPlan      $plan Exact pinned plan.
	 * @return array<string,mixed>
	 */
	private function cancel_preview( WorkflowRunRecord $run, WorkflowPlan $plan ): array {
		return array(
			'status'       => 'ok',
			'run'          => $this->run_payload( $run, $plan ),
			'dry_run'      => WorkflowDryRun::from_plan( $plan )->to_array(),
			'message'      => 'Cancellation preview only; the workflow run and its steps were not changed.',
			'next_actions' => array( 'Repeat content_workflow_cancel without dry_run after confirming the cancellation boundary.' ),
			'bounded'      => true,
		);
	}

	/**
	 * Return an input-required response.
	 *
	 * @param WorkflowRunRecord $run Run record.
	 * @param WorkflowPlan      $plan Exact workflow plan.
	 * @return array<string,mixed>
	 */
	private function input_required( WorkflowRunRecord $run, WorkflowPlan $plan ): array {
		return array(
			'status'              => 'blocked',
			'error'               => 'input_required',
			'message'             => 'The workflow needs the missing input fields before it can continue.',
			'run'                 => $this->run_payload( $run, $plan ),
			'missing_input_paths' => $plan->missing_paths(),
			'bounded'             => true,
		);
	}

	/**
	 * Return a terminal-run response.
	 *
	 * @param WorkflowRunRecord $run Run record.
	 * @param WorkflowPlan      $plan Exact workflow plan.
	 * @return array<string,mixed>
	 */
	private function terminal_payload( WorkflowRunRecord $run, WorkflowPlan $plan ): array {
		return array(
			'status'  => 'ok',
			'run'     => $this->run_payload( $run, $plan ),
			'bounded' => true,
		);
	}

	/**
	 * Return an authentication error response.
	 *
	 * @return array<string,mixed>
	 */
	private function auth_error(): array {
		return $this->error( 'auth_unavailable', 'The workflow connector must be called through an authenticated MCP connection.' );
	}

	/**
	 * Convert a runner exception to a bounded error response.
	 *
	 * @param WorkflowRunnerException $exception Runner exception.
	 * @return array<string,mixed>
	 */
	private function runner_error( WorkflowRunnerException $exception ): array {
		$code     = $exception->error_code();
		$messages = array(
			'run_not_found'          => 'No workflow run exists for that run_id.',
			'plan_binding_mismatch'  => 'The supplied input does not match the pinned workflow plan.',
			'input_incomplete'       => 'The workflow input is incomplete.',
			'approval_required'      => 'Explicit approval is required for the planned write steps.',
			'approval_mismatch'      => 'Approval evidence does not match this workflow plan.',
			'requirements_unchecked' => 'Workflow requirements have not been verified.',
			'requirements_blocked'   => 'One or more workflow requirements are unavailable.',
			'invalid_state'          => 'The workflow is not in a state that supports this operation.',
			'cancel_not_allowed'     => 'The workflow is not at a proven safe cancellation boundary.',
		);

		return $this->error( 'workflow_' . $code, $messages[ $code ] ?? 'The workflow operation could not be completed.' );
	}

	/**
	 * Build a bounded connector error.
	 *
	 * @param string              $code Error code.
	 * @param string              $message Public-safe message.
	 * @param array<string,mixed> $extra Additional safe fields.
	 * @return array<string,mixed>
	 */
	private function error( string $code, string $message, array $extra = array() ): array {
		return array_merge(
			array(
				'status'  => 'error',
				'error'   => $code,
				'message' => $message,
				'bounded' => true,
			),
			$extra
		);
	}

	/**
	 * Convert a public input value to a validated contract.
	 *
	 * @param mixed $value Candidate input object.
	 * @return WorkflowInputContract|array<string,mixed>
	 */
	private function input( mixed $value ): WorkflowInputContract|array {
		if ( $value instanceof stdClass ) {
			try {
				return WorkflowInputContract::from_value( $value );
			} catch ( Throwable ) {
				return $this->error( 'input_invalid', 'Workflow input must be a bounded JSON object.' );
			}
		}
		if ( is_array( $value ) && ! array_is_list( $value ) ) {
			try {
				return WorkflowInputContract::from_value( $value );
			} catch ( Throwable ) {
				return $this->error( 'input_invalid', 'Workflow input must be a bounded JSON object.' );
			}
		}

		return $this->error( 'input_required', 'Provide the workflow input object.' );
	}
}

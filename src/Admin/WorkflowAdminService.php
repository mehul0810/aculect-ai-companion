<?php
/**
 * Application service for the template-first workflow admin surface.
 *
 * @package Aculect\AICompanion\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterCatalog;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterDescriptor;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionCompatibilityEvaluator;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRecord;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRepository;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRepositoryException;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionValidationException;
use Throwable;

/**
 * Translates guided admin fields into validated immutable workflow versions.
 *
 * This service deliberately accepts only a closed adapter catalog. It does
 * not evaluate arbitrary PHP/JS, permit publish/delete abilities, or bypass
 * the definition validator and repository concurrency checks.
 */
final class WorkflowAdminService {

	private WorkflowTemplateCatalog $templates;
	private WorkflowDefinitionRepository $repository;

	/**
	 * Create the service with repository and template collaborators.
	 *
	 * @param WorkflowDefinitionRepository|null $repository Definition repository.
	 * @param WorkflowTemplateCatalog|null      $templates Starter catalog.
	 */
	public function __construct( ?WorkflowDefinitionRepository $repository = null, ?WorkflowTemplateCatalog $templates = null ) {
		$this->repository = $repository ?? new WorkflowDefinitionRepository();
		$this->templates  = $templates ?? new WorkflowTemplateCatalog();
	}

	/**
	 * Return starter templates for the page.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function templates(): array {
		return $this->templates->all();
	}

	/**
	 * Return the closed adapter catalog for guided step selection.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function adapters(): array {
		return array_map(
			static fn ( WorkflowAdapterDescriptor $descriptor ): array => $descriptor->to_array(),
			WorkflowAdapterCatalog::descriptors()
		);
	}

	/**
	 * List latest workflow versions for the admin table.
	 *
	 * @return array{records:list<WorkflowDefinitionRecord>,error:string|null}
	 */
	public function list_records(): array {
		try {
			return array(
				'records' => $this->repository->list(
					array(
						'per_page'         => 100,
						'include_disabled' => true,
					)
				),
				'error'   => null,
			);
		} catch ( Throwable ) {
			return array(
				'records' => array(),
				'error'   => 'Workflow definitions are temporarily unavailable.',
			);
		}
	}

	/**
	 * Read one latest definition for editing.
	 *
	 * @param string $workflow_id Stable workflow identifier.
	 * @return WorkflowDefinitionRecord|null
	 */
	public function record( string $workflow_id ): ?WorkflowDefinitionRecord {
		try {
			return $this->repository->get( $workflow_id, null, true );
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Validate guided fields and persist a new immutable version.
	 *
	 * @param array<string,mixed> $submitted Sanitized form values.
	 * @param int                 $actor_id Acting administrator.
	 * @return array{ok:bool,record?:WorkflowDefinitionRecord,errors?:array<string,string>,error?:string}
	 */
	public function save( array $submitted, int $actor_id ): array {
		if ( $actor_id < 1 ) {
			return array(
				'ok'     => false,
				'errors' => array( 'form' => 'A valid administrator is required.' ),
			);
		}

		$workflow_id = $this->identifier( $submitted['workflow_id'] ?? '' );
		$existing    = '' === $workflow_id ? null : $this->record( $workflow_id );
		$expected    = max( 0, (int) ( $submitted['expected_version'] ?? 0 ) );
		if ( null !== $existing && 0 === $expected ) {
			$expected = $existing->latest_version();
		}
		if ( null === $existing && $expected > 0 ) {
			return array(
				'ok'     => false,
				'errors' => array( 'workflow_id' => 'The workflow could not be found for this versioned edit.' ),
			);
		}

		try {
			$definition = $this->definition_from_input( $submitted, $actor_id, $existing );
			$template   = $this->template_key( $submitted['template_id'] ?? '', $existing );
			$record     = null === $existing
				? $this->repository->create( $definition, $template, 1 )
				: $this->repository->update( $definition, $expected, $template, 1 );

			return array(
				'ok'     => true,
				'record' => $record,
			);
		} catch ( WorkflowAdminValidationException $exception ) {
			return array(
				'ok'     => false,
				'errors' => $exception->errors(),
			);
		} catch ( WorkflowDefinitionValidationException $exception ) {
			return array(
				'ok'     => false,
				'errors' => array( 'form' => 'Definition validation failed at ' . $exception->error_path() . '.' ),
			);
		} catch ( WorkflowDefinitionRepositoryException $exception ) {
			$message = match ( $exception->error_code() ) {
				'workflow_version_conflict' => 'Someone else saved this workflow. Reload it before saving again.',
				'workflow_already_exists'   => 'That workflow ID is already in use.',
				'workflow_disabled'          => 'Disabled workflows must be restored outside this guided editor.',
				default                     => 'The workflow could not be saved right now.',
			};

			return array(
				'ok'     => false,
				'errors' => array( 'form' => $message ),
			);
		} catch ( Throwable ) {
			return array(
				'ok'     => false,
				'errors' => array( 'form' => 'The workflow could not be saved right now.' ),
			);
		}
	}

	/**
	 * Disable a workflow using an optimistic version check.
	 *
	 * @param string $workflow_id Stable workflow identifier.
	 * @param int    $actor_id Acting administrator.
	 * @param int    $expected_version Latest version shown by the form.
	 * @return array{ok:bool,record?:WorkflowDefinitionRecord,errors?:array<string,string>}
	 */
	public function disable( string $workflow_id, int $actor_id, int $expected_version ): array {
		$workflow_id = $this->identifier( $workflow_id );
		if ( '' === $workflow_id || $actor_id < 1 || $expected_version < 1 ) {
			return array(
				'ok'     => false,
				'errors' => array( 'form' => 'The workflow identity or version is invalid.' ),
			);
		}

		try {
			$record = $this->repository->disable( $workflow_id, $actor_id, $expected_version );
			if ( null === $record ) {
				return array(
					'ok'     => false,
					'errors' => array( 'form' => 'The workflow could not be found.' ),
				);
			}

			return array(
				'ok'     => true,
				'record' => $record,
			);
		} catch ( WorkflowDefinitionRepositoryException $exception ) {
			$message = 'workflow_version_conflict' === $exception->error_code()
				? 'Someone else changed this workflow. Reload it before disabling.'
				: 'The workflow could not be disabled right now.';
			return array(
				'ok'     => false,
				'errors' => array( 'form' => $message ),
			);
		} catch ( Throwable ) {
			return array(
				'ok'     => false,
				'errors' => array( 'form' => 'The workflow could not be disabled right now.' ),
			);
		}
	}

	/**
	 * Preview the bounded compatibility impact of an edit.
	 *
	 * @param array<string,mixed>      $submitted Guided form values.
	 * @param int                      $actor_id Acting administrator.
	 * @param WorkflowDefinitionRecord $existing Existing latest record.
	 * @return array<string,mixed>|null Compatibility report, or null when invalid.
	 */
	public function compatibility_preview( array $submitted, int $actor_id, WorkflowDefinitionRecord $existing ): ?array {
		try {
			$target = $this->definition_from_input( $submitted, $actor_id, $existing );

			return ( new WorkflowDefinitionCompatibilityEvaluator() )->evaluate( $existing->definition(), $target )->to_array();
		} catch ( Throwable ) {
			return null;
		}
	}

	/**
	 * Build and validate one definition from guided fields.
	 *
	 * @param array<string,mixed>           $submitted Guided form values.
	 * @param int                           $actor_id Acting administrator.
	 * @param WorkflowDefinitionRecord|null $existing Existing latest record.
	 * @throws WorkflowAdminValidationException When guided fields or the closed definition contract rejects values.
	 */
	public function definition_from_input( array $submitted, int $actor_id, ?WorkflowDefinitionRecord $existing = null ): WorkflowDefinition {
		$errors      = array();
		$template_id = $this->template_key( $submitted['template_id'] ?? '', $existing );
		$template    = $this->templates->get( $template_id );
		if ( null === $template ) {
			$template = $this->templates->get( 'blank' ) ?? array();
		}
		$workflow_id  = $this->identifier( $submitted['workflow_id'] ?? ( $existing?->workflow_id() ?? '' ) );
		$name         = $this->bounded_text( $submitted['name'] ?? ( $template['label'] ?? '' ), 120 );
		$description  = $this->bounded_text( $submitted['description'] ?? ( $template['description'] ?? '' ), 1000 );
		$status_value = (string) ( $submitted['status'] ?? 'draft' );
		$status       = in_array( $status_value, array( 'draft', 'published' ), true ) ? $status_value : 'draft';
		$mode_value   = (string) ( $submitted['target_mode'] ?? ( $template['target_mode'] ?? 'either' ) );
		$mode         = in_array( $mode_value, array( 'new', 'existing', 'either' ), true ) ? $mode_value : 'either';
		$post_types   = $this->post_types( $submitted['post_types'] ?? ( $template['post_types'] ?? array() ) );
		$fields       = $this->input_fields( $submitted['input_fields'] ?? ( $template['input_fields'] ?? array() ), $errors );
		$steps        = $this->steps( $submitted['step_abilities'] ?? ( $template['step_abilities'] ?? array() ), $errors );
		$write_value  = (string) ( $submitted['write_policy'] ?? ( $template['write_policy'] ?? 'proposal_only' ) );
		$write_mode   = in_array( $write_value, array( 'proposal_only', 'draft_only', 'approved_update' ), true ) ? $write_value : 'proposal_only';

		if ( '' === $workflow_id ) {
			$errors['workflow_id'] = 'Use 3–64 lowercase letters, numbers, underscores, or hyphens.';
		}
		if ( '' === $name ) {
			$errors['name'] = 'Add a workflow name.';
		}
		if ( '' === $description ) {
			$errors['description'] = 'Add a short description.';
		}
		if ( array() === $post_types ) {
			$errors['post_types'] = 'Choose at least one public post type.';
		}
		if ( array() === $steps && ! isset( $errors['step_abilities'] ) ) {
			$errors['step_abilities'] = 'Choose at least one supported ability.';
		}
		if ( 'proposal_only' === $write_mode && $this->has_write_step( $steps ) ) {
			$errors['write_policy'] = 'Proposal-only workflows cannot include write abilities.';
		}
		if ( array() !== $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Errors are bounded field keys and fixed messages.
			throw new WorkflowAdminValidationException( $errors );
		}

		$version    = null === $existing ? 1 : $existing->latest_version() + 1;
		$created_by = null === $existing ? $actor_id : (int) ( $existing->definition()->to_array()['created_by'] ?? $existing->created_by() );
		$definition = array(
			'definition_schema_version' => 1,
			'workflow_id'               => $workflow_id,
			'workflow_version'          => $version,
			'name'                      => $name,
			'description'               => $description,
			'content_target'            => array(
				'mode'       => $mode,
				'post_types' => $post_types,
			),
			'input_schema'              => $fields,
			'steps'                     => $steps,
			'allowed_abilities'         => array_values( array_unique( array_map( static fn ( array $step ): string => (string) $step['ability_id'], $steps ) ) ),
			'write_policy'              => array( 'mode' => $write_mode ),
			'approval_gates'            => array_values( array_map( static fn ( array $step ): string => (string) $step['step_id'], array_filter( $steps, static fn ( array $step ): bool => 'write' === $step['kind'] ) ) ),
			'output_contract'           => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'status'          => array( 'type' => 'string' ),
					'summary'         => array(
						'type'      => 'string',
						'maxLength' => 1000,
					),
					'steps_completed' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
			),
			'validation_rules'          => array(),
			'status'                    => $status,
			'created_by'                => $created_by,
			'updated_by'                => $actor_id,
			'compatibility'             => array(
				'input_contract_version'  => 1,
				'output_contract_version' => 1,
			),
		);

		return WorkflowDefinition::from_array( $definition );
	}

	/**
	 * Resolve a safe template key.
	 *
	 * @param mixed                         $value Submitted key.
	 * @param WorkflowDefinitionRecord|null $existing Existing record.
	 */
	private function template_key( mixed $value, ?WorkflowDefinitionRecord $existing ): string {
		$key = sanitize_key( (string) $value );
		if ( null !== $existing && '' === $key ) {
			$key = sanitize_key( $existing->template_id() );
		}

		return null !== $this->templates->get( $key ) ? $key : 'blank';
	}

	/**
	 * Convert a field list into a bounded object schema.
	 *
	 * @param mixed                $value Field lines or a list of field lines.
	 * @param array<string,string> $errors Validation errors by field.
	 * @return array<string,mixed>
	 */
	private function input_fields( mixed $value, array &$errors ): array {
		$lines    = is_array( $value ) ? $value : preg_split( '/\r?\n/', (string) $value );
		$schema   = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		);
		$required = array();
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			$parts = array_map( 'trim', explode( ':', $line ) );
			if ( count( $parts ) < 2 || 1 !== preg_match( '/^[a-z][a-z0-9_]{1,63}$/D', $parts[0] ) || ! in_array( $parts[1], array( 'string', 'integer', 'number', 'boolean' ), true ) ) {
				$errors['input_fields'] = 'Inputs must use field:type[:required] with a supported primitive type.';
				continue;
			}
			if ( isset( $schema['properties'][ $parts[0] ] ) ) {
				$errors['input_fields'] = 'Each input field may appear only once.';
				continue;
			}
			$schema['properties'][ $parts[0] ] = array( 'type' => $parts[1] );
			if ( isset( $parts[2] ) && 'required' === strtolower( $parts[2] ) ) {
				$required[] = $parts[0];
			}
		}
		if ( array() !== $required ) {
			$schema['required'] = array_values( array_unique( $required ) );
		}

		return $schema;
	}

	/**
	 * Resolve ordered step abilities from the closed adapter catalog.
	 *
	 * @param mixed                $value Ability lines.
	 * @param array<string,string> $errors Validation errors by field.
	 * @return list<array<string,mixed>>
	 */
	private function steps( mixed $value, array &$errors ): array {
		$lines       = is_array( $value ) ? $value : preg_split( '/\r?\n|,/', (string) $value );
		$descriptors = array();
		foreach ( WorkflowAdapterCatalog::descriptors() as $descriptor ) {
			$descriptors[ $descriptor->ability_id() ] ??= $descriptor;
		}

		$steps = array();
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$ability_id = strtolower( trim( (string) $line ) );
			if ( '' === $ability_id ) {
				continue;
			}
			$descriptor = $descriptors[ $ability_id ] ?? null;
			if ( ! $descriptor instanceof WorkflowAdapterDescriptor ) {
				$errors['step_abilities'] = 'Every step must use an ability from the supported catalog.';
				continue;
			}
			$step_id = 'step_' . ( count( $steps ) + 1 );
			$steps[] = array(
				'step_id'         => $step_id,
				'adapter_id'      => $descriptor->adapter_id(),
				'adapter_version' => $descriptor->adapter_version(),
				'ability_id'      => $descriptor->ability_id(),
				'kind'            => $descriptor->kind(),
				'arguments'       => array(),
				'depends_on'      => 0 === count( $steps ) ? array() : array( 'step_' . count( $steps ) ),
			);
		}

		return $steps;
	}

	/**
	 * Normalize post types without permitting arbitrary object access.
	 *
	 * @param mixed $value Post-type list or CSV.
	 * @return list<string>
	 */
	private function post_types( mixed $value ): array {
		$values = is_array( $value ) ? $value : preg_split( '/\r?\n|,/', (string) $value );
		$known  = function_exists( 'get_post_types' ) ? array_map( 'strval', (array) get_post_types( array( 'public' => true ), 'names' ) ) : array();
		$result = array();
		foreach ( is_array( $values ) ? $values : array() as $item ) {
			$key = sanitize_key( trim( (string) $item ) );
			if ( '' !== $key && ( array() === $known || in_array( $key, $known, true ) ) ) {
				$result[] = $key;
			}
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * Determine whether a step list contains a write adapter.
	 *
	 * @param list<array<string,mixed>> $steps Workflow steps.
	 */
	private function has_write_step( array $steps ): bool {
		foreach ( $steps as $step ) {
			if ( 'write' === (string) ( $step['kind'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	private function identifier( mixed $value ): string {
		$value = sanitize_key( trim( (string) $value ) );

		return 1 === preg_match( '/^[a-z][a-z0-9_-]{2,63}$/D', $value ) ? $value : '';
	}

	private function bounded_text( mixed $value, int $max ): string {
		$value = sanitize_text_field( (string) $value );

		return '' === $value ? '' : substr( $value, 0, $max );
	}
}

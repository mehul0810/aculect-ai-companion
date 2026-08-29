<?php
/**
 * Template-first custom workflow administration page.
 *
 * @package Aculect\AICompanion\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRecord;
use Aculect\AICompanion\Workflows\Execution\WorkflowAuditRecord;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a small guided admin surface without arbitrary workflow code.
 */
final class WorkflowAdminPage {

	private const PAGE_SLUG      = 'aculect-ai-companion-workflows';
	private const SAVE_ACTION    = 'aculect_ai_companion_save_workflow';
	private const DISABLE_ACTION = 'aculect_ai_companion_disable_workflow';
	private const SAVE_NONCE     = 'aculect_workflow_save';
	private const DISABLE_NONCE  = 'aculect_workflow_disable';
	private const CAPABILITY     = 'manage_options';

	private WorkflowAdminService $service;
	/**
	 * Form values retained when validation fails.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $form_values = null;
	/**
	 * Bounded validation errors for the current form.
	 *
	 * @var array<string,string>
	 */
	private array $form_errors = array();
	private ?string $notice    = null;

	public function __construct( ?WorkflowAdminService $service = null ) {
		$this->service = $service ?? new WorkflowAdminService();
	}

	/** Register menu and mutation handlers. */
	public function register(): void {
		add_submenu_page(
			'options-general.php',
			__( 'Content Workflows', 'aculect-ai-companion' ),
			__( 'Content Workflows', 'aculect-ai-companion' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::DISABLE_ACTION, array( $this, 'handle_disable' ) );
	}

	/** Render the list and guided editor. */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'aculect-ai-companion' ) );
		}

		$list      = $this->service->list_records();
		$edit_id   = sanitize_key( (string) ( $_GET['workflow_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin selection.
		$record    = '' === $edit_id ? null : $this->service->record( $edit_id );
		$values    = null !== $this->form_values ? $this->form_values : $this->values_for_record( $record );
		$templates = $this->service->templates();
		$adapters  = $this->service->adapters();
		$roles     = $this->service->roles();
		$audit     = $this->service->recent_audit();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Content Workflows', 'aculect-ai-companion' ) . '</h1>';
		echo '<p>' . esc_html__( 'Create bounded, versioned workflows from approved starters. Publishing and deletion are never exposed as workflow abilities.', 'aculect-ai-companion' ) . '</p>';
		$this->render_notice( $list['error'] ?? null );
		$this->render_notice( $this->notice );

		$this->render_list( $list['records'] );
		$this->render_audit( $audit['events'], $audit['error'] ?? null );
		$this->render_editor( $values, $templates, $adapters, $roles, $record );
		echo '</div>';
	}

	/** Persist a new workflow version after nonce/capability checks. */
	public function handle_save(): void {
		$this->assert_admin_request( self::SAVE_NONCE );
		$submitted           = $this->submitted_values();
		$status              = sanitize_key( (string) $this->post_value( 'save_status', 'draft' ) );
		$submitted['status'] = in_array( $status, array( 'draft', 'published' ), true ) ? $status : 'draft';
		$result              = $this->service->save( $submitted, get_current_user_id() );
		if ( ! $result['ok'] ) {
			$this->form_values = $submitted;
			$this->form_errors = isset( $result['errors'] ) ? $result['errors'] : array( 'form' => 'The workflow could not be saved.' );
			$this->render();
			return;
		}

		$record = $result['record'] ?? null;
		if ( $record instanceof WorkflowDefinitionRecord ) {
			$this->redirect(
				array(
					'workflow_id' => $record->workflow_id(),
					'updated'     => '1',
				)
			);
		}
	}

	/** Disable a workflow without deleting its immutable history. */
	public function handle_disable(): void {
		$this->assert_admin_request( self::DISABLE_NONCE );
		$workflow_id = sanitize_key( (string) $this->post_value( 'workflow_id', '' ) );
		$version     = absint( $this->post_value( 'expected_version', 0 ) );
		$result      = $this->service->disable( $workflow_id, get_current_user_id(), $version );
		if ( ! $result['ok'] ) {
			$this->notice = (string) ( $result['errors']['form'] ?? 'The workflow could not be disabled.' );
			$this->render();
			return;
		}

		$this->redirect( array( 'disabled' => '1' ) );
	}

	/**
	 * Validate the current admin request.
	 *
	 * @param string $action Nonce action.
	 */
	private function assert_admin_request( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'aculect-ai-companion' ) );
		}
		$nonce = sanitize_text_field( (string) $this->post_value( '_wpnonce', '' ) );
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'aculect-ai-companion' ) );
		}
	}

	/**
	 * Extract and lightly normalize form fields; the service owns validation.
	 *
	 * @return array<string,mixed>
	 */
	private function submitted_values(): array {
		$allowed_roles = $this->post_value( 'allowed_roles', array() );
		if ( is_array( $allowed_roles ) ) {
			$allowed_roles = array_map(
				static fn ( mixed $role ): mixed => is_scalar( $role ) ? sanitize_key( (string) $role ) : $role,
				$allowed_roles
			);
		}

		return array(
			'workflow_id'      => sanitize_key( (string) $this->post_value( 'workflow_id', '' ) ),
			'expected_version' => absint( $this->post_value( 'expected_version', 0 ) ),
			'template_id'      => sanitize_key( (string) $this->post_value( 'template_id', 'blank' ) ),
			'name'             => sanitize_text_field( (string) $this->post_value( 'name', '' ) ),
			'description'      => sanitize_text_field( (string) $this->post_value( 'description', '' ) ),
			'target_mode'      => sanitize_key( (string) $this->post_value( 'target_mode', 'either' ) ),
			'post_types'       => (string) $this->post_value( 'post_types', '' ),
			'input_fields'     => (string) $this->post_value( 'input_fields', '' ),
			'step_abilities'   => (string) $this->post_value( 'step_abilities', '' ),
			'step_arguments'   => substr( (string) $this->post_value( 'step_arguments', '' ), 0, 32768 ),
			'write_policy'     => sanitize_key( (string) $this->post_value( 'write_policy', 'proposal_only' ) ),
			'allowed_roles'    => $allowed_roles,
		);
	}

	/**
	 * Read one request value after removing WordPress request slashes.
	 *
	 * @param string $key     Request key.
	 * @param mixed  $default Fallback value.
	 * @return mixed Unslashed request value or fallback.
	 */
	private function post_value( string $key, mixed $default = '' ): mixed {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the mutation nonce before reading request values.
		return array_key_exists( $key, $_POST ) ? wp_unslash( $_POST[ $key ] ) : $default;
	}

	/**
	 * Render the current workflow table.
	 *
	 * @param array<int,WorkflowDefinitionRecord> $records Records to render.
	 */
	private function render_list( array $records ): void {
		echo '<h2>' . esc_html__( 'Saved workflows', 'aculect-ai-companion' ) . '</h2>';
		if ( array() === $records ) {
			echo '<p>' . esc_html__( 'No workflows yet. Start with a template below.', 'aculect-ai-companion' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Workflow</th><th>Status</th><th>Version</th><th>Availability</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $records as $record ) {
			$value = $record->definition()->to_array();
			echo '<tr><td><strong>' . esc_html( (string) ( $value['name'] ?? $record->workflow_id() ) ) . '</strong><br><code>' . esc_html( $record->workflow_id() ) . '</code></td>';
			echo '<td>' . esc_html( $record->status() ) . '</td><td>' . esc_html( (string) $record->latest_version() ) . '</td><td>' . ( $record->published_version() > 0 && 'disabled' !== $record->status() ? esc_html__( 'Published to connected assistants', 'aculect-ai-companion' ) : esc_html__( 'Draft only', 'aculect-ai-companion' ) ) . '</td><td><a class="button" href="' . esc_url( $this->page_url( array( 'workflow_id' => $record->workflow_id() ) ) ) . '">' . esc_html__( 'Edit', 'aculect-ai-companion' ) . '</a> ';
			if ( 'disabled' !== $record->status() ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
				wp_nonce_field( self::DISABLE_NONCE );
				echo '<input type="hidden" name="action" value="' . esc_attr( self::DISABLE_ACTION ) . '"><input type="hidden" name="workflow_id" value="' . esc_attr( $record->workflow_id() ) . '"><input type="hidden" name="expected_version" value="' . esc_attr( (string) $record->latest_version() ) . '"><button class="button-link-delete" type="submit">' . esc_html__( 'Disable', 'aculect-ai-companion' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render the guided editor.
	 *
	 * @param array<string,mixed>                 $values Form values.
	 * @param array<string,array<string,mixed>>   $templates Templates.
	 * @param list<array<string,mixed>>           $adapters Adapter descriptors.
	 * @param list<array{id:string,label:string}> $roles Registered roles.
	 * @param WorkflowDefinitionRecord|null       $record Existing record.
	 */
	private function render_editor( array $values, array $templates, array $adapters, array $roles, ?WorkflowDefinitionRecord $record ): void {
		echo '<hr><h2>' . esc_html( null === $record ? __( 'Add workflow', 'aculect-ai-companion' ) : __( 'Edit workflow', 'aculect-ai-companion' ) ) . '</h2>';
		if ( null !== $record && $record->published_version() > 0 ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Saving creates the next immutable version. Existing runs stay pinned to their original checksum; review compatibility before publishing.', 'aculect-ai-companion' ) . '</p></div>';
			$preview = $this->service->migration_preview( $values, get_current_user_id(), $record );
			if ( is_array( $preview ) ) {
				$change_codes = array_map(
					static fn ( mixed $change ): string => is_array( $change ) ? (string) ( $change['code'] ?? '' ) : '',
					(array) ( $preview['actions'] ?? array() )
				);
				echo '<p><strong>' . esc_html__( 'Migration preview:', 'aculect-ai-companion' ) . '</strong> ' . esc_html( (string) ( $preview['status'] ?? 'unavailable' ) ) . ( isset( $preview['migration_id'] ) ? ' · ' . esc_html( substr( (string) $preview['migration_id'], 0, 12 ) ) : '' ) . ( array() !== array_filter( $change_codes ) ? ' — ' . esc_html( implode( ', ', array_filter( $change_codes ) ) ) : '' ) . '</p>';
			}
		}
		if ( array() !== $this->form_errors ) {
			echo '<div class="notice notice-error inline"><ul>';
			foreach ( $this->form_errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::SAVE_NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '"><input type="hidden" name="expected_version" value="' . esc_attr( (string) ( $values['expected_version'] ?? 0 ) ) . '">';
		echo '<table class="form-table"><tbody>';
		$this->field( 'workflow_id', __( 'Workflow ID', 'aculect-ai-companion' ), $values['workflow_id'] ?? '', 'Stable lowercase ID; leave blank only for a new workflow.' );
		echo '<tr><th><label for="aculect-workflow-template">' . esc_html__( 'Starter template', 'aculect-ai-companion' ) . '</label></th><td><select id="aculect-workflow-template" name="template_id">';
		foreach ( $templates as $id => $template ) {
			echo '<option value="' . esc_attr( $id ) . '"' . ( (string) ( $values['template_id'] ?? 'blank' ) === $id ? ' selected' : '' ) . '>' . esc_html( (string) ( $template['label'] ?? $id ) ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Templates provide safe defaults; all fields remain editable below.', 'aculect-ai-companion' ) . '</p></td></tr>';
		$this->field( 'name', __( 'Name', 'aculect-ai-companion' ), $values['name'] ?? '', 'Shown to administrators and assistants.' );
		$this->field( 'description', __( 'Description', 'aculect-ai-companion' ), $values['description'] ?? '', 'Explain the intended outcome and review boundary.' );
		$this->field( 'post_types', __( 'Content target', 'aculect-ai-companion' ), $values['post_types'] ?? '', 'Comma- or line-separated public post types, for example post or page.' );
		$this->field( 'input_fields', __( 'Inputs', 'aculect-ai-companion' ), $values['input_fields'] ?? '', 'One per line: field:type[:required]. Types: string, integer, number, boolean.' );
		$this->field( 'step_abilities', __( 'Steps', 'aculect-ai-companion' ), $values['step_abilities'] ?? '', 'One supported ability per line, in execution order. Dependencies are generated from this order.' );
		$this->field( 'step_arguments', __( 'Step arguments (optional JSON)', 'aculect-ai-companion' ), $values['step_arguments'] ?? '', 'Optional object keyed by step_1, position, or ability ID. Use {{input.field}} and {{steps.step_id.output.field}} for typed runtime bindings. Empty values receive safe input bindings automatically.' );
		echo '<tr><th><span>' . esc_html__( 'Role access', 'aculect-ai-companion' ) . '</span></th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Role access', 'aculect-ai-companion' ) . '</legend>';
		foreach ( $roles as $role ) {
			$id = (string) $role['id'];
			echo '<label style="display:block"><input type="checkbox" name="allowed_roles[]" value="' . esc_attr( $id ) . '"' . ( in_array( $id, (array) ( $values['allowed_roles'] ?? array() ), true ) ? ' checked' : '' ) . '> ' . esc_html( (string) $role['label'] ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Leave every role unchecked to inherit the existing Aculect ability policy. Administrators remain subject to the same ability, capability, scope, and approval checks.', 'aculect-ai-companion' ) . '</p></fieldset></td></tr>';
		echo '<tr><th><label for="aculect-target-mode">' . esc_html__( 'Target mode', 'aculect-ai-companion' ) . '</label></th><td><select id="aculect-target-mode" name="target_mode">';
		foreach ( array(
			'new'      => 'New content',
			'existing' => 'Existing content',
			'either'   => 'New or existing',
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . ( (string) ( $values['target_mode'] ?? 'either' ) === $key ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label for="aculect-write-policy">' . esc_html__( 'Write policy', 'aculect-ai-companion' ) . '</label></th><td><select id="aculect-write-policy" name="write_policy">';
		foreach ( array(
			'proposal_only'   => 'Proposal only',
			'draft_only'      => 'Draft only',
			'approved_update' => 'Approved update',
		) as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . ( (string) ( $values['write_policy'] ?? 'proposal_only' ) === $key ? ' selected' : '' ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Every write step automatically receives an approval gate. Publish, schedule, and delete are not available.', 'aculect-ai-companion' ) . '</p></td></tr></tbody></table>';
		echo '<h3>' . esc_html__( 'Supported step catalog', 'aculect-ai-companion' ) . '</h3><p>' . esc_html__( 'Use the exact ability IDs below in the Steps field. Availability still depends on the connected user’s scopes, roles, capabilities, and global policy.', 'aculect-ai-companion' ) . '</p><table class="widefat striped"><thead><tr><th>Ability</th><th>Adapter</th><th>Kind</th><th>Capabilities</th></tr></thead><tbody>';
		foreach ( $adapters as $adapter ) {
			echo '<tr><td><code>' . esc_html( (string) ( $adapter['ability_id'] ?? '' ) ) . '</code></td><td>' . esc_html( (string) ( $adapter['adapter_id'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $adapter['kind'] ?? '' ) ) . '</td><td>' . esc_html( implode( ', ', array_map( 'strval', (array) ( $adapter['capabilities'] ?? array() ) ) ) ) . '</td></tr>';
		}
		echo '</tbody></table><p><button class="button button-primary" name="save_status" value="draft" type="submit">' . esc_html__( 'Save draft', 'aculect-ai-companion' ) . '</button> <button class="button" name="save_status" value="published" type="submit">' . esc_html__( 'Validate and publish', 'aculect-ai-companion' ) . '</button></p></form>';
		$this->render_template_defaults_script( $templates );
	}

	/**
	 * Populate editable fields when an administrator changes the starter.
	 *
	 * @param array<string,array<string,mixed>> $templates Starter templates.
	 */
	private function render_template_defaults_script( array $templates ): void {
		$defaults = array();
		foreach ( $templates as $id => $template ) {
			$arguments       = function_exists( 'wp_json_encode' ) ? wp_json_encode( $template['step_arguments'] ?? array() ) : '{}';
			$defaults[ $id ] = array(
				'name'           => (string) ( $template['label'] ?? $id ),
				'description'    => (string) ( $template['description'] ?? '' ),
				'target_mode'    => (string) ( $template['target_mode'] ?? 'either' ),
				'post_types'     => implode( ",\n", array_map( 'strval', (array) ( $template['post_types'] ?? array() ) ) ),
				'input_fields'   => implode( "\n", array_map( 'strval', (array) ( $template['input_fields'] ?? array() ) ) ),
				'step_abilities' => implode( "\n", array_map( 'strval', (array) ( $template['step_abilities'] ?? array() ) ) ),
				'step_arguments' => is_string( $arguments ) ? $arguments : '{}',
				'write_policy'   => (string) ( $template['write_policy'] ?? 'proposal_only' ),
			);
		}
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $defaults, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) : '{}';
		if ( ! is_string( $encoded ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_* makes the bounded JSON safe for a raw script data block; HTML-escaping quotes would make JSON.parse fail.
		echo '<script type="application/json" id="aculect-workflow-template-defaults">' . $encoded . '</script>';
		echo '<script>(function(){const select=document.getElementById("aculect-workflow-template");const payload=document.getElementById("aculect-workflow-template-defaults");if(!select||!payload){return;}let defaults={};try{defaults=JSON.parse(payload.textContent||"{}");}catch(error){return;}const fields=["name","description","target_mode","post_types","input_fields","step_abilities","step_arguments","write_policy"];select.addEventListener("change",function(){const values=defaults[select.value]||{};fields.forEach(function(field){const input=document.getElementById("aculect-"+field);if(input&&Object.prototype.hasOwnProperty.call(values,field)){input.value=values[field];}});});})();</script>';
	}

	/**
	 * Render one compact text input.
	 *
	 * @param string $name Field name.
	 * @param string $label Field label.
	 * @param mixed  $value Field value.
	 * @param string $description Helper text.
	 */
	private function field( string $name, string $label, mixed $value, string $description ): void {
		echo '<tr><th><label for="aculect-' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( in_array( $name, array( 'description', 'post_types', 'input_fields', 'step_abilities', 'step_arguments' ), true ) ) {
			$textarea_value = function_exists( 'esc_textarea' ) ? esc_textarea( (string) $value ) : esc_html( (string) $value );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Value is escaped by esc_textarea or esc_html above.
			echo '<textarea class="large-text" rows="' . ( 'description' === $name ? '3' : '4' ) . '" id="aculect-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">' . $textarea_value . '</textarea>';
		} else {
			echo '<input class="regular-text" id="aculect-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
		}
		echo '<p class="description">' . esc_html( $description ) . '</p></td></tr>';
	}

	/**
	 * Return form values from a record or safe defaults.
	 *
	 * @param WorkflowDefinitionRecord|null $record Existing record.
	 * @return array<string,mixed>
	 */
	private function values_for_record( ?WorkflowDefinitionRecord $record ): array {
		if ( null === $record ) {
			$template            = $this->service->templates()['blank'];
			$step_arguments_json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $template['step_arguments'] ?? array() ) : '{}';
			return array(
				'workflow_id'      => '',
				'expected_version' => 0,
				'template_id'      => 'blank',
				'name'             => $template['label'],
				'description'      => $template['description'],
				'target_mode'      => $template['target_mode'],
				'post_types'       => implode( ', ', $template['post_types'] ),
				'input_fields'     => implode( "\n", $template['input_fields'] ),
				'step_abilities'   => implode( "\n", $template['step_abilities'] ),
				'step_arguments'   => is_string( $step_arguments_json ) ? $step_arguments_json : '{}',
				'write_policy'     => $template['write_policy'],
				'allowed_roles'    => array(),
				'status'           => 'draft',
			);
		}

		$value      = $record->definition()->to_array();
		$properties = (array) ( $value['input_schema']['properties'] ?? array() );
		$required   = array_map( 'strval', (array) ( $value['input_schema']['required'] ?? array() ) );
		$fields     = array();
		foreach ( $properties as $name => $schema ) {
			$type     = is_array( $schema ) ? (string) ( $schema['type'] ?? 'string' ) : 'string';
			$fields[] = (string) $name . ':' . $type . ( in_array( (string) $name, $required, true ) ? ':required' : '' );
		}
		$steps          = array();
		$step_arguments = array();
		foreach ( (array) ( $value['steps'] ?? array() ) as $step ) {
			if ( is_array( $step ) ) {
				$steps[] = (string) ( $step['ability_id'] ?? '' );
				$step_id = (string) ( $step['step_id'] ?? '' );
				if ( '' !== $step_id && is_array( $step['arguments'] ?? null ) ) {
					$step_arguments[ $step_id ] = $step['arguments'];
				}
			}
		}
		$step_arguments_json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $step_arguments ) : '{}';

		return array(
			'workflow_id'      => $record->workflow_id(),
			'expected_version' => $record->latest_version(),
			'template_id'      => '' === $record->template_id() ? 'blank' : $record->template_id(),
			'name'             => $value['name'] ?? '',
			'description'      => $value['description'] ?? '',
			'target_mode'      => $value['content_target']['mode'] ?? 'either',
			'post_types'       => implode( ', ', array_map( 'strval', (array) ( $value['content_target']['post_types'] ?? array() ) ) ),
			'input_fields'     => implode( "\n", $fields ),
			'step_abilities'   => implode( "\n", $steps ),
			'step_arguments'   => is_string( $step_arguments_json ) ? $step_arguments_json : '{}',
			'write_policy'     => $value['write_policy']['mode'] ?? 'proposal_only',
			'allowed_roles'    => $record->allowed_roles(),
			'status'           => $value['status'] ?? 'draft',
		);
	}

	/**
	 * Render recent summary-only workflow events.
	 *
	 * @param array<int,WorkflowAuditRecord> $events Recent events.
	 * @param string|null                    $error  Storage error, if any.
	 * @phpstan-param list<WorkflowAuditRecord> $events
	 */
	private function render_audit( array $events, ?string $error ): void {
		echo '<h2>' . esc_html__( 'Recent workflow activity', 'aculect-ai-companion' ) . '</h2>';
		if ( null !== $error && '' !== $error ) {
			echo '<p>' . esc_html( $error ) . '</p>';
			return;
		}
		if ( array() === $events ) {
			echo '<p>' . esc_html__( 'No workflow events have been recorded yet.', 'aculect-ai-companion' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Workflow</th><th>Event</th><th>Outcome</th><th>Changed fields</th><th>When</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			if ( ! $event instanceof WorkflowAuditRecord ) {
				continue;
			}
			echo '<tr><td><code>' . esc_html( $event->workflow_id() ) . '</code> v' . esc_html( (string) $event->workflow_version() ) . '</td><td>' . esc_html( $event->event_type() ) . ( '' !== $event->step_id() ? ' · ' . esc_html( $event->step_id() ) : '' ) . '</td><td>' . esc_html( $event->outcome_code() ?? '—' ) . '</td><td>' . esc_html( implode( ', ', $event->changed_fields() ) ) . '</td><td>' . esc_html( $event->created_at() ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render a safe notice.
	 *
	 * @param string|null $message Safe notice text.
	 */
	private function render_notice( ?string $message ): void {
		if ( null !== $message && '' !== $message ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Redirect back to the page using only allowlisted query fields.
	 *
	 * @param array<string,string> $args Query arguments.
	 */
	private function redirect( array $args ): void {
		$url = $this->page_url( $args );
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $url );
			exit;
		}
		$this->notice = 'Saved.';
	}

	/**
	 * Build the workflow admin page URL.
	 *
	 * @param array<string,string> $args Query arguments.
	 */
	private function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'options-general.php' ) );
	}
}

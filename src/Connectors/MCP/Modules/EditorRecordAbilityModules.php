<?php
/**
 * Database-only Site Editor write contracts.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\EditorRecordAbilities;
use Aculect\AICompanion\Connectors\MCP\EditorRecordState;
use Aculect\AICompanion\Connectors\MCP\EditorStylePolicy;

/** Keeps each editor mutation separately discoverable and confirmable. */
final class EditorRecordAbilityModules {

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Declare closed, bounded editor-record operations.
	 *
	 * @return list<AbilityModuleInterface>
	 */
	public function all(): array {
		$target   = array(
			'post_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$revision = array(
			'revision_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$state    = array(
			'expected_state' => array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{64}$',
			),
		);
		$changes  = array(
			'changes' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'minProperties'        => 1,
				'properties'           => array(
					'title'   => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 200,
					),
					'content' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 100000,
					),
				),
			),
		);
		$style    = array(
			'path'  => array(
				'type' => 'string',
				'enum' => EditorStylePolicy::PATHS,
			),
			'value' => array(
				'type'      => array( 'string', 'null' ),
				'maxLength' => 64,
			),
		);
		return array(
			$this->factory->create(
				'site_editor.list_records',
				'List Database Site Editor Records',
				'List existing database template, template-part, navigation or global-style IDs for bounded read-before-write workflows. Filters theme-owned records to the active theme. Never creates overrides or reads theme files; file-only templates remain in the existing template discovery tools.',
				'Site Editor',
				'content:read',
				true,
				$this->schema(
					array(
						'type'     => array(
							'type' => 'string',
							'enum' => EditorRecordState::TYPES,
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 5000,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
					array( 'type' )
				),
				static fn ( array $args ): array => ( new EditorRecordAbilities() )->list_records( $args )
			),
			$this->factory->create( 'site_editor.read_record', 'Read Editable Site Editor Record', 'Read one existing database template, template part, navigation or current-theme global-style record and expected_state. Optional revision_id binds comparison and subsequent restore. No file access; style output contains only finite user overrides, not computed defaults.', 'Site Editor', 'content:read', true, $this->schema( $target + $revision, array( 'post_id' ) ), static fn ( array $args ): array => ( new EditorRecordAbilities() )->read( $args ) ),
			$this->factory->create( 'site_editor.update_record', 'Update Site Editor Blocks', 'Update only title and/or registered serialized block content of an existing database template, template part or navigation record. Requires edit_theme_options, fresh expected_state, explicit confirmation, no edit lock and a verified recovery revision. No files, metadata, assignment, creation or status changes.', 'Site Editor', 'content:draft', false, $this->schema( $target + $state + $changes, array( 'post_id', 'expected_state', 'changes' ) ), static fn ( array $args ): array => ( new EditorRecordAbilities() )->write( 'update_record', $args ) ),
			$this->factory->create( 'site_editor.set_style', 'Set Global Style Override', 'Set one allowlisted color, typography, spacing or layout value in an existing current-theme user-style record; null removes that override. Hex colors, bounded CSS dimensions and declared typography values only. Requires explicit confirmation, fresh expected_state and a recovery revision. No custom CSS, theme files or arbitrary theme.json settings.', 'Site Editor', 'content:draft', false, $this->schema( $target + $state + $style, array( 'post_id', 'expected_state', 'path', 'value' ) ), static fn ( array $args ): array => ( new EditorRecordAbilities() )->write( 'set_style', $args ) ),
			$this->factory->create( 'site_editor.restore_record', 'Restore Site Editor Content Revision', 'Restore title, content and excerpt from one saved revision of this database editor record. Read with the same revision_id first to obtain expected_state. Always requires explicit confirmation, native editor permission, no lock and a verified recovery point. Preserves status, terms and metadata; published design changes are immediate.', 'Site Editor', 'content:draft', false, $this->schema( $target + $state + $revision, array( 'post_id', 'expected_state', 'revision_id' ) ), static fn ( array $args ): array => ( new EditorRecordAbilities() )->write( 'restore_record', $args ) ),
		);
	}

	/**
	 * Build a closed object schema.
	 *
	 * @param array<string,mixed> $properties Named input fields.
	 * @param array<int,string>   $required Mandatory field names.
	 * @return array<string,mixed>
	 */
	private function schema( array $properties, array $required ): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'required'             => $required,
		);
	}
}

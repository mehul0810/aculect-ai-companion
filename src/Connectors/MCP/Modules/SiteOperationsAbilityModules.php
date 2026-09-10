<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\IntegrityChecksumAbilities;
use Aculect\AICompanion\Connectors\MCP\RegisteredFieldAbilities;
use Aculect\AICompanion\Connectors\MCP\RenderedPageInspectionAbilities;
use Aculect\AICompanion\Connectors\MCP\TargetedMaintenanceAbilities;

/**
 * Declares bounded inspection, registered-field and native maintenance tools.
 */
final class SiteOperationsAbilityModules {

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Return independently authorized operation modules.
	 *
	 * @return array<string,AbilityModuleInterface>
	 */
	public function all(): array {
		$post    = array(
			'post_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$page    = array(
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
		);
		$modules = array(
			$this->factory->create( 'site.inspect_rendered_page', 'Inspect Public Rendered Page', 'Inspect a public post permalink without cookies or redirects. Returns bounded server-rendered headings, links and head metadata, not screenshots or JavaScript-rendered content.', 'Site Inspection', 'content:read', true, $this->schema( $post, array( 'post_id' ) ), static fn ( array $args ): array => ( new RenderedPageInspectionAbilities() )->inspect( $args ) ),
			$this->factory->create( 'content_fields.list_fields', 'List Registered Content Fields', 'List permitted single scalar REST-exposed fields for an editable content item. Protected, unregistered and complex fields are excluded; no arbitrary metadata or field builder is exposed.', 'Content Fields', 'content:read', true, $this->schema( $post + $page, array( 'post_id' ) ), static fn ( array $args ): array => ( new RegisteredFieldAbilities() )->list_fields( $args ) ),
			$this->factory->create( 'content_fields.read_field', 'Read Registered Content Field', 'Read one permitted registered scalar field and its expected_state token before updating. Requires content editing and field-specific permission.', 'Content Fields', 'content:read', true, $this->field_schema( false ), static fn ( array $args ): array => ( new RegisteredFieldAbilities() )->read_field( $args ) ),
			$this->factory->create( 'content_fields.update_field', 'Update Registered Content Field', 'Update one existing, permitted scalar content field using its declared schema and expected_state token. Requires explicit confirmation; cannot create fields or modify protected or complex metadata.', 'Content Fields', 'content:draft', false, $this->field_schema( true ), static fn ( array $args ): array => ( new RegisteredFieldAbilities() )->update_field( $args ) ),
			$this->factory->create( 'maintenance.clean_post_cache', 'Invalidate Native Post Cache', 'Explicitly invalidate one editable post native cache. Requires administrator permission and confirmation. Does not purge a CDN, page cache or shared object-cache backend.', 'Site Maintenance', 'content:draft', false, $this->schema( $post, array( 'post_id' ) ), static fn ( array $args ): array => ( new TargetedMaintenanceAbilities() )->clean_post_cache( $args ) ),
			$this->factory->create( 'maintenance.flush_rewrite_rules', 'Rebuild Site Rewrite Rules', 'Explicitly soft-flush the current site rewrite rules after WordPress loads. Requires administrator permission and confirmation. Never changes permalink settings, server files or other sites.', 'Site Maintenance', 'content:draft', false, $this->schema( array(), array() ), static fn ( array $args ): array => ( new TargetedMaintenanceAbilities() )->flush_rewrite_rules( $args ) ),
			$this->factory->create( 'integrity.check_core', 'Check Core File Checksums', 'Read-only bounded comparison with WordPress.org checksums for the installed stable version and locale. Sends version and locale only. No repair, unknown-file scan or malware verdict; page through all results.', 'Site Integrity', 'content:read', true, $this->schema( $page, array() ), static fn ( array $args ): array => ( new IntegrityChecksumAbilities() )->core( $args ) ),
			$this->factory->create(
				'integrity.check_plugin',
				'Check Plugin File Checksums',
				'Read-only bounded comparison for one installed plugin. Sends its slug and version to WordPress.org, never source files. Missing manifests and external update identities are unsupported, not a pass. No repair or malware verdict.',
				'Site Integrity',
				'content:read',
				true,
				$this->schema(
					array(
						'plugin' => array(
							'type'      => 'string',
							'maxLength' => 191,
							'pattern'   => '^[a-z0-9-]+/[a-zA-Z0-9_.-]+\\.php$',
						),
					) + $page,
					array( 'plugin' )
				),
				static fn ( array $args ): array => ( new IntegrityChecksumAbilities() )->plugin( $args )
			),
		);
		$result  = array();
		foreach ( $modules as $module ) {
			$result[ $module->id() ] = $module;
		}
		return $result;
	}

	/**
	 * Build the closed scalar-field contract.
	 *
	 * @param bool $write Include change and concurrency controls.
	 * @return array<string,mixed>
	 */
	private function field_schema( bool $write ): array {
		$properties = array(
			'post_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'key'     => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 191,
			),
		);
		$required   = array( 'post_id', 'key' );
		if ( $write ) {
			$properties['expected_state'] = array(
				'type'    => 'string',
				'pattern' => '^[a-f0-9]{64}$',
			);
			$properties['value']          = array(
				'type'      => array( 'string', 'integer', 'number', 'boolean' ),
				'maxLength' => 4000,
			);
			$required                     = array_merge( $required, array( 'expected_state', 'value' ) );
		}
		return $this->schema( $properties, $required );
	}

	/**
	 * Construct a closed object schema.
	 *
	 * @param array<string,mixed> $properties Named fields.
	 * @param array               $required Required fields.
	 * @phpstan-param list<string> $required
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

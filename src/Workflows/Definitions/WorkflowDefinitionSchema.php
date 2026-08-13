<?php
/**
 * Immutable workflow definition v1 schema constants.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

/**
 * Owns the closed v1 vocabulary and resource bounds.
 */
final class WorkflowDefinitionSchema {

	public const VERSION                       = 1;
	public const MAX_ENCODED_BYTES             = 262144;
	public const MAX_DEPTH                     = 16;
	public const MAX_NODES                     = 512;
	public const MAX_STEPS                     = 50;
	public const MAX_ABILITIES                 = 50;
	public const MAX_POST_TYPES                = 20;
	public const MAX_RULES                     = 50;
	public const MAX_SCHEMA_PROPERTIES         = 50;
	public const MAX_SCHEMA_ENUM_VALUES        = 50;
	public const MAX_SCHEMA_COLLECTION_ITEMS   = 100;
	public const MAX_SCHEMA_STRING_LENGTH      = 32768;
	public const MAX_SCHEMA_PATTERN_LENGTH     = 256;
	public const MAX_SCHEMA_DESCRIPTION_LENGTH = 1000;

	public const TOP_LEVEL_KEYS = array(
		'definition_schema_version',
		'workflow_id',
		'workflow_version',
		'name',
		'description',
		'content_target',
		'input_schema',
		'steps',
		'allowed_abilities',
		'write_policy',
		'approval_gates',
		'output_contract',
		'validation_rules',
		'status',
		'created_by',
		'updated_by',
		'compatibility',
	);

	public const CONTENT_TARGET_KEYS  = array( 'mode', 'post_types' );
	public const STEP_KEYS            = array(
		'step_id',
		'adapter_id',
		'adapter_version',
		'ability_id',
		'kind',
		'arguments',
		'depends_on',
	);
	public const WRITE_POLICY_KEYS    = array( 'mode' );
	public const VALIDATION_RULE_KEYS = array( 'rule_id', 'severity' );
	public const COMPATIBILITY_KEYS   = array( 'input_contract_version', 'output_contract_version' );

	public const SCHEMA_TYPES           = array( 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' );
	public const SCHEMA_COMMON_KEYS     = array( 'type', 'description', 'enum', 'const' );
	public const SCHEMA_TYPE_KEYS       = array(
		'object'  => array( 'properties', 'required', 'additionalProperties', 'minProperties', 'maxProperties' ),
		'array'   => array( 'items', 'minItems', 'maxItems', 'uniqueItems' ),
		'string'  => array( 'minLength', 'maxLength', 'pattern' ),
		'integer' => array( 'minimum', 'maximum' ),
		'number'  => array( 'minimum', 'maximum' ),
		'boolean' => array(),
		'null'    => array(),
	);
	public const SCHEMA_VOCABULARY_KEYS = array( '$schema', '$vocabulary' );
	public const SCHEMA_REFERENCE_KEYS  = array( '$ref', '$dynamicRef' );

	private function __construct() {
	}
}

<?php
/**
 * Content planner v1 workflow adapter.
 *
 * @package Aculect\AICompanion\Workflows\Adapters
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Adapters;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionRequest;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputValidator;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanningCanonicalizer;
use Throwable;

/**
 * Maps content/prepare-draft to the existing read-only planning ability.
 *
 * @internal This adapter exposes only a structural summary. Source prompts,
 * indexed content, URLs, sessions, and authentication data never enter its
 * result contract.
 */
final class ContentPlannerAdapter implements WorkflowAdapterInterface {

	private const ADAPTER_ID          = 'content_planner';
	private const ADAPTER_VERSION     = 1;
	private const WORKFLOW_ABILITY_ID = 'content/prepare-draft';
	private const INTERNAL_ABILITY_ID = 'content_workflow.prepare_post';
	private const STEP_ID             = 'prepare_content';
	private const STEP_KIND           = 'proposal';
	private const EXPECTED_STATUS     = 'ready';
	private const EXPECTED_WORKFLOW   = 'content_workflow_prepare_post';

	private const MAX_RAW_OUTPUT_BYTES = 1048576;
	private const MAX_RAW_DEPTH        = 16;
	private const MAX_RAW_NODES        = 20000;
	private const MAX_RAW_STRING_BYTES = 131072;
	private const MAX_RAW_ITEMS        = 1000;
	private const MAX_RAW_KEY_BYTES    = 128;

	private const CONTENT_MODES = array(
		'article',
		'page',
		'landing_page',
		'visual_layout',
		'service_page',
		'product_page',
		'case_study',
	);

	private const LAYOUT_MODES = array(
		'page',
		'landing_page',
		'visual_layout',
		'service_page',
		'product_page',
		'case_study',
	);

	private const BLOCK_FAMILIES = array(
		'text',
		'media',
		'layout',
		'navigation',
		'data',
		'embed',
		'design',
		'widget',
	);

	private const OUTLINE_KINDS = array(
		'article_section',
		'hero',
		'card_grid',
		'steps',
		'social_proof',
		'call_to_action',
		'media_text',
		'comparison',
		'narrative_section',
		'metric_grid',
		'requested_section',
	);

	/**
	 * Fixed operation name, expected tool, and read-only declaration.
	 *
	 * @var array<string, array{tool:string,read_only:bool}>
	 */
	private const OPERATIONS = array(
		'create_draft'        => array(
			'tool'      => 'content_workflow_create_draft',
			'read_only' => false,
		),
		'update_post'         => array(
			'tool'      => 'content_workflow_update_post',
			'read_only' => false,
		),
		'update_rankmath_seo' => array(
			'tool'      => 'seo_workflow_update_rankmath',
			'read_only' => false,
		),
		'search_chunks'       => array(
			'tool'      => 'content_search_chunks',
			'read_only' => true,
		),
		'internal_links'      => array(
			'tool'      => 'content_find_internal_links',
			'read_only' => true,
		),
		'validate_blocks'     => array(
			'tool'      => 'intelligence_content_validate_blocks',
			'read_only' => true,
		),
		'block_discovery'     => array(
			'tool'      => 'intelligence_blocks_list_available',
			'read_only' => true,
		),
		'pattern_discovery'   => array(
			'tool'      => 'intelligence_patterns_list_available',
			'read_only' => true,
		),
	);

	private AbilitiesRegistry $abilities;
	private AbilityExecutionGateway $gateway;

	/**
	 * Create the adapter around the authoritative ability execution gateway.
	 *
	 * @param AbilitiesRegistry|null $abilities Registry used for contract inspection and gateway dispatch.
	 */
	public function __construct( ?AbilitiesRegistry $abilities = null ) {
		$this->abilities = $abilities ?? new AbilitiesRegistry();
		$this->gateway   = new AbilityExecutionGateway( $this->abilities );
	}

	public function adapter_id(): string {
		return self::ADAPTER_ID;
	}

	public function adapter_version(): int {
		return self::ADAPTER_VERSION;
	}

	public function ability_id(): string {
		return self::WORKFLOW_ABILITY_ID;
	}

	public function kind(): string {
		return self::STEP_KIND;
	}

	public function is_read_only(): bool {
		return true;
	}

	public function required_capabilities(): array {
		return array();
	}

	public function input_schema(): array {
		$string = static fn( int $maximum ): array => array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => $maximum,
		);

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'brief'                    => $string( 1000 ),
				'post_type'                => array_merge( $string( 64 ), array( 'pattern' => '^[a-z0-9][a-z0-9_-]{0,63}$' ) ),
				'audience'                 => $string( 500 ),
				'seo_intent'               => $string( 500 ),
				'desired_word_count'       => array(
					'type'    => 'integer',
					'minimum' => 3000,
					'maximum' => 5000,
				),
				'content_mode'             => array(
					'type' => 'string',
					'enum' => self::CONTENT_MODES,
				),
				'content_type'             => $string( 120 ),
				'layout_intent'            => $string( 1000 ),
				'visual_reference_summary' => $string( 1000 ),
				'section_requirements'     => $this->string_list_schema( 12, 120 ),
				'preferred_block_families' => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'string',
						'enum' => self::BLOCK_FAMILIES,
					),
					'maxItems' => 8,
				),
				'preferred_blocks'         => $this->identifier_list_schema( 20 ),
				'preferred_patterns'       => $this->identifier_list_schema( 12 ),
				'existing_post_id'         => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'additionalProperties' => false,
			'required'             => array( 'brief' ),
		);
	}

	public function output_schema(): array {
		$block_name      = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 128,
			'pattern'   => '^[A-Za-z0-9][A-Za-z0-9_.-]*/[A-Za-z0-9][A-Za-z0-9_.-]*$',
		);
		$section_id      = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 64,
			'pattern'   => '^[a-z0-9][a-z0-9-]{0,63}$',
		);
		$operation_names = array_keys( self::OPERATIONS );
		$operation_tools = array_values( array_column( self::OPERATIONS, 'tool' ) );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'status'               => array(
					'type' => 'string',
					'enum' => array( self::EXPECTED_STATUS ),
				),
				'workflow'             => array(
					'type' => 'string',
					'enum' => array( self::EXPECTED_WORKFLOW ),
				),
				'content_mode'         => array(
					'type' => 'string',
					'enum' => self::CONTENT_MODES,
				),
				'post_type'            => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
					'pattern'   => '^[a-z0-9][a-z0-9_-]{0,63}$',
				),
				'desired_word_count'   => array(
					'type'    => 'integer',
					'minimum' => 3000,
					'maximum' => 5000,
				),
				'outline'              => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 24,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'           => $section_id,
							'kind'         => array(
								'type' => 'string',
								'enum' => self::OUTLINE_KINDS,
							),
							'level'        => array(
								'type' => 'integer',
								'enum' => array( 2 ),
							),
							'target_words' => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => 5000,
							),
							'blocks'       => array(
								'type'     => 'array',
								'minItems' => 1,
								'maxItems' => 20,
								'items'    => $block_name,
							),
						),
						'additionalProperties' => false,
						'required'             => array( 'id', 'kind', 'level', 'target_words', 'blocks' ),
					),
				),
				'block_plan'           => array(
					'type'                 => 'object',
					'properties'           => array(
						'format'           => array(
							'type' => 'string',
							'enum' => array( 'serialized_wordpress_blocks' ),
						),
						'content_mode'     => array(
							'type' => 'string',
							'enum' => self::CONTENT_MODES,
						),
						'allowed_blocks'   => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 20,
							'items'    => $block_name,
						),
						'forbidden_blocks' => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 1,
							'items'    => array(
								'type' => 'string',
								'enum' => array( 'core/html' ),
							),
						),
						'validation_tool'  => array(
							'type' => 'string',
							'enum' => array( 'intelligence_content_validate_blocks' ),
						),
						'section_ids'      => array(
							'type'        => 'array',
							'minItems'    => 1,
							'maxItems'    => 24,
							'uniqueItems' => true,
							'items'       => $section_id,
						),
					),
					'additionalProperties' => false,
					'required'             => array( 'format', 'content_mode', 'allowed_blocks', 'forbidden_blocks', 'validation_tool', 'section_ids' ),
				),
				'context'              => array(
					'type'                 => 'object',
					'properties'           => array(
						'status'               => array(
							'type' => 'string',
							'enum' => array( 'ready', 'unavailable' ),
						),
						'memory_count'         => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 8,
						),
						'related_item_count'   => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 5,
						),
						'relevant_chunk_count' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 6,
						),
						'internal_link_count'  => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 8,
						),
						'warning_count'        => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 16,
						),
					),
					'additionalProperties' => false,
					'required'             => array( 'status', 'memory_count', 'related_item_count', 'relevant_chunk_count', 'internal_link_count', 'warning_count' ),
				),
				'available_operations' => array(
					'type'     => 'array',
					'minItems' => count( self::OPERATIONS ),
					'maxItems' => count( self::OPERATIONS ),
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'operation' => array(
								'type' => 'string',
								'enum' => $operation_names,
							),
							'tool'      => array(
								'type' => 'string',
								'enum' => $operation_tools,
							),
							'available' => array( 'type' => 'boolean' ),
							'read_only' => array( 'type' => 'boolean' ),
						),
						'additionalProperties' => false,
						'required'             => array( 'operation', 'tool', 'available', 'read_only' ),
					),
				),
				'next_actions'         => array(
					'type'        => 'array',
					'minItems'    => 4,
					'maxItems'    => 4,
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'use_private_context', 'compose_serialized_blocks', 'validate_blocks', 'create_or_update_content' ),
					),
				),
			),
			'additionalProperties' => false,
			'required'             => array( 'status', 'workflow', 'content_mode', 'post_type', 'desired_word_count', 'outline', 'block_plan', 'context', 'available_operations', 'next_actions' ),
		);
	}

	public function execute( WorkflowPlan $plan, string $step_id, array $arguments, array $auth ): WorkflowAdapterResult {
		$binding = WorkflowPlanStepBinding::from_plan( $plan, $step_id );
		if (
			null === $binding
			|| ! $binding->belongs_to( $plan )
			|| self::STEP_ID !== $binding->step_id()
			|| self::ADAPTER_ID !== $binding->adapter_id()
			|| self::ADAPTER_VERSION !== $binding->adapter_version()
			|| self::WORKFLOW_ABILITY_ID !== $binding->ability_id()
			|| self::STEP_KIND !== $binding->kind()
		) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH );
		}

		if ( ! $this->ability_contract_is_current() ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_ABILITY_CONTRACT_MISMATCH );
		}

		// Session persistence is deliberately outside this private adapter contract.
		if ( array_key_exists( 'workflow_session_id', $arguments ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_INVALID_ARGUMENTS );
		}

		try {
			$input      = WorkflowInputContract::from_value( $arguments );
			$validation = ( new WorkflowInputValidator() )->validate( $input, $this->input_schema() );
			if ( array() !== $validation->missing_paths() || array() !== $validation->invalid_paths() ) {
				return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_INVALID_ARGUMENTS );
			}
			$arguments = get_object_vars( $input->value() );
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_INVALID_ARGUMENTS );
		}

		try {
			$outcome = $this->gateway->execute(
				new AbilityExecutionRequest(
					array(
						'name'      => self::INTERNAL_ABILITY_ID,
						'arguments' => $arguments,
					),
					$auth
				)
			);
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_EXECUTION_NOT_AVAILABLE );
		}

		if ( AbilityExecutionGateway::OUTCOME_SUCCESS !== $outcome->type ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_GATEWAY_REJECTED );
		}

		$output = $outcome->data['result'] ?? null;
		if ( ! is_array( $output ) || array_is_list( $output ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
		}
		if ( isset( $output['error'] ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_ABILITY_FAILED );
		}

		return $this->projected_output_result( $output );
	}

	/**
	 * Validate the private ability payload and return only a structural summary.
	 *
	 * @param array<string, mixed> $output Raw gateway result.
	 */
	private function projected_output_result( array $output ): WorkflowAdapterResult {
		if ( ! $this->raw_output_is_bounded( $output ) || ! $this->has_exact_keys( $output, $this->expected_output_keys() ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
		}

		$content_mode       = $output['content_mode'] ?? null;
		$post_type          = $output['post_type'] ?? null;
		$desired_word_count = $output['desired_word_count'] ?? null;
		if (
			self::EXPECTED_STATUS !== ( $output['status'] ?? null )
			|| self::EXPECTED_WORKFLOW !== ( $output['workflow'] ?? null )
			|| ! is_string( $content_mode ) || ! in_array( $content_mode, self::CONTENT_MODES, true )
			|| ! $this->matches_patterned_string( $post_type, 64, '/^[a-z0-9][a-z0-9_-]{0,63}$/D' )
			|| ! is_int( $desired_word_count ) || 3000 > $desired_word_count || 5000 < $desired_word_count
			|| ! $this->private_echo_fields_are_bounded( $output )
		) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
		}

		$outline_projection = $this->project_outline( $output['outline'] ?? null, $content_mode );
		if ( null === $outline_projection ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
		}
		$outline = $outline_projection['outline'];

		$block_plan = $this->project_block_plan( $output['block_plan'] ?? null, $content_mode, $output['outline'], $outline_projection['raw_ids'], array_column( $outline, 'id' ) );
		$context    = $this->project_context( $output['intelligence_context'] ?? null );
		$operations = $this->project_operations( $output['required_operations'] ?? null );
		if ( null === $block_plan || null === $context || null === $operations || ! $this->next_actions_are_current( $output['next_actions'] ?? null, $content_mode ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
		}

		$projected = array(
			'status'               => self::EXPECTED_STATUS,
			'workflow'             => self::EXPECTED_WORKFLOW,
			'content_mode'         => $content_mode,
			'post_type'            => $post_type,
			'desired_word_count'   => $desired_word_count,
			'outline'              => $outline,
			'block_plan'           => $block_plan,
			'context'              => $context,
			'available_operations' => $operations,
			'next_actions'         => array( 'use_private_context', 'compose_serialized_blocks', 'validate_blocks', 'create_or_update_content' ),
		);

		try {
			$contract   = WorkflowInputContract::from_value( $projected );
			$validation = ( new WorkflowInputValidator() )->validate( $contract, $this->output_schema() );
			if ( array() !== $validation->missing_paths() || array() !== $validation->invalid_paths() ) {
				return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
			}

			return WorkflowAdapterResult::success( get_object_vars( $contract->value() ) );
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
		}
	}

	/**
	 * Return the exact upstream top-level result keys expected by this projection.
	 *
	 * @return list<string>
	 */
	private function expected_output_keys(): array {
		return array(
			'status',
			'workflow',
			'content_mode',
			'post_type',
			'brief',
			'audience',
			'seo_intent',
			'layout_intent',
			'visual_reference',
			'section_requirements',
			'desired_word_count',
			'existing_post_id',
			'outline',
			'block_plan',
			'recommendations',
			'required_operations',
			'intelligence_context',
			'operations',
			'next_actions',
		);
	}

	/**
	 * Validate source fields that must never be copied into the result.
	 *
	 * @param array<string, mixed> $output Raw output.
	 */
	private function private_echo_fields_are_bounded( array $output ): bool {
		foreach ( array(
			'brief'            => 1000,
			'audience'         => 500,
			'seo_intent'       => 500,
			'layout_intent'    => 1000,
			'visual_reference' => 1000,
		) as $field => $maximum ) {
			if ( ! is_string( $output[ $field ] ?? null ) || strlen( $output[ $field ] ) > $maximum ) {
				return false;
			}
		}

		if ( ! is_int( $output['existing_post_id'] ?? null ) || 0 > $output['existing_post_id'] ) {
			return false;
		}
		if ( ! $this->string_list_matches( $output['section_requirements'] ?? null, 12, 120 ) ) {
			return false;
		}

		$recommendations = $output['recommendations'] ?? null;
		return is_array( $recommendations )
			&& ! array_is_list( $recommendations )
			&& $this->has_exact_keys( $recommendations, array( 'taxonomies', 'media', 'seo' ) )
			&& $this->bounded_string_map( $recommendations, 1000 );
	}

	/**
	 * Validate and project the structural outline without headings or source text.
	 *
	 * @param mixed  $raw          Raw outline.
	 * @param string $content_mode Validated content mode.
	 * @return array{outline:list<array<string, mixed>>,raw_ids:list<string>}|null
	 */
	private function project_outline( mixed $raw, string $content_mode ): ?array {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || 1 > count( $raw ) || 24 < count( $raw ) ) {
			return null;
		}

		$is_layout = in_array( $content_mode, self::LAYOUT_MODES, true );
		$projected = array();
		$seen      = array();
		$raw_ids   = array();
		foreach ( $raw as $index => $item ) {
			$expected_keys = $is_layout
				? array( 'id', 'heading', 'level', 'section_type', 'target_words', 'blocks', 'layout_strategy', 'pattern_search_terms' )
				: array( 'id', 'heading', 'level', 'target_words', 'blocks' );
			if ( ! is_array( $item ) || array_is_list( $item ) || ! $this->has_exact_keys( $item, $expected_keys ) ) {
				return null;
			}

			$id   = $item['id'] ?? null;
			$kind = $is_layout ? ( $item['section_type'] ?? null ) : 'article_section';
			if (
				! $this->matches_patterned_string( $id, 200, '/^(?:[a-z0-9_-]|%[0-9a-f]{2})+$/D' )
				|| isset( $seen[ $id ] )
				|| ! $this->matches_nonempty_string( $item['heading'] ?? null, 200 )
				|| ! is_string( $kind ) || ! in_array( $kind, self::OUTLINE_KINDS, true )
				|| 2 !== ( $item['level'] ?? null )
				|| ! is_int( $item['target_words'] ?? null ) || 1 > $item['target_words'] || 5000 < $item['target_words']
				|| ! $this->block_name_list_matches( $item['blocks'] ?? null, 20 )
			) {
				return null;
			}

			if ( $is_layout && ( ! $this->matches_nonempty_string( $item['layout_strategy'] ?? null, 1000 ) || ! $this->string_list_matches( $item['pattern_search_terms'] ?? null, 12, 120 ) ) ) {
				return null;
			}

			$seen[ $id ] = true;
			$raw_ids[]   = $id;
			$projected[] = array(
				'id'           => 'section-' . ( $index + 1 ),
				'kind'         => $kind,
				'level'        => 2,
				'target_words' => $item['target_words'],
				'blocks'       => array_values( $item['blocks'] ),
			);
		}

		return array(
			'outline' => $projected,
			'raw_ids' => $raw_ids,
		);
	}

	/**
	 * Validate and project the safe block-plan fields.
	 *
	 * @param mixed  $raw          Raw block plan.
	 * @param string $content_mode Content mode.
	 * @param mixed  $raw_outline  Raw outline used by the upstream layout plan.
	 * @param array  $raw_section_ids Raw upstream section IDs.
	 * @param array  $projected_ids   Safe projected section IDs.
	 * @phpstan-param list<string> $raw_section_ids
	 * @phpstan-param list<string> $projected_ids
	 * @return array<string, mixed>|null
	 */
	private function project_block_plan( mixed $raw, string $content_mode, mixed $raw_outline, array $raw_section_ids, array $projected_ids ): ?array {
		if ( ! is_array( $raw ) || array_is_list( $raw ) ) {
			return null;
		}

		$is_layout = in_array( $content_mode, self::LAYOUT_MODES, true );
		$keys      = array( 'format', 'content_mode', 'allowed_blocks', 'never_use', 'validation_tool', 'section_ids', 'preferred_block_families', 'preferred_blocks', 'preferred_patterns', 'layout_intent', 'visual_reference_summary' );
		if ( $is_layout ) {
			$keys = array_merge( $keys, array( 'layout_blocks', 'article_blocks', 'layout_strategy', 'layout_plan', 'block_search_terms', 'pattern_search_terms' ) );
		}
		if ( ! $this->has_exact_keys( $raw, $keys ) ) {
			return null;
		}

		if (
			'serialized_wordpress_blocks' !== ( $raw['format'] ?? null )
			|| ( $raw['content_mode'] ?? null ) !== $content_mode
			|| ! $this->block_name_list_matches( $raw['allowed_blocks'] ?? null, 20 )
			|| array( 'core/html' ) !== ( $raw['never_use'] ?? null )
			|| 'intelligence_content_validate_blocks' !== ( $raw['validation_tool'] ?? null )
			|| ( $raw['section_ids'] ?? null ) !== $raw_section_ids
			|| ! $this->enum_list_matches( $raw['preferred_block_families'] ?? null, self::BLOCK_FAMILIES, 8 )
			|| ! $this->identifier_list_matches( $raw['preferred_blocks'] ?? null, 20 )
			|| ! $this->identifier_list_matches( $raw['preferred_patterns'] ?? null, 12 )
			|| ! is_string( $raw['layout_intent'] ?? null ) || 1000 < strlen( $raw['layout_intent'] )
			|| ! is_string( $raw['visual_reference_summary'] ?? null ) || 1000 < strlen( $raw['visual_reference_summary'] )
		) {
			return null;
		}

		if (
			$is_layout
			&& (
				! $this->block_name_list_matches( $raw['layout_blocks'] ?? null, 20 )
				|| ! $this->block_name_list_matches( $raw['article_blocks'] ?? null, 20 )
				|| ! $this->matches_nonempty_string( $raw['layout_strategy'] ?? null, 2000 )
				|| ( $raw['layout_plan'] ?? null ) !== $raw_outline
				|| ! $this->string_list_matches( $raw['block_search_terms'] ?? null, 16, 120 )
				|| ! $this->string_list_matches( $raw['pattern_search_terms'] ?? null, 16, 120 )
			)
		) {
			return null;
		}

		return array(
			'format'           => 'serialized_wordpress_blocks',
			'content_mode'     => $content_mode,
			'allowed_blocks'   => array_values( $raw['allowed_blocks'] ),
			'forbidden_blocks' => array( 'core/html' ),
			'validation_tool'  => 'intelligence_content_validate_blocks',
			'section_ids'      => $projected_ids,
		);
	}

	/**
	 * Project only availability and bounded item counts from private context.
	 *
	 * @param mixed $raw Raw intelligence context.
	 * @return array<string, int|string>|null
	 */
	private function project_context( mixed $raw ): ?array {
		if ( ! is_array( $raw ) || array_is_list( $raw ) || ! is_string( $raw['status'] ?? null ) ) {
			return null;
		}

		if ( 'unavailable' === $raw['status'] ) {
			if ( ! $this->has_exact_keys( $raw, array( 'status', 'reason', 'message' ) ) || 'content_index_runtime_unavailable' !== ( $raw['reason'] ?? null ) || ! $this->matches_nonempty_string( $raw['message'] ?? null, 500 ) ) {
				return null;
			}

			return array(
				'status'               => 'unavailable',
				'memory_count'         => 0,
				'related_item_count'   => 0,
				'relevant_chunk_count' => 0,
				'internal_link_count'  => 0,
				'warning_count'        => 0,
			);
		}

		if ( 'ready' !== $raw['status'] || ! $this->has_exact_keys( $raw, array( 'status', 'query', 'memories', 'related_items', 'relevant_chunks', 'internal_links', 'warnings' ) ) || ! is_string( $raw['query'] ) || 2500 < strlen( $raw['query'] ) || ! $this->string_list_matches( $raw['warnings'], 16, 500 ) ) {
			return null;
		}

		$counts = array(
			'memory_count'         => $this->result_item_count( $raw['memories'], 8 ),
			'related_item_count'   => $this->result_item_count( $raw['related_items'], 5 ),
			'relevant_chunk_count' => $this->result_item_count( $raw['relevant_chunks'], 6 ),
			'internal_link_count'  => $this->result_item_count( $raw['internal_links'], 8 ),
		);
		if ( in_array( null, $counts, true ) ) {
			return null;
		}

		return array_merge(
			array( 'status' => 'ready' ),
			$counts,
			array( 'warning_count' => count( $raw['warnings'] ) )
		);
	}

	/**
	 * Return a bounded item count from an optional operation result.
	 *
	 * @param mixed $raw     Operation result or empty skipped value.
	 * @param int   $maximum Maximum item count.
	 */
	private function result_item_count( mixed $raw, int $maximum ): ?int {
		if ( array() === $raw ) {
			return 0;
		}
		if ( ! is_array( $raw ) || array_is_list( $raw ) || isset( $raw['error'] ) || ! isset( $raw['items'] ) || ! is_array( $raw['items'] ) || ! array_is_list( $raw['items'] ) || $maximum < count( $raw['items'] ) ) {
			return null;
		}

		return count( $raw['items'] );
	}

	/**
	 * Validate fixed operation identities and project availability only.
	 *
	 * @param mixed $raw Required-operation map.
	 * @return list<array<string, bool|string>>|null
	 */
	private function project_operations( mixed $raw ): ?array {
		if ( ! is_array( $raw ) || array_is_list( $raw ) || ! $this->has_exact_keys( $raw, array_keys( self::OPERATIONS ) ) ) {
			return null;
		}

		$projected = array();
		foreach ( self::OPERATIONS as $operation => $expected ) {
			$entry = $raw[ $operation ] ?? null;
			if (
				! is_array( $entry ) || array_is_list( $entry )
				|| ! $this->operation_entry_is_current( $operation, $entry )
				|| ( $entry['tool'] ?? null ) !== $expected['tool']
				|| ! is_bool( $entry['available'] ?? null )
				|| ( $entry['read_only'] ?? null ) !== $expected['read_only']
			) {
				return null;
			}

			$projected[] = array(
				'operation' => $operation,
				'tool'      => $expected['tool'],
				'available' => $entry['available'],
				'read_only' => $expected['read_only'],
			);
		}

		return $projected;
	}

	/**
	 * Validate the current internal operation-availability envelope.
	 *
	 * @param string               $operation Fixed operation name.
	 * @param array<string, mixed> $entry     Raw operation entry.
	 */
	private function operation_entry_is_current( string $operation, array $entry ): bool {
		$fixed = in_array( $operation, array( 'validate_blocks', 'block_discovery', 'pattern_discovery' ), true );
		if ( $fixed ) {
			return $this->has_exact_keys( $entry, array( 'tool', 'available', 'read_only' ) );
		}

		$derived = in_array( $operation, array( 'create_draft', 'update_post', 'update_rankmath_seo' ), true );
		$keys    = array( 'tool', 'available', 'required_scopes', 'read_only', 'core_default', 'configurable', 'wordpress_ability', 'availability_channels', 'availability_model' );
		$keys[]  = $derived ? 'derived' : 'always_on';
		if ( $derived ) {
			$keys[] = 'dependency_ids';
			$keys[] = 'dependency_tools';
		}
		if ( false === ( $entry['available'] ?? null ) ) {
			$keys[] = 'blocked_by';
		}
		if ( array_key_exists( 'missing_scopes', $entry ) ) {
			$keys[] = 'missing_scopes';
		}
		if ( ! $this->has_exact_keys( $entry, $keys ) ) {
			return false;
		}

		$channels = $entry['availability_channels'] ?? null;
		if (
			! is_bool( $entry['core_default'] ?? null )
			|| ! is_bool( $entry['configurable'] ?? null )
			|| ! is_array( $entry['wordpress_ability'] ?? null ) || array_is_list( $entry['wordpress_ability'] )
			|| ! is_array( $channels ) || array_is_list( $channels )
			|| ! $this->has_exact_keys( $channels, array( 'mcp', 'wordpress_abilities', 'summary', 'wordpress_status' ) )
			|| ! is_bool( $channels['mcp'] ?? null )
			|| ! is_bool( $channels['wordpress_abilities'] ?? null )
			|| ! in_array( $channels['summary'] ?? null, array( 'both', 'mcp_only', 'wordpress_abilities_only', 'neither' ), true )
			|| ! $this->matches_patterned_string( $channels['wordpress_status'] ?? null, 64, '/^[a-z0-9][a-z0-9_-]{0,63}$/D' )
			|| array( $derived ? 'content:draft' : 'content:read' ) !== ( $entry['required_scopes'] ?? null )
		) {
			return false;
		}

		if ( false === $entry['available'] && ! $this->matches_nonempty_string( $entry['blocked_by'] ?? null, 512 ) ) {
			return false;
		}
		if ( isset( $entry['missing_scopes'] ) && ! $this->string_list_matches( $entry['missing_scopes'], 16, 64 ) ) {
			return false;
		}

		return $derived
			? true === ( $entry['derived'] ?? null )
				&& 'derived_from_allowed_dependencies' === ( $entry['availability_model'] ?? null )
				&& $this->identifier_list_matches( $entry['dependency_ids'] ?? null, 16 )
				&& $this->identifier_list_matches( $entry['dependency_tools'] ?? null, 16 )
			: true === ( $entry['always_on'] ?? null )
				&& 'core_default_read' === ( $entry['availability_model'] ?? null );
	}

	/**
	 * Verify the upstream instructions before replacing them with stable codes.
	 *
	 * @param mixed  $raw          Raw next actions.
	 * @param string $content_mode Content mode.
	 */
	private function next_actions_are_current( mixed $raw, string $content_mode ): bool {
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) || 4 !== count( $raw ) ) {
			return false;
		}

		$compose = in_array( $content_mode, self::LAYOUT_MODES, true )
			? 'Generate sectioned, layout-aware serialized WordPress block markup using the outline section IDs and layout_plan; prefer registered patterns, core/group, core/columns, core/cover, core/media-text, and editable media/text blocks when they match the visual direction.'
			: 'Generate sectioned serialized WordPress block markup using the outline section IDs.';

		return array(
			'Use intelligence_context.memories, related_items, relevant_chunks, and internal_links while drafting.',
			$compose,
			'Validate the full block document before any write.',
			'Call content_workflow_create_draft for new long-form content or content_workflow_update_post for an existing item.',
		) === $raw;
	}

	/**
	 * Ensure the existing module and its input contract have not drifted.
	 */
	private function ability_contract_is_current(): bool {
		$module = $this->abilities->module( self::INTERNAL_ABILITY_ID );
		if (
			null === $module
			|| self::INTERNAL_ABILITY_ID !== $module->id()
			|| ! $module->is_read_only()
			|| array( 'content:read' ) !== $module->required_scopes()
		) {
			return false;
		}

		try {
			$current  = $this->semantic_schema( AbilityExecutionGateway::input_schema_for_module( $module ) );
			$expected = $this->expected_internal_input_schema();
			$encoder  = new WorkflowPlanningCanonicalizer();
			if ( ! hash_equals( $encoder->normalize_and_encode( $expected )['json'], $encoder->normalize_and_encode( $current )['json'] ) ) {
				return false;
			}

			return $this->input_schema_is_compatible_subset( $this->input_schema(), $current );
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return false;
		}
	}

	/**
	 * Return the semantic upstream schema with descriptions removed.
	 *
	 * @return array<string, mixed>
	 */
	private function expected_internal_input_schema(): array {
		$string = array( 'type' => 'string' );

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'brief'                    => $string,
				'post_type'                => $string,
				'audience'                 => $string,
				'seo_intent'               => $string,
				'desired_word_count'       => array(
					'type'    => 'integer',
					'minimum' => 3000,
					'maximum' => 5000,
				),
				'content_mode'             => array(
					'type' => 'string',
					'enum' => self::CONTENT_MODES,
				),
				'content_type'             => $string,
				'layout_intent'            => $string,
				'visual_reference_summary' => $string,
				'section_requirements'     => array(
					'type'     => 'array',
					'items'    => $string,
					'maxItems' => 12,
				),
				'preferred_block_families' => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'string',
						'enum' => self::BLOCK_FAMILIES,
					),
					'maxItems' => 8,
				),
				'preferred_blocks'         => array(
					'type'     => 'array',
					'items'    => $string,
					'maxItems' => 20,
				),
				'preferred_patterns'       => array(
					'type'     => 'array',
					'items'    => $string,
					'maxItems' => 12,
				),
				'existing_post_id'         => array( 'type' => 'integer' ),
				'workflow_session_id'      => $string,
			),
			'additionalProperties' => false,
			'required'             => array( 'brief' ),
		);
	}

	/**
	 * Remove presentation-only schema descriptions recursively.
	 *
	 * @param array<string, mixed> $schema Raw module schema.
	 * @return array<string, mixed>
	 */
	private function semantic_schema( array $schema ): array {
		unset( $schema['description'] );
		foreach ( $schema as $key => $value ) {
			if ( is_array( $value ) ) {
				$schema[ $key ] = array_is_list( $value )
					? array_map( fn( mixed $item ): mixed => is_array( $item ) ? $this->semantic_schema( $item ) : $item, $value )
					: $this->semantic_schema( $value );
			}
		}

		return $schema;
	}

	/**
	 * Verify that the adapter accepts only values also accepted upstream.
	 *
	 * @param array<string, mixed> $subset Adapter schema.
	 * @param array<string, mixed> $upstream Upstream schema.
	 */
	private function input_schema_is_compatible_subset( array $subset, array $upstream ): bool {
		if ( ( $subset['type'] ?? null ) !== ( $upstream['type'] ?? null ) || false !== ( $subset['additionalProperties'] ?? null ) || false !== ( $upstream['additionalProperties'] ?? null ) ) {
			return false;
		}

		$subset_properties   = is_array( $subset['properties'] ?? null ) ? $subset['properties'] : array();
		$upstream_properties = is_array( $upstream['properties'] ?? null ) ? $upstream['properties'] : array();
		if ( isset( $subset_properties['workflow_session_id'] ) ) {
			return false;
		}
		if ( array() !== array_diff( (array) ( $upstream['required'] ?? array() ), (array) ( $subset['required'] ?? array() ) ) ) {
			return false;
		}

		foreach ( $subset_properties as $key => $candidate ) {
			$owner = $upstream_properties[ $key ] ?? null;
			if ( ! is_array( $candidate ) || ! is_array( $owner ) || ! $this->schema_node_is_subset( $candidate, $owner ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Compare the type and every upstream enum/range constraint.
	 *
	 * @param array<string, mixed> $subset Candidate subset node.
	 * @param array<string, mixed> $upstream Upstream node.
	 */
	private function schema_node_is_subset( array $subset, array $upstream ): bool {
		if ( ( $subset['type'] ?? null ) !== ( $upstream['type'] ?? null ) ) {
			return false;
		}
		if ( isset( $upstream['enum'] ) && ( ! isset( $subset['enum'] ) || array() !== array_diff( (array) $subset['enum'], (array) $upstream['enum'] ) ) ) {
			return false;
		}
		foreach ( array( 'minimum', 'minLength', 'minItems' ) as $minimum ) {
			if ( isset( $upstream[ $minimum ] ) && ( ! isset( $subset[ $minimum ] ) || $subset[ $minimum ] < $upstream[ $minimum ] ) ) {
				return false;
			}
		}
		foreach ( array( 'maximum', 'maxLength', 'maxItems' ) as $maximum ) {
			if ( isset( $upstream[ $maximum ] ) && ( ! isset( $subset[ $maximum ] ) || $subset[ $maximum ] > $upstream[ $maximum ] ) ) {
				return false;
			}
		}

		if ( isset( $subset['items'] ) ) {
			return isset( $upstream['items'] ) && is_array( $subset['items'] ) && is_array( $upstream['items'] ) && $this->schema_node_is_subset( $subset['items'], $upstream['items'] );
		}

		return true;
	}

	/**
	 * Reject unexpectedly large or non-JSON raw ability results before projection.
	 *
	 * @param array<string, mixed> $output Raw output.
	 */
	private function raw_output_is_bounded( array $output ): bool {
		$nodes = 0;
		if ( ! $this->raw_node_is_bounded( $output, 0, $nodes ) ) {
			return false;
		}

		$encoded = wp_json_encode( $output );
		return is_string( $encoded ) && self::MAX_RAW_OUTPUT_BYTES >= strlen( $encoded );
	}

	/**
	 * Enforce raw output depth, node, collection, key, and string budgets.
	 *
	 * @param mixed $value Value to inspect.
	 * @param int   $depth Current depth.
	 * @param int   $nodes Running node count.
	 */
	private function raw_node_is_bounded( mixed $value, int $depth, int &$nodes ): bool {
		if ( self::MAX_RAW_DEPTH < $depth || self::MAX_RAW_NODES < ++$nodes ) {
			return false;
		}
		if ( is_string( $value ) ) {
			return self::MAX_RAW_STRING_BYTES >= strlen( $value );
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return true;
		}
		if ( ! is_array( $value ) || self::MAX_RAW_ITEMS < count( $value ) ) {
			return false;
		}

		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && self::MAX_RAW_KEY_BYTES < strlen( $key ) ) {
				return false;
			}
			if ( ! $this->raw_node_is_bounded( $item, $depth + 1, $nodes ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build a bounded string-list schema.
	 *
	 * @param int $max_items  Maximum list items.
	 * @param int $max_length Maximum string length.
	 * @return array<string, mixed>
	 */
	private function string_list_schema( int $max_items, int $max_length ): array {
		return array(
			'type'     => 'array',
			'items'    => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => $max_length,
			),
			'maxItems' => $max_items,
		);
	}

	/**
	 * Build a bounded identifier-list schema.
	 *
	 * @param int $max_items Maximum list items.
	 * @return array<string, mixed>
	 */
	private function identifier_list_schema( int $max_items ): array {
		$schema                     = $this->string_list_schema( $max_items, 128 );
		$schema['items']['pattern'] = '^[A-Za-z0-9][A-Za-z0-9_/.:-]{0,127}$';
		return $schema;
	}

	/**
	 * Determine whether an object-like array has exactly the expected keys.
	 *
	 * @param array<mixed> $value         Value to inspect.
	 * @param array        $expected_keys Expected keys.
	 * @phpstan-param list<string> $expected_keys
	 */
	private function has_exact_keys( array $value, array $expected_keys ): bool {
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		sort( $expected_keys, SORT_STRING );
		return $expected_keys === $keys;
	}

	private function matches_nonempty_string( mixed $value, int $maximum ): bool {
		return is_string( $value ) && '' !== $value && $maximum >= strlen( $value );
	}

	private function matches_patterned_string( mixed $value, int $maximum, string $pattern ): bool {
		return $this->matches_nonempty_string( $value, $maximum ) && 1 === preg_match( $pattern, $value );
	}

	/**
	 * Determine whether every map value fits a string bound.
	 *
	 * @param array<string, mixed> $map     Map to inspect.
	 * @param int                  $maximum Maximum string length.
	 */
	private function bounded_string_map( array $map, int $maximum ): bool {
		foreach ( $map as $value ) {
			if ( ! is_string( $value ) || $maximum < strlen( $value ) ) {
				return false;
			}
		}
		return true;
	}

	private function string_list_matches( mixed $value, int $maximum_items, int $maximum_length ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || $maximum_items < count( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! $this->matches_nonempty_string( $item, $maximum_length ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Determine whether a value is a bounded list from a fixed enum.
	 *
	 * @param mixed $value         Value to inspect.
	 * @param array $enum          Allowed values.
	 * @param int   $maximum_items Maximum list items.
	 * @phpstan-param list<string> $enum
	 */
	private function enum_list_matches( mixed $value, array $enum, int $maximum_items ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || $maximum_items < count( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || ! in_array( $item, $enum, true ) ) {
				return false;
			}
		}
		return true;
	}

	private function block_name_list_matches( mixed $value, int $maximum_items ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || 1 > count( $value ) || $maximum_items < count( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! $this->matches_patterned_string( $item, 128, '/^[A-Za-z0-9][A-Za-z0-9_.-]*\/[A-Za-z0-9][A-Za-z0-9_.-]*$/D' ) ) {
				return false;
			}
		}
		return true;
	}

	private function identifier_list_matches( mixed $value, int $maximum_items ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || $maximum_items < count( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! $this->matches_patterned_string( $item, 128, '/^[A-Za-z0-9][A-Za-z0-9_\/.:-]{0,127}$/D' ) ) {
				return false;
			}
		}
		return true;
	}
}

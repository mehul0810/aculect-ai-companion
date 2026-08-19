<?php
/**
 * WordPress v1 read-only workflow adapter.
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
 * Maps content/get-item to the existing content.get_item gateway boundary.
 *
 * @internal This adapter intentionally has no direct ability-registry or
 * content-service execution path.
 */
final class WordPressReadAdapter implements WorkflowAdapterInterface {

	private const ADAPTER_ID          = 'wordpress';
	private const ADAPTER_VERSION     = 1;
	private const WORKFLOW_ABILITY_ID = 'content/get-item';
	private const INTERNAL_ABILITY_ID = 'content.get_item';
	private const STEP_KIND           = 'read';

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
		return array( 'read_post' );
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array( 'type' => 'integer' ),
			),
			'additionalProperties' => false,
			'required'             => array( 'id' ),
		);
	}

	public function output_schema(): array {
		$term_image_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array( 'type' => 'integer' ),
				'meta_key'      => array( 'type' => 'string' ),
				'source_url'    => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
		$term_schema       = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array( 'type' => 'integer' ),
				'taxonomy'    => array( 'type' => 'string' ),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'parent'      => array( 'type' => 'integer' ),
				'count'       => array( 'type' => 'integer' ),
				'image'       => $term_image_schema,
			),
			'additionalProperties' => false,
			'required'             => array( 'id', 'taxonomy', 'name', 'slug', 'description', 'parent', 'count', 'image' ),
		);
		$block_schema      = array(
			'type'                 => 'object',
			'properties'           => array(
				'path'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'path_label' => array( 'type' => 'string' ),
				'block_name' => array( 'type' => 'string' ),
				'text'       => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
			'required'             => array( 'path', 'path_label', 'block_name', 'text' ),
		);

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                  => array( 'type' => 'integer' ),
				'type'                => array( 'type' => 'string' ),
				'title'               => array( 'type' => 'string' ),
				'slug'                => array( 'type' => 'string' ),
				'status'              => array( 'type' => 'string' ),
				'content'             => array( 'type' => 'string' ),
				'excerpt'             => array( 'type' => 'string' ),
				'author'              => array( 'type' => 'integer' ),
				'author_display_name' => array( 'type' => 'string' ),
				'featured_media'      => array( 'type' => 'integer' ),
				'date'                => array( 'type' => 'string' ),
				'date_gmt'            => array( 'type' => 'string' ),
				'modified_gmt'        => array( 'type' => 'string' ),
				'link'                => array( 'type' => 'string' ),
				'terms'               => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'  => 'array',
						'items' => $term_schema,
					),
				),
				'block_locators'      => array(
					'type'  => 'array',
					'items' => $block_schema,
				),
				'mime_type'           => array( 'type' => 'string' ),
				'source_url'          => array( 'type' => 'string' ),
				'alt_text'            => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
			'required'             => array(
				'id',
				'type',
				'title',
				'slug',
				'status',
				'content',
				'excerpt',
				'author',
				'author_display_name',
				'featured_media',
				'date',
				'date_gmt',
				'modified_gmt',
				'link',
				'terms',
				'block_locators',
			),
		);
	}

	public function execute( WorkflowPlan $plan, string $step_id, array $arguments, array $auth ): WorkflowAdapterResult {
		$binding = WorkflowPlanStepBinding::from_plan( $plan, $step_id );
		if (
			null === $binding
			|| ! $binding->belongs_to( $plan )
			|| self::ADAPTER_ID !== $binding->adapter_id()
			|| self::ADAPTER_VERSION !== $binding->adapter_version()
			|| self::WORKFLOW_ABILITY_ID !== $binding->ability_id()
			|| self::STEP_KIND !== $binding->kind()
		) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH );
		}

		$internal_ability_id = $this->mapped_internal_ability_id( $binding->ability_id() );
		if ( null === $internal_ability_id || ! $this->ability_contract_is_current( $internal_ability_id ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_ABILITY_CONTRACT_MISMATCH );
		}

		try {
			$input     = WorkflowInputContract::from_value( $arguments );
			$arguments = get_object_vars( $input->value() );
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_INVALID_ARGUMENTS );
		}

		try {
			$outcome = $this->gateway->execute(
				new AbilityExecutionRequest(
					array(
						'name'      => $internal_ability_id,
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
		if ( ! is_array( $output ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
		}
		if ( isset( $output['error'] ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_ABILITY_FAILED );
		}

		return $this->validated_output_result( $output );
	}

	/**
	 * Validate the gateway result against this adapter's closed output contract.
	 *
	 * An empty array represents the ability's intentionally empty object result
	 * for a missing or forbidden post. Non-empty lists are never valid objects.
	 *
	 * @param array<mixed> $output Gateway result.
	 */
	private function validated_output_result( array $output ): WorkflowAdapterResult {
		if ( array() === $output ) {
			return WorkflowAdapterResult::success( array() );
		}
		if ( array_is_list( $output ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
		}
		if ( ! $this->collections_match_contract( $output ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
		}

		try {
			$output = $this->normalize_empty_collection_objects( $output );

			$contract   = WorkflowInputContract::from_value( $output );
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
	 * Preserve object semantics for empty dynamic maps in the JSON contract.
	 *
	 * @param array<mixed> $output Validated raw output.
	 * @return array<mixed>
	 */
	private function normalize_empty_collection_objects( array $output ): array {
		if ( array() === $output['terms'] ) {
			$output['terms'] = new \stdClass();

			return $output;
		}

		foreach ( $output['terms'] as $taxonomy => $terms ) {
			foreach ( $terms as $index => $term ) {
				if ( array() === $term['image'] ) {
					$output['terms'][ $taxonomy ][ $index ]['image'] = new \stdClass();
				}
			}
		}

		return $output;
	}

	/**
	 * Validate the dynamic taxonomy map and nested collection records exactly.
	 *
	 * @param array<mixed> $output Non-empty ability output.
	 */
	private function collections_match_contract( array $output ): bool {
		if ( ! isset( $output['terms'], $output['block_locators'] ) || ! is_array( $output['terms'] ) || ! is_array( $output['block_locators'] ) ) {
			return false;
		}
		if ( ! array_is_list( $output['block_locators'] ) ) {
			return false;
		}

		foreach ( $output['block_locators'] as $locator ) {
			if ( ! is_array( $locator ) || ! $this->has_exact_keys( $locator, array( 'path', 'path_label', 'block_name', 'text' ) ) ) {
				return false;
			}
			if ( ! is_array( $locator['path'] ) || ! array_is_list( $locator['path'] ) || ! is_string( $locator['path_label'] ) || ! is_string( $locator['block_name'] ) || ! is_string( $locator['text'] ) ) {
				return false;
			}
			foreach ( $locator['path'] as $path_part ) {
				if ( ! is_int( $path_part ) || $path_part < 0 ) {
					return false;
				}
			}
		}

		foreach ( $output['terms'] as $taxonomy => $terms ) {
			if ( ! is_string( $taxonomy ) || '' === $taxonomy || ! is_array( $terms ) || ! array_is_list( $terms ) ) {
				return false;
			}
			foreach ( $terms as $term ) {
				if ( ! $this->term_matches_contract( $term, $taxonomy ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Validate one exact term and its optional image record.
	 *
	 * @param mixed  $term     Term record.
	 * @param string $taxonomy Owning taxonomy-map key.
	 */
	private function term_matches_contract( mixed $term, string $taxonomy ): bool {
		if ( ! is_array( $term ) || ! $this->has_exact_keys( $term, array( 'id', 'taxonomy', 'name', 'slug', 'description', 'parent', 'count', 'image' ) ) ) {
			return false;
		}
		if (
			! is_int( $term['id'] )
			|| ! is_string( $term['taxonomy'] )
			|| $taxonomy !== $term['taxonomy']
			|| ! is_string( $term['name'] )
			|| ! is_string( $term['slug'] )
			|| ! is_string( $term['description'] )
			|| ! is_int( $term['parent'] )
			|| ! is_int( $term['count'] )
			|| ! is_array( $term['image'] )
		) {
			return false;
		}

		$image = $term['image'];
		if ( array() === $image ) {
			return true;
		}

		return $this->has_exact_keys( $image, array( 'attachment_id', 'meta_key', 'source_url' ) )
			&& is_int( $image['attachment_id'] )
			&& is_string( $image['meta_key'] )
			&& is_string( $image['source_url'] );
	}

	/**
	 * Whether an array has exactly the expected string keys.
	 *
	 * @param array<mixed> $value         Value to inspect.
	 * @param array        $expected_keys Exact expected keys.
	 * @phpstan-param list<string> $expected_keys
	 */
	private function has_exact_keys( array $value, array $expected_keys ): bool {
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		sort( $expected_keys, SORT_STRING );

		return $expected_keys === $keys;
	}

	/**
	 * Map the one supported workflow ability without generic alias expansion.
	 *
	 * @param string $workflow_ability_id Slash-separated workflow ability ID.
	 */
	private function mapped_internal_ability_id( string $workflow_ability_id ): ?string {
		return self::WORKFLOW_ABILITY_ID === $workflow_ability_id ? self::INTERNAL_ABILITY_ID : null;
	}

	/**
	 * Ensure the existing module has not drifted beyond this adapter contract.
	 *
	 * @param string $internal_ability_id Internal dotted ability ID.
	 */
	private function ability_contract_is_current( string $internal_ability_id ): bool {
		$module = $this->abilities->module( $internal_ability_id );
		if (
			null === $module
			|| self::INTERNAL_ABILITY_ID !== $module->id()
			|| ! $module->is_read_only()
		) {
			return false;
		}

		try {
			$canonicalizer = new WorkflowPlanningCanonicalizer();
			$current       = $canonicalizer->normalize_and_encode( AbilityExecutionGateway::input_schema_for_module( $module ) )['json'];
			$expected      = $canonicalizer->normalize_and_encode( $this->input_schema() )['json'];

			return hash_equals( $expected, $current );
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return false;
		}
	}
}

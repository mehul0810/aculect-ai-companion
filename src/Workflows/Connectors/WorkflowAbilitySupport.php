<?php
/**
 * Normalization helpers for the custom workflow connector.
 *
 * @package Aculect\AICompanion\Workflows\Connectors
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Connectors;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRecord;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunRecord;
use stdClass;

/**
 * Keeps connector boundary normalization out of the orchestration class.
 *
 * @internal
 */
final class WorkflowAbilitySupport {

	public const MAX_LIST = 50;
	public const MAX_PAGE = 1000;

	/**
	 * Explicit mapping from catalog-facing slash IDs to registry IDs.
	 *
	 * Search and related-content abilities use distinct internal namespaces, so
	 * deriving an internal ID by replacing separators is unsafe.
	 *
	 * @var array<string, string>
	 */
	private const INTERNAL_IDS = array(
		'content/get-item'            => 'content.get_item',
		'content/prepare-draft'       => 'content_workflow.prepare_post',
		'content/list-items'          => 'content.list_items',
		'content/get-seo'             => 'content.get_seo',
		'media/list-items'            => 'media.list_items',
		'media/get-item'              => 'media.get_item',
		'media/audit-usage'           => 'media.audit_usage',
		'content/search-items'        => 'content_search.items',
		'content/search-chunks'       => 'content_search.chunks',
		'content/find-related'        => 'content_find.related',
		'content/find-internal-links' => 'content_find.internal_links',
		'content/create-item'         => 'content.create_item',
		'content/update-item'         => 'content.update_item',
		'content/update-block'        => 'content.update_block',
		'content/update-seo'          => 'content.update_seo',
		'media/update-item'           => 'media.update_item',
		'media/upload-item'           => 'media.upload_item',
		'content-media/apply-image'   => 'content_media.apply_image',
	);

	/**
	 * Return the internal registry ID for a public catalog ID.
	 *
	 * @param string $ability_id Public catalog ID.
	 */
	public static function internal_ability_id( string $ability_id ): string {
		return self::INTERNAL_IDS[ strtolower( $ability_id ) ] ?? '';
	}

	/**
	 * Normalize a public workflow identifier.
	 *
	 * @param mixed $value Candidate identifier.
	 */
	public static function identifier( mixed $value ): string {
		$value = strtolower( trim( (string) $value ) );
		$value = (string) preg_replace( '/[^a-z0-9_-]/', '', $value );

		return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{2,63}$/D', $value ) ? $value : '';
	}

	/**
	 * Normalize a public run identifier.
	 *
	 * @param mixed $value Candidate run ID.
	 */
	public static function run_id( mixed $value ): string {
		$value = trim( (string) $value );

		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{1,63}$/D', $value ) ? $value : '';
	}

	/**
	 * Bound a workflow list size.
	 *
	 * @param mixed $value Candidate page size.
	 */
	public static function bounded_limit( mixed $value ): int {
		$value = is_int( $value ) ? $value : self::MAX_LIST;

		return max( 1, min( self::MAX_LIST, $value ) );
	}

	/**
	 * Bound a workflow list page.
	 *
	 * @param mixed $value Candidate page number.
	 */
	public static function bounded_page( mixed $value ): int {
		$value = is_int( $value ) ? $value : 1;

		return max( 1, min( self::MAX_PAGE, $value ) );
	}

	/**
	 * Convert an object-like value to an associative array.
	 *
	 * @param mixed $value Candidate map.
	 * @return array<string,mixed>|null
	 */
	public static function map( mixed $value ): ?array {
		if ( $value instanceof stdClass ) {
			$value = get_object_vars( $value );
		}

		return is_array( $value ) && ! array_is_list( $value ) ? $value : null;
	}

	/**
	 * Determine whether a definition record is publicly published.
	 *
	 * @param WorkflowDefinitionRecord $record Definition record.
	 */
	public static function is_published( WorkflowDefinitionRecord $record ): bool {
		return 'disabled' !== $record->status() && 'published' === (string) ( $record->definition()->to_array()['status'] ?? '' );
	}

	/**
	 * Determine whether the current user may view a run.
	 *
	 * @param WorkflowRunRecord   $run  Run record.
	 * @param array<string,mixed> $auth Auth context.
	 */
	public static function can_view( WorkflowRunRecord $run, array $auth ): bool {
		if ( $run->created_by() === (int) $auth['user_id'] ) {
			return true;
		}
		if ( in_array( 'manage_options', (array) ( $auth['capabilities'] ?? array() ), true ) ) {
			return true;
		}

		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}
}

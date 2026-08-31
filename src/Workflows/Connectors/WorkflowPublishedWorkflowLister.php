<?php
/**
 * Published custom workflow listing service.
 *
 * @package Aculect\AICompanion\Workflows\Connectors
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Connectors;

use Aculect\AICompanion\Connectors\MCP\WorkflowGuideRegistry;
use Aculect\AICompanion\Workflows\Authorization\WorkflowRoleAccessPolicy;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRecord;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRepositoryInterface;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRepositoryException;
use Throwable;

/**
 * Applies role narrowing before projecting a bounded published workflow list.
 *
 * The repository's page_stride is deliberately separate from the lookahead
 * count. This service scans additional source pages only when role filtering
 * removes rows, preserving complete pages without leaking restricted records.
 */
final class WorkflowPublishedWorkflowLister {

	public function __construct(
		private readonly WorkflowDefinitionRepositoryInterface $definitions,
		private readonly WorkflowRoleAccessPolicy $role_access
	) {
	}

	/**
	 * Build the public list response.
	 *
	 * @param array<string,mixed> $args Tool arguments.
	 * @param array<string,mixed> $auth Trusted authenticated actor context.
	 * @return array<string,mixed>
	 * @throws WorkflowDefinitionRepositoryException When storage is unavailable.
	 */
	public function list( array $args, array $auth ): array {
		$limit                  = WorkflowAbilitySupport::bounded_limit( $args['limit'] ?? WorkflowAbilitySupport::MAX_LIST );
		$page                   = WorkflowAbilitySupport::bounded_page( $args['page'] ?? 1 );
		[ $records, $has_more ] = $this->published_records( $limit, $page, $auth );
		$items                  = array_map( fn ( WorkflowDefinitionRecord $record ): array => $this->summary( $record ), $records );

		return array(
			'status'           => 'ok',
			'custom_workflows' => $items,
			'fixed_guides'     => $this->guides( $limit ),
			'pagination'       => array(
				'page'      => $page,
				'per_page'  => $limit,
				'returned'  => count( $items ),
				'has_more'  => $has_more,
				'next_page' => $has_more ? $page + 1 : null,
			),
			'bounded'          => true,
			'next_actions'     => array(
				'Call content_workflow_get for one published workflow before preparing a run.',
				'Use content_workflow_prepare with an input object; missing fields are returned without mutation.',
				...( $has_more ? array( 'Call content_workflow_list with page=' . ( $page + 1 ) . ' to continue.' ) : array() ),
			),
		);
	}

	/**
	 * Scan source pages from the beginning of the visible sequence.
	 *
	 * Role filtering can promote a lookahead row into the current page. Starting
	 * each request from page one makes the public page number a visible filtered
	 * offset, so the next page cannot restart at a source row already returned.
	 * The bounded scan still uses the repository's page_stride lookahead and
	 * stops as soon as the requested page has one lookahead row.
	 *
	 * @param int                 $limit Page size.
	 * @param int                 $page  Requested visible page.
	 * @param array<string,mixed> $auth  Trusted authenticated actor context.
	 * @return array{0:list<WorkflowDefinitionRecord>,1:bool}
	 * @throws WorkflowDefinitionRepositoryException When storage is unavailable.
	 */
	private function published_records( int $limit, int $page, array $auth ): array {
		$records         = array();
		$source_page     = 1;
		$source_has_more = false;
		$scanned_pages   = 0;
		$seen_records    = array();
		$target_start    = ( $page - 1 ) * $limit;
		$target_end      = $target_start + $limit;

		while ( $scanned_pages < WorkflowAbilitySupport::MAX_PAGE ) {
			$batch           = $this->definitions->list_published(
				array(
					'per_page'    => $limit + 1,
					'page'        => $source_page,
					'page_stride' => $limit,
				)
			);
			$source_has_more = count( $batch ) > $limit;
			foreach ( $batch as $record ) {
				if ( ! $record instanceof WorkflowDefinitionRecord || ! $this->role_access->is_allowed( $record->allowed_roles(), $auth ) ) {
					continue;
				}
				$key = $record->workflow_id() . ':' . $record->published_version();
				if ( isset( $seen_records[ $key ] ) ) {
					continue;
				}
				$seen_records[ $key ] = true;
				$records[]            = $record;
			}

			if ( count( $records ) > $target_end || ! $source_has_more ) {
				break;
			}
			++$source_page;
			++$scanned_pages;
		}

		$has_more = count( $records ) > $target_end || ( $source_has_more && $scanned_pages >= WorkflowAbilitySupport::MAX_PAGE );
		return array( array_slice( $records, $target_start, $limit ), $has_more );
	}

	/**
	 * Return a bounded workflow summary.
	 *
	 * @param WorkflowDefinitionRecord $record Published definition record.
	 * @return array<string,mixed>
	 */
	private function summary( WorkflowDefinitionRecord $record ): array {
		$value = $record->definition()->to_array();

		return array(
			'workflow_id'      => $record->workflow_id(),
			'name'             => (string) ( $value['name'] ?? '' ),
			'description'      => (string) ( $value['description'] ?? '' ),
			'workflow_version' => (int) ( $value['workflow_version'] ?? 0 ),
			'checksum'         => $record->definition()->checksum(),
			'template_id'      => $record->template_id(),
			'write_policy'     => WorkflowAbilitySupport::map( $value['write_policy'] ?? array() ) ?? array(),
			'approval_gates'   => array_values( (array) ( $value['approval_gates'] ?? array() ) ),
			'status'           => 'published',
		);
	}

	/**
	 * Return fixed workflow guides without making guide failures fatal.
	 *
	 * @param int $limit Page size.
	 * @return list<array<string,mixed>>
	 */
	private function guides( int $limit ): array {
		try {
			$payload = ( new WorkflowGuideRegistry() )->list_guides(
				array(
					'detail'         => 'summary',
					'available_only' => false,
				)
			);
		} catch ( Throwable ) {
			return array();
		}

		return is_array( $payload['items'] ?? null ) ? array_slice( $payload['items'], 0, $limit ) : array();
	}
}

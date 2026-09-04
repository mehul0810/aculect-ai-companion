<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Intelligence\ContentIndexRepository;

/**
 * Returns durable memory through a permission-safe reviewed projection.
 */
final class MemoryListService {

	public function __construct(
		private readonly ?ContentIndexRepository $repository = null
	) {
	}

	/**
	 * List durable Aculect memory items.
	 *
	 * @param array<string, mixed> $args            Query arguments.
	 * @param bool                 $can_review_all  Whether pending and dismissed memory may be reviewed.
	 * @return array<string, mixed>
	 */
	public function list( array $args, bool $can_review_all ): array {
		$requested_status = sanitize_key( (string) ( $args['status'] ?? 'approved' ) );
		if ( ! $can_review_all ) {
			if ( 'approved' !== $requested_status ) {
				return array(
					'status'  => 'error',
					'error'   => 'forbidden',
					'message' => 'Only administrators may review pending or dismissed Aculect memory.',
				);
			}

			$args['status'] = 'approved';
		}

		$result                 = $this->repo()->list_memories( $args );
		$result['protocol']     = array(
			'source_of_truth' => 'Aculect Intelligence local memory, not ChatGPT or Claude saved memory.',
			'write_path'      => 'Use intelligence_feedback_submit for normal learning suggestions. Use memory_save only when explicit write permission and confirmation are available.',
			'review_default'  => 'New memory_save entries default to pending review unless status is explicitly approved.',
		);
		$result['next_actions'] = array( 'Use relevant memory items as constraints when preparing content workflows.' );

		return $result;
	}

	private function repo(): ContentIndexRepository {
		return $this->repository ?? new ContentIndexRepository();
	}
}

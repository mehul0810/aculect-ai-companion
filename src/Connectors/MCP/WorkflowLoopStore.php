<?php
/**
 * Transient-backed MCP workflow loop state.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Stores bounded item progress so MCP clients can resume collection workflows.
 */
final class WorkflowLoopStore {

	private const TRANSIENT_PREFIX       = 'aculect_ai_companion_workflow_loop_';
	private const TTL                    = 43200;
	private const MAX_ITEMS              = 50;
	private const MAX_BATCH_SIZE         = 10;
	private const MAX_GUIDANCE_LENGTH    = 1200;
	private const DEFAULT_WORD_THRESHOLD = 300;

	private const ITEM_STATUSES = array(
		'pending',
		'running',
		'succeeded',
		'failed',
		'skipped',
		'blocked',
		'cancelled',
	);

	/**
	 * Create a resumable item loop.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function create( array $args ): array {
		$source = $this->source( $args['source'] ?? '' );
		$items  = 'provided_items' === $source ? $this->provided_items( $args ) : $this->thin_page_items( $args );

		if ( array() === $items ) {
			return $this->error( 'workflow_loop_no_items', 'No readable items matched the loop source and filters.' );
		}

		$workflow = $this->key( $args['workflow'] ?? $args['workflow_id'] ?? 'thin_page_cleanup', 80 );
		$loop     = array(
			'id'                  => $this->new_id(),
			'workflow_session_id' => $this->id( $args['workflow_session_id'] ?? '' ),
			'workflow'            => '' === $workflow ? 'thin_page_cleanup' : $workflow,
			'source'              => $source,
			'state'               => 'created',
			'created_at'          => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'updated_at'          => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'user_id'             => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			'objective'           => $this->text( $args['objective'] ?? $args['brief'] ?? 'Improve thin content items.', 300 ),
			'guidance'            => $this->text( $args['guidance'] ?? '', self::MAX_GUIDANCE_LENGTH ),
			'batch_size'          => $this->batch_size( $args['batch_size'] ?? 1 ),
			'filters'             => $this->filters( $source, $args ),
			'items'               => $items,
			'current_item_id'     => 0,
			'events'              => array(
				$this->event( 'created', sprintf( 'Workflow loop created with %d item(s).', count( $items ) ) ),
			),
		);

		$this->write( $loop );

		return $this->response( $loop );
	}

	/**
	 * Read a stored loop.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get( array $args ): array {
		$loop = $this->read_from_args( $args );
		if ( array() === $loop ) {
			return $this->error( 'workflow_loop_not_found', 'No workflow loop was found for that ID.' );
		}

		return $this->response( $loop );
	}

	/**
	 * Mark completed work and return the next item to process.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function run_next( array $args ): array {
		$loop = $this->read_from_args( $args );
		if ( array() === $loop ) {
			return $this->error( 'workflow_loop_not_found', 'No workflow loop was found for that ID.' );
		}

		$loop = $this->apply_completed_item(
			$loop,
			absint( $args['completed_item_id'] ?? 0 ),
			(string) ( $args['completed_status'] ?? '' ),
			(string) ( $args['completed_message'] ?? '' )
		);
		$loop = $this->maybe_resume( $loop, $args );

		if ( $this->is_terminal_or_paused( $loop ) ) {
			$this->write( $loop );
			return $this->response( $loop );
		}

		$running = $this->items_with_status( $loop, 'running' );
		if ( array() !== $running ) {
			$this->write( $loop );
			return $this->response( $loop, array( 'active_item' => $this->active_item( $loop, $running[0] ) ) );
		}

		$index = $this->first_item_index_with_status( $loop, 'pending' );
		if ( null === $index ) {
			$loop = $this->complete_if_finished( $loop );
			$this->write( $loop );
			return $this->response( $loop );
		}

		$loop = $this->mark_item_status( $loop, $index, 'running', 'Loop item started.' );
		$this->write( $loop );

		return $this->response( $loop, array( 'active_item' => $this->active_item( $loop, $loop['items'][ $index ] ) ) );
	}

	/**
	 * Mark completed work and return a bounded batch of items to process.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function run_batch( array $args ): array {
		$loop = $this->read_from_args( $args );
		if ( array() === $loop ) {
			return $this->error( 'workflow_loop_not_found', 'No workflow loop was found for that ID.' );
		}

		foreach ( (array) ( $args['completed_items'] ?? array() ) as $completed ) {
			if ( ! is_array( $completed ) ) {
				continue;
			}

			$loop = $this->apply_completed_item(
				$loop,
				absint( $completed['id'] ?? $completed['item_id'] ?? 0 ),
				(string) ( $completed['status'] ?? '' ),
				(string) ( $completed['message'] ?? '' )
			);
		}
		$loop = $this->maybe_resume( $loop, $args );

		if ( $this->is_terminal_or_paused( $loop ) ) {
			$this->write( $loop );
			return $this->response( $loop, array( 'items_to_process' => array() ) );
		}

		$running = $this->items_with_status( $loop, 'running' );
		if ( array() !== $running ) {
			$this->write( $loop );
			return $this->response( $loop, array( 'items_to_process' => $this->active_items( $loop, $running ) ) );
		}

		$limit   = $this->batch_size( $args['limit'] ?? $loop['batch_size'] ?? 1 );
		$started = array();
		foreach ( $loop['items'] as $index => $item ) {
			if ( count( $started ) >= $limit ) {
				break;
			}

			if ( 'pending' !== (string) ( $item['status'] ?? '' ) ) {
				continue;
			}

			$loop      = $this->mark_item_status( $loop, (int) $index, 'running', 'Loop batch item started.' );
			$started[] = $loop['items'][ $index ];
		}

		$loop = array() === $started ? $this->complete_if_finished( $loop ) : $loop;
		$this->write( $loop );

		return $this->response( $loop, array( 'items_to_process' => $this->active_items( $loop, $started ) ) );
	}

	/**
	 * Pause a loop without discarding item progress.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function pause( array $args ): array {
		$loop = $this->read_from_args( $args );
		if ( array() === $loop ) {
			return $this->error( 'workflow_loop_not_found', 'No workflow loop was found for that ID.' );
		}

		$loop['state']      = 'paused';
		$loop['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$loop['events']     = $this->append_event( $loop, 'paused', $this->text( $args['message'] ?? 'Workflow loop paused.', 240 ) );
		$this->write( $loop );

		return $this->response( $loop );
	}

	/**
	 * Cancel a loop and prevent future run calls from starting pending items.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function cancel( array $args ): array {
		$loop = $this->read_from_args( $args );
		if ( array() === $loop ) {
			return $this->error( 'workflow_loop_not_found', 'No workflow loop was found for that ID.' );
		}

		foreach ( $loop['items'] as $index => $item ) {
			if ( in_array( (string) ( $item['status'] ?? '' ), array( 'pending', 'running' ), true ) ) {
				$loop['items'][ $index ]['status'] = 'cancelled';
			}
		}

		$loop['state']      = 'cancelled';
		$loop['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$loop['events']     = $this->append_event( $loop, 'cancelled', $this->text( $args['message'] ?? 'Workflow loop cancelled.', 240 ) );
		$this->write( $loop );

		return $this->response( $loop );
	}

	/**
	 * Build thin-page items from the content index.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return list<array<string, mixed>>
	 */
	private function thin_page_items( array $args ): array {
		$limit          = min( self::MAX_ITEMS, max( 1, absint( $args['limit'] ?? 20 ) ) );
		$max_word_count = min( 5000, max( 1, absint( $args['max_word_count'] ?? self::DEFAULT_WORD_THRESHOLD ) ) );
		$result         = ( new IntelligenceIndexAbilities() )->search_items(
			array_filter(
				array(
					'query'          => $this->text( $args['query'] ?? '', 200 ),
					'post_type'      => $this->key( $args['post_type'] ?? 'page', 60 ),
					'status'         => $this->key( $args['status'] ?? 'publish', 20 ),
					'max_word_count' => $max_word_count,
					'per_page'       => $limit,
					'context'        => 'compact',
				),
				static fn ( mixed $value ): bool => '' !== $value
			)
		);

		return $this->items_from_rows( (array) ( $result['items'] ?? array() ) );
	}

	/**
	 * Build items from explicit client-provided item references.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return list<array<string, mixed>>
	 */
	private function provided_items( array $args ): array {
		return $this->items_from_rows( (array) ( $args['items'] ?? array() ) );
	}

	/**
	 * Normalize item rows.
	 *
	 * @param array<int, mixed> $rows Raw rows.
	 * @return list<array<string, mixed>>
	 */
	private function items_from_rows( array $rows ): array {
		$items = array();
		$seen  = array();
		foreach ( $rows as $row ) {
			if ( count( $items ) >= self::MAX_ITEMS || ! is_array( $row ) ) {
				continue;
			}

			$id = absint( $row['id'] ?? $row['post_id'] ?? 0 );
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$items[]     = array(
				'id'           => $id,
				'type'         => $this->key( $row['type'] ?? $row['post_type'] ?? 'page', 60 ),
				'post_status'  => $this->key( $row['status'] ?? $row['post_status'] ?? '', 20 ),
				'title'        => $this->text( $row['title'] ?? $row['post_title'] ?? '', 160 ),
				'permalink'    => $this->url( $row['permalink'] ?? $row['url'] ?? '' ),
				'word_count'   => absint( $row['word_count'] ?? 0 ),
				'stale'        => ! empty( $row['stale'] ),
				'status_note'  => '',
				'attempts'     => 0,
				'status_since' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'status'       => 'pending',
			);
		}

		return $items;
	}

	/**
	 * Apply one completed item update.
	 *
	 * @param array<string, mixed> $loop    Loop state.
	 * @param int                  $item_id Item ID.
	 * @param string               $status  New item status.
	 * @param string               $message Completion message.
	 * @return array<string, mixed>
	 */
	private function apply_completed_item( array $loop, int $item_id, string $status, string $message ): array {
		if ( $item_id <= 0 ) {
			return $loop;
		}

		$status = $this->item_status( $status );
		if ( '' === $status || in_array( $status, array( 'pending', 'running' ), true ) ) {
			return $loop;
		}

		foreach ( $loop['items'] as $index => $item ) {
			if ( (int) ( $item['id'] ?? 0 ) !== $item_id ) {
				continue;
			}

			$loop = $this->mark_item_status(
				$loop,
				(int) $index,
				$status,
				'' === $message ? sprintf( 'Loop item marked %s.', $status ) : $message
			);

			return $this->complete_if_finished( $loop );
		}

		$loop['events'] = $this->append_event( $loop, 'blocked', 'Ignored completed item outside the stored loop scope.', $item_id );

		return $loop;
	}

	/**
	 * Mark one item status.
	 *
	 * @param array<string, mixed> $loop    Loop state.
	 * @param int                  $index   Item index.
	 * @param string               $status  New status.
	 * @param string               $message Event message.
	 * @return array<string, mixed>
	 */
	private function mark_item_status( array $loop, int $index, string $status, string $message ): array {
		$status = $this->item_status( $status );
		if ( '' === $status || ! isset( $loop['items'][ $index ] ) ) {
			return $loop;
		}

		$item                    = (array) $loop['items'][ $index ];
		$item['status']          = $status;
		$item['status_note']     = $this->text( $message, 240 );
		$item['status_since']    = gmdate( 'Y-m-d\TH:i:s\Z' );
		$item['attempts']        = 'running' === $status ? absint( $item['attempts'] ?? 0 ) + 1 : absint( $item['attempts'] ?? 0 );
		$loop['items'][ $index ] = $item;
		$loop['current_item_id'] = (int) ( $item['id'] ?? 0 );
		$loop['state']           = in_array( $status, array( 'failed', 'blocked' ), true ) ? 'blocked' : 'running';
		$loop['updated_at']      = gmdate( 'Y-m-d\TH:i:s\Z' );
		$loop['events']          = $this->append_event( $loop, $status, $message, (int) ( $item['id'] ?? 0 ), (string) ( $item['title'] ?? '' ) );

		return $loop;
	}

	/**
	 * Complete a loop when no work remains.
	 *
	 * @param array<string, mixed> $loop Loop state.
	 * @return array<string, mixed>
	 */
	private function complete_if_finished( array $loop ): array {
		if ( array() !== $this->items_with_status( $loop, 'pending' ) || array() !== $this->items_with_status( $loop, 'running' ) ) {
			return $loop;
		}

		$summary = $this->summary( $loop );
		if ( (int) ( $summary['blocked'] ?? 0 ) > 0 || (int) ( $summary['failed'] ?? 0 ) > 0 ) {
			$loop['state'] = 'blocked';
		} else {
			$loop['state'] = 'complete';
		}

		$loop['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$loop['events']     = $this->append_event( $loop, (string) $loop['state'], 'Workflow loop has no pending items.' );

		return $loop;
	}

	/**
	 * Return whether loop state prevents new work.
	 *
	 * @param array<string, mixed> $loop Loop state.
	 */
	private function is_terminal_or_paused( array $loop ): bool {
		return in_array( (string) ( $loop['state'] ?? '' ), array( 'paused', 'cancelled', 'complete' ), true );
	}

	/**
	 * Resume a paused loop when explicitly requested.
	 *
	 * @param array<string, mixed> $loop Loop state.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function maybe_resume( array $loop, array $args ): array {
		if ( 'paused' !== (string) ( $loop['state'] ?? '' ) || empty( $args['resume'] ) ) {
			return $loop;
		}

		$loop['state']      = 'running';
		$loop['updated_at'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$loop['events']     = $this->append_event( $loop, 'running', 'Workflow loop resumed.' );

		return $loop;
	}

	/**
	 * Return active item payloads.
	 *
	 * @param array<string, mixed>       $loop  Loop state.
	 * @param list<array<string, mixed>> $items Items.
	 * @return list<array<string, mixed>>
	 */
	private function active_items( array $loop, array $items ): array {
		return array_map( fn ( array $item ): array => $this->active_item( $loop, $item ), $items );
	}

	/**
	 * Return one active item with next tool guidance.
	 *
	 * @param array<string, mixed> $loop Loop state.
	 * @param array<string, mixed> $item Item state.
	 * @return array<string, mixed>
	 */
	private function active_item( array $loop, array $item ): array {
		$tool = ( new AbilitiesRegistry() )->tool_name( 'content_workflow.prepare_post' );

		return array_merge(
			$this->public_item( $item ),
			array(
				'next_tool'           => $tool,
				'next_tool_arguments' => array_filter(
					array(
						'brief'               => $this->item_brief( $loop, $item ),
						'existing_post_id'    => (int) ( $item['id'] ?? 0 ),
						'post_type'           => (string) ( $item['type'] ?? 'page' ),
						'content_mode'        => 'page' === (string) ( $item['type'] ?? '' ) ? 'service_page' : 'article',
						'workflow_session_id' => (string) ( $loop['workflow_session_id'] ?? '' ),
					),
					static fn ( mixed $value ): bool => '' !== $value && 0 !== $value
				),
			)
		);
	}

	/**
	 * Build a compact item-specific brief.
	 *
	 * @param array<string, mixed> $loop Loop state.
	 * @param array<string, mixed> $item Item state.
	 */
	private function item_brief( array $loop, array $item ): string {
		$parts = array_filter(
			array(
				(string) ( $loop['guidance'] ?? '' ),
				sprintf( 'Target item: #%d %s.', (int) ( $item['id'] ?? 0 ), (string) ( $item['title'] ?? '' ) ),
				sprintf( 'Current indexed word count: %d.', (int) ( $item['word_count'] ?? 0 ) ),
				'Prepare a focused content improvement plan before any write.',
			)
		);

		return $this->text( implode( ' ', $parts ), self::MAX_GUIDANCE_LENGTH );
	}

	/**
	 * Return loop response.
	 *
	 * @param array<string, mixed> $loop  Loop state.
	 * @param array<string, mixed> $extra Extra response fields.
	 * @return array<string, mixed>
	 */
	private function response( array $loop, array $extra = array() ): array {
		return array_merge(
			array(
				'status'        => 'success',
				'workflow'      => 'workflow_loop',
				'workflow_loop' => array(
					'id'                  => (string) ( $loop['id'] ?? '' ),
					'workflow_session_id' => (string) ( $loop['workflow_session_id'] ?? '' ),
					'workflow'            => (string) ( $loop['workflow'] ?? '' ),
					'source'              => (string) ( $loop['source'] ?? '' ),
					'state'               => (string) ( $loop['state'] ?? '' ),
					'objective'           => (string) ( $loop['objective'] ?? '' ),
					'guidance'            => (string) ( $loop['guidance'] ?? '' ),
					'filters'             => (array) ( $loop['filters'] ?? array() ),
					'summary'             => $this->summary( $loop ),
					'current_item'        => $this->current_item( $loop ),
					'recent_events'       => array_slice( (array) ( $loop['events'] ?? array() ), -8 ),
					'next_actions'        => $this->next_actions( $loop ),
				),
				'items'         => array_map( array( $this, 'public_item' ), (array) ( $loop['items'] ?? array() ) ),
				'summary'       => $this->summary( $loop ),
				'next_actions'  => $this->next_actions( $loop ),
			),
			$extra
		);
	}

	/**
	 * Return public item fields.
	 *
	 * @param array<string, mixed> $item Item state.
	 * @return array<string, mixed>
	 */
	private function public_item( array $item ): array {
		return array(
			'id'           => (int) ( $item['id'] ?? 0 ),
			'type'         => (string) ( $item['type'] ?? '' ),
			'status'       => (string) ( $item['status'] ?? '' ),
			'post_status'  => (string) ( $item['post_status'] ?? '' ),
			'title'        => (string) ( $item['title'] ?? '' ),
			'permalink'    => (string) ( $item['permalink'] ?? '' ),
			'word_count'   => (int) ( $item['word_count'] ?? 0 ),
			'stale'        => ! empty( $item['stale'] ),
			'attempts'     => (int) ( $item['attempts'] ?? 0 ),
			'status_note'  => (string) ( $item['status_note'] ?? '' ),
			'status_since' => (string) ( $item['status_since'] ?? '' ),
		);
	}

	/**
	 * Return current item.
	 *
	 * @param array<string, mixed> $loop Loop state.
	 * @return array<string, mixed>
	 */
	private function current_item( array $loop ): array {
		$current_id = absint( $loop['current_item_id'] ?? 0 );
		foreach ( (array) ( $loop['items'] ?? array() ) as $item ) {
			if ( (int) ( $item['id'] ?? 0 ) === $current_id ) {
				return $this->public_item( (array) $item );
			}
		}

		$pending = $this->items_with_status( $loop, 'pending' );
		return array() === $pending ? array() : $this->public_item( $pending[0] );
	}

	/**
	 * Return item status counts.
	 *
	 * @param array<string, mixed> $loop Loop state.
	 * @return array<string, int>
	 */
	private function summary( array $loop ): array {
		$summary = array_fill_keys( self::ITEM_STATUSES, 0 );
		foreach ( (array) ( $loop['items'] ?? array() ) as $item ) {
			$status = (string) ( is_array( $item ) ? ( $item['status'] ?? '' ) : '' );
			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
		}

		$summary['total'] = count( (array) ( $loop['items'] ?? array() ) );

		return $summary;
	}

	/**
	 * Return compact next actions.
	 *
	 * @param array<string, mixed> $loop Loop state.
	 * @return list<string>
	 */
	private function next_actions( array $loop ): array {
		return match ( (string) ( $loop['state'] ?? '' ) ) {
			'created' => array( 'Call workflow_loop_run_next or workflow_loop_run_batch to start bounded item processing.' ),
			'running' => array( 'Use the returned next_tool and next_tool_arguments for each active item, then report item completion on the next loop call.' ),
			'paused' => array( 'Call workflow_loop_run_next or workflow_loop_run_batch with resume=true when ready to resume.' ),
			'blocked' => array( 'Review failed or blocked items, then retry with corrected guidance or skip those items.' ),
			'cancelled' => array( 'No new items will be started for this loop.' ),
			'complete' => array( 'No further workflow loop action is required.' ),
			default => array( 'Read workflow_loop_get for current status.' ),
		};
	}

	/**
	 * Return items matching one status.
	 *
	 * @param array<string, mixed> $loop   Loop state.
	 * @param string               $status Item status.
	 * @return list<array<string, mixed>>
	 */
	private function items_with_status( array $loop, string $status ): array {
		return array_values(
			array_filter(
				(array) ( $loop['items'] ?? array() ),
				static fn ( mixed $item ): bool => is_array( $item ) && (string) ( $item['status'] ?? '' ) === $status
			)
		);
	}

	/**
	 * Return first item index matching one status.
	 *
	 * @param array<string, mixed> $loop   Loop state.
	 * @param string               $status Item status.
	 */
	private function first_item_index_with_status( array $loop, string $status ): ?int {
		foreach ( (array) ( $loop['items'] ?? array() ) as $index => $item ) {
			if ( is_array( $item ) && (string) ( $item['status'] ?? '' ) === $status ) {
				return (int) $index;
			}
		}

		return null;
	}

	/**
	 * Read loop from args.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function read_from_args( array $args ): array {
		$id = $this->id( $args['workflow_loop_id'] ?? $args['loop_id'] ?? $args['id'] ?? '' );

		return '' === $id ? array() : $this->read( $id );
	}

	/**
	 * Read loop by ID.
	 *
	 * @param string $id Loop ID.
	 * @return array<string, mixed>
	 */
	private function read( string $id ): array {
		$stored = get_transient( $this->transient_key( $id ) );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Persist loop state.
	 *
	 * @param array<string, mixed> $loop Loop state.
	 */
	private function write( array $loop ): void {
		set_transient( $this->transient_key( (string) $loop['id'] ), $loop, self::TTL );
	}

	/**
	 * Return transient key.
	 *
	 * @param string $id Loop ID.
	 */
	private function transient_key( string $id ): string {
		return self::TRANSIENT_PREFIX . hash( 'sha256', $id );
	}

	/**
	 * Append one bounded event.
	 *
	 * @param array<string, mixed> $loop    Loop state.
	 * @param string               $state   Event state.
	 * @param string               $message Event message.
	 * @param int                  $item_id Item ID.
	 * @param string               $title   Item title.
	 * @return list<array<string, mixed>>
	 */
	private function append_event( array $loop, string $state, string $message, int $item_id = 0, string $title = '' ): array {
		return array_slice(
			array_merge(
				(array) ( $loop['events'] ?? array() ),
				array( $this->event( $state, $message, $item_id, $title ) )
			),
			-20
		);
	}

	/**
	 * Build one event.
	 *
	 * @param string $state   Event state.
	 * @param string $message Event message.
	 * @param int    $item_id Item ID.
	 * @param string $title   Item title.
	 * @return array<string, mixed>
	 */
	private function event( string $state, string $message, int $item_id = 0, string $title = '' ): array {
		return array(
			'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'state'   => $this->key( $state, 40 ),
			'message' => $this->text( $message, 240 ),
			'item_id' => $item_id,
			'title'   => $this->text( $title, 120 ),
		);
	}

	/**
	 * Return filters stored with the loop.
	 *
	 * @param string               $source Loop source.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @return array<string, mixed>
	 */
	private function filters( string $source, array $args ): array {
		if ( 'thin_pages' !== $source ) {
			return array( 'source' => 'provided_items' );
		}

		return array(
			'source'         => 'thin_pages',
			'query'          => $this->text( $args['query'] ?? '', 200 ),
			'post_type'      => $this->key( $args['post_type'] ?? 'page', 60 ),
			'status'         => $this->key( $args['status'] ?? 'publish', 20 ),
			'max_word_count' => min( 5000, max( 1, absint( $args['max_word_count'] ?? self::DEFAULT_WORD_THRESHOLD ) ) ),
			'limit'          => min( self::MAX_ITEMS, max( 1, absint( $args['limit'] ?? 20 ) ) ),
		);
	}

	/**
	 * Return normalized source.
	 *
	 * @param mixed $value Raw source.
	 */
	private function source( mixed $value ): string {
		$source = $this->key( $value, 40 );

		return in_array( $source, array( 'thin_pages', 'provided_items' ), true ) ? $source : 'thin_pages';
	}

	/**
	 * Return bounded batch size.
	 *
	 * @param mixed $value Raw size.
	 */
	private function batch_size( mixed $value ): int {
		return min( self::MAX_BATCH_SIZE, max( 1, absint( $value ) ) );
	}

	/**
	 * Return normalized item status.
	 *
	 * @param mixed $value Raw status.
	 */
	private function item_status( mixed $value ): string {
		$status = $this->key( $value, 40 );

		return in_array( $status, self::ITEM_STATUSES, true ) ? $status : '';
	}

	/**
	 * Return error response.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @return array<string, mixed>
	 */
	private function error( string $code, string $message ): array {
		return array(
			'status'  => 'error',
			'error'   => $code,
			'message' => $message,
		);
	}

	/**
	 * Generate a loop ID.
	 */
	private function new_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'wl_' . str_replace( '-', '', wp_generate_uuid4() );
		}

		return 'wl_' . substr( hash( 'sha256', uniqid( 'aculect_workflow_loop_', true ) ), 0, 32 );
	}

	/**
	 * Sanitize a stored ID.
	 *
	 * @param mixed $value Raw value.
	 */
	private function id( mixed $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = preg_replace( '/[^A-Za-z0-9_-]+/', '', $value ) ?? '';

		return substr( $value, 0, 80 );
	}

	/**
	 * Sanitize a key.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Max length.
	 */
	private function key( mixed $value, int $limit ): string {
		return substr( sanitize_key( is_scalar( $value ) ? (string) $value : '' ), 0, $limit );
	}

	/**
	 * Sanitize text.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Max length.
	 */
	private function text( mixed $value, int $limit ): string {
		return substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, $limit );
	}

	/**
	 * Sanitize URL.
	 *
	 * @param mixed $value Raw URL.
	 */
	private function url( mixed $value ): string {
		return esc_url_raw( is_scalar( $value ) ? (string) $value : '' );
	}
}

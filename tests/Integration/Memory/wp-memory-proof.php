<?php
/**
 * Real WordPress proof for packaged memory transactions, migrations and sync.
 *
 * @package Aculect\AICompanion\Tests\Integration\Memory
 */

declare(strict_types=1);

use Aculect\AICompanion\Intelligence\Database\Installer;
use Aculect\AICompanion\Intelligence\Database\MemorySchemaMigrator;
use Aculect\AICompanion\Intelligence\LearningSuggestionRepository;
use Aculect\AICompanion\Intelligence\Memory\MemoryRepository;
use Aculect\AICompanion\Intelligence\Memory\MemoryService;
use Aculect\AICompanion\Intelligence\Memory\Sync\SiteMemorySyncAdapter;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Real disposable database semantics are the subject of this proof.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only bounded assertion messages.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily select a second real connection to prove the production backfill race.

/**
 * Stop on a bounded assertion without dumping memory values or database credentials.
 *
 * @param bool   $condition Required condition.
 * @param string $message Failure explanation.
 * @throws RuntimeException When proof fails.
 */
function aculect_memory_proof_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Refuse every non-disposable or non-packaged runtime before any mutation.
 *
 * @param array<mixed> $arguments Explicit WP-CLI eval-file arguments.
 */
function aculect_memory_proof_guard( array $arguments ): void {
	$url = home_url();
	aculect_memory_proof_assert(
		defined( 'WP_CLI' ) && WP_CLI
		&& 'aculect-disposable-memory-proof' === ( $arguments[0] ?? '' )
		&& in_array( wp_parse_url( $url, PHP_URL_HOST ), array( 'localhost', '127.0.0.1', '[::1]' ), true )
		&& 8882 === (int) wp_parse_url( $url, PHP_URL_PORT ),
		'Memory proof requires the explicit disposable localhost:8882 wp-env runtime.'
	);
	aculect_memory_proof_assert( current_user_can( 'manage_options' ), 'Memory proof requires the disposable WordPress administrator.' );
	$source = ( new ReflectionClass( MemoryService::class ) )->getFileName();
	$plugin = realpath( WP_PLUGIN_DIR . '/aculect-ai-companion' );
	aculect_memory_proof_assert( is_string( $source ) && is_string( $plugin ) && str_starts_with( $source, $plugin . '/' ), 'Memory proof is not executing the installed package.' );
	aculect_memory_proof_assert( Installer::install( true ), 'Activated package memory tables are unavailable.' );
}

/**
 * Prove the batch cap and retained metadata collaborator against real SQL.
 *
 * @param string $run Unique disposable fixture identity.
 */
function aculect_memory_proof_batch( string $run ): void {
	$items = array();
	for ( $index = 0; $index < 101; ++$index ) {
		$items[] = array(
			'key'       => 'proof.batch.' . $run . '.' . $index,
			'namespace' => 'proof-batch-' . $run,
			'value'     => 'Disposable batch fixture ' . $index,
		);
	}
	$lookups = 0;
	$observe = static function ( string $query ) use ( &$lookups ): string {
		if ( str_contains( $query, 'information_schema.TABLES' ) && str_contains( $query, ' AS Engine' ) ) {
			++$lookups;
		}
		return $query;
	};
	add_filter( 'query', $observe );
	try {
		$result = ( new MemoryService() )->save_batch( $items );
	} finally {
		remove_filter( 'query', $observe );
	}
	aculect_memory_proof_assert( 100 === count( $result['saved'] ) && true === $result['truncated'], 'Memory batch did not enforce its 100-record cap.' );
	aculect_memory_proof_assert( 1 === count( $result['failures'] ) && 'memory_batch_truncated' === $result['failures'][0]['error'], 'Memory batch omitted the explicit truncation failure.' );
	aculect_memory_proof_assert( 2 === $lookups, 'Memory batch repeated engine metadata queries instead of retaining its collaborator.' );
	foreach ( $result['saved'] as $memory ) {
		$history = ( new MemoryService() )->history( $memory['memory_uuid'], $memory['namespace'] );
		aculect_memory_proof_assert( 1 === count( $history ) && 1 === (int) $history[0]['memory_version'], 'A committed batch item is missing its exact history event.' );
	}
	echo "PASS memory batch cap, atomic history and constant engine lookups\n";
}

/**
 * Prove option-write failure rolls back approved guidance and its event.
 *
 * @param string $run Unique disposable fixture identity.
 */
function aculect_memory_proof_review( string $run ): void {
	global $wpdb;
	$learning = new LearningSuggestionRepository();
	$queued   = $learning->submit(
		array(
			'domain'           => 'content',
			'issue'            => 'Disposable review failure ' . $run,
			'suggested_update' => 'Review must commit with its memory event.',
		)
	);
	aculect_memory_proof_assert( 'queued' === $queued['status'], 'Learning suggestion was not queued.' );
	$id     = $queued['suggestion']['id'];
	$key    = 'learning.content.' . $id;
	$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Installer::memory_events_table() ) );
	$reject = static fn ( mixed $next, mixed $previous ): mixed => $previous;
	add_filter( 'pre_update_option_aculect_ai_companion_learning_suggestions', $reject, 10, 2 );
	try {
		$approved = $learning->review( $id, 'approve' );
	} finally {
		remove_filter( 'pre_update_option_aculect_ai_companion_learning_suggestions', $reject, 10 );
	}
	aculect_memory_proof_assert( false === $approved, 'Learning review reported success after WordPress rejected its option write.' );
	aculect_memory_proof_assert( array() === ( new MemoryRepository() )->find( $key ), 'Failed review left approved guidance in the real memory table.' );
	$after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Installer::memory_events_table() ) );
	aculect_memory_proof_assert( $before === $after, 'Failed review left a committed memory event.' );
	$items = array_column( $learning->admin_payload()['items'], null, 'id' );
	aculect_memory_proof_assert( 'pending' === $items[ $id ]['status'], 'Failed review did not retain the pending suggestion.' );
	aculect_memory_proof_assert( $learning->review( $id, 'approve' ), 'Review did not succeed after the option failure was removed.' );
	$memory  = ( new MemoryRepository() )->find( $key );
	$history = ( new MemoryService() )->history( $memory['memory_uuid'] );
	aculect_memory_proof_assert( 'approved' === $memory['status'] && 1 === count( $history ) && 1 === (int) $history[0]['memory_version'], 'Review retry did not create exactly one approved memory/event pair.' );
	$items = array_column( $learning->admin_payload()['items'], null, 'id' );
	aculect_memory_proof_assert( 'approved' === $items[ $id ]['status'], 'Successful review did not persist its option state.' );
	echo "PASS learning option failure rolls back memory/history; retry commits one event\n";
}

/**
 * Inject one independent queue mutation immediately before a primary queue CAS.
 *
 * @param wpdb                  $second     Second disposable database connection.
 * @param callable():array|bool $concurrent Independent queue operation.
 * @param array<string,mixed>   $outcome    Captured independent operation result.
 * @return Closure Query filter.
 */
function aculect_memory_proof_queue_interleave( wpdb $second, callable $concurrent, array &$outcome ): Closure {
	global $wpdb;
	$primary = $wpdb;
	$table   = $wpdb->options;

	return static function ( string $query ) use ( $primary, $second, $table, $concurrent, &$outcome ): string {
		global $wpdb;
		if ( array() !== $outcome || ! str_starts_with( ltrim( $query ), "UPDATE {$table} SET option_value" ) || ! str_contains( $query, 'HEX(option_value)' ) ) {
			return $query;
		}

		// The primary snapshot has already been read. Commit an independent
		// mutation before its CAS so the stale owner must fail without replacement.
		$outcome = array( 'status' => 'injected' );
		$wpdb    = $second;
		try {
			$outcome = array( 'result' => $concurrent() );
		} finally {
			$wpdb = $primary;
		}

		return $query;
	};
}

/**
 * Prove the production learning queue CAS across two real WordPress connections.
 *
 * @param string $run Unique disposable fixture identity.
 */
function aculect_memory_proof_learning_queue( string $run ): void {
	global $wpdb;

	$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$second->set_prefix( $wpdb->prefix );
	try {
		$learning = new LearningSuggestionRepository();
		$seed     = $learning->submit(
			array(
				'issue'            => 'Queue seed ' . $run,
				'suggested_update' => 'Keep the existing queue snapshot.',
			)
		);
		aculect_memory_proof_assert( 'queued' === $seed['status'], 'Could not create the learning queue seed.' );

		$submission_outcome = array();
		$submission_filter  = aculect_memory_proof_queue_interleave(
			$second,
			static fn (): array => ( new LearningSuggestionRepository() )->submit(
				array(
					'issue'            => 'Concurrent queue submission ' . $run,
					'suggested_update' => 'Keep this independent submission.',
				)
			),
			$submission_outcome
		);
		add_filter( 'query', $submission_filter, PHP_INT_MAX );
		try {
			$stale_submission = $learning->submit(
				array(
					'issue'            => 'Stale queue submission ' . $run,
					'suggested_update' => 'This stale write must fail safely.',
				)
			);
		} finally {
			remove_filter( 'query', $submission_filter, PHP_INT_MAX );
		}
		aculect_memory_proof_assert( 'storage_error' === ( $stale_submission['error'] ?? '' ) && 'queued' === ( $submission_outcome['result']['status'] ?? '' ), 'Concurrent queue submissions did not produce one safe failure and one persisted item.' );
		$retried_submission = $learning->submit(
			array(
				'issue'            => 'Retried queue submission ' . $run,
				'suggested_update' => 'Retry from a fresh queue snapshot.',
			)
		);
		aculect_memory_proof_assert( 'queued' === $retried_submission['status'], 'A stale queue submission could not retry from fresh state.' );
		$issues = array_column( $learning->admin_payload()['items'], 'issue' );
		aculect_memory_proof_assert( in_array( 'Concurrent queue submission ' . $run, $issues, true ) && in_array( 'Retried queue submission ' . $run, $issues, true ) && ! in_array( 'Stale queue submission ' . $run, $issues, true ), 'Queue retry lost an independent submission or retained a stale write.' );

		$editable     = $learning->submit(
			array(
				'issue'            => 'Editable queue item ' . $run,
				'suggested_update' => 'Original editable guidance.',
			)
		);
		$editable_id  = (string) $editable['suggestion']['id'];
		$edit_outcome = array();
		$edit_filter  = aculect_memory_proof_queue_interleave(
			$second,
			static fn (): array => ( new LearningSuggestionRepository() )->submit(
				array(
					'issue'            => 'Submission during queue edit ' . $run,
					'suggested_update' => 'Retain this unrelated queue item.',
				)
			),
			$edit_outcome
		);
		add_filter( 'query', $edit_filter, PHP_INT_MAX );
		try {
			$stale_edit = $learning->update(
				$editable_id,
				array(
					'issue'            => 'Stale queue edit ' . $run,
					'suggested_update' => 'This stale edit must not overwrite state.',
				)
			);
		} finally {
			remove_filter( 'query', $edit_filter, PHP_INT_MAX );
		}
		$items = array_column( $learning->admin_payload()['items'], null, 'id' );
		aculect_memory_proof_assert( false === $stale_edit && 'queued' === ( $edit_outcome['result']['status'] ?? '' ) && ( $items[ $editable_id ]['issue'] ?? '' ) === 'Editable queue item ' . $run && in_array( 'Submission during queue edit ' . $run, array_column( $items, 'issue' ), true ), 'A stale edit replaced an unrelated submission or its original queue item.' );
		aculect_memory_proof_assert(
			$learning->update(
				$editable_id,
				array(
					'issue'            => 'Retried queue edit ' . $run,
					'suggested_update' => 'Fresh edit after the independent submission.',
				)
			),
			'A stale edit could not retry from fresh queue state.'
		);

		$reviewable     = $learning->submit(
			array(
				'issue'            => 'Reviewable queue item ' . $run,
				'suggested_update' => 'Original review guidance.',
			)
		);
		$reviewable_id  = (string) $reviewable['suggestion']['id'];
		$memory_key     = 'learning.content.' . $reviewable_id;
		$before_events  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Installer::memory_events_table() ) );
		$review_outcome = array();
		$review_filter  = aculect_memory_proof_queue_interleave(
			$second,
			static fn (): bool => ( new LearningSuggestionRepository() )->update(
				$reviewable_id,
				array(
					'issue'            => 'Concurrent review edit ' . $run,
					'suggested_update' => 'This edit wins over the stale review.',
				)
			),
			$review_outcome
		);
		add_filter( 'query', $review_filter, PHP_INT_MAX );
		try {
			$stale_review = $learning->review( $reviewable_id, 'approve', 'Stale review note.' );
		} finally {
			remove_filter( 'query', $review_filter, PHP_INT_MAX );
		}
		$items = array_column( $learning->admin_payload()['items'], null, 'id' );
		aculect_memory_proof_assert( false === $stale_review && true === ( $review_outcome['result'] ?? false ) && ( $items[ $reviewable_id ]['issue'] ?? '' ) === 'Concurrent review edit ' . $run && array() === ( new MemoryRepository() )->find( $memory_key ), 'A stale review overwrote a concurrent edit or committed memory.' );
		$after_failed_events = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Installer::memory_events_table() ) );
		aculect_memory_proof_assert( $before_events === $after_failed_events, 'A stale review committed a memory history event.' );
		aculect_memory_proof_assert( $learning->review( $reviewable_id, 'approve', 'Retried review note.' ), 'A stale review could not retry after the concurrent edit.' );
		$memory  = ( new MemoryRepository() )->find( $memory_key );
		$history = ( new MemoryService() )->history( $memory['memory_uuid'] ?? '' );
		aculect_memory_proof_assert( 'approved' === ( $memory['status'] ?? '' ) && 1 === count( $history ) && 1 === (int) ( $history[0]['memory_version'] ?? 0 ), 'Review retry did not commit exactly one memory/event pair.' );

		for ( $index = 0; $index < 105; ++$index ) {
			$retained = $learning->submit(
				array(
					'issue'            => 'Queue retention ' . $run . ' ' . $index,
					'suggested_update' => 'Bounded disposable queue retention fixture.',
				)
			);
			aculect_memory_proof_assert( 'queued' === $retained['status'], 'Could not add a bounded queue retention fixture.' );
		}
		$issues = array_column( $learning->admin_payload()['items'], 'issue' );
		aculect_memory_proof_assert( 100 === count( $issues ) && ! in_array( 'Queue retention ' . $run . ' 0', $issues, true ) && in_array( 'Queue retention ' . $run . ' 104', $issues, true ), 'Learning queue retention did not preserve the latest 100 entries.' );
	} finally {
		$second->close();
	}

	echo "PASS learning queue CAS submissions, edits, reviews, retries and retention\n";
}

/**
 * Inject a normal save on a second real connection at the production CAS update.
 *
 * @param wpdb                $second Second connection to the disposable database.
 * @param array<string,mixed> $input Concurrent normal-save input.
 * @param array<string,mixed> $outcome Captured interleaving result.
 * @return Closure Query filter.
 */
function aculect_memory_proof_interleave( wpdb $second, array $input, array &$outcome ): Closure {
	global $wpdb;
	$primary = $wpdb;
	$table   = Installer::memory_items_table();
	return static function ( string $query ) use ( $primary, $second, $table, $input, &$outcome ): string {
		global $wpdb;
		if ( array() !== $outcome || ! str_starts_with( ltrim( $query ), 'UPDATE `' . $table . '` SET' ) || ! str_contains( $query, $input['key'] ) ) {
			return $query;
		}
		// The SELECT already happened. This independent transaction commits before the stale UPDATE.
		$outcome = array( 'status' => 'injected' );
		$wpdb    = $second;
		try {
			$outcome = ( new MemoryService() )->save( $input );
		} finally {
			$wpdb = $primary;
		}
		return $query;
	};
}

/**
 * Prove production backfill loses the race safely without replacing UUID/history.
 *
 * @param string $run Unique disposable fixture identity.
 */
function aculect_memory_proof_migration( string $run ): void {
	global $wpdb;
	$input   = array(
		'key'       => 'proof.race.' . $run,
		'namespace' => 'proof-race-' . $run,
		'value'     => 'Original disposable value',
	);
	$initial = ( new MemoryService() )->save( $input );
	aculect_memory_proof_assert( 'success' === $initial['status'], 'Could not create the migration race fixture.' );
	$initial = $initial['memory'];
	aculect_memory_proof_assert( 1 === $wpdb->update( Installer::memory_items_table(), array( 'content_hash' => '' ), array( 'id' => $initial['id'] ), array( '%s' ), array( '%d' ) ), 'Could not create the legacy blank-hash fixture.' );
	$cursor_option = 'aculect_ai_companion_memory_backfill_cursor';
	$previous      = get_option( $cursor_option, null );
	update_option( $cursor_option, (int) $initial['id'] - 1, false );
	$second = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$second->set_prefix( $wpdb->prefix );
	$input['value']            = 'Concurrent committed disposable value';
	$input['expected_version'] = (int) $initial['version'];
	$outcome                   = array();
	$interleave                = aculect_memory_proof_interleave( $second, $input, $outcome );
	add_filter( 'query', $interleave, PHP_INT_MAX );
	try {
		$result = MemorySchemaMigrator::backfill();
		aculect_memory_proof_assert( 'success' === ( $outcome['status'] ?? '' ), 'The second-connection normal save did not commit at the CAS boundary.' );
		aculect_memory_proof_assert( MemorySchemaMigrator::PENDING === $result, 'Production backfill did not retry its lost CAS race.' );
		aculect_memory_proof_assert( MemorySchemaMigrator::COMPLETE === MemorySchemaMigrator::backfill(), 'Production backfill did not complete after rereading the committed row.' );
	} finally {
		remove_filter( 'query', $interleave, PHP_INT_MAX );
		$second->close();
		if ( null === $previous ) {
			delete_option( $cursor_option );
		} else {
			update_option( $cursor_option, $previous, false );
		}
	}
	$latest = ( new MemoryRepository() )->find( $input['key'], $input['namespace'] );
	$hash   = hash( 'sha256', $input['namespace'] . "\n" . $input['key'] . "\n" . $input['value'] );
	aculect_memory_proof_assert( $initial['memory_uuid'] === $latest['memory_uuid'] && $input['value'] === $latest['value'] && $hash === $latest['content_hash'] && 2 === $latest['version'], 'Backfill corrupted the concurrent value, hash, UUID or version.' );
	$history = ( new MemoryService() )->history( $latest['memory_uuid'], $input['namespace'] );
	aculect_memory_proof_assert( 2 === count( $history ) && 2 === (int) $history[0]['memory_version'], 'Backfill changed the identity or immutable event history.' );
	$payload = $wpdb->get_var( $wpdb->prepare( 'SELECT payload FROM %i WHERE memory_uuid = %s AND memory_version = %d LIMIT 1', Installer::memory_events_table(), $latest['memory_uuid'], 2 ) );
	$payload = is_string( $payload ) ? json_decode( $payload, true ) : null;
	aculect_memory_proof_assert( is_array( $payload ) && ( $payload['content_hash'] ?? '' ) === $hash, 'Committed event and latest memory content hash disagree.' );
	echo "PASS production backfill CAS against a second real WordPress database connection\n";
}

/**
 * Collect a bounded snapshot and its event fence.
 *
 * @param SiteMemorySyncAdapter $adapter Opted-in local adapter.
 * @return array{items:list<array<string,mixed>>,cursor:string}
 * @throws RuntimeException When the bounded snapshot does not finish.
 */
function aculect_memory_proof_snapshot( SiteMemorySyncAdapter $adapter ): array {
	$items  = array();
	$cursor = '';
	for ( $page = 0; $page < 10; ++$page ) {
		$feed   = $adapter->pull( $cursor, 20 );
		$items  = array_merge( $items, $feed['items'] );
		$cursor = $feed['cursor'];
		if ( ! $feed['has_more'] ) {
			return compact( 'items', 'cursor' );
		}
	}
	throw new RuntimeException( 'Disposable memory snapshot exceeded its bounded page budget.' );
}

/**
 * Prove replay stays pending/private and cannot overwrite later admin review.
 *
 * @param SiteMemorySyncAdapter $adapter Opted-in adapter.
 * @param string                $namespace Isolated fixture namespace.
 */
function aculect_memory_proof_import( SiteMemorySyncAdapter $adapter, string $namespace ): void {
	$proposal = array(
		'id'      => 'external-proposal',
		'version' => 1,
		'value'   => 'Review this imported proposal.',
	);
	$accepted = $adapter->push( array( $proposal ), 'external-checkpoint' );
	aculect_memory_proof_assert( array( 'external-proposal' ) === $accepted['accepted'] && array() === $accepted['rejected'], 'Versioned memory proposal was not accepted.' );
	$key    = 'sync.' . hash( 'sha256', $adapter->id() . "\n" . $namespace . "\nexternal-proposal\n1" );
	$memory = ( new MemoryRepository() )->find( $key, $namespace );
	aculect_memory_proof_assert( 'pending' === $memory['status'] && 'private' === $memory['visibility'], 'Imported proposal bypassed site review/privacy.' );
	$again = $adapter->push( array( $proposal ), 'external-checkpoint' );
	aculect_memory_proof_assert( array( 'external-proposal' ) === $again['accepted'] && 1 === count( ( new MemoryService() )->history( $memory['memory_uuid'], $namespace ) ), 'Identical replay created duplicate memory history.' );
	$approved = ( new MemoryService() )->save(
		array_merge(
			$memory,
			array(
				'status'           => 'approved',
				'visibility'       => 'site',
				'expected_version' => 1,
			)
		)
	);
	aculect_memory_proof_assert( 'success' === $approved['status'], 'Could not review imported proposal through the normal service.' );
	$adapter->push( array( $proposal ), 'external-checkpoint' );
	$reviewed = ( new MemoryRepository() )->find( $key, $namespace );
	aculect_memory_proof_assert( 'approved' === $reviewed['status'] && 2 === $reviewed['version'], 'Replay overwrote later administrator review.' );
	$proposal['value'] = 'Different text for the same external version.';
	$conflict          = $adapter->push( array( $proposal ), 'external-checkpoint' );
	aculect_memory_proof_assert( 'memory_sync_version_conflict' === ( $conflict['rejected']['external-proposal'] ?? '' ) && '' === $conflict['cursor'], 'Conflicting replay overwrote a reviewed proposal or advanced its cursor.' );
}

/**
 * Prove only current approved shareable context leaves the site-owned sync feed.
 *
 * @param string $run Unique disposable fixture identity.
 * @throws RuntimeException When opt-in unexpectedly permits synchronization.
 */
function aculect_memory_proof_sync( string $run ): void {
	$namespace = 'proof-sync-' . $run;
	$adapter   = new SiteMemorySyncAdapter( 'proof:' . $run, $namespace );
	try {
		$adapter->pull( '', 20 );
		throw new RuntimeException( 'Memory sync was enabled without explicit site opt-in.' );
	} catch ( RuntimeException $error ) {
		aculect_memory_proof_assert( 'memory_sync_disabled' === $error->getMessage(), 'Memory sync did not fail closed before site opt-in.' );
	}
	add_filter( 'aculect_ai_companion_memory_sync_enabled', '__return_true' );
	try {
		$records = array();
		foreach ( array( 'approved', 'private', 'future', 'expired' ) as $kind ) {
			$input = array(
				'key'        => 'proof.sync.' . $run . '.' . $kind,
				'namespace'  => $namespace,
				'value'      => 'Disposable ' . $kind . ' marker',
				'status'     => 'approved',
				'visibility' => 'private' === $kind ? 'private' : 'site',
				'valid_from' => 'future' === $kind ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : null,
				'expires_at' => 'expired' === $kind ? gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) : null,
			);
			$saved = ( new MemoryService() )->save( $input );
			aculect_memory_proof_assert( 'success' === $saved['status'], 'Could not create the sync eligibility fixtures.' );
			$records[ $kind ] = $saved['memory'];
		}
		$snapshot = aculect_memory_proof_snapshot( $adapter );
		$by_id    = array_column( $snapshot['items'], null, 'id' );
		aculect_memory_proof_assert( 'upsert' === $by_id[ $records['approved']['memory_uuid'] ]['operation'], 'Approved live site memory was not shared.' );
		foreach ( array( 'private', 'future', 'expired' ) as $kind ) {
			$item = $by_id[ $records[ $kind ]['memory_uuid'] ];
			aculect_memory_proof_assert( 'remove' === $item['operation'] && array( 'id', 'operation' ) === array_keys( $item ), 'Ineligible memory leaked content or omitted its invalidation.' );
		}
		$private = ( new MemoryService() )->save(
			array_merge(
				$records['approved'],
				array(
					'visibility'       => 'private',
					'expected_version' => 1,
				)
			)
		);
		aculect_memory_proof_assert( 'success' === $private['status'], 'Could not revoke visibility for the delta proof.' );
		$delta = $adapter->pull( $snapshot['cursor'], 20 );
		aculect_memory_proof_assert( 1 === count( $delta['items'] ) && array( 'id', 'operation' ) === array_keys( $delta['items'][0] ) && 'remove' === $delta['items'][0]['operation'], 'Previously shared memory was not invalidated without disclosing private content.' );
		aculect_memory_proof_import( $adapter, $namespace );
	} finally {
		remove_filter( 'aculect_ai_companion_memory_sync_enabled', '__return_true' );
	}
	echo "PASS sync consent, live/private/future eligibility, invalidations and reviewed replay\n";
}

aculect_memory_proof_guard( isset( $args ) && is_array( $args ) ? $args : array() );
$run = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 12 );
aculect_memory_proof_batch( $run );
aculect_memory_proof_review( $run );
aculect_memory_proof_learning_queue( $run );
aculect_memory_proof_migration( $run );
aculect_memory_proof_sync( $run );
echo "PASS packaged Aculect Memory real WordPress proof\n";

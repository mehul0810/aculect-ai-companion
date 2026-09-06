<?php
/**
 * Memory synchronization trust-boundary and replay regressions.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\Memory\Sync\SiteMemorySyncAdapter;
use Aculect\AICompanion\Connectors\MCP\Modules\MemorySyncAbilityModules;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Commenting.DocComment.MissingShort, Squiz.Commenting.FunctionComment.MissingParamTag, WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database fixture.

final class MemorySyncTest extends TestCase {
	private mixed $original_wpdb;
	private MemorySyncWpdb $database;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->database      = new MemorySyncWpdb();
		$GLOBALS['wpdb']     = $this->database;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_memory_sync_enabled'] = static fn (): bool => true;
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		unset( $GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_memory_sync_enabled'], $GLOBALS['aculect_ai_companion_test_denied_caps'], $GLOBALS['aculect_ai_companion_test_current_user_id'] );
	}

	public function test_imports_are_private_pending_and_replay_does_not_mutate(): void {
		$adapter = new SiteMemorySyncAdapter( 'client1' );
		$change  = array(
			'id'         => 'voice',
			'version'    => 1,
			'value'      => 'Concise.',
			'status'     => 'approved',
			'visibility' => 'site',
		);
		self::assertSame( array( 'voice' ), $adapter->push( array( $change ), 'batch1' )['accepted'] );
		$row = array_values( $this->database->rows )[0];
		self::assertSame( 'pending', $row['status'] );
		self::assertSame( 'private', $row['visibility'] );
		self::assertCount( 1, $this->database->events );
		self::assertSame( array(), $adapter->push( array( $change ), 'batch1' )['rejected'] );
		self::assertCount( 1, $this->database->events );
		$change['value'] = 'Different';
		self::assertSame( 'memory_sync_version_conflict', $adapter->push( array( $change ), 'batch2' )['rejected']['voice'] );
	}

	public function test_proposal_identity_and_replay_are_isolated_by_namespace(): void {
		$change = array(
			'id'      => 'voice',
			'version' => 1,
			'value'   => 'Concise.',
		);
		$first  = new SiteMemorySyncAdapter( 'client', 'site-a' );
		$second = new SiteMemorySyncAdapter( 'client', 'site-b' );
		self::assertSame( array( 'voice' ), $first->push( array( $change ), '' )['accepted'] );
		self::assertSame( array( 'voice' ), $second->push( array( $change ), '' )['accepted'] );
		self::assertCount( 2, $this->database->rows );
		self::assertSame( array( 'voice' ), $first->push( array( $change ), '' )['accepted'] );
		self::assertSame( array( 'voice' ), $second->push( array( $change ), '' )['accepted'] );
		self::assertCount( 2, $this->database->events );
	}

	public function test_new_external_versions_do_not_overwrite_reviewed_guidance(): void {
		$adapter = new SiteMemorySyncAdapter( 'client1' );
		$change  = array(
			'id'      => 'voice',
			'version' => 1,
			'value'   => 'Original.',
		);
		$adapter->push( array( $change ), '' );
		$key                                    = array_key_first( $this->database->rows );
		$this->database->rows[ $key ]['status'] = 'approved';
		$change['version']                      = 2;
		$change['value']                        = 'Proposed revision.';
		$adapter->push( array( $change ), '' );
		self::assertCount( 2, $this->database->rows );
		self::assertSame( 'Original.', $this->database->rows[ $key ]['value'] );
		self::assertSame( 'approved', $this->database->rows[ $key ]['status'] );
	}

	public function test_partial_failure_does_not_advance_checkpoint(): void {
		$result = ( new SiteMemorySyncAdapter( 'client' ) )->push(
			array(
				array(
					'id'      => 'x',
					'version' => 0,
					'value'   => 'Bad',
				),
			),
			'next'
		);
		self::assertSame( '', $result['cursor'] );
		self::assertSame( 'invalid_memory_proposal', $result['rejected']['x'] );
		self::assertCount( 0, $this->database->rows );
	}

	public function test_opt_in_is_required_before_database_access(): void {
		unset( $GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_memory_sync_enabled'] );
		$this->expectExceptionMessage( 'memory_sync_disabled' );
		( new SiteMemorySyncAdapter( 'client' ) )->pull( '', 20 );
	}

	public function test_capability_is_required_even_when_enabled(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		$this->expectExceptionMessage( 'forbidden' );
		( new SiteMemorySyncAdapter( 'client' ) )->push( array(), '' );
	}

	public function test_oversized_batch_is_rejected_before_partial_writes(): void {
		$this->expectExceptionMessage( 'memory_sync_batch_limit' );
		( new SiteMemorySyncAdapter( 'client' ) )->push( array_fill( 0, 21, array() ), '' );
	}

	public function test_feed_redacts_private_and_expired_records(): void {
		$public               = array(
			'sequence'    => 1,
			'memory_uuid' => 'public-id',
			'namespace'   => 'site',
			'status'      => 'approved',
			'visibility'  => 'site',
			'sensitivity' => 'normal',
			'value'       => 'Public',
			'deleted_at'  => null,
		);
		$this->database->feed = array(
			$public,
			array_merge(
				$public,
				array(
					'sequence'    => 2,
					'memory_uuid' => 'private-id',
					'visibility'  => 'private',
					'value'       => 'SECRET',
				)
			),
			array_merge(
				$public,
				array(
					'sequence'    => 3,
					'memory_uuid' => 'expired-id',
					'expires_at'  => '2000-01-01 00:00:00',
					'value'       => 'EXPIRED',
				)
			),
		);
		$result               = ( new SiteMemorySyncAdapter( 'client' ) )->pull( '', 20 );
		self::assertSame( 'Public', $result['items'][0]['memory']['value'] );
		self::assertSame(
			array(
				'id'        => 'private-id',
				'operation' => 'remove',
			),
			$result['items'][1]
		);
		self::assertSame( 'remove', $result['items'][2]['operation'] );
		self::assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $result ) );
	}

	public function test_cursor_is_namespace_bound_and_malformed_values_fail(): void {
		$adapter = new SiteMemorySyncAdapter( 'client' );
		$result  = $adapter->pull( '', 20 );
		foreach ( array( 'bad', str_repeat( 'x', 1025 ) ) as $cursor ) {
			try {
				$adapter->pull( $cursor, 20 );
				self::fail( 'Invalid cursor accepted.' );
			} catch ( RuntimeException $error ) {
				self::assertSame( 'invalid_sync_cursor', $error->getMessage() );
			}
		}
		$this->expectExceptionMessage( 'invalid_sync_cursor' );
		( new SiteMemorySyncAdapter( 'client', 'other' ) )->pull( $result['cursor'], 20 );
	}

	public function test_tools_preserve_scope_confirmation_and_dry_run(): void {
		$modules = ( new MemorySyncAbilityModules() )->all();
		self::assertSame( array( 'content:draft' ), $modules['memory.sync_push']->required_scopes() );
		self::assertTrue( ( new ToolSafety() )->requires_confirmation( 'memory.sync_push', array() ) );
		$result = $modules['memory.sync_push']->execute(
			array(
				'dry_run' => true,
				'items'   => array(
					array(
						'id'      => 'a',
						'version' => 1,
						'value'   => 'Never written',
					),
				),
			)
		);
		self::assertSame( 'preview', $result['status'] );
		self::assertCount( 0, $this->database->rows );
	}
}

final class MemorySyncWpdb {
	public string $prefix     = 'wp_';
	public string $last_error = '';
	public array $rows        = array();
	public array $events      = array();
	public array $feed        = array();
	private array $arguments  = array();

	public function prepare( string $query, mixed ...$args ): string {
		$this->arguments = $args;
		return $query;
	}

	public function query( string $query ): int {
		unset( $query );
		return 1;
	}

	public function get_var( string $query ): int {
		unset( $query );
		return 3;
	}

	public function get_results( string $query, string $format ): array {
		unset( $query, $format );
		return $this->feed;
	}

	public function get_row( string $query, string $format ): ?array {
		unset( $format );
		if ( str_contains( $query, 'information_schema' ) ) {
			return array(
				'Name'   => $this->arguments[0],
				'Engine' => 'InnoDB',
			);
		}
		return $this->rows[ (string) ( $this->arguments[1] ?? '' ) ] ?? null;
	}

	public function insert( string $table, array $data, array $formats ): int {
		unset( $formats );
		if ( str_contains( $table, 'memory_events' ) ) {
			$this->events[] = $data;
		} else {
			$data['id']                        = count( $this->rows ) + 1;
			$this->rows[ $data['memory_key'] ] = $data;
		}
		return 1;
	}
}

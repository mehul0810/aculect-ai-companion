<?php
/**
 * Bounded native content-revision recovery regression tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionOutcome;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionRequest;
use Aculect\AICompanion\Connectors\MCP\ContentRevisionRecovery;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use Aculect\AICompanion\Tests\Support\InMemoryExecutionClaimStore;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class ContentRevisionRecoveryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/fixtures/content-revision-recovery-stubs.php';

		$GLOBALS['aculect_ai_companion_test_options']                = array();
		$GLOBALS['aculect_ai_companion_test_transients']             = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']        = 1;
		$GLOBALS['aculect_ai_companion_test_users']                  = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Recovery Test Admin',
				'user_login'   => 'recovery-test',
			),
		);
		$GLOBALS['aculect_ai_companion_test_posts']                  = array();
		$GLOBALS['aculect_ai_companion_test_post_meta']              = array();
		$GLOBALS['aculect_ai_companion_test_post_revisions']         = array();
		$GLOBALS['aculect_ai_companion_test_post_types']             = array(
			'post' => new \WP_Post_Type( 'post' ),
			'page' => new \WP_Post_Type( 'page' ),
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps']            = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback']    = null;
		$GLOBALS['content_revision_recovery_test_lock_callback']     = null;
		$GLOBALS['content_revision_recovery_test_revisions_enabled'] = true;
		$GLOBALS['content_revision_recovery_test_backup_callback']   = null;
		$GLOBALS['content_revision_recovery_test_next_revision_id']  = 301;
		$GLOBALS['content_revision_recovery_test_backup_calls']      = 0;
		$GLOBALS['content_revision_recovery_test_slash_calls']       = 0;

		$this->seed_posts();
	}

	public function test_compare_returns_only_bounded_field_counts_and_rejects_bad_targets(): void {
		$result = ( new ContentRevisionRecovery() )->compare( $this->target_args() );

		self::assertSame( 100, $result['post_id'] );
		self::assertSame( 201, $result['revision_id'] );
		self::assertSame( 'draft', $result['status'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['expected_state'] );
		self::assertSame(
			array(
				'post_title'   => array(
					'changed'        => true,
					'current_bytes'  => 13,
					'revision_bytes' => 14,
				),
				'post_content' => array(
					'changed'        => true,
					'current_bytes'  => 15,
					'revision_bytes' => 16,
				),
				'post_excerpt' => array(
					'changed'        => true,
					'current_bytes'  => 15,
					'revision_bytes' => 16,
				),
			),
			$result['fields']
		);
		self::assertArrayNotHasKey( 'post_content', $result );
		self::assertStringNotContainsString( 'Revision content', wp_json_encode( $result ) );

		foreach ( array( 0, -1, '100', 1.5, true, array( 100 ) ) as $invalid_id ) {
			$args            = $this->target_args();
			$args['post_id'] = $invalid_id;
			self::assertSame( 'invalid_revision_target', ( new ContentRevisionRecovery() )->compare( $args )['error'] );
		}

		$foreign = $this->revision( 202, 999 );
		$GLOBALS['aculect_ai_companion_test_posts'][202] = $foreign;
		self::assertSame(
			'invalid_revision',
			( new ContentRevisionRecovery() )->compare(
				array(
					'post_id'     => 100,
					'revision_id' => 202,
				)
			)['error']
		);
	}

	public function test_compare_enforces_read_edit_and_native_publish_capabilities(): void {
		foreach ( array( 'read_post', 'edit_post' ) as $denied_capability ) {
			$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability ) use ( $denied_capability ): bool {
				return $denied_capability !== $capability;
			};
			self::assertSame( 'forbidden', ( new ContentRevisionRecovery() )->compare( $this->target_args() )['error'] );
		}

		$GLOBALS['aculect_ai_companion_test_capability_callback']     = static function ( string $capability ): bool {
			return 'publish_posts' !== $capability;
		};
		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_status = 'publish';
		self::assertSame( 'forbidden', ( new ContentRevisionRecovery() )->compare( $this->target_args() )['error'] );
	}

	public function test_compare_rejects_autosaves_trash_and_custom_post_types(): void {
		$service             = new ContentRevisionRecovery();
		$autosave            = $this->revision( 202, 100 );
		$autosave->post_name = 'autosave-202';
		$GLOBALS['aculect_ai_companion_test_posts'][202] = $autosave;
		self::assertSame(
			'invalid_revision',
			$service->compare(
				array(
					'post_id'     => 100,
					'revision_id' => 202,
				)
			)['error']
		);

		foreach ( array( 'trash', 'auto-draft' ) as $status ) {
			$GLOBALS['aculect_ai_companion_test_posts'][100]->post_status = $status;
			self::assertSame( 'unsupported_post', $service->compare( $this->target_args() )['error'] );
		}

		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_status = 'draft';
		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_type   = 'book';
		self::assertSame( 'unsupported_post', $service->compare( $this->target_args() )['error'] );
	}

	public function test_restore_rejects_stale_parent_or_revision_state(): void {
		$service  = new ContentRevisionRecovery();
		$expected = $service->compare( $this->target_args() )['expected_state'];
		$args     = $this->restore_args( $expected );

		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_title = 'Changed title';
		self::assertSame( 'stale_revision_state', $service->restore( $args )['error'] );

		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_title   = 'Current title';
		$GLOBALS['aculect_ai_companion_test_posts'][201]->post_excerpt = 'Changed revision excerpt';
		self::assertSame( 'stale_revision_state', $service->restore( $args )['error'] );
		self::assertSame( 0, $GLOBALS['content_revision_recovery_test_backup_calls'] );
	}

	public function test_restore_stops_when_revisions_are_disabled_or_parent_is_locked(): void {
		$service  = new ContentRevisionRecovery();
		$expected = $service->compare( $this->target_args() )['expected_state'];
		$args     = $this->restore_args( $expected );

		$GLOBALS['content_revision_recovery_test_lock_callback'] = static fn ( int $post_id ): int => 100 === $post_id ? 7 : 8;
		self::assertSame( 'post_locked', $service->restore( $args )['error'] );

		$GLOBALS['content_revision_recovery_test_lock_callback']     = null;
		$GLOBALS['content_revision_recovery_test_revisions_enabled'] = false;
		self::assertSame( 'recovery_unavailable', $service->restore( $args )['error'] );
		self::assertSame( 0, $GLOBALS['content_revision_recovery_test_backup_calls'] );
	}

	public function test_no_op_restore_does_not_create_a_recovery_revision_or_write(): void {
		$parent   = get_post( 100 );
		$revision = get_post( 201 );
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			$revision->{$field} = $parent->{$field};
		}

		$service  = new ContentRevisionRecovery();
		$expected = $service->compare( $this->target_args() )['expected_state'];
		$result   = $service->restore( $this->restore_args( $expected ) );

		self::assertTrue( $result['success'] );
		self::assertFalse( $result['changed'] );
		self::assertSame( 0, $GLOBALS['content_revision_recovery_test_backup_calls'] );
		self::assertSame( 0, $GLOBALS['content_revision_recovery_test_slash_calls'] );
	}

	public function test_restore_fails_terminally_when_recovery_revision_is_missing_or_hook_throws(): void {
		$service  = new ContentRevisionRecovery();
		$expected = $service->compare( $this->target_args() )['expected_state'];
		$args     = $this->restore_args( $expected );
		$GLOBALS['content_revision_recovery_test_backup_callback'] = static function ( int $post_id ): false {
			self::assertSame( 100, $post_id );
			return false;
		};

		$missing = $service->restore( $args );
		self::assertSame( 'partial_write', $missing['error'] );
		self::assertTrue( $missing['terminal'] );
		self::assertSame( 'Current title', get_post( 100 )->post_title );
		self::assertSame( 0, $GLOBALS['content_revision_recovery_test_slash_calls'] );

		$GLOBALS['content_revision_recovery_test_backup_callback'] = static function ( int $post_id ): never {
			unset( $post_id );
			throw new \RuntimeException( 'Simulated revision hook failure.' );
		};
		$thrown = $service->restore( $args );
		self::assertSame( 'partial_write', $thrown['error'] );
		self::assertTrue( $thrown['terminal'] );
		self::assertSame( 2, $GLOBALS['content_revision_recovery_test_backup_calls'] );
	}

	public function test_restore_saves_verified_preimage_and_preserves_status_and_metadata(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][100]->post_status = 'publish';
		$GLOBALS['aculect_ai_companion_test_post_meta'][100]          = array( '_recovery_test_meta' => 'keep-me' );
		$service  = new ContentRevisionRecovery();
		$expected = $service->compare( $this->target_args() )['expected_state'];
		$result   = $service->restore( $this->restore_args( $expected ) );

		self::assertTrue( $result['success'] );
		self::assertTrue( $result['changed'] );
		self::assertSame( array( 'post_title', 'post_content', 'post_excerpt' ), $result['restored_fields'] );
		self::assertSame( 'Revision title', get_post( 100 )->post_title );
		self::assertSame( 'Revision content', get_post( 100 )->post_content );
		self::assertSame( 'Revision excerpt', get_post( 100 )->post_excerpt );
		self::assertSame( 'publish', get_post( 100 )->post_status );
		self::assertSame( array( '_recovery_test_meta' => 'keep-me' ), $GLOBALS['aculect_ai_companion_test_post_meta'][100] );
		self::assertSame( 'Current title', get_post( $result['recovery_revision_id'] )->post_title );
		self::assertSame( 1, $GLOBALS['content_revision_recovery_test_backup_calls'] );
		self::assertSame( 1, $GLOBALS['content_revision_recovery_test_slash_calls'] );
	}

	public function test_gateway_requires_write_scope_confirmation_and_replays_only_once(): void {
		$gateway   = new AbilityExecutionGateway( null, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
		$args      = $this->restore_args( ( new ContentRevisionRecovery() )->compare( $this->target_args() )['expected_state'] );
		$auth      = $this->trusted_write_auth( array( 'content:read' ) );
		$read_only = $gateway->execute( $this->request( $args, $auth ) );
		self::assertSame( AbilityExecutionGateway::OUTCOME_AUTH_CHALLENGE, $read_only->type );
		self::assertSame( array( 'content:draft' ), $read_only->data['required_scopes'] );

		$auth['scopes'] = array( 'content:draft' );
		$preview        = $gateway->execute( $this->request( $args, $auth ) )->data['result'];
		self::assertSame( 'confirmation_required', $preview['status'] );
		self::assertTrue( $preview['confirmation_required'] );
		self::assertSame( 0, $GLOBALS['content_revision_recovery_test_backup_calls'] );

		$tampered                       = $args;
		$tampered['expected_state']     = str_repeat( '0', 64 );
		$tampered['confirmation_token'] = $preview['confirmation_token'];
		self::assertSame( 'invalid_confirmation_token', $gateway->execute( $this->request( $tampered, $auth ) )->data['result']['error'] );
		self::assertSame( 0, $GLOBALS['content_revision_recovery_test_backup_calls'] );

		$confirmed                       = $args;
		$confirmed['confirmation_token'] = $preview['confirmation_token'];
		$written                         = $gateway->execute( $this->request( $confirmed, $auth ) )->data['result'];
		self::assertTrue( $written['success'] );
		self::assertSame( 1, $GLOBALS['content_revision_recovery_test_backup_calls'] );
		$replayed = $gateway->execute( $this->request( $confirmed, $auth ) )->data['result'];
		self::assertTrue( $replayed['replayed'] );
		self::assertSame( 1, $GLOBALS['content_revision_recovery_test_backup_calls'] );
	}

	/**
	 * Seed a parent post and a saved revision with distinct native fields.
	 */
	private function seed_posts(): void {
		$parent                                     = new \WP_Post(
			array(
				'ID'                => 100,
				'post_type'         => 'post',
				'post_status'       => 'draft',
				'post_name'         => 'recovery-post',
				'post_author'       => 1,
				'post_parent'       => 0,
				'post_modified_gmt' => '2026-09-15 09:00:00',
				'post_title'        => 'Current title',
				'post_content'      => 'Current content',
				'post_excerpt'      => 'Current excerpt',
			)
		);
		$revision                                   = $this->revision( 201, 100 );
		$GLOBALS['aculect_ai_companion_test_posts'] = array(
			100 => $parent,
			201 => $revision,
		);
		$GLOBALS['aculect_ai_companion_test_post_revisions'] = array( 100 => array( 201 => $revision ) );
	}

	/**
	 * Create a saved revision fixture.
	 *
	 * @param int $revision_id Revision ID.
	 * @param int $parent_id Parent post ID.
	 * @return \WP_Post
	 */
	private function revision( int $revision_id, int $parent_id ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'                => $revision_id,
				'post_type'         => 'revision',
				'post_status'       => 'inherit',
				'post_parent'       => $parent_id,
				'post_name'         => 'revision-' . $revision_id,
				'post_modified_gmt' => '2026-09-14 09:00:00',
				'post_title'        => 'Revision title',
				'post_content'      => 'Revision content',
				'post_excerpt'      => 'Revision excerpt',
			)
		);
	}

	/**
	 * Return the exact parent/revision IDs used by this test class.
	 *
	 * @return array{post_id:int,revision_id:int}
	 */
	private function target_args(): array {
		return array(
			'post_id'     => 100,
			'revision_id' => 201,
		);
	}

	/**
	 * Return restore arguments with an explicit fresh-state token.
	 *
	 * @param string $expected_state Expected state HMAC.
	 * @return array{post_id:int,revision_id:int,expected_state:string}
	 */
	private function restore_args( string $expected_state ): array {
		return $this->target_args() + array( 'expected_state' => $expected_state );
	}

	/**
	 * Build a request for the actual ability execution gateway.
	 *
	 * @param array<string,mixed> $args Ability arguments.
	 * @param array<string,mixed> $auth Authenticated actor and scopes.
	 */
	private function request( array $args, array $auth ): AbilityExecutionRequest {
		return new AbilityExecutionRequest(
			array(
				'name'      => 'revisions.restore_content',
				'arguments' => $args,
			),
			$auth
		);
	}

	/**
	 * Build an authenticated actor with trusted direct-write enabled.
	 *
	 * @param array<string> $scopes OAuth scopes.
	 * @return array<string,mixed>
	 */
	private function trusted_write_auth( array $scopes ): array {
		return array(
			'user_id'                  => 1,
			'client_id'                => 'content-recovery-gateway-test',
			'provider'                 => 'chatgpt',
			'scopes'                   => $scopes,
			'profile'                  => 'full_access',
			'access_level'             => ConnectionAccessLevel::WRITE,
			'write_permission_enabled' => true,
		);
	}
}

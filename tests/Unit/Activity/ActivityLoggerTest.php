<?php
/**
 * Tests for connected AI activity metadata extraction.
 *
 * @package Aculect\AICompanion\Tests\Unit\Activity
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Activity;

use Aculect\AICompanion\Activity\ActivityLogger;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies activity logging stores metadata instead of raw action payloads.
 */
final class ActivityLoggerTest extends TestCase {

	public function test_safe_argument_metadata_drops_content_payloads(): void {
		$metadata = $this->invokePrivate(
			new ActivityLogger(),
			'safe_argument_metadata',
			array(
				'content.create_item',
				array(
					'post_type' => 'post',
					'title'     => 'Do not store this title',
					'content'   => '<p>Do not store this body.</p>',
					'url'       => 'https://images.example.test/path/photo.jpg?token=secret',
					'status'    => 'draft',
				),
			)
		);

		self::assertSame( 'content.create_item', $metadata['action'] );
		self::assertSame( 'post', $metadata['post_type'] );
		self::assertSame( 'draft', $metadata['status'] );
		self::assertSame( 'images.example.test', $metadata['source_host'] );
		self::assertArrayNotHasKey( 'title', $metadata );
		self::assertArrayNotHasKey( 'content', $metadata );
		self::assertArrayNotHasKey( 'url', $metadata );
	}

	public function test_recorded_context_includes_risk_level_without_payload_values(): void {
		$context = $this->invokePrivate(
			new ActivityLogger(),
			'context',
			array(
				'content.create_item',
				array(
					'title'   => 'Private draft title',
					'content' => '<p>Private body.</p>',
					'status'  => 'publish',
				),
				array(
					'id'     => 12,
					'type'   => 'post',
					'status' => 'publish',
				),
				'publish',
			)
		);

		self::assertSame( 'publish', $context['risk_level'] );
		self::assertContains( 'title', $context['argument_keys'] );
		self::assertArrayNotHasKey( 'title', $context['metadata'] );
		self::assertArrayNotHasKey( 'content', $context['metadata'] );
	}

	public function test_context_marks_trusted_write_without_payload_values(): void {
		$context = $this->invokePrivate(
			new ActivityLogger(),
			'context',
			array(
				'content.update_item',
				array(
					'title'   => 'Private title',
					'content' => '<p>Private body.</p>',
				),
				array(
					'id'     => 42,
					'status' => 'updated',
				),
				'update',
				array(
					'write_permission_used' => true,
					'access_level'          => 'full_write',
				),
			)
		);

		self::assertTrue( $context['write_permission']['used'] );
		self::assertSame( 'write', $context['write_permission']['access_level'] );
		self::assertArrayNotHasKey( 'title', $context['metadata'] );
		self::assertArrayNotHasKey( 'content', $context['metadata'] );
	}

	public function test_target_prefers_result_identifier_for_content_updates(): void {
		$target = $this->invokePrivate(
			new ActivityLogger(),
			'target',
			array(
				'content.update_item',
				array( 'id' => 12 ),
				array(
					'id'   => 34,
					'type' => 'page',
				),
			)
		);

		self::assertSame(
			array(
				'type' => 'page',
				'id'   => 34,
			),
			$target
		);
	}

	public function test_plugin_lifecycle_activity_uses_plugin_target_and_safe_basename_metadata(): void {
		$logger   = new ActivityLogger();
		$target   = $this->invokePrivate(
			$logger,
			'target',
			array(
				'plugin_lifecycle.update_plugin',
				array( 'plugin' => 'acme/acme.php' ),
				array(
					'status'  => 'updated',
					'changed' => true,
					'plugin'  => array( 'plugin' => 'acme/acme.php' ),
				),
			)
		);
		$metadata = $this->invokePrivate(
			$logger,
			'safe_argument_metadata',
			array(
				'plugin_lifecycle.update_plugin',
				array(
					'plugin'  => 'acme/acme.php',
					'package' => 'https://private.example/plugin.zip',
				),
			)
		);

		self::assertSame(
			array(
				'type' => 'plugin',
				'id'   => null,
			),
			$target
		);
		self::assertSame( 'acme/acme.php', $metadata['plugin'] );
		self::assertArrayNotHasKey( 'package', $metadata );
	}

	public function test_target_handles_workflow_and_index_events_without_payloads(): void {
		$workflow_target = $this->invokePrivate(
			new ActivityLogger(),
			'target',
			array(
				'content_workflow.update_post',
				array(
					'id'          => 12,
					'section_map' => array(
						'introduction' => '<!-- wp:paragraph --><p>Private body.</p><!-- /wp:paragraph -->',
					),
				),
				array(
					'post_id'  => 12,
					'workflow' => 'content_workflow_update_post',
				),
			)
		);

		$metadata = $this->invokePrivate(
			new ActivityLogger(),
			'safe_argument_metadata',
			array(
				'content_workflow.update_post',
				array(
					'id'          => 12,
					'update_mode' => 'sections',
					'section_map' => array(
						'introduction' => '<!-- wp:paragraph --><p>Private body.</p><!-- /wp:paragraph -->',
					),
				),
			)
		);

		$index_target = $this->invokePrivate(
			new ActivityLogger(),
			'target',
			array(
				'content_index.refresh_batch',
				array( 'post_type' => 'post' ),
				array( 'status' => 'queued' ),
			)
		);

		self::assertSame(
			array(
				'type' => 'content',
				'id'   => 12,
			),
			$workflow_target
		);
		self::assertSame( 'sections', $metadata['update_mode'] );
		self::assertArrayNotHasKey( 'section_map', $metadata );
		self::assertSame(
			array(
				'type' => 'intelligence_job',
				'id'   => null,
			),
			$index_target
		);
	}

	public function test_memory_save_activity_context_excludes_memory_values(): void {
		$logger = new ActivityLogger();
		$args   = array(
			'key'      => 'brand.voice.primary',
			'value'    => 'Private durable guidance that should not be logged.',
			'evidence' => 'Private evidence that should not be logged.',
			'status'   => 'approved',
		);

		$target  = $this->invokePrivate( $logger, 'target', array( 'memory.save', $args, array( 'status' => 'confirmation_required' ) ) );
		$context = $this->invokePrivate( $logger, 'context', array( 'memory.save', $args, array( 'status' => 'confirmation_required' ), 'update' ) );

		self::assertSame(
			array(
				'type' => 'memory',
				'id'   => null,
			),
			$target
		);
		self::assertSame( 'update', $context['risk_level'] );
		self::assertContains( 'value', $context['argument_keys'] );
		self::assertSame( 'approved', $context['metadata']['status'] );
		self::assertArrayNotHasKey( 'key', $context['metadata'] );
		self::assertArrayNotHasKey( 'value', $context['metadata'] );
		self::assertArrayNotHasKey( 'evidence', $context['metadata'] );
	}

	public function test_timeline_context_hashes_identifiers_and_excludes_secret_material(): void {
		$logger  = new ActivityLogger();
		$context = $this->invokePrivate(
			$logger,
			'timeline_context',
			array(
				'tool_call_end',
				array(
					'status'              => 'error',
					'method'              => 'tools/call',
					'tool'                => 'content.update_item',
					'client_secret'       => 'do-not-store',
					'authorization'       => 'Bearer do-not-store',
					'access_token'        => 'do-not-store',
					'authorization_code'  => 'do-not-store',
					'refresh_token'       => 'do-not-store',
					'full_request_body'   => array( 'content' => 'Private body' ),
					'full_content_body'   => '<p>Private body.</p>',
					'risk_level'          => 'publish',
					'duration_ms'         => 42,
					'target_summary'      => str_repeat( 'Private title ', 30 ),
					'error_code'          => 'invalid_scope',
					'blocked_by'          => 'oauth_scope',
					'confirmation_policy' => 'required_before_write',
				),
				array(
					'provider'    => 'ChatGPT',
					'client_id'   => 'client-secret-id',
					'client_name' => 'ChatGPT Connector',
					'user_id'     => 7,
				),
			)
		);

		self::assertSame( 'tool_call_end', $context['timeline_event'] );
		self::assertSame( 'chatgpt', $context['provider'] );
		self::assertSame( 7, $context['user_id'] );
		self::assertSame( 'error', $context['status'] );
		self::assertSame( 'content.update_item', $context['tool_name'] );
		self::assertSame( 'publish', $context['risk_level'] );
		self::assertSame( 42, $context['duration_ms'] );
		self::assertSame( 'invalid_scope', $context['error_code'] );
		self::assertSame( 'oauth_scope', $context['blocked_by'] );
		self::assertSame( 'required_before_write', $context['confirmation_policy'] );
		self::assertStringStartsWith( 'sha256:', $context['client_hash'] );
		self::assertStringStartsWith( 'sha256:', $context['session_hash'] );
		self::assertNotSame( 'client-secret-id', $context['client_hash'] );
		self::assertLessThanOrEqual( 160, strlen( $context['target_summary'] ) );
		self::assertArrayNotHasKey( 'client_secret', $context );
		self::assertArrayNotHasKey( 'authorization', $context );
		self::assertArrayNotHasKey( 'access_token', $context );
		self::assertArrayNotHasKey( 'authorization_code', $context );
		self::assertArrayNotHasKey( 'refresh_token', $context );
		self::assertArrayNotHasKey( 'full_request_body', $context );
		self::assertArrayNotHasKey( 'full_content_body', $context );
	}

	public function test_rejected_refresh_timeline_explains_pre_auth_identity_and_reconnect(): void {
		$logger  = new ActivityLogger();
		$context = $this->invokePrivate(
			$logger,
			'timeline_context',
			array(
				'token_refresh',
				array(
					'status'               => 'error',
					'error_code'           => 'invalid_grant',
					'identity_status'      => 'unavailable_pre_auth',
					'refresh_token_state'  => 'revoked',
					'recovery_action'      => 'reconnect_assistant',
					'connection_id'        => 42,
					'connection_client_id' => 'stored-client-id',
					'refresh_token'        => 'do-not-store',
					'full_request_body'    => array( 'refresh_token' => 'do-not-store' ),
				),
				array(
					'provider'  => 'codex',
					'client_id' => 'request-client-id',
				),
			)
		);
		$message = $this->invokePrivate( $logger, 'timeline_message', array( 'token_refresh', 'error', $context ) );

		self::assertSame( 'unavailable_pre_auth', $context['identity_status'] );
		self::assertSame( 'revoked', $context['refresh_token_state'] );
		self::assertSame( 'reconnect_assistant', $context['recovery_action'] );
		self::assertSame( 42, $context['connection_id'] );
		self::assertStringStartsWith( 'sha256:', $context['connection_client_hash'] );
		self::assertNotSame( 'stored-client-id', $context['connection_client_hash'] );
		self::assertArrayNotHasKey( 'connection_client_id', $context );
		self::assertArrayNotHasKey( 'refresh_token', $context );
		self::assertArrayNotHasKey( 'full_request_body', $context );
		self::assertSame(
			'Refresh was rejected before a WordPress identity was available. This request did not authenticate a WordPress session. Reconnect the assistant to restore access.',
			$message
		);
		self::assertStringNotContainsString( 'Unknown user', $message );
	}

	/**
	 * Invoke a private method for focused unit coverage.
	 *
	 * @param object $object    Object instance.
	 * @param string $method    Method name.
	 * @param array  $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}
}

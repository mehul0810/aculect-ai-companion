<?php
/**
 * Memory administration identity contract.
 *
 * @package Aculect\AICompanion\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\MemoryReviewAction;
use PHPUnit\Framework\TestCase;

/** Tests immutable identity and required observed revisions. */
final class MemoryReviewActionTest extends TestCase {
	public function test_every_action_preserves_namespace_and_version(): void {
		foreach ( array( 'approve', 'dismiss', 'delete', 'update' ) as $action ) {
			$result = ( new MemoryReviewAction() )->input(
				$action,
				'voice',
				array(
					'key'              => 'voice',
					'namespace'        => 'client:ChatGPT',
					'expected_version' => '7',
				)
			);
			self::assertSame( 'client:ChatGPT', $result['namespace'] );
			self::assertSame( 7, $result['expected_version'] );
			self::assertSame( 'voice', $result['key'] );
		}
	}

	public function test_invalid_identity_or_renaming_is_rejected_before_storage(): void {
		$valid = array(
			'key'              => 'voice',
			'namespace'        => 'site',
			'expected_version' => '7',
		);
		foreach ( array( array( 'key' => 'renamed' ), array( 'namespace' => '' ), array( 'namespace' => array() ), array( 'expected_version' => 0 ), array( 'expected_version' => '7x' ), array( 'value' => new \stdClass() ) ) as $invalid ) {
			self::assertNull( ( new MemoryReviewAction() )->input( 'update', 'voice', array_replace( $valid, $invalid ) ) );
		}
		self::assertNull( ( new MemoryReviewAction() )->input( 'update', 'voice', array() ) );
		self::assertFalse( ( new MemoryReviewAction() )->execute( 'unknown', 'voice', $valid ) );
	}

	public function test_approval_does_not_implicitly_share_private_memory(): void {
		$input  = array(
			'namespace'        => 'site',
			'expected_version' => 4,
			'visibility'       => 'private',
		);
		$action = new MemoryReviewAction();
		self::assertSame( 'private', $action->input( 'approve', 'tone', $input )['visibility'] );
		$input['visibility'] = 'site';
		self::assertSame( 'site', $action->input( 'update', 'tone', $input )['visibility'] );
		$input['visibility'] = 'public';
		self::assertNull( $action->input( 'update', 'tone', $input ) );
	}
}

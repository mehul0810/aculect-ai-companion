<?php
/**
 * Keep finite style recovery compatible with safe native presets.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\EditorStylePolicy;
use PHPUnit\Framework\TestCase;

final class EditorStyleRecoveryPolicyTest extends TestCase {

	public function test_native_preset_recovery_preserves_unchanged_unrestricted_paths(): void {
		$policy = new EditorStylePolicy();
		$before = array(
			'styles' => array(
				'color'      => array( 'text' => 'var:preset|color|contrast' ),
				'typography' => array( 'fontSize' => 'var:preset|font-size|large' ),
				'spacing'    => array( 'padding' => '20px' ),
			),
		);
		$after  = $policy->apply( $before, 'styles.color.text', '#112233' );
		self::assertTrue( $policy->recoverable( $before, 'styles.color.text' ) );
		self::assertTrue( $policy->restorable( $after, $before ) );
		self::assertFalse( $policy->valid( 'styles.color.text', 'var:preset|color|contrast' ) );
		$before['styles']['css'] = 'body { display:none }';
		self::assertFalse( $policy->restorable( $after, $before ) );
	}

	public function test_unsupported_values_cannot_be_promised_as_recoverable(): void {
		$policy = new EditorStylePolicy();
		foreach ( array( 'url(https://example.org/)', 'var(--arbitrary)', 'var:preset|spacing|wrong-type', array( 'unexpected' ) ) as $value ) {
			self::assertFalse( $policy->recoverable( array( 'styles' => array( 'color' => array( 'text' => $value ) ) ), 'styles.color.text' ) );
		}
		self::assertTrue( $policy->recoverable( array( 'styles' => array( 'typography' => array( 'fontWeight' => 400 ) ) ), 'styles.typography.fontWeight' ) );
	}

	public function test_absent_nested_override_removal_preserves_native_scalar_padding(): void {
		$policy = new EditorStylePolicy();
		$config = array( 'styles' => array( 'spacing' => array( 'padding' => '20px' ) ) );
		self::assertSame( $config, $policy->apply( $config, 'styles.spacing.padding.top', null ) );
		$this->expectException( \InvalidArgumentException::class );
		$policy->apply( $config, 'styles.spacing.padding.top', '10px' );
	}
}

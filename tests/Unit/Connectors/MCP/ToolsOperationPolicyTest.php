<?php
/**
 * Administrative tools execution policy tests.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ToolsOperationPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Navigation must never imply permission to execute an operation.
 */
final class ToolsOperationPolicyTest extends TestCase {

	public function test_prohibited_native_operations_are_explicitly_denied(): void {
		foreach ( array( 'theme-editor.php', 'plugin-editor.php', 'ms-delete-site.php', 'plugin-editor.php?file=test.php' ) as $slug ) {
			$result = ToolsOperationPolicy::for_page( $slug );
			self::assertSame( 'not_allowed', $result['status'] );
			self::assertFalse( $result['execution_allowed'] );
			self::assertStringContainsString( 'not allowed through Aculect abilities', $result['message'] );
		}
	}

	public function test_other_pages_do_not_imply_execution_authority(): void {
		foreach ( array( '', 'tools.php', 'network.php', 'export.php' ) as $slug ) {
			$result = ToolsOperationPolicy::for_page( $slug );
			self::assertSame( 'navigation_only', $result['status'] );
			self::assertFalse( $result['execution_allowed'] );
		}
	}
}

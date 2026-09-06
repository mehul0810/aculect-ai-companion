<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpToolAnnotations;
use PHPUnit\Framework\TestCase;

final class McpToolAnnotationsTest extends TestCase {

	public function test_external_wordpress_mutations_are_open_world(): void {
		$registry    = new AbilitiesRegistry();
		$annotations = new McpToolAnnotations();

		foreach ( array( 'plugin_lifecycle.install_plugin', 'plugin_lifecycle.update_plugin', 'theme_lifecycle.switch_theme', 'redirects.create' ) as $ability_id ) {
			$module = $registry->module( $ability_id );
			self::assertNotNull( $module );
			self::assertTrue( $annotations->for_module( $module )['openWorldHint'] );
		}
	}
}

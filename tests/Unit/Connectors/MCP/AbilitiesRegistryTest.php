<?php
/**
 * Tests for MCP ability registration and public tool-name mapping.
 *
 * @package Quark\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Quark\Tests\Unit\Connectors\MCP;

use PHPUnit\Framework\TestCase;
use Quark\Connectors\MCP\AbilitiesRegistry;

/**
 * Verifies the internal ability IDs remain decoupled from MCP-safe tool names.
 */
final class AbilitiesRegistryTest extends TestCase {

	private AbilitiesRegistry $registry;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['quark_test_options'] = array();
		$this->registry                = new AbilitiesRegistry();
	}

	public function test_public_tool_names_are_claude_safe_and_round_trip_to_internal_ids(): void {
		foreach ( $this->registry->definitions() as $internal_id => $definition ) {
			$tool_name = $this->registry->tool_name( (string) $internal_id );

			self::assertSame((string) $internal_id, (string) $definition['id']);
			self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $tool_name);
			self::assertStringNotContainsString('.', $tool_name);
			self::assertStringNotContainsString('/', $tool_name);
			self::assertSame((string) $internal_id, $this->registry->internal_id($tool_name));
		}
	}

	public function test_legacy_create_draft_aliases_map_to_create_item(): void {
		self::assertSame('content.create_item', $this->registry->internal_id('content.create_draft'));
		self::assertSame('content.create_item', $this->registry->internal_id('content_create_draft'));
	}

	public function test_public_definitions_include_enabled_status_and_tool_name(): void {
		$public_definitions = $this->registry->public_definitions();

		self::assertNotEmpty($public_definitions);

		foreach ( $public_definitions as $definition ) {
			self::assertArrayHasKey('toolName', $definition);
			self::assertArrayHasKey('enabled', $definition);
			self::assertTrue($definition['enabled']);
			self::assertIsString($definition['toolName']);
			self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $definition['toolName']);
		}
	}

	public function test_requested_expansion_abilities_are_registered(): void {
		$definitions = $this->registry->definitions();

		foreach (
			array(
				'wp_abilities.discover',
				'wp_abilities.get_info',
				'wp_abilities.run',
				'comments.list_items',
				'comments.get_item',
				'comments.create_item',
				'comments.update_item',
				'media.upload_item',
				'site.get_info',
				'site.list_plugins',
				'site.list_themes',
			) as $ability_id
		) {
			self::assertArrayHasKey($ability_id, $definitions);
			self::assertTrue($this->registry->is_known($this->registry->tool_name($ability_id)));
		}

		self::assertSame(array('content:draft'), $this->registry->required_scopes('wp_abilities.run'));
		self::assertSame(array('content:draft'), $this->registry->required_scopes('media.upload_item'));
		self::assertSame(array('content:read'), $this->registry->required_scopes('site.list_plugins'));
	}

	public function test_saving_enabled_ids_sanitizes_unknown_values_and_public_aliases(): void {
		$this->registry->save_enabled_ids(
			array(
				'content_list_items',
				'content.create_draft',
				'<script>',
				array(),
			)
		);

		self::assertSame(
			array(
				'content.list_items',
				'content.create_item',
			),
			$this->registry->enabled_ids()
		);
	}
}

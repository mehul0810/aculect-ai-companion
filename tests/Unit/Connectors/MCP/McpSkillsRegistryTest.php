<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpSkillsRegistry;
use Aculect\AICompanion\Connectors\MCP\Skills\CustomMcpSkillStore;
use Aculect\AICompanion\Tests\Support\ScopedSkillOptions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/ScopedSkillOptions.php';

/**
 * Verifies the stable MCP Skills manifest and packaged core guidance.
 */
final class McpSkillsRegistryTest extends TestCase {

	private McpSkillsRegistry $registry;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_site_options']     = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite']     = false;
		$GLOBALS['aculect_ai_companion_test_blog_id']          = 1;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();
		ScopedSkillOptions::reset();
		$this->registry = new McpSkillsRegistry();
	}

	public function test_core_skills_follow_agent_skills_frontmatter_and_raw_file_manifest_contract(): void {
		$result = $this->registry->list_skills( $this->all_required_abilities() );
		$all    = $result['skills'];
		if ( isset( $result['nextCursor'] ) ) {
			$next = $this->registry->list_skills( $this->all_required_abilities(), $result['nextCursor'] );
			$all  = array_merge( $all, $next['skills'] );
		}

		self::assertCount( 3, $all );
		foreach ( $all as $skill ) {
			self::assertMatchesRegularExpression( '/^skill:\/\/[a-z0-9-]+\/SKILL\.md$/', $skill['uri'] );
			self::assertSame( basename( dirname( $skill['uri'] ) ), $skill['frontmatter']['name'] );
			self::assertGreaterThan( 0, strlen( $skill['frontmatter']['description'] ) );
			self::assertCount( 1, $skill['resources'] );
			self::assertSame( $skill['uri'], $skill['resources'][0]['uri'] );

			$read = $this->registry->read_resource( $skill['uri'], $this->all_required_abilities() );
			$text = $read['contents'][0]['text'];
			self::assertSame( strlen( $text ), $skill['resources'][0]['size'] );
			self::assertSame( 'sha256:' . hash( 'sha256', $text ), $skill['resources'][0]['digest'] );
			self::assertStringContainsString( 'name: ' . $skill['frontmatter']['name'], $text );
			self::assertStringContainsString( 'description: ' . $skill['frontmatter']['description'], $text );
		}
	}

	public function test_skills_are_filtered_when_required_abilities_are_not_exposed(): void {
		$site_audit = $this->registry->list_skills( array( 'site_workflow_audit' ) );
		$content    = $this->registry->list_skills( array( 'content_search_items', 'content_get_item' ) );
		$empty      = $this->registry->list_skills( array( 'site_get_info' ) );

		self::assertSame( array( 'wordpress-site-audit' ), $this->skill_slugs( $site_audit ) );
		self::assertSame( array( 'content-optimization' ), $this->skill_slugs( $content ) );
		self::assertSame( array(), $this->skill_slugs( $empty ) );
	}

	public function test_manifest_uses_atomic_cursor_pages_and_rejects_malformed_or_stale_cursors(): void {
		$abilities = $this->all_required_abilities();
		$first     = $this->registry->list_skills( $abilities );

		self::assertCount( 2, $first['skills'] );
		self::assertArrayHasKey( 'nextCursor', $first );
		$second = $this->registry->list_skills( $abilities, $first['nextCursor'] );
		self::assertCount( 1, $second['skills'] );
		self::assertArrayNotHasKey( 'nextCursor', $second );

		self::assertSame( 'invalid_cursor', $this->registry->list_skills( $abilities, '%%%not-a-cursor%%%' )['error'] );
		self::assertSame( 'invalid_cursor', $this->registry->list_skills( $abilities, str_repeat( 'a', 257 ) )['error'] );
		self::assertSame( 'invalid_cursor', $this->registry->list_skills( array( 'site_workflow_audit' ), $first['nextCursor'] )['error'] );
	}

	public function test_get_and_resource_read_fail_closed_for_unknown_or_malformed_uris(): void {
		$abilities = $this->all_required_abilities();
		self::assertSame( 'skill_not_found', $this->registry->get_skill( 'skill://unknown/SKILL.md', $abilities )['error'] );
		self::assertSame( 'skill_not_found', $this->registry->get_skill( 'skill://wordpress-site-audit/../SKILL.md', $abilities )['error'] );
		self::assertSame( 'skill_not_found', $this->registry->read_resource( 'skill://content-optimization/private.md', $abilities )['error'] );
		self::assertSame( 'skill_not_found', $this->registry->read_resource( 'skill://content-optimization/SKILL.md', array() )['error'] );
	}

	public function test_resource_descriptors_follow_per_connection_skill_availability(): void {
		$resources = $this->registry->resource_descriptors( array( 'site_workflow_audit' ) );

		self::assertCount( 1, $resources );
		self::assertSame( 'skill://wordpress-site-audit/SKILL.md', $resources[0]['uri'] );
		self::assertSame( 'text/markdown', $resources[0]['mimeType'] );
	}

	public function test_custom_skills_require_enabled_state_and_connection_dependencies_and_serve_all_resources(): void {
		$store   = new CustomMcpSkillStore( new ScopedSkillOptions() );
		$created = $store->create(
			array(
				'id'                 => 'custom-editorial-guide',
				'skill_md'           => "---\nname: custom-editorial-guide\ndescription: Editorial validation\n---\nUse the approved editorial checklist.\n",
				'references'         => array( 'references/checklist.md' => "Check headings and links.\n" ),
				'required_abilities' => array( 'site_workflow.audit' ),
				'required_plugins'   => array(),
				'enabled'            => true,
			)
		);
		self::assertTrue( $created['success'] );
		$registry = new McpSkillsRegistry( $store );

		self::assertSame( array(), $registry->list_skills( array( 'content_get_item' ) )['skills'] );
		$listed       = $registry->list_skills( array( 'site_workflow_audit' ) );
		$custom_entry = array_values( array_filter( $listed['skills'], static fn ( array $entry ): bool => str_contains( $entry['uri'], 'custom-editorial-guide' ) ) )[0];
		self::assertCount( 2, $custom_entry['resources'] );
		self::assertSame( 'skill://custom-editorial-guide/SKILL.md', $custom_entry['uri'] );
		$get = $registry->get_skill( $custom_entry['uri'], array( 'site_workflow_audit' ) );
		self::assertCount( 2, $get['skill']['resources'] );

		$reference = $registry->read_resource( 'skill://custom-editorial-guide/references/checklist.md', array( 'site_workflow_audit' ) );
		self::assertSame( "Check headings and links.\n", $reference['contents'][0]['text'] );
		self::assertSame( 'skill_not_found', $registry->read_resource( 'skill://custom-editorial-guide/references/../private.md', array( 'site_workflow_audit' ) )['error'] );

		$store->set_enabled( 'custom-editorial-guide', false );
		$disabled_page = $registry->list_skills( array( 'site_workflow_audit' ) );
		self::assertNotContains( 'custom-editorial-guide', $this->skill_slugs( $disabled_page ) );
	}

	public function test_plugin_skills_are_validated_dependency_gated_and_duplicate_ids_fail_closed(): void {
		$GLOBALS['aculect_ai_companion_test_options']['active_plugins']                                    = array( 'vendor/editorial.php', 'vendor/other.php' );
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_skill_providers'] = static function (): array {
			$skill                          = array(
				'id'                 => 'plugin-editorial-check',
				'skill_md'           => "---\nname: plugin-editorial-check\ndescription: Editorial plugin checklist\n---\nReview the draft carefully.\n",
				'references'         => array( 'references/review.md' => "Check the title and summary.\n" ),
				'required_abilities' => array( 'site_workflow.audit' ),
				'required_plugins'   => array(),
				'enabled'            => true,
			);
			$oversized                      = $skill;
			$oversized['id']                = 'plugin-oversized-guide';
			$oversized['skill_md']          = str_replace( 'plugin-editorial-check', 'plugin-oversized-guide', $skill['skill_md'] ) . str_repeat( 'x', 33000 );
			$invalid                        = $skill;
			$invalid['skill_md']           .= "\n<script>alert(1)</script>\n";
			$collision_provider             = $skill;
			$collision_provider['id']       = 'plugin-shared-collision';
			$collision_provider['skill_md'] = str_replace( 'plugin-editorial-check', 'plugin-shared-collision', $skill['skill_md'] );
			return array(
				'vendor/editorial.php' => array( $skill, $oversized, $invalid, $collision_provider ),
				'vendor/other.php'     => array( $collision_provider ),
				'vendor/inactive.php'  => array( $skill ),
			);
		};
		$registry = new McpSkillsRegistry();
		$listed   = $registry->list_skills( array( 'site_workflow_audit' ) );
		$slugs    = $this->skill_slugs( $listed );
		self::assertContains( 'plugin-editorial-check', $slugs );
		self::assertNotContains( 'plugin-oversized-guide', $slugs );
		self::assertNotContains( 'plugin-shared-collision', $slugs );
		self::assertNotContains( 'plugin-inactive-provider', $slugs );
		self::assertSame( "Check the title and summary.\n", $registry->read_resource( 'skill://plugin-editorial-check/references/review.md', array( 'site_workflow_audit' ) )['contents'][0]['text'] );
		self::assertSame( array(), $registry->list_skills( array() )['skills'] );
	}

	public function test_plugin_dependency_requires_site_or_network_activation_and_catalog_is_metadata_only(): void {
		$GLOBALS['aculect_ai_companion_test_is_multisite']                            = true;
		$GLOBALS['aculect_ai_companion_test_options']['active_plugins']               = array( 'vendor/provider.php' );
		$GLOBALS['aculect_ai_companion_test_site_options']['active_sitewide_plugins'] = array( 'vendor/network.php' => 1 );
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_skill_providers'] = static function (): array {
			return array(
				'vendor/provider.php' => array(
					array(
						'id'                 => 'plugin-needs-network',
						'skill_md'           => "---\nname: plugin-needs-network\ndescription: Network requirement check\n---\nUse the site review workflow.\n",
						'references'         => array(),
						'required_abilities' => array(),
						'required_plugins'   => array( 'vendor/network.php' ),
					),
				),
			);
		};
		$store = new CustomMcpSkillStore( new ScopedSkillOptions() );
		$store->create(
			array(
				'id'                 => 'custom-local-only',
				'skill_md'           => "---\nname: custom-local-only\ndescription: Site local custom guide\n---\nUse the local site guide.\n",
				'references'         => array(),
				'required_abilities' => array(),
				'required_plugins'   => array(),
				'enabled'            => true,
			)
		);
		$registry = new McpSkillsRegistry( $store );
		self::assertContains( 'plugin-needs-network', $this->skill_slugs( $registry->list_skills( array() ) ) );
		self::assertContains( 'custom-local-only', array_column( $registry->admin_catalog(), 'id' ) );
		self::assertSame( 'custom', $this->catalog_entry( $registry->admin_catalog(), 'custom-local-only' )['source'] );
		self::assertArrayNotHasKey( 'documents', $this->catalog_entry( $registry->admin_catalog(), 'custom-local-only' ) );
		$GLOBALS['aculect_ai_companion_test_blog_id'] = 2;
		self::assertNotContains( 'custom-local-only', array_column( $registry->admin_catalog(), 'id' ) );
	}

	public function test_plugin_catalog_fails_closed_when_global_declaration_or_byte_budget_is_exceeded(): void {
		$GLOBALS['aculect_ai_companion_test_options']['active_plugins'] = array( 'vendor/bulk.php' );
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_skill_providers'] = static function (): array {
			$items = array();
			for ( $index = 0; $index <= 100; ++$index ) {
				$id      = 'plugin-bulk-' . $index;
				$items[] = array(
					'id'         => $id,
					'skill_md'   => "---\nname: {$id}\ndescription: Bulk test\n---\nUse the available ability.\n",
					'references' => array(),
				);
			}
			return array( 'vendor/bulk.php' => $items );
		};
		self::assertSame( array(), $this->registry->list_skills( array() )['skills'] );

		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_skill_providers'] = static function (): array {
			$items = array();
			for ( $index = 0; $index < 70; ++$index ) {
				$id      = 'plugin-bulk-' . $index;
				$items[] = array(
					'id'         => $id,
					'skill_md'   => "---\nname: {$id}\ndescription: Bulk test\n---\n" . str_repeat( 'x', 31000 ),
					'references' => array(),
				);
			}
			return array( 'vendor/bulk.php' => $items );
		};
		self::assertSame( array(), $this->registry->list_skills( array() )['skills'] );
	}

	/**
	 * Return all dependencies needed for the three bundled skills.
	 *
	 * @return list<string>
	 */
	private function all_required_abilities(): array {
		return array(
			'site_workflow_audit',
			'content_search_items',
			'content_get_item',
			'site_get_info',
			'site_get_health',
		);
	}

	/**
	 * Return final path segments for a page of Skill entries.
	 *
	 * @param array<string, mixed> $page Skill list response.
	 * @return list<string>
	 */
	private function skill_slugs( array $page ): array {
		return array_map(
			static fn ( array $skill ): string => basename( dirname( $skill['uri'] ) ),
			$page['skills']
		);
	}

	/**
	 * Return one catalog entry by stable Skill ID.
	 *
	 * @param list<array<string,mixed>> $catalog Provider metadata.
	 * @param string                    $id Skill identifier.
	 * @return array<string,mixed>
	 */
	private function catalog_entry( array $catalog, string $id ): array {
		foreach ( $catalog as $entry ) {
			if ( ( $entry['id'] ?? null ) === $id ) {
				return $entry;
			}
		}
		return array();
	}
}

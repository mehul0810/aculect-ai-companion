<?php
/**
 * Tests for MCP content workflow abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ContentWorkflowAbilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies higher-level content workflows stay block-safe and deterministic.
 */
final class ContentWorkflowAbilitiesTest extends TestCase {

	private ContentWorkflowAbilities $abilities;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			7 => (object) array(
				'ID'           => 7,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
			);
			$GLOBALS['aculect_ai_companion_test_posts']           = array(
				123 => new \WP_Post(
					array(
						'ID'           => 123,
						'post_type'    => 'post',
						'post_status'  => 'draft',
						'post_title'   => 'Existing Guide',
						'post_content' => '<!-- wp:heading {"anchor":"introduction"} --><h2 id="introduction">Introduction</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Old opening section.</p><!-- /wp:paragraph -->'
							. '<!-- wp:heading {"anchor":"implementation-notes"} --><h2 id="implementation-notes">Implementation Notes</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Existing implementation guidance stays intact.</p><!-- /wp:paragraph -->',
					)
				),
			);

			$this->abilities = new ContentWorkflowAbilities();
			$this->registerTestBlocks();
		}

	public function test_prepare_post_returns_deterministic_long_form_block_plan(): void {
		$result = $this->abilities->prepare_post(
			array(
				'brief'              => 'Write a comprehensive guide to MCP content workflows.',
				'post_type'          => 'post',
				'audience'           => 'WordPress site owners',
				'seo_intent'         => 'MCP content management',
				'desired_word_count' => 3500,
			)
		);

		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'content_workflow_prepare_post', $result['workflow'] );
		self::assertSame( 3500, $result['desired_word_count'] );
		self::assertGreaterThanOrEqual( 6, count( $result['outline'] ) );
		self::assertSame( 'serialized_wordpress_blocks', $result['block_plan']['format'] );
		self::assertContains( 'core/paragraph', $result['block_plan']['allowed_blocks'] );
		self::assertSame( array( 'core/html' ), $result['block_plan']['never_use'] );
		self::assertSame( 'content_workflow_create_draft', $result['required_operations']['create_draft']['tool'] );
		self::assertArrayHasKey( 'intelligence_context', $result );
		self::assertSame( 'unavailable', $result['intelligence_context']['status'] );
	}

	public function test_create_draft_refuses_custom_html_before_wordpress_write(): void {
		$result = $this->abilities->create_draft(
			array(
				'title'   => 'Unsafe draft',
				'content' => '<!-- wp:html --><div>Raw</div><!-- /wp:html -->',
			)
		);

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'invalid_block_content', $result['error'] );
		self::assertFalse( $result['block_validation']['valid'] );
		self::assertContains( 'Never use the Custom HTML block (core/html). Use registered semantic blocks or patterns instead.', $result['warnings'] );
	}

	public function test_update_post_dry_run_accepts_valid_section_map_and_requires_confirmation(): void {
		$result = $this->abilities->update_post(
			array(
				'id'          => 123,
				'update_mode' => 'sections',
				'section_map' => array(
					'introduction' => array(
						'content' => '<!-- wp:heading --><h2>Introduction</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Opening section.</p><!-- /wp:paragraph -->',
					),
				),
				'dry_run'     => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertTrue( $result['dry_run'] );
		self::assertSame( 'content_workflow_update_post', $result['workflow'] );
			self::assertTrue( $result['confirmation_required'] );
			self::assertTrue( $result['block_validation']['valid'] );
			self::assertSame( 'content', $result['changes'][0]['field'] );
			self::assertSame( array( 'introduction' ), $result['section_updates'] );
			self::assertStringContainsString( 'Opening section.', $result['changes'][0]['to'] );
			self::assertStringContainsString( 'Existing implementation guidance stays intact.', $result['changes'][0]['to'] );
			self::assertStringNotContainsString( 'Old opening section.', $result['changes'][0]['to'] );
		}

		public function test_update_post_section_map_rejects_unknown_section_ids(): void {
			$result = $this->abilities->update_post(
				array(
					'id'          => 123,
					'update_mode' => 'sections',
					'section_map' => array(
						'missing-section' => array(
							'content' => '<!-- wp:heading --><h2>Missing Section</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Should not merge.</p><!-- /wp:paragraph -->',
						),
					),
					'dry_run'     => true,
				)
			);

			self::assertSame( 'error', $result['status'] );
			self::assertSame( 'section_not_found', $result['error'] );
			self::assertSame( array( 'missing-section' ), $result['missing_section_ids'] );
			self::assertContains( 'implementation-notes', $result['available_section_ids'] );
		}

	public function test_desired_word_count_is_clamped_for_long_form_workflows(): void {
		$result = $this->abilities->prepare_post(
			array(
				'brief'              => 'Plan a very large guide.',
				'desired_word_count' => 12000,
			)
		);

		self::assertSame( 5000, $result['desired_word_count'] );
	}

	private function registerTestBlocks(): void {
		\WP_Block_Type_Registry::get_instance()->unregister_all();
		foreach ( array( 'core/heading', 'core/paragraph', 'core/list', 'core/quote', 'core/image', 'core/buttons', 'core/table', 'core/separator', 'core/html' ) as $name ) {
			\WP_Block_Type_Registry::get_instance()->register(
				$name,
				array(
					'title'    => $name,
					'category' => 'text',
					'supports' => array(
						'inserter' => true,
					),
				)
			);
		}
	}
}

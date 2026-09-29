<?php
/**
 * Tests for server-verified pattern picker candidates.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\IntelligenceRegistry;
use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\PatternPickerCandidates;
use PHPUnit\Framework\TestCase;

/**
 * Verifies compatibility is based on current WordPress registries and parsed content.
 */
final class PatternPickerCandidatesTest extends TestCase {
	private PatternPickerCandidates $picker;

	protected function setUp(): void {
		parent::setUp();
		$this->picker                                     = new PatternPickerCandidates();
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		\WP_Block_Type_Registry::get_instance()->unregister_all();
		\WP_Block_Patterns_Registry::get_instance()->unregister_all();
		foreach ( array( 'core/paragraph', 'core/heading', 'core/group' ) as $name ) {
			\WP_Block_Type_Registry::get_instance()->register( $name, array( 'title' => $name ) );
		}
		$this->register_pattern(
			'theme/hero',
			'<!-- wp:group --><!-- wp:heading --><h2>Hero</h2><!-- /wp:heading --><!-- /wp:group -->',
			array(
				'source'     => 'theme',
				'categories' => array( 'hero' ),
				'postTypes'  => array( 'page' ),
				'blockTypes' => array( 'core/group' ),
			)
		);
	}

	public function test_marks_compatible_only_for_verified_identity_registered_blocks_and_target_type(): void {
		$result = $this->picker->list(
			array(
				'content_type' => 'page',
				'context'      => 'full',
			)
		);

		self::assertSame( 'aculect.pattern-picker.v1', $result['schema'] );
		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'compatible', $result['items'][0]['compatibility'] );
		self::assertSame( array( 'core/group', 'core/heading' ), $result['items'][0]['supported_blocks'] );
		self::assertSame( 'page', $result['items'][0]['content_type'] );
		self::assertTrue( $result['items'][0]['content_available'] );
		self::assertArrayNotHasKey( 'content', $result['items'][0] );
	}

	public function test_untrusted_descriptive_metadata_cannot_make_unregistered_content_compatible(): void {
		$this->register_pattern(
			'plugin/forged',
			'<!-- wp:missing/unsafe --><p>Not registered</p><!-- /wp:missing/unsafe -->',
			array(
				'source'     => 'plugin',
				'categories' => array( 'hero' ),
				'postTypes'  => array( 'page' ),
				'blockTypes' => array( 'core/group' ),
			)
		);
		$this->register_pattern(
			'plugin/identity',
			'<!-- wp:group /-->',
			array(
				'name'      => 'theme/renamed',
				'postTypes' => array( 'page' ),
			)
		);

		$result = $this->picker->list( array( 'content_type' => 'page' ) );
		$items  = array_column( $result['items'], null, 'name' );

		self::assertSame( 'incompatible', $items['plugin/forged']['compatibility'] );
		self::assertSame( array( 'missing/unsafe' ), $items['plugin/forged']['supported_blocks'] );
		self::assertStringContainsString( 'not registered', $items['plugin/forged']['compatibility_message'] );
		self::assertArrayNotHasKey( 'theme/renamed', $items );
		self::assertSame( 'compatible', $items['plugin/identity']['compatibility'] );
	}

	public function test_missing_blocks_and_html_block_fail_closed(): void {
		$this->register_pattern( 'plugin/no-blocks', '<section>Text only</section>', array( 'postTypes' => array( 'page' ) ) );
		$this->register_pattern( 'plugin/html', '<!-- wp:html --><div>HTML</div><!-- /wp:html -->', array( 'postTypes' => array( 'page' ) ) );

		$result = $this->picker->list( array( 'content_type' => 'page' ) );
		$items  = array_column( $result['items'], null, 'id' );

		self::assertSame( 'unknown', $items['plugin/no-blocks']['compatibility'] );
		self::assertSame( 'incompatible', $items['plugin/html']['compatibility'] );
		self::assertStringContainsString( 'Custom HTML', $items['plugin/html']['compatibility_message'] );
	}

	public function test_content_type_mismatch_is_incompatible_and_missing_selection_is_unknown(): void {
		self::assertSame( 0, $this->picker->list( array( 'content_type' => 'post' ) )['total'] );
		self::assertSame( 'unknown', $this->picker->list()['items'][0]['compatibility'] );
	}

	public function test_unavailable_or_removed_patterns_have_actionable_state(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'read' );
		$denied = $this->picker->list( array( 'content_type' => 'page' ) );
		self::assertSame( 'unavailable', $denied['status'] );
		self::assertStringContainsString( 'access', $denied['message'] );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		\WP_Block_Patterns_Registry::get_instance()->unregister_all();
		$removed = $this->picker->list( array( 'content_type' => 'page' ) );
		self::assertSame( 'ready', $removed['status'] );
		self::assertSame( array(), $removed['items'] );
		self::assertSame( 0, $removed['total'] );
	}

	public function test_existing_pattern_inventory_shape_remains_available_with_picker_fields_added(): void {
		$result = ( new IntelligenceRegistry() )->execute( 'intelligence.patterns.list_available', array( 'content_type' => 'page' ) );

		self::assertArrayHasKey( 'context', $result );
		self::assertArrayHasKey( 'site_context', $result );
		self::assertArrayHasKey( 'content_guidance', $result );
		self::assertArrayHasKey( 'content_available', $result['items'][0] );
		self::assertArrayNotHasKey( 'compatibility', $result['items'][0] );
		self::assertArrayNotHasKey( 'schema', $result );

		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_apps_enabled'] = static fn (): bool => true;
		$unnegotiated = ( new IntelligenceRegistry() )->execute(
			'intelligence.patterns.list_available',
			array(
				'content_type' => 'page',
				'for_picker'   => true,
			)
		);
		self::assertArrayNotHasKey( 'schema', $unnegotiated );
		self::assertArrayNotHasKey( 'compatibility', $unnegotiated['items'][0] );

		$initialize = array(
			'params' => array(
				'capabilities' => array(
					'extensions' => array(
						McpAppsNegotiation::EXTENSION => array(
							'mimeTypes' => array( McpAppsNegotiation::MIME_TYPE ),
						),
					),
				),
			),
		);
		$auth       = array( 'token_id' => 'pattern-picker-capability-test' );
		$negotiated = McpAppsNegotiation::enabled_for_request( 'initialize', $initialize, '2025-11-25', $auth );
		$picker     = McpAppsNegotiation::with_request_enabled(
			$negotiated,
			static fn (): array => ( new IntelligenceRegistry() )->execute(
				'intelligence.patterns.list_available',
				array(
					'content_type' => 'page',
					'for_picker'   => true,
				)
			)
		);
		self::assertSame( 'compatible', $picker['items'][0]['compatibility'] );
		self::assertSame( 'aculect.pattern-picker.v1', $picker['schema'] );
		$without_hint = McpAppsNegotiation::with_request_enabled(
			$negotiated,
			static fn (): array => ( new IntelligenceRegistry() )->execute( 'intelligence.patterns.list_available', array( 'content_type' => 'page' ) )
		);
		self::assertSame( 'aculect.pattern-picker.v1', $without_hint['schema'] );
		self::assertSame( 'compatible', $without_hint['items'][0]['compatibility'] );
		unset( $GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_apps_enabled'] );
	}

	public function test_freeform_markup_within_parsed_pattern_is_marked_unparsed(): void {
		$method = new \ReflectionMethod( PatternPickerCandidates::class, 'inspect_blocks' );
		$result = $method->invoke(
			$this->picker,
			array(
				array(
					'blockName'   => 'core/group',
					'innerHTML'   => '',
					'innerBlocks' => array(
						array(
							'blockName'   => null,
							'innerHTML'   => '<p>Unwrapped text</p>',
							'innerBlocks' => array(),
						),
					),
				),
			)
		);

		self::assertTrue( $result['unparsed'] );
	}

	public function test_source_category_block_and_content_type_filters_are_applied_before_pagination(): void {
		$this->register_pattern(
			'plugin/matching',
			'<!-- wp:group /-->',
			array(
				'source'     => 'plugin',
				'categories' => array( 'cards' ),
				'blockTypes' => array( 'core/group' ),
				'postTypes'  => array( 'page' ),
			)
		);
		$this->register_pattern(
			'plugin/other',
			'<!-- wp:paragraph /-->',
			array(
				'source'     => 'plugin',
				'categories' => array( 'text' ),
				'postTypes'  => array( 'post' ),
			)
		);

		$result = $this->picker->list(
			array(
				'source'       => 'plugin',
				'category'     => 'cards',
				'block_type'   => 'core/group',
				'content_type' => 'page',
				'per_page'     => 1,
				'page'         => 1,
			)
		);

		self::assertSame( 1, $result['total'] );
		self::assertCount( 1, $result['items'] );
		self::assertSame( 'plugin/matching', $result['items'][0]['id'] );
	}

	private function register_pattern( string $name, string $content, array $metadata = array() ): void {
		\WP_Block_Patterns_Registry::get_instance()->register(
			$name,
			array_merge(
				array(
					'title'   => $name,
					'content' => $content,
				),
				$metadata
			)
		);
	}
}

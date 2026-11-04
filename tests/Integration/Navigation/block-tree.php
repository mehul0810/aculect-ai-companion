<?php
/**
 * Pure navigation transformer proof against the loaded native WordPress parser.
 *
 * Run: wp eval-file tests/Integration/Navigation/block-tree.php
 * Uses synthetic strings only; no posts, settings, or other site data are changed.
 * This deliberately avoids the lightweight unit bootstrap's block parser doubles.
 *
 * @package Aculect\AICompanion\Tests\Integration\Navigation
 */

use Aculect\AICompanion\Connectors\MCP\NavigationBlockTree;

// WP-CLI eval-file prepends code, so strict_types cannot be declared here.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI synthetic assertion labels and bounded metadata only.

require_once dirname( __DIR__, 3 ) . '/src/Connectors/MCP/NavigationBlockTree.php';

/** Exercises only the pure transformation boundary with real WordPress functions. */
final class AculectNavigationBlockTreeProof {
	private NavigationBlockTree $service;
	private int $checks = 0;

	/** Run the native parser contract and emit a bounded receipt. */
	public function run(): void {
		$this->service = new NavigationBlockTree();
		$this->check( class_exists( 'WP_Block_Parser' ), 'native_parser_loaded' );
		$this->fields();
		$this->placement();
		$this->preservation();
		$this->refusals();
		$this->limits();
		echo wp_json_encode(
			array(
				'proof'         => 'navigation-block-tree-native',
				'result'        => 'passed',
				'checks'        => $this->checks,
				'wordpress'     => $GLOBALS['wp_version'],
				'php'           => PHP_VERSION,
				'source_sha256' => hash_file( 'sha256', ( new ReflectionClass( NavigationBlockTree::class ) )->getFileName() ),
			)
		) . "\n";
	}

	/**
	 * Assert a named condition without exposing source content.
	 *
	 * @param bool   $condition Required outcome.
	 * @param string $label Synthetic check label.
	 * @throws RuntimeException On a failed contract.
	 */
	private function check( bool $condition, string $label ): void {
		++$this->checks;
		if ( ! $condition ) {
			throw new RuntimeException( 'Navigation block proof failed: ' . $label );
		}
	}

	/**
	 * Make a native serialized custom link.
	 *
	 * @param string               $label Label.
	 * @param array<string, mixed> $extra Additional attributes.
	 */
	private function link( string $label, array $extra = array() ): string {
		return get_comment_delimited_block_content(
			'core/navigation-link',
			array_merge(
				array(
					'label' => $label,
					'url'   => '/old',
					'kind'  => 'custom',
				),
				$extra
			),
			''
		);
	}

	/**
	 * Make a native submenu retaining the supplied child serialization.
	 *
	 * @param string $label Label.
	 * @param string $children Children.
	 */
	private function submenu( string $label, string $children ): string {
		return get_comment_delimited_block_content( 'core/navigation-submenu', array( 'label' => $label ), $children );
	}

	/** Check supported fields, sanitization, custom URL identity and no-op bytes. */
	private function fields(): void {
		$content = $this->link(
			'Original',
			array(
				'className' => 'keep',
				'unknown'   => array( 'x' => 7 ),
			)
		);
		$result  = $this->service->apply(
			$content,
			'0',
			array(
				'label'  => '<b>New</b>',
				'url'    => 'https://example.test/new',
				'target' => '_blank',
				'rel'    => 'NoFollow  noopener nofollow',
			)
		);
		$this->check( ! isset( $result['error'] ), 'fields_success' );
		$attrs = parse_blocks( $result['content'] )[0]['attrs'];
		$this->check( 'New' === $attrs['label'] && 'https://example.test/new' === $attrs['url'], 'fields_sanitized' );
		$this->check( true === $attrs['opensInNewTab'] && 'nofollow noopener' === $attrs['rel'], 'target_rel' );
		$this->check( array( 'x' => 7 ) === $attrs['unknown'] && 'keep' === $attrs['className'], 'unknown_attributes_preserved' );
		$this->check( parse_blocks( $content )[0] === $result['before'] && parse_blocks( $result['content'] )[0] === $result['after'], 'before_after' );
		$this->check( false === $this->service->apply( $content, '0', array( 'target' => '_self' ) )['changed'], 'default_target_noop' );
		foreach ( array( '', '_self' ) as $target ) {
			$closed = $this->service->apply( $result['content'], '0', array( 'target' => $target ) );
			$this->check( false === $closed['after']['attrs']['opensInNewTab'], 'target_cleared' );
		}
		$noncanonical = '<!-- wp:navigation-link { "label": "A", "kind": "custom" } /-->';
		$noop         = $this->service->apply(
			$noncanonical,
			'0',
			array(
				'label' => 'A',
				'order' => 1,
			)
		);
		$this->check( $noncanonical === $noop['content'] && false === $noop['changed'], 'no_op_exact_bytes' );
		foreach ( array( 'https://example.test/a?x=1&y=2', 'http://example.test', '/route', '#fragment' ) as $url ) {
			$this->check( ! isset( $this->service->apply( $content, '0', array( 'url' => $url ) )['error'] ), 'safe_url' );
		}
		$page = $this->link(
			'Page',
			array(
				'kind' => 'post-type',
				'type' => 'page',
				'id'   => 42,
			)
		);
		$this->check( ! isset( $this->service->apply( $page, '0', array( 'label' => 'Renamed' ) )['error'] ), 'page_label_allowed' );
		$this->check( isset( $this->service->apply( $page, '0', array( 'url' => '/replacement' ) )['error'] ), 'page_url_refused' );
	}

	/** Check paths, final sibling order, shifted parents and subtree moves. */
	private function placement(): void {
		$content = $this->link( 'A' ) . '<!-- wp:page-list /-->' . $this->submenu( 'B', $this->link( 'C' ) . $this->submenu( 'D', $this->link( 'E' ) ) ) . $this->link( 'F' );
		$this->check( 'E' === $this->service->load( $content, '2.1.0' )['item']['attrs']['label'], 'nested_path' );
		$moved = $this->service->apply( $content, '0', array( 'order' => 3 ) );
		$this->check( '2' === $moved['new_block_path'] && 'A' === parse_blocks( $moved['content'] )[2]['attrs']['label'], 'root_order_counts_page_list' );
		$moved = $this->service->apply(
			$content,
			'0',
			array(
				'parent_path' => '2',
				'order'       => 2,
			)
		);
		$this->check( '1.1' === $moved['new_block_path'], 'destination_path_adjusted_after_removal' );
		$this->check( 'A' === $this->service->load( $moved['content'], '1.1' )['item']['attrs']['label'], 'destination_item' );
		$moved = $this->service->apply(
			$content,
			'2.1',
			array(
				'parent_path' => '',
				'order'       => 1,
			)
		);
		$this->check( '0' === $moved['new_block_path'] && 'E' === $this->service->load( $moved['content'], '0.0' )['item']['attrs']['label'], 'subtree_move_preserves_descendants' );
		$moved = $this->service->apply( $content, '3', array( 'parent_path' => '2' ) );
		$this->check( '2.2' === $moved['new_block_path'], 'new_parent_appends_by_default' );
		$moved = $this->service->apply( $content, '2.0', array( 'order' => 2 ) );
		$this->check( 'C' === $this->service->load( $moved['content'], '2.1' )['item']['attrs']['label'], 'same_parent_reorder' );
		$moved = $this->service->apply(
			$content,
			'2.1.0',
			array(
				'parent_path' => '2',
				'order'       => 1,
			)
		);
		$this->check( 'E' === $this->service->load( $moved['content'], '2.0' )['item']['attrs']['label'], 'move_to_ancestor' );
		$empty = $this->link( 'A' ) . $this->submenu( 'Empty', '' );
		$moved = $this->service->apply( $empty, '0', array( 'parent_path' => '1' ) );
		$this->check( '0.0' === $moved['new_block_path'], 'move_into_empty_submenu' );
		$whitespace = $this->link( 'A' ) . "\n" . $this->link( 'B' );
		$this->check( 'B' === $this->service->load( $whitespace, '2' )['item']['attrs']['label'], 'root_whitespace_counts_as_block' );
		$moved = $this->service->apply( $whitespace, '2', array( 'order' => 1 ) );
		$this->check( "\n" === parse_blocks( $moved['content'] )[2]['innerHTML'], 'root_whitespace_preserved' );
	}

	/** Check preservation of unrelated markup and exact child structures. */
	private function preservation(): void {
		$custom  = '<!-- wp:custom/opaque {"x":1} --><strong>Keep me</strong><!-- /wp:custom/opaque -->';
		$content = $this->link( 'A' ) . $custom . $this->submenu( 'B', $this->link( 'C' ) );
		$before  = parse_blocks( $content );
		$result  = $this->service->apply( $content, '0', array( 'label' => 'New' ) );
		$after   = parse_blocks( $result['content'] );
		$this->check( $before[1] === $after[1] && $before[2] === $after[2], 'unrelated_blocks_exact' );
		$result = $this->service->apply( $content, '2', array( 'label' => 'New' ) );
		$this->check( $before[2]['innerBlocks'] === $result['after']['innerBlocks'], 'submenu_fields_keep_children' );
		$wrapped = $this->submenu( 'B', '<div>' . $this->link( 'C' ) . '</div>' ) . $this->link( 'D' );
		$this->check( ! isset( $this->service->apply( $wrapped, '0.0', array( 'label' => 'New' ) )['error'] ), 'field_edit_preserves_wrapping_markup' );
		$this->check( isset( $this->service->apply( $wrapped, '0.0', array( 'parent_path' => '' ) )['error'] ), 'meaningful_source_markup_refused' );
		$this->check( isset( $this->service->apply( $wrapped, '1', array( 'parent_path' => '0' ) )['error'] ), 'meaningful_destination_markup_refused' );
		$space  = $this->submenu( 'B', "\n\t" . $this->link( 'C' ) . "\n" ) . $this->link( 'D' );
		$result = $this->service->apply(
			$space,
			'1',
			array(
				'parent_path' => '0',
				'order'       => 1,
			)
		);
		$this->check( "\n\t\n" === parse_blocks( $result['content'] )[0]['innerHTML'], 'container_whitespace_retained' );
	}

	/** Check unsupported targets, parent cycles, malformed inputs and unsafe URLs. */
	private function refusals(): void {
		$content = $this->link( 'A' ) . $this->submenu( 'B', $this->submenu( 'C', $this->link( 'D' ) ) ) . '<!-- wp:page-list /-->';
		foreach ( array( '', '-1', '0.01', '00', '0.', '.0', '500', '99999999999999999999', '0.0', '4', '2' ) as $path ) {
			$this->check( isset( $this->service->load( $content, $path )['error'] ), 'invalid_or_unsupported_path' );
		}
		foreach ( array( '1', '1.0', '1.0.0', '0', '2', '9', '-1' ) as $parent ) {
			$this->check( isset( $this->service->apply( $content, '1', array( 'parent_path' => $parent ) )['error'] ), 'invalid_parent_or_cycle' );
		}
		foreach ( array( 0, -1, 5, 501, '1', null, false ) as $order ) {
			$this->check( isset( $this->service->apply( $content, '0', array( 'order' => $order ) )['error'] ), 'invalid_order' );
		}
		foreach ( array( array(), array( 'label' ), array( 'html' => '<b>x</b>' ), array( 'label' => array() ), array( 'label' => '   ' ), array( 'label' => '<b></b>' ), array( 'label' => str_repeat( 'a', 256 ) ), array( 'target' => '_parent' ), array( 'rel' => 'foo" onclick=' ), array( 'parent_path' => null ) ) as $changes ) {
			$this->check( isset( $this->service->apply( $content, '0', $changes )['error'] ), 'invalid_changes' );
		}
		foreach ( array( '', '//example.test', '/%2fexample.test', 'javascript:alert(1)', 'data:text/html,x', 'mailto:a@example.test', 'https://u:p@example.test', 'HTTP://example.test', '/<script>', '/quote"here', 'https://example.test/a b', "https://example.test/\n", '/back\\slash', '/%0aheader', '/%5cexample.test', 'https:///missing-host', 'https://' ) as $url ) {
			$this->check( isset( $this->service->apply( $content, '0', array( 'url' => $url ) )['error'] ), 'unsafe_url' );
		}
		foreach ( array( 'core/page-list', 'custom/container', 'core/navigation-link' ) as $name ) {
			$nested = get_comment_delimited_block_content( $name, array(), $this->link( 'Hidden' ) );
			$this->check( isset( $this->service->load( $nested, '0.0' )['error'] ), 'unsupported_ancestor' );
		}
		$this->check( isset( $this->service->load( '<!-- wp:navigation-link {invalid} /-->', '0' )['error'] ), 'invalid_attributes' );
		$this->check( isset( $this->service->load( '<!-- wp:navigation-link {"label":"A","unknown":1.0} /-->', '0' )['error'] ), 'lossy_attribute_round_trip_refused' );
	}

	/** Check byte, count and depth bounds before and after edits. */
	private function limits(): void {
		$link = $this->link( 'A' );
		$this->check( ! isset( $this->service->load( str_repeat( $link, 500 ), '499' )['error'] ), '500_blocks_allowed' );
		$this->check( isset( $this->service->load( str_repeat( $link, 501 ), '0' )['error'] ), '501_blocks_refused' );
		$this->check( isset( $this->service->load( str_repeat( ' ', NavigationBlockTree::MAX_BYTES + 1 ), '0' )['error'] ), 'oversize_input_refused' );
		$large = $this->link( 'A', array( 'unknown' => str_repeat( 'x', NavigationBlockTree::MAX_BYTES - strlen( $link ) - 14 ) ) );
		$this->check( ! isset( $this->service->load( $large, '0' )['error'] ), 'bounded_large_input' );
		$this->check( isset( $this->service->apply( $large, '0', array( 'label' => str_repeat( 'b', 255 ) ) )['error'] ), 'oversize_output_refused' );
		$deep = $link;
		for ( $i = 0; $i < 19; ++$i ) {
			$deep = $this->submenu( 'P', $deep );
		}
		$path = implode( '.', array_fill( 0, 20, '0' ) );
		$this->check( ! isset( $this->service->load( $deep, $path )['error'] ), '20_levels_allowed' );
		$this->check( isset( $this->service->load( $this->submenu( 'P', $deep ), '0' )['error'] ), '21_levels_refused' );
		$this->check( isset( $this->service->load( $deep, $path . '.0' )['error'] ), '21_level_path_refused' );
		$deep_with_empty = str_replace( $link, $this->submenu( 'Empty', '' ), $deep ) . $link;
		$this->check( isset( $this->service->apply( $deep_with_empty, '1', array( 'parent_path' => $path ) )['error'] ), 'move_cannot_exceed_depth' );
	}
}

( new AculectNavigationBlockTreeProof() )->run();

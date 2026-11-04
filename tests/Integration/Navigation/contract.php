<?php
/**
 * Native navigation gateway contract for an explicitly disposable local site.
 *
 * Usage: wp --user=1 eval-file contract.php disposable-loopback source-checkout
 * Uses an already-authorized synthetic context, without OAuth or HTTP requests.
 * Creates only synthetic menus/posts, restores theme mods and runtime filters.
 * Native execution receipts and audit entries retain their normal expiration.
 * This is implementation-aware integration coverage, not source-blind review.
 *
 * @package AculectAICompanion
 */

// WP-CLI eval-file prepends code, so strict_types is unsupported here.

use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionRequest;
use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Bounded CLI proof metadata only.
// phpcs:disable WordPress.PHP.YodaConditions.NotYoda -- Assertions compare expected native fixture values to observed values.

/** Exercise real gateway policy, WordPress serialization and persistence. */
final class AculectNativeNavigationProof {

	private array $checks        = array();
	private array $posts         = array();
	private array $menus         = array();
	private array $updates       = array();
	private array $auth          = array();
	private array $last_result   = array();
	private string $location     = '';
	private string $theme_option = '';
	private mixed $old_mods      = null;
	private array $old_locations = array();
	private array $old_features  = array();
	private bool $seeded         = false;

	/**
	 * Run with fail-first assertions and cleanup even when a case fails.
	 *
	 * @param array $arguments Explicit CLI fixture opt-in.
	 */
	public function run( array $arguments ): void {
		global $wp_version;
		$failure = '';
		try {
			$this->guard( $arguments );
			$this->seed();
			$this->classic_and_locations();
			$this->zero_order_failure( false );
			$this->zero_order_failure( true );
			$this->block_apply();
			$this->block_moves();
			$this->stale_states();
			$this->denied_authority();
			$this->unsupported_content();
			$this->recovery_required();
		} catch ( Throwable $error ) {
			$failure = $error instanceof LogicException ? $error->getMessage() : get_class( $error );
		} finally {
			try {
				$this->cleanup();
			} catch ( Throwable $error ) {
				$failure        = '' === $failure ? 'fixture_cleanup_failed' : $failure;
				$this->checks[] = array(
					'check'  => 'fixture_cleanup_failed',
					'passed' => false,
				);
			}
		}
		echo wp_json_encode(
			array(
				'proof'               => 'native-wordpress-navigation-gateway',
				'result'              => '' === $failure ? 'passed' : 'failed',
				'failure'             => $failure,
				'checks'              => $this->checks,
				'last_gateway_result' => $this->last_result,
				'wordpress'           => $wp_version,
				'php'                 => PHP_VERSION,
				'artifact_kind'       => 'source-checkout',
				'gateway_sha256'      => hash_file( 'sha256', ( new ReflectionClass( AbilityExecutionGateway::class ) )->getFileName() ),
				'boundaries'          => array( 'seeded authorized context; no OAuth or HTTP', 'synthetic local fixtures only', 'native classic and block feature combination in current theme; no installed classic/hybrid theme catalog or theme switching', 'native sequential replay; no concurrent execution proof', 'implementation-aware integration; not source-blind review', 'native execution receipts and audit entries retain normal expiration' ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n";
		if ( '' !== $failure ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Require explicit CLI opt-in, a local host and an existing administrator.
	 *
	 * @param array $arguments Explicit CLI fixture opt-in.
	 */
	private function guard( array $arguments ): void {
		$this->check( defined( 'WP_CLI' ) && WP_CLI, 'wp_cli_required' );
		$this->check( 'disposable-loopback' === ( $arguments[0] ?? '' ) && 'source-checkout' === ( $arguments[1] ?? '' ), 'explicit_disposable_source_opt_in' );
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$harness = 'wordpress' === $host && defined( 'WP_CODEX_CLOUD_DEV' ) && true === WP_CODEX_CLOUD_DEV; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Exact isolated Docker hostname.
		$this->check( $harness || in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ), 'loopback_or_isolated_harness' );
		$this->check( 'local' === wp_get_environment_type(), 'local_environment_required' );
		$this->check( current_user_can( 'manage_options' ) && current_user_can( 'edit_theme_options' ), 'existing_fixture_admin_required' );
		$this->check( post_type_exists( 'wp_navigation' ), 'native_navigation_type_available' );
	}

	/** Save runtime registration and exact native theme-mod option before fixtures. */
	private function seed(): void {
		global $_wp_registered_nav_menus, $_wp_theme_features;
		$this->old_locations = $_wp_registered_nav_menus ?? array();
		$this->old_features  = $_wp_theme_features ?? array();
		$this->theme_option  = 'theme_mods_' . get_stylesheet();
		$this->old_mods      = get_option( $this->theme_option, null );
		$this->seeded        = true;
		$this->location      = 'aculect-proof-' . substr( wp_generate_uuid4(), 0, 8 );
		register_nav_menu( $this->location, 'Disposable navigation contract' );
		$this->auth = array(
			'user_id'                  => get_current_user_id(),
			'client_id'                => 'navigation-proof-' . wp_generate_uuid4(),
			'token_id'                 => 'synthetic-session-' . wp_generate_uuid4(),
			'provider'                 => 'mcp',
			'scopes'                   => array( 'content:read', 'content:draft' ),
			'profile'                  => 'full_access',
			'access_level'             => ConnectionAccessLevel::WRITE,
			'write_permission_enabled' => true,
		);
		add_action( 'post_updated', array( $this, 'observe_update' ), 10, 1 );
	}

	/**
	 * Count target updates independently of revisions and gateway receipts.
	 *
	 * @param int $id Updated native post ID.
	 */
	public function observe_update( int $id ): void {
		if ( isset( $this->updates[ $id ] ) ) {
			++$this->updates[ $id ];
		}
	}

	/**
	 * Route all operations through the production authenticated gateway.
	 *
	 * @param string $name Public tool name.
	 * @param array  $arguments Tool input.
	 * @param array  $auth Scoped authentication overrides.
	 */
	private function call( string $name, array $arguments = array(), array $auth = array() ): array {
		$request                 = new AbilityExecutionRequest(
			array(
				'name'      => $name,
				'arguments' => $arguments,
			),
			array_replace( $this->auth, $auth )
		);
		$outcome                 = McpAppsNegotiation::with_request_enabled( false, static fn () => ( new AbilityExecutionGateway() )->execute( $request ) );
		$result                  = $outcome->data['result'] ?? $outcome->data;
		$result['_gateway_type'] = $outcome->type;
		$this->last_result       = array_intersect_key( $result, array_flip( array( 'status', 'error', 'outcome', 'replayed', '_gateway_type' ) ) );
		return $result;
	}

	/**
	 * Obtain a server-generated token only from a successful dry run.
	 *
	 * @param string $tool Public tool name.
	 * @param array  $args Exact proposal.
	 */
	private function confirmed_args( string $tool, array $args ): array {
		$preview = $this->call( $tool, $args + array( 'dry_run' => true ) );
		$this->check( 'preview' === ( $preview['status'] ?? '' ) && true === ( $preview['confirmation_required'] ?? false ) && is_string( $preview['confirmation_token'] ?? null ), $tool . '_confirmed_preview' );
		return $args + array( 'confirmation_token' => $preview['confirmation_token'] );
	}

	/**
	 * Create a native published navigation record with preservation sentinels.
	 *
	 * @param string|null $content Optional serialized fixture content.
	 */
	private function navigation( ?string $content = null ): int {
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'wp_navigation',
					'post_status'  => 'publish',
					'post_title'   => 'Disposable navigation contract',
					'post_excerpt' => 'Keep this native excerpt.',
					'post_content' => $content ?? $this->markup(),
					'post_author'  => get_current_user_id(),
				)
			),
			true
		);
		$this->check( is_int( $id ) && $id > 0, 'synthetic_navigation_created' );
		$this->posts[]        = $id;
		$this->updates[ $id ] = 0;
		update_post_meta( $id, '_aculect_navigation_proof', 'preserve-native-meta' );
		return $id;
	}

	/** Use core markup with a non-item sibling and a nested submenu subtree. */
	private function markup(): string {
		return '<!-- wp:navigation-link {"label":"Original","url":"/original","kind":"custom","className":"keep-link"} /-->'
			. '<!-- wp:spacer {"height":"16px"} --><div style="height:16px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->'
			. '<!-- wp:navigation-submenu {"label":"Resources","className":"keep-submenu"} -->'
			. '<!-- wp:navigation-link {"label":"Child","url":"/child","kind":"custom"} /-->'
			. '<!-- wp:navigation-submenu {"label":"Nested"} --><!-- wp:navigation-link {"label":"Deep","url":"/deep","kind":"custom"} /--><!-- /wp:navigation-submenu -->'
			. '<!-- /wp:navigation-submenu -->'
			. '<!-- wp:navigation-link {"label":"Sibling","url":"/sibling","kind":"custom"} /-->';
	}

	/**
	 * Read a fresh token for the full native record using the public item tool.
	 *
	 * @param int    $id Native navigation ID.
	 * @param string $path Dotted structural path.
	 * @param array  $changes Proposed item changes.
	 */
	private function item_args( int $id, string $path, array $changes ): array {
		$read = $this->call(
			'navigation_read_block_item',
			array(
				'navigation_id' => $id,
				'block_path'    => $path,
			)
		);
		$this->check( is_string( $read['expected_state'] ?? null ) && $path === ( $read['item']['block_path'] ?? null ), 'block_item_read_has_record_token' );
		return array(
			'navigation_id'  => $id,
			'block_path'     => $path,
			'expected_state' => $read['expected_state'],
			'changes'        => $changes,
		);
	}

	/** Verify classic item editing, location assignment and coexisting surfaces. */
	private function classic_and_locations(): void {
		$menu = wp_create_nav_menu( 'Disposable navigation ' . wp_generate_uuid4() );
		$this->check( is_int( $menu ) && $menu > 0, 'synthetic_classic_menu_created' );
		$this->menus[] = $menu;
		$item          = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Classic original',
				'menu-item-url'    => '/classic',
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			)
		);
		$this->check( is_int( $item ) && $item > 0, 'synthetic_classic_item_created' );
		$read = $this->call(
			'navigation_read_item',
			array(
				'menu_id' => $menu,
				'item_id' => $item,
			)
		);
		$this->check( 0 === (int) get_post( $item )->menu_order && 0 === ( $read['item']['order'] ?? null ) && is_string( $read['expected_state'] ?? null ), 'native_zero_order_is_readable' );
		$args     = array(
			'menu_id'        => $menu,
			'item_id'        => $item,
			'expected_state' => $read['expected_state'] ?? '',
			'changes'        => array(
				'label' => 'Classic updated',
				'url'   => '/classic-updated',
			),
		);
		$rejected = $this->call( 'navigation_update_item', $args + array( 'dry_run' => true ) );
		$this->check( 'unsupported_order' === ( $rejected['error'] ?? '' ) && 'Classic original' === get_post( $item )->post_title && 0 === (int) get_post( $item )->menu_order, 'zero_order_requires_explicit_positive_order_without_write' );
		$args['changes']['order'] = 1;
		$preview                  = $this->call( 'navigation_update_item', $args + array( 'dry_run' => true ) );
		$diff                     = array_column( $preview['changes'] ?? array(), null, 'field' );
		$this->check( 'preview' === ( $preview['status'] ?? '' ) && 0 === ( $diff['order']['from'] ?? null ) && 1 === ( $diff['order']['to'] ?? null ) && is_string( $preview['confirmation_token'] ?? null ), 'classic_explicit_order_repair_previewed' );
		$apply = $args + array( 'confirmation_token' => $preview['confirmation_token'] );
		$this->check( 'Classic original' === get_post( $item )->post_title, 'classic_preview_no_write' );
		$result = $this->call( 'navigation_update_item', $apply );
		$this->check( true === ( $result['success'] ?? false ) && 'Classic updated' === get_post( $item )->post_title && '/classic-updated' === get_post_meta( $item, '_menu_item_url', true ) && 1 === (int) get_post( $item )->menu_order, 'classic_native_edit_applied' );
		$location = $this->call( 'navigation_read_location_context' );
		$map      = get_nav_menu_locations();
		$args     = array(
			'location'       => $this->location,
			'menu_id'        => $menu,
			'expected_state' => $location['expected_state'] ?? '',
		);
		$apply    = $this->confirmed_args( 'navigation_assign_location', $args );
		$this->check( $map === get_nav_menu_locations(), 'location_preview_no_write' );
		$result                 = $this->call( 'navigation_assign_location', $apply );
		$map[ $this->location ] = $menu;
		$actual_map             = get_nav_menu_locations();
		ksort( $map, SORT_STRING );
		ksort( $actual_map, SORT_STRING );
		$this->check( true === ( $result['success'] ?? false ) && $map === $actual_map, 'location_exact_map_assignment' );
		$this->coexisting_surfaces( $menu );
	}

	/**
	 * Exercise coexisting native features using the actual current theme mode.
	 *
	 * @param int $menu Synthetic classic menu ID.
	 */
	private function coexisting_surfaces( int $menu ): void {
		$id             = $this->navigation();
		$is_block_theme = wp_is_block_theme();
		$context        = $this->call( 'navigation_get_context' );
		$this->check( $is_block_theme === ( $context['theme']['is_block_theme'] ?? null ) && ( $is_block_theme ? 'block_theme' : 'hybrid_theme' ) === ( $context['navigation']['theme_mode'] ?? '' ), 'native_theme_mode_matches_core' );
		$this->check( array() === array_diff( array( 'classic_menu', 'wp_navigation' ), $context['navigation']['available_surfaces'] ?? array() ), 'native_context_exposes_classic_and_block_surfaces' );
		$classic = $this->call( 'navigation_list_items', array( 'menu_id' => $menu ) );
		$blocks  = $this->call( 'navigation_list_items', array( 'navigation_id' => $id ) );
		$this->check( ! empty( $classic['items'] ) && ! empty( $blocks['items'] ), 'classic_and_block_surfaces_coexist' );
		$editable = $this->call(
			'navigation_read_block_item',
			array(
				'navigation_id' => $id,
				'block_path'    => '0',
			)
		);
		$this->check( 'Original' === ( $editable['item']['label'] ?? '' ), 'block_item_read_with_classic_location_available' );
	}

	/**
	 * A one-shot native hook failure must never trigger zero-order compensation.
	 *
	 * @param bool $after_save Throw after persistence instead of before the row save.
	 */
	private function zero_order_failure( bool $after_save ): void {
		$case = $after_save ? 'post_save' : 'pre_save';
		$menu = wp_create_nav_menu( 'Disposable failure ' . wp_generate_uuid4() );
		$this->check( is_int( $menu ) && $menu > 0, $case . '_failure_menu_created' );
		$this->menus[] = $menu;
		$item          = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Failure original',
				'menu-item-url'    => '/failure',
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			)
		);
		$this->check( is_int( $item ) && $item > 0 && 0 === (int) get_post( $item )->menu_order, $case . '_native_zero_order_fixture' );
		$this->updates[ $item ] = 0;
		$read                   = $this->call(
			'navigation_read_item',
			array(
				'menu_id' => $menu,
				'item_id' => $item,
			)
		);
		$args                   = array(
			'menu_id'        => $menu,
			'item_id'        => $item,
			'expected_state' => $read['expected_state'] ?? '',
			'changes'        => array(
				'label' => 'Failure proposed',
				'order' => 1,
			),
		);
		$apply                  = $this->confirmed_args( 'navigation_update_item', $args );
		$calls                  = 0;
		$before_hook            = static function ( array $data, array $postarr ) use ( $item, &$calls ): array {
			if ( $item === (int) ( $postarr['ID'] ?? 0 ) && 1 === ++$calls ) {
				throw new RuntimeException( 'Synthetic pre-save failure.' );
			}
			return $data;
		};
		$after_hook             = static function ( int $menu_id, int $item_id ) use ( $item, &$calls ): void {
			if ( $item === $item_id && 1 === ++$calls ) {
				throw new RuntimeException( 'Synthetic post-save failure.' );
			}
		};
		if ( $after_save ) {
			add_action( 'wp_update_nav_menu_item', $after_hook, 99, 2 );
		} else {
			add_filter( 'wp_insert_post_data', $before_hook, 99, 2 );
		}
		try {
			$result = $this->call( 'navigation_update_item', $apply );
			$this->check( 'partial_write' === ( $result['error'] ?? '' ) && true === ( $result['terminal'] ?? false ) && empty( $result['success'] ), $case . '_failure_is_terminal_partial' );
			$updates = $after_save ? 1 : 0;
			$this->check( 1 === $calls && $updates === $this->updates[ $item ] && $updates === (int) get_post( $item )->menu_order, $case . '_no_compensation_or_reposition_attempt' );
			$this->check( ( $after_save ? 'Failure proposed' : 'Failure original' ) === get_post( $item )->post_title, $case . '_native_row_matches_failure_boundary' );
			$post   = get_post( $item )->to_array();
			$meta   = get_post_meta( $item );
			$replay = $this->call( 'navigation_update_item', $apply );
			$this->check( 'partial_write' === ( $replay['error'] ?? '' ) && true === ( $replay['replayed'] ?? false ) && true === ( $replay['terminal'] ?? false ) && 1 === $calls && $updates === $this->updates[ $item ] && $post === get_post( $item )->to_array() && $meta === get_post_meta( $item ), $case . '_partial_receipt_replay_never_reexecutes' );
		} finally {
			remove_action( 'wp_update_nav_menu_item', $after_hook, 99 );
			remove_filter( 'wp_insert_post_data', $before_hook, 99 );
		}
	}

	/** Exact confirmed write preserves native fields, sibling blocks and recovery. */
	private function block_apply(): void {
		$id      = $this->navigation();
		$before  = get_post( $id );
		$meta    = get_post_meta( $id );
		$mods    = get_theme_mods();
		$changes = array(
			'label'  => 'Updated',
			'url'    => '/updated',
			'target' => '_blank',
			'rel'    => 'nofollow noopener',
		);
		$args    = $this->item_args( $id, '0', $changes );
		$gate    = $this->call( 'navigation_update_block_item', $args );
		$this->check( 'confirmation_required' === ( $gate['status'] ?? '' ) && 0 === $this->updates[ $id ], 'trusted_write_still_requires_confirmation' );
		$preview = $this->call( 'navigation_update_block_item', $args + array( 'dry_run' => true ) );
		$diff    = array_column( $preview['changes'] ?? array(), null, 'field' );
		$this->check( 'Original' === ( $diff['label']['from'] ?? '' ) && 'Updated' === ( $diff['label']['to'] ?? '' ) && '/original' === ( $diff['url']['from'] ?? '' ) && '/updated' === ( $diff['url']['to'] ?? '' ), 'block_preview_before_after_fields' );
		$this->check( $before->post_content === get_post( $id )->post_content && 0 === $this->updates[ $id ], 'block_preview_has_no_native_update' );
		$this->check( is_string( $preview['confirmation_token'] ?? null ), 'block_preview_confirmation_token' );
		$apply                       = $args + array( 'confirmation_token' => $preview['confirmation_token'] );
		$changed                     = $apply;
		$changed['changes']['label'] = 'Unapproved change';
		$this->check( 'invalid_confirmation_token' === ( $this->call( 'navigation_update_block_item', $changed )['error'] ?? '' ) && 0 === $this->updates[ $id ], 'changed_arguments_cannot_consume_confirmation' );
		$result = $this->call( 'navigation_update_block_item', $apply );
		$this->check( true === ( $result['success'] ?? false ) && 1 === $this->updates[ $id ], 'confirmed_block_write_exactly_once' );
		$after                = get_post( $id );
		$expected             = parse_blocks( $before->post_content );
		$expected[0]['attrs'] = array_replace(
			$expected[0]['attrs'],
			array(
				'label'         => 'Updated',
				'url'           => '/updated',
				'opensInNewTab' => true,
				'rel'           => 'nofollow noopener',
			)
		);
		$this->check( $expected === parse_blocks( $after->post_content ), 'all_siblings_submenus_and_other_attributes_preserved' );
		foreach ( array( 'post_title', 'post_status', 'post_excerpt', 'post_name', 'post_parent', 'post_author' ) as $field ) {
			$this->check( $before->$field === $after->$field, $field . '_preserved' );
		}
		$this->check( $meta === get_post_meta( $id ) && $mods === get_theme_mods(), 'native_meta_and_theme_mods_preserved' );
		$revision = get_post( $result['recovery_revision_id'] ?? 0 );
		$this->check( $revision instanceof WP_Post && 'revision' === $revision->post_type && $id === (int) $revision->post_parent && $before->post_content === $revision->post_content && $before->post_title === $revision->post_title && $before->post_excerpt === $revision->post_excerpt, 'native_recovery_revision_has_exact_preimage' );
		$replay = $this->call( 'navigation_update_block_item', $apply );
		$this->check( true === ( $replay['replayed'] ?? false ) && 1 === $this->updates[ $id ] && $after->post_content === get_post( $id )->post_content, 'confirmed_replay_returns_receipt_without_write' );
	}

	/** Move existing blocks by full sibling index while preserving descendants. */
	private function block_moves(): void {
		$id     = $this->navigation();
		$before = parse_blocks( get_post( $id )->post_content );
		$args   = $this->item_args(
			$id,
			'3',
			array(
				'parent_path' => '2',
				'order'       => 1,
			)
		);
		$result = $this->call( 'navigation_update_block_item', $this->confirmed_args( 'navigation_update_block_item', $args ) );
		$after  = parse_blocks( get_post( $id )->post_content );
		$this->check( true === ( $result['success'] ?? false ) && '2.0' === ( $result['new_block_path'] ?? '' ) && $before[3] === $after[2]['innerBlocks'][0] && $before[2]['innerBlocks'] === array_slice( $after[2]['innerBlocks'], 1 ) && $before[1] === $after[1], 'reparent_preserves_nested_subtrees_and_nonitem_sibling' );
		$args      = $this->item_args(
			$id,
			'2',
			array(
				'parent_path' => '',
				'order'       => 1,
				'label'       => 'Resources renamed',
			)
		);
		$result    = $this->call( 'navigation_update_block_item', $this->confirmed_args( 'navigation_update_block_item', $args ) );
		$reordered = parse_blocks( get_post( $id )->post_content );
		$this->check( true === ( $result['success'] ?? false ) && '0' === ( $result['new_block_path'] ?? '' ) && $after[2]['innerBlocks'] === $reordered[0]['innerBlocks'] && 'Resources renamed' === $reordered[0]['attrs']['label'] && $before[1] === $reordered[2], 'submenu_label_and_full_sibling_order_preserve_children' );
	}

	/** Reject stale sibling content and scoped theme identity changes. */
	private function stale_states(): void {
		$id    = $this->navigation();
		$args  = $this->item_args( $id, '0', array( 'label' => 'Stale proposal' ) );
		$apply = $this->confirmed_args( 'navigation_update_block_item', $args );
		$human = str_replace( '"label":"Sibling"', '"label":"Human sibling"', get_post( $id )->post_content );
		$this->check(
			$id === wp_update_post(
				wp_slash(
					array(
						'ID'           => $id,
						'post_content' => $human,
					)
				),
				true
			),
			'native_sibling_edit_created'
		);
		$this->check( 'stale_editor_state' === ( $this->call( 'navigation_update_block_item', $apply )['error'] ?? '' ) && 1 === $this->updates[ $id ] && $human === get_post( $id )->post_content, 'stale_sibling_content_rejected_without_write' );
		$args         = $this->item_args( $id, '0', array( 'label' => 'Theme stale proposal' ) );
		$apply        = $this->confirmed_args( 'navigation_update_block_item', $args );
		$theme_filter = static fn ( string $stylesheet ): string => $stylesheet . '-proof-state';
		add_filter( 'stylesheet', $theme_filter );
		try {
			$this->check( 'stale_editor_state' === ( $this->call( 'navigation_update_block_item', $apply )['error'] ?? '' ) && 1 === $this->updates[ $id ], 'stale_theme_identity_rejected_without_switching_theme' );
		} finally {
			remove_filter( 'stylesheet', $theme_filter );
		}
		$context = $this->call( 'navigation_read_location_context' );
		$args    = array(
			'location'       => $this->location,
			'menu_id'        => 0,
			'expected_state' => $context['expected_state'] ?? '',
		);
		$apply   = $this->confirmed_args( 'navigation_assign_location', $args );
		$other   = $this->location . '-other';
		register_nav_menu( $other, 'Synthetic concurrent location registration' );
		$map = get_nav_menu_locations();
		try {
			$this->check( 'stale_state' === ( $this->call( 'navigation_assign_location', $apply )['error'] ?? '' ) && $map === get_nav_menu_locations(), 'stale_registered_location_state_rejected' );
		} finally {
			unregister_nav_menu( $other );
		}
	}

	/** Deny scopes and each native capability independently without user creation. */
	private function denied_authority(): void {
		$id     = $this->navigation();
		$args   = $this->item_args( $id, '0', array( 'label' => 'Denied proposal' ) );
		$result = $this->call( 'navigation_update_block_item', $args + array( 'dry_run' => true ), array( 'scopes' => array( 'content:read' ) ) );
		$this->check( 'auth_challenge' === $result['_gateway_type'], 'missing_draft_scope_denied' );
		foreach ( array( 'edit_theme_options', 'read_post', 'edit_post' ) as $capability ) {
			$deny = static function ( array $caps, string $cap, int $user_id, array $cap_args ) use ( $capability, $id ): array {
				return $capability === $cap && ( 'edit_theme_options' === $cap || $id === ( $cap_args[0] ?? null ) ) ? array( 'do_not_allow' ) : $caps;
			};
			add_filter( 'map_meta_cap', $deny, 99, 4 );
			try {
				$result = $this->call( 'navigation_update_block_item', $args + array( 'dry_run' => true ) );
				$this->check( 'tool_error' === $result['_gateway_type'] || 'forbidden' === ( $result['error'] ?? '' ), $capability . '_denied' );
			} finally {
				remove_filter( 'map_meta_cap', $deny, 99 );
			}
		}
		$this->check( 0 === $this->updates[ $id ] && $this->markup() === get_post( $id )->post_content, 'authority_denials_have_no_native_updates' );
	}

	/** Refuse dynamic targets, custom content and URL changes on bound links. */
	private function unsupported_content(): void {
		$cases = array(
			'page_list'    => '<!-- wp:page-list /-->',
			'custom_block' => '<!-- wp:proof/unknown {"label":"Custom"} /-->',
			'custom_html'  => '<!-- wp:html --><p>Custom markup</p><!-- /wp:html -->',
			'freeform'     => '<p>Unstructured navigation</p>',
		);
		foreach ( $cases as $case => $content ) {
			$id     = $this->navigation( $content );
			$state  = $this->call( 'site_editor_read_record', array( 'post_id' => $id ) );
			$args   = array(
				'navigation_id'  => $id,
				'block_path'     => '0',
				'expected_state' => $state['expected_state'] ?? '',
				'changes'        => array( 'label' => 'Forbidden conversion' ),
				'dry_run'        => true,
			);
			$result = $this->call( 'navigation_update_block_item', $args );
			$this->check( isset( $result['error'] ) && 'invalid_params' !== $result['_gateway_type'] && 0 === $this->updates[ $id ] && $content === get_post( $id )->post_content, $case . '_rejected_without_conversion' );
		}
		$id     = $this->navigation( '<!-- wp:navigation-link {"label":"Bound post","url":"/bound","kind":"post-type","type":"page","id":1} /-->' );
		$args   = $this->item_args( $id, '0', array( 'url' => '/cannot-rebind' ) );
		$result = $this->call( 'navigation_update_block_item', $args + array( 'dry_run' => true ) );
		$this->check( isset( $result['error'] ) && 0 === $this->updates[ $id ], 'noncustom_link_url_rejected' );
	}

	/** Native revision retention remains required even for trusted connections. */
	private function recovery_required(): void {
		$id    = $this->navigation();
		$args  = $this->item_args( $id, '0', array( 'label' => 'No recovery proposal' ) );
		$limit = static fn ( int $keep, WP_Post $post ): int => $id === $post->ID ? 1 : $keep;
		add_filter( 'wp_revisions_to_keep', $limit, 99, 2 );
		try {
			$result = $this->call( 'navigation_update_block_item', $args + array( 'dry_run' => true ) );
			$this->check( 'editor_recovery_unavailable' === ( $result['error'] ?? '' ) && 0 === $this->updates[ $id ], 'insufficient_revision_retention_rejected' );
		} finally {
			remove_filter( 'wp_revisions_to_keep', $limit, 99 );
		}
	}

	/** Delete this run's native objects and restore exact original registration/mods. */
	private function cleanup(): void {
		global $_wp_registered_nav_menus, $_wp_theme_features;
		remove_action( 'post_updated', array( $this, 'observe_update' ), 10 );
		if ( ! $this->seeded ) {
			return;
		}
		$clean = true;
		foreach ( $this->menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu );
			wp_delete_nav_menu( $menu );
			$clean = $clean && false === wp_get_nav_menu_object( $menu );
			foreach ( is_array( $items ) ? $items : array() as $item ) {
				$clean = $clean && null === get_post( $item->ID );
			}
		}
		foreach ( $this->posts as $id ) {
			wp_delete_post( $id, true );
			$clean = $clean && null === get_post( $id ) && array() === wp_get_post_revisions( $id );
		}
		$_wp_registered_nav_menus = $this->old_locations; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore exact pre-fixture runtime registration.
		$_wp_theme_features       = $this->old_features; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- register_nav_menu adds menus support; restore exact pre-fixture state.
		if ( null === $this->old_mods ) {
			delete_option( $this->theme_option );
		} else {
			update_option( $this->theme_option, $this->old_mods );
		}
		$this->check( $clean && $this->old_mods === get_option( $this->theme_option, null ), 'synthetic_objects_removed_and_theme_mods_restored' );
	}

	/**
	 * Emit fixed assertion names only, never credentials or proposal payloads.
	 *
	 * @param bool   $passed Native assertion result.
	 * @param string $name Fixed diagnostic label.
	 * @throws LogicException When an assertion fails.
	 */
	private function check( bool $passed, string $name ): void {
		$this->checks[] = array(
			'check'  => $name,
			'passed' => $passed,
		);
		if ( ! $passed ) {
			throw new LogicException( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed assertion label emitted as JSON by run().
		}
	}
}

( new AculectNativeNavigationProof() )->run( $args ?? array() );

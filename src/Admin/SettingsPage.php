<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Activity\ActivityLogger;
use Aculect\AICompanion\Activity\ActivityRepository;
use Aculect\AICompanion\Brand\BrandProfile;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\MCP\AccessLockdown;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use Aculect\AICompanion\Connectors\MCP\PluginIncidentReporter;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\RoleConnectionEntryPoint;
use Aculect\AICompanion\Connectors\OAuth\AuthorizationController;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;
use Aculect\AICompanion\Connectors\Providers\ProviderRegistry;
use Aculect\AICompanion\Diagnostics\ConnectionHealth;
use Aculect\AICompanion\Diagnostics\LogRepository;
use Aculect\AICompanion\Diagnostics\LogSettings;
use Aculect\AICompanion\Diagnostics\McpToolManifest;
use Aculect\AICompanion\Intelligence\ContentIndexRepository;
use Aculect\AICompanion\Intelligence\ContentIndexer;
use Aculect\AICompanion\Intelligence\LearningSuggestionRepository;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page controller for connector setup and session management.
 */
final class SettingsPage {

	private const PAGE_SLUG            = 'aculect-ai-companion';
	private const SETTINGS_PARENT_FILE = 'options-general.php';
	private const ASSET_HANDLE         = 'aculect-ai-companion-settings-app';
	private const STYLE_HANDLE         = 'aculect-ai-companion-settings-style';

	/**
	 * Cached ability payload for the current request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $ability_payload_cache = null;

	/**
	 * Cached changelog payload for the current request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $changelog_cache = null;

	/**
	 * Cached plugin metadata for the current request.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $plugin_metadata_cache = null;

	/**
	 * Cached readme headers for the current request.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $readme_headers_cache = null;

	/**
	 * Register the settings page and page-specific assets.
	 */
	public function register(): void {
		add_options_page(
			__( 'Aculect AI Companion', 'aculect-ai-companion' ),
			__( 'AI Companion', 'aculect-ai-companion' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'parent_file', array( $this, 'highlight_parent_menu' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_submenu' ) );
	}

	/**
	 * Register admin-only REST routes used by the settings React app.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'aculect-ai-companion/v1',
			'/settings-payload',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_settings_payload' ),
				'permission_callback' => array( $this, 'can_manage_settings' ),
				'args'                => array(
					'tab' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Check whether the current user can load admin settings payloads.
	 */
	public function can_manage_settings(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return a tab-specific settings payload for client-side tab hydration.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function rest_settings_payload( WP_REST_Request $request ): WP_REST_Response {
		$tab = sanitize_key( (string) $request->get_param( 'tab' ) );

		return new WP_REST_Response( $this->settings_payload( $tab ) );
	}

	/**
	 * Render the settings page shell or the OAuth consent screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'aculect-ai-companion' ) );
		}

		if ( $this->is_oauth_consent_view() ) {
			( new AuthorizationController() )->render_admin_consent();
			return;
		}

		echo '<div class="wrap aculect-ai-companion-settings-wrap"><div id="aculect-ai-companion-settings-app-root" class="aculect-ai-companion-settings-app-root"></div></div>';
	}

	/**
	 * Enqueue settings-page assets and hydrate the React application.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->is_settings_admin_screen( $hook_suffix ) ) {
			return;
		}

		if ( $this->is_oauth_consent_view() ) {
			wp_enqueue_style( 'aculect-ai-companion-oauth-consent', ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/css/oauth-consent.css', array(), ACULECT_AI_COMPANION_VERSION );
			return;
		}

		$asset_path = ACULECT_AI_COMPANION_PLUGIN_DIR . 'build/index.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-primitives' ),
				'version'      => ACULECT_AI_COMPANION_VERSION,
			);

		wp_register_script(
			self::ASSET_HANDLE,
			ACULECT_AI_COMPANION_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			(string) $asset['version'],
			true
		);
		wp_enqueue_script( self::ASSET_HANDLE );
		wp_enqueue_style( 'wp-components' );

		$style_path = ACULECT_AI_COMPANION_PLUGIN_DIR . 'build/style-index.css';
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style( self::STYLE_HANDLE, ACULECT_AI_COMPANION_PLUGIN_URL . 'build/style-index.css', array( 'wp-components' ), (string) $asset['version'] );
		}

		wp_localize_script(
			self::ASSET_HANDLE,
			'aculectAICompanionSettingsData',
			$this->settings_payload()
		);
	}

	/**
	 * Return settings data for the React application.
	 *
	 * @param string|null $requested_tab Optional requested tab override.
	 * @return array<string, mixed>
	 */
	private function settings_payload( ?string $requested_tab = null ): array {
		$payload_tab      = null === $requested_tab
			? $this->current_payload_tab()
			: $this->normalize_payload_tab( $requested_tab );
		$access_tokens    = new AccessTokenRepository();
		$ability_registry = new AbilitiesRegistry();
		$sample_data      = new LocalSampleData();
		$access_tokens->revoke_superseded_active_sessions();
		$real_session_count   = $access_tokens->active_token_count();
		$active_session_count = $sample_data->active_session_count( $real_session_count, $payload_tab );

		$payload = array_merge(
			$this->base_payload( $payload_tab, $active_session_count ),
			$this->connection_payload( $payload_tab, $access_tokens, $ability_registry ),
			$this->ability_payload( $payload_tab, $ability_registry ),
			$this->tab_payload( $payload_tab ),
			array(
				'actions' => $this->actions_payload(),
			)
		);

		return $sample_data->apply( $payload, $payload_tab, $real_session_count );
	}

	/**
	 * Return shared settings data that is cheap enough for every tab.
	 *
	 * @param string $payload_tab          Normalized payload tab.
	 * @param int    $active_session_count Active OAuth session count.
	 * @return array<string, mixed>
	 */
	private function base_payload( string $payload_tab, int $active_session_count ): array {
		return array(
			'version'            => ACULECT_AI_COMPANION_VERSION,
			'pluginMetadata'     => $this->plugin_metadata(),
			'payloadTab'         => $payload_tab,
			'hydratedTabs'       => $this->hydrated_tabs( $payload_tab ),
			'adminPageUrl'       => esc_url_raw( $this->settings_url() ),
			'settingsPayloadUrl' => esc_url_raw( rest_url( 'aculect-ai-companion/v1/settings-payload' ) ),
			'settingsRestNonce'  => wp_create_nonce( 'wp_rest' ),
			'brandIconUrl'       => esc_url_raw(
				ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/images/aculect-icon-light.svg'
			),
			'brandMarkUrl'       => esc_url_raw(
				ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/images/aculect-mark.svg'
			),
			'connectorLogoUrls'  => $this->connector_logo_urls(),
			'isConnected'        => $active_session_count > 0,
			'activeSessionCount' => $active_session_count,
			'accessPaused'       => AccessLockdown::is_paused(),
			'currentUserId'      => get_current_user_id(),
			'mcpUrl'             => Helpers::mcp_resource(),
			'connectionRequests' => $this->connection_requests(),
			'providers'          => $this->providers(),
			'status'             => $this->status(),
			'diagnostics'        => $this->diagnostics( 'logs' === $payload_tab ),
			'roleConnections'    => $this->role_connections_payload(),
			'roleAbilities'      => $this->role_abilities_payload(),
			'connectionHealth'   => ( new ConnectionHealth() )->last_result(),
		);
	}

	/**
	 * Return session lists only for tabs that render connection tables.
	 *
	 * @param string                $payload_tab   Normalized payload tab.
	 * @param AccessTokenRepository $access_tokens Access token repository.
	 * @param AbilitiesRegistry     $registry      Ability registry.
	 * @return array<string, mixed>
	 */
	private function connection_payload( string $payload_tab, AccessTokenRepository $access_tokens, AbilitiesRegistry $registry ): array {
		if ( 'connections' !== $payload_tab ) {
			return array(
				'sessions'        => array(),
				'revokedSessions' => array(),
			);
		}

		return array(
			'sessions'        => $this->connection_sessions_with_effective_abilities( $access_tokens->list_active_sessions(), $registry ),
			'revokedSessions' => $this->connection_sessions_with_effective_abilities( $access_tokens->list_revoked_sessions(), $registry ),
		);
	}

	/**
	 * Add effective MCP ability details to admin connection rows.
	 *
	 * @param array<int, array<string, mixed>> $sessions Connection sessions.
	 * @param AbilitiesRegistry                $registry Ability registry.
	 * @return array<int, array<string, mixed>>
	 */
	private function connection_sessions_with_effective_abilities( array $sessions, AbilitiesRegistry $registry ): array {
		if ( array() === $sessions ) {
			return $sessions;
		}

		$availability = new McpToolAvailability();

		return array_map(
			function ( array $session ) use ( $availability, $registry ): array {
				$user_id = absint( $session['user_id'] ?? 0 );
				$scopes  = array_values( array_map( 'strval', (array) ( $session['scopes'] ?? array() ) ) );
				$modules = $availability->ability_modules_for_user( $user_id, $registry, $scopes );
				$policy  = $availability->ability_policy_for_user( $user_id, $registry, $scopes );
				$writes  = array_filter(
					$modules,
					static fn( AbilityModuleInterface $module ): bool => ! $module->is_read_only()
				);

				$session['effective_abilities']           = array_values(
					array_map(
						fn( AbilityModuleInterface $module ): array => array(
							'id'          => $module->id(),
							'toolName'    => $registry->tool_name( $module->id() ),
							'title'       => $module->title(),
							'description' => $module->description(),
							'scopes'      => $module->required_scopes(),
							'readOnly'    => $module->is_read_only(),
						),
						$modules
					)
				);
				$session['effective_write_ability_count'] = count( $writes );
				$session['effective_ability_summary']     = array(
					'available_count'          => count( $modules ),
					'write_count'              => count( $writes ),
					'blocked_by_global_count'  => count( (array) ( $policy['blocked_by_global_ids'] ?? array() ) ),
					'blocked_by_role_count'    => count( (array) ( $policy['blocked_by_role_ids'] ?? array() ) ),
					'default_read_only_policy' => true === ( $policy['default_read_only_policy'] ?? false ),
					'explicit_role_policy'     => true === ( $policy['explicit_role_policy'] ?? false ),
					'scope_aware'              => true === ( $policy['scope_aware'] ?? false ),
					'missing_user'             => true === ( $policy['missing_user'] ?? false ),
					'missing_role'             => true === ( $policy['missing_role'] ?? false ),
				);

				return $session;
			},
			$sessions
		);
	}

	/**
	 * Return ability controls while deferring role samples to the Abilities tab.
	 *
	 * @param string            $payload_tab      Normalized payload tab.
	 * @param AbilitiesRegistry $ability_registry Ability registry.
	 * @return array<string, mixed>
	 */
	private function ability_payload( string $payload_tab, AbilitiesRegistry $ability_registry ): array {
		if ( null === self::$ability_payload_cache ) {
			$wp_abilities                = new WordPressAbilitiesPolicy();
			$tool_safety                 = new ToolSafety();
			self::$ability_payload_cache = array(
				'abilities'                => $ability_registry->public_definitions(),
				'coreDefaultAbilities'     => $ability_registry->core_default_public_definitions(),
				'enabledAbilities'         => $ability_registry->enabled_ids(),
				'wpAbilities'              => $wp_abilities->public_definitions(),
				'enabledWpAbilities'       => $wp_abilities->allowed_ids(),
				'confirmationGroups'       => $tool_safety->confirmation_groups(),
				'confirmationGroupOptions' => $tool_safety->available_confirmation_groups(),
			);
		}

		$role_ability_policy = 'abilities' === $payload_tab
			? ( new RoleAbilitiesPolicy() )->admin_payload( $ability_registry )
			: array();

		return array_merge(
			self::$ability_payload_cache,
			array(
				'roleAbilityPolicy' => $role_ability_policy,
			)
		);
	}

	/**
	 * Return data that belongs to one expensive or hidden tab.
	 *
	 * @param string $payload_tab Normalized payload tab.
	 * @return array<string, mixed>
	 */
	private function tab_payload( string $payload_tab ): array {
		$activity_payload = 'activity' === $payload_tab
			? $this->activity_payload()
			: $this->empty_activity_payload();
		$brand_profile    = 'brand' === $payload_tab
			? ( new BrandProfile() )->admin_payload()
			: array();
		$learning         = 'learning' === $payload_tab
			? ( new LearningSuggestionRepository() )->admin_payload()
			: LearningSuggestionRepository::empty_payload();
		$internal_links   = 'links-map' === $payload_tab
			? $this->internal_links_map_payload()
			: $this->empty_internal_links_map_payload();
		$memory           = 'learning' === $payload_tab
			? $this->memory_payload()
			: $this->empty_memory_payload();
		$incidents        = 'learning' === $payload_tab
			? ( new PluginIncidentReporter() )->admin_payload()
			: PluginIncidentReporter::empty_payload();
		$changelog        = 'changelog' === $payload_tab
			? $this->load_changelog()
			: array();

		return array(
			'activity'            => $activity_payload,
			'brandProfile'        => $brand_profile,
			'internalLinksMap'    => $internal_links,
			'learningSuggestions' => $learning,
			'memoryRecords'       => $memory,
			'incidentReports'     => $incidents,
			'changelog'           => $changelog,
		);
	}

	/**
	 * Return a bounded internal-link map payload for the admin app.
	 *
	 * @return array<string, mixed>
	 */
	private function internal_links_map_payload(): array {
		$filters       = $this->internal_links_map_filters();
		$repository    = new ContentIndexRepository();
		$per_page      = (int) $filters['per_page'];
		$audit_payload = $repository->internal_link_audit(
			array(
				'page'               => $filters['page'],
				'per_page'           => $per_page,
				'state'              => $filters['state'],
				'post_type'          => $filters['post_type'],
				'status'             => $filters['status'],
				'min_inbound_links'  => $filters['min_inbound_links'],
				'thin_word_count'    => $filters['thin_word_count'],
				'max_outbound_links' => $filters['max_outbound_links'],
			)
		);
		$items         = array_values(
			array_map(
				array( $this, 'internal_links_map_item' ),
				array_filter( (array) ( $audit_payload['items'] ?? array() ), 'is_array' )
			)
		);
		$total         = (int) ( $audit_payload['total'] ?? count( $items ) );
		$page          = max( 1, (int) ( $audit_payload['page'] ?? $filters['page'] ) );
		$total_pages   = max( 1, (int) ceil( max( 0, $total ) / max( 1, $per_page ) ) );
		$page          = min( $page, $total_pages );
		$index         = (array) ( $audit_payload['index'] ?? array() );
		$index_total   = (int) ( $index['total_items'] ?? 0 );
		$summary       = $this->internal_links_map_summary( $items, $index, $total );
		$cluster_items = array_slice( $items, 0, 8 );

		return array(
			'items'             => $items,
			'total'             => $total,
			'page'              => $page,
			'perPage'           => $per_page,
			'totalPages'        => $total_pages,
			'filters'           => $filters,
			'thresholds'        => (array) ( $audit_payload['thresholds'] ?? array() ),
			'summary'           => $summary,
			'clusters'          => $this->internal_links_map_clusters( $cluster_items ),
			'index'             => $index,
			'prevUrl'           => $page > 1 ? $this->internal_links_map_url( $filters, $page - 1 ) : '',
			'nextUrl'           => $page < $total_pages ? $this->internal_links_map_url( $filters, $page + 1 ) : '',
			'suggestionsUrl'    => esc_url_raw( $this->settings_url( array( 'tab' => 'learning' ) ) ),
			'refreshUrl'        => esc_url_raw( $this->settings_url( array( 'tab' => 'diagnostics' ) ) ),
			'hasSuggestionFlow' => true,
			'emptyState'        => array(
				'title'       => 0 === $index_total ? __( 'No indexed content yet', 'aculect-ai-companion' ) : __( 'No internal-link rows match these filters', 'aculect-ai-companion' ),
				'description' => 0 === $index_total ? __( 'Run the content index refresh diagnostics before reviewing internal-link structure.', 'aculect-ai-companion' ) : __( 'Try another status, post type, or review state to inspect a different slice of the link map.', 'aculect-ai-companion' ),
			),
		);
	}

	/**
	 * Return an empty internal-link map payload shape.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_internal_links_map_payload(): array {
		return array(
			'items'             => array(),
			'total'             => 0,
			'page'              => 1,
			'perPage'           => 20,
			'totalPages'        => 1,
			'filters'           => array(
				'page'               => 1,
				'per_page'           => 20,
				'state'              => 'needs_review',
				'post_type'          => '',
				'status'             => '',
				'min_inbound_links'  => 2,
				'thin_word_count'    => 300,
				'max_outbound_links' => 25,
			),
			'thresholds'        => array(),
			'summary'           => array(
				'totalIndexed'   => 0,
				'totalVisible'   => 0,
				'orphan'         => 0,
				'underlinked'    => 0,
				'stale'          => 0,
				'linkHeavy'      => 0,
				'staleIndexRows' => 0,
				'latestIndexed'  => '',
			),
			'clusters'          => array(),
			'index'             => array(
				'total_items'       => 0,
				'stale_items'       => 0,
				'latest_indexed_at' => '',
			),
			'prevUrl'           => '',
			'nextUrl'           => '',
			'suggestionsUrl'    => '',
			'refreshUrl'        => '',
			'hasSuggestionFlow' => true,
			'emptyState'        => array(
				'title'       => __( 'No indexed content yet', 'aculect-ai-companion' ),
				'description' => __( 'Run the content index refresh diagnostics before reviewing internal-link structure.', 'aculect-ai-companion' ),
			),
		);
	}

	/**
	 * Return sanitized internal-link map filters from the current admin URL.
	 *
	 * @return array<string, mixed>
	 */
	private function internal_links_map_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin filters.
		$state = isset( $_GET['links_state'] ) ? sanitize_key( wp_unslash( (string) $_GET['links_state'] ) ) : 'needs_review';
		if ( ! in_array( $state, array( 'all', 'needs_review', 'orphan', 'underlinked', 'thin', 'stale', 'link_heavy' ), true ) ) {
			$state = 'needs_review';
		}

		$status = isset( $_GET['links_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['links_status'] ) ) : '';
		if ( '' !== $status && ! in_array( $status, array( 'publish', 'future', 'draft', 'pending', 'private' ), true ) ) {
			$status = '';
		}

		$per_page = isset( $_GET['links_per_page'] ) ? absint( $_GET['links_per_page'] ) : 20;
		if ( $per_page <= 0 ) {
			$per_page = 20;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin pagination parameter.
			'page'               => isset( $_GET['links_page'] ) ? max( 1, absint( $_GET['links_page'] ) ) : 1,
			'per_page'           => max( 10, min( 50, $per_page ) ),
			'state'              => $state,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter.
			'post_type'          => isset( $_GET['links_post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['links_post_type'] ) ) : '',
			'status'             => $status,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only threshold filter.
			'min_inbound_links'  => isset( $_GET['links_min_inbound'] ) ? max( 1, min( 100, absint( $_GET['links_min_inbound'] ) ) ) : 2,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only threshold filter.
			'thin_word_count'    => isset( $_GET['links_thin_words'] ) ? max( 1, min( 5000, absint( $_GET['links_thin_words'] ) ) ) : 300,
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only threshold filter.
			'max_outbound_links' => isset( $_GET['links_max_outbound'] ) ? max( 1, min( 500, absint( $_GET['links_max_outbound'] ) ) ) : 25,
		);
	}

	/**
	 * Add admin action links to one bounded internal-link audit row.
	 *
	 * @param array<string, mixed> $item Audit row.
	 * @return array<string, mixed>
	 */
	private function internal_links_map_item( array $item ): array {
		$post_id = absint( $item['post_id'] ?? $item['id'] ?? 0 );

		return array_merge(
			$item,
			array(
				'post_id'        => $post_id,
				'editUrl'        => $post_id > 0 ? esc_url_raw( (string) get_edit_post_link( $post_id, 'raw' ) ) : '',
				'viewUrl'        => '' !== (string) ( $item['permalink'] ?? '' ) ? esc_url_raw( (string) $item['permalink'] ) : ( $post_id > 0 ? esc_url_raw( (string) get_permalink( $post_id ) ) : '' ),
				'suggestionsUrl' => esc_url_raw( $this->settings_url( array( 'tab' => 'learning' ) ) ),
			)
		);
	}

	/**
	 * Build summary counters from the bounded visible rows and index aggregate.
	 *
	 * @param list<array<string, mixed>> $items Visible audit rows.
	 * @param array<string, mixed>       $index Index summary.
	 * @param int                        $total Total rows matching filters.
	 * @return array<string, mixed>
	 */
	private function internal_links_map_summary( array $items, array $index, int $total ): array {
		$summary = array(
			'totalIndexed'   => (int) ( $index['total_items'] ?? 0 ),
			'totalVisible'   => $total,
			'orphan'         => 0,
			'underlinked'    => 0,
			'stale'          => 0,
			'linkHeavy'      => 0,
			'staleIndexRows' => (int) ( $index['stale_items'] ?? 0 ),
			'latestIndexed'  => (string) ( $index['latest_indexed_at'] ?? '' ),
		);

		foreach ( $items as $item ) {
			$flags = array_map( 'strval', (array) ( $item['flags'] ?? array() ) );
			if ( in_array( 'orphan', $flags, true ) ) {
				++$summary['orphan'];
			}
			if ( in_array( 'underlinked', $flags, true ) ) {
				++$summary['underlinked'];
			}
			if ( in_array( 'stale_index', $flags, true ) ) {
				++$summary['stale'];
			}
			if ( in_array( 'link_heavy', $flags, true ) ) {
				++$summary['linkHeavy'];
			}
		}

		return $summary;
	}

	/**
	 * Build a small relationship preview grouped by post type.
	 *
	 * @param list<array<string, mixed>> $items Visible audit rows.
	 * @return list<array<string, mixed>>
	 */
	private function internal_links_map_clusters( array $items ): array {
		$clusters = array();

		foreach ( $items as $item ) {
			$type = (string) ( $item['type'] ?? 'content' );
			if ( ! isset( $clusters[ $type ] ) ) {
				$clusters[ $type ] = array(
					'id'            => $type,
					'label'         => $type,
					'total'         => 0,
					'needsReview'   => 0,
					'inboundTotal'  => 0,
					'outboundTotal' => 0,
					'items'         => array(),
				);
			}

			++$clusters[ $type ]['total'];
			$clusters[ $type ]['inboundTotal']  += (int) ( $item['inbound_internal_links'] ?? 0 );
			$clusters[ $type ]['outboundTotal'] += (int) ( $item['outbound_internal_links'] ?? 0 );
			if ( ! empty( $item['needs_review'] ) ) {
				++$clusters[ $type ]['needsReview'];
			}

			if ( count( $clusters[ $type ]['items'] ) < 4 ) {
				$clusters[ $type ]['items'][] = array(
					'postId'   => (int) ( $item['post_id'] ?? 0 ),
					'title'    => (string) ( $item['title'] ?? '' ),
					'inbound'  => (int) ( $item['inbound_internal_links'] ?? 0 ),
					'outbound' => (int) ( $item['outbound_internal_links'] ?? 0 ),
					'flags'    => (array) ( $item['flags'] ?? array() ),
				);
			}
		}

		return array_values( $clusters );
	}

	/**
	 * Build an Internal Links tab pagination URL.
	 *
	 * @param array<string, mixed> $filters Current filters.
	 * @param int                  $page    Page number.
	 */
	private function internal_links_map_url( array $filters, int $page ): string {
		return add_query_arg(
			array_filter(
				array(
					'page'               => 'aculect-ai-companion',
					'tab'                => 'links-map',
					'links_page'         => max( 1, $page ),
					'links_state'        => (string) ( $filters['state'] ?? 'needs_review' ),
					'links_post_type'    => (string) ( $filters['post_type'] ?? '' ),
					'links_status'       => (string) ( $filters['status'] ?? '' ),
					'links_per_page'     => (int) ( $filters['per_page'] ?? 20 ),
					'links_min_inbound'  => (int) ( $filters['min_inbound_links'] ?? 2 ),
					'links_thin_words'   => (int) ( $filters['thin_word_count'] ?? 300 ),
					'links_max_outbound' => (int) ( $filters['max_outbound_links'] ?? 25 ),
				),
				static fn( mixed $value ): bool => '' !== $value && 0 !== $value
			),
			$this->settings_url()
		);
	}

	/**
	 * Return durable memory rows for the admin app.
	 *
	 * @return array<string, mixed>
	 */
	private function memory_payload(): array {
		$payload = ( new ContentIndexRepository() )->list_memories(
			array(
				'status'   => '',
				'per_page' => 50,
			)
		);
		$items   = is_array( $payload['items'] ?? null ) ? $payload['items'] : array();
		$summary = array(
			'total'     => (int) ( $payload['total'] ?? count( $items ) ),
			'approved'  => 0,
			'pending'   => 0,
			'dismissed' => 0,
		);

		foreach ( $items as $item ) {
			$status = (string) ( is_array( $item ) ? ( $item['status'] ?? 'pending' ) : 'pending' );
			if ( array_key_exists( $status, $summary ) ) {
				++$summary[ $status ];
			}
		}

		$payload['summary'] = $summary;

		return $payload;
	}

	/**
	 * Return empty memory payload shape.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_memory_payload(): array {
		return array(
			'items'    => array(),
			'total'    => 0,
			'page'     => 1,
			'per_page' => 50,
			'context'  => 'compact',
			'summary'  => array(
				'total'     => 0,
				'approved'  => 0,
				'pending'   => 0,
				'dismissed' => 0,
			),
		);
	}

	/**
	 * Return role-connection settings for the Advanced tab.
	 *
	 * @return array<string, mixed>
	 */
	private function role_connections_payload(): array {
		return array(
			'enabled'      => RoleConnectionEntryPoint::is_enabled(),
			'allowedRoles' => RoleConnectionEntryPoint::allowed_roles(),
			'roleOptions'  => RoleConnectionEntryPoint::role_options(),
			'shortcode'    => '[aculect_ai_companion_connect]',
			'blockName'    => 'aculect/ai-companion-connect',
			'functionName' => 'aculect_ai_companion_connection_entry',
		);
	}

	/**
	 * Return role ability policy editor settings.
	 *
	 * @return array<string, bool>
	 */
	private function role_abilities_payload(): array {
		return array(
			'enabled' => RoleAbilitiesPolicy::is_editing_enabled(),
		);
	}

	/**
	 * Return admin-post action names and nonces for forms.
	 *
	 * @return array<string, string>
	 */
	private function actions_payload(): array {
		return array(
			'adminPostUrl'                    => admin_url( 'admin-post.php' ),
			'saveAbilitiesAction'             => 'aculect_ai_companion_save_abilities',
			'saveRoleAbilitiesAction'         => 'aculect_ai_companion_save_role_abilities',
			'saveAdvancedAction'              => 'aculect_ai_companion_save_advanced',
			'exportSettingsAction'            => 'aculect_ai_companion_export_settings',
			'exportMcpToolManifestAction'     => 'aculect_ai_companion_export_mcp_tool_manifest',
			'importSettingsAction'            => 'aculect_ai_companion_import_settings',
			'resetSettingsAction'             => 'aculect_ai_companion_reset_settings',
			'saveBrandAction'                 => 'aculect_ai_companion_save_brand',
			'reviewLearningSuggestionAction'  => 'aculect_ai_companion_review_learning_suggestion',
			'reviewMemoryAction'              => 'aculect_ai_companion_review_memory_item',
			'runDiagnosticsAction'            => 'aculect_ai_companion_run_connection_diagnostics',
			'runContentIndexSweepAction'      => 'aculect_ai_companion_run_content_index_sweep',
			'clearLogsAction'                 => 'aculect_ai_companion_clear_logs',
			'setLockdownAction'               => 'aculect_ai_companion_set_lockdown',
			'setSessionAccessLevelAction'     => 'aculect_ai_companion_set_session_access_level',
			'setSessionWritePermissionAction' => 'aculect_ai_companion_set_session_write_permission',
			'revokeSessionAction'             => 'aculect_ai_companion_revoke_session',
			'revokeAllAction'                 => 'aculect_ai_companion_revoke_all_sessions',
			'saveAbilitiesNonce'              => wp_create_nonce( 'aculect_ai_companion_save_abilities' ),
			'saveRoleAbilitiesNonce'          => wp_create_nonce( 'aculect_ai_companion_save_role_abilities' ),
			'saveAdvancedNonce'               => wp_create_nonce( 'aculect_ai_companion_save_advanced' ),
			'exportSettingsNonce'             => wp_create_nonce( 'aculect_ai_companion_export_settings' ),
			'exportMcpToolManifestNonce'      => wp_create_nonce( 'aculect_ai_companion_export_mcp_tool_manifest' ),
			'importSettingsNonce'             => wp_create_nonce( 'aculect_ai_companion_import_settings' ),
			'resetSettingsNonce'              => wp_create_nonce( 'aculect_ai_companion_reset_settings' ),
			'saveBrandNonce'                  => wp_create_nonce( 'aculect_ai_companion_save_brand' ),
			'reviewLearningSuggestionNonce'   => wp_create_nonce( 'aculect_ai_companion_review_learning_suggestion' ),
			'reviewMemoryNonce'               => wp_create_nonce( 'aculect_ai_companion_review_memory_item' ),
			'runDiagnosticsNonce'             => wp_create_nonce( 'aculect_ai_companion_run_connection_diagnostics' ),
			'runContentIndexSweepNonce'       => wp_create_nonce( 'aculect_ai_companion_run_content_index_sweep' ),
			'clearLogsNonce'                  => wp_create_nonce( 'aculect_ai_companion_clear_logs' ),
			'setLockdownNonce'                => wp_create_nonce( 'aculect_ai_companion_set_lockdown' ),
			'setSessionAccessLevelNonce'      => wp_create_nonce( 'aculect_ai_companion_set_session_access_level' ),
			'setSessionWritePermissionNonce'  => wp_create_nonce( 'aculect_ai_companion_set_session_write_permission' ),
			'revokeSessionNonce'              => wp_create_nonce( 'aculect_ai_companion_revoke_session' ),
			'revokeAllNonce'                  => wp_create_nonce( 'aculect_ai_companion_revoke_all_sessions' ),
		);
	}

	/**
	 * Persist role-specific MCP ability policy from the admin form.
	 */
	public function handle_save_role_abilities(): void {
		$this->guard_action( 'aculect_ai_companion_save_role_abilities' );
		$registry = new AbilitiesRegistry();
		$policy   = new RoleAbilitiesPolicy();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$role      = isset( $_POST['role_ability_role'] ) ? sanitize_key( wp_unslash( (string) $_POST['role_ability_role'] ) ) : '';
		$action    = isset( $_POST['role_ability_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['role_ability_action'] ) ) : 'save';
		$ids       = isset( $_POST['enabled_role_abilities'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['enabled_role_abilities'] ) )
			: array();
		$copy_from = isset( $_POST['copy_from_role'] ) ? sanitize_key( wp_unslash( (string) $_POST['copy_from_role'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! RoleAbilitiesPolicy::is_editing_enabled() ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'                       => 'aculect-ai-companion',
						'tab'                        => 'abilities',
						'role_abilities_not_enabled' => '1',
						'role'                       => $role,
					),
					$this->settings_url()
				)
			);
			exit;
		}

		if ( 'reset' === $action ) {
			$policy->reset_role_policy( $role, $registry );
		} elseif ( 'copy' === $action ) {
			$policy->copy_role_policy( $copy_from, $role, $registry );
		} else {
			$policy->save_role_policy( $role, $ids, $registry );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'aculect-ai-companion',
					'tab'                  => 'abilities',
					'role_abilities_saved' => '1',
					'role'                 => $role,
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Persist enabled MCP abilities from the admin form.
	 */
	public function handle_save_abilities(): void {
		$this->guard_action( 'aculect_ai_companion_save_abilities' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$enabled = isset( $_POST['enabled_abilities'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['enabled_abilities'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		( new AbilitiesRegistry() )->save_enabled_ids( $enabled );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$confirmation_groups = isset( $_POST['confirmation_required_groups'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['confirmation_required_groups'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		( new ToolSafety() )->save_confirmation_groups( $confirmation_groups );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$enabled_wp_abilities = isset( $_POST['enabled_wp_abilities'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['enabled_wp_abilities'] ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		( new WordPressAbilitiesPolicy() )->save_allowed_ids( $enabled_wp_abilities );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'aculect-ai-companion',
					'tab'             => 'abilities',
					'abilities_saved' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Persist advanced diagnostic settings from the admin form.
	 */
	public function handle_save_advanced(): void {
		$this->guard_action( 'aculect_ai_companion_save_advanced' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$enabled = isset( $_POST['diagnostic_logging_enabled'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['diagnostic_logging_enabled'] ) );

		LogSettings::set_enabled( $enabled );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$role_connections_enabled = isset( $_POST['role_connections_enabled'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['role_connections_enabled'] ) );
		$role_connection_roles    = isset( $_POST['role_connection_roles'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['role_connection_roles'] ) )
			: array();
		$role_abilities_enabled   = isset( $_POST['role_abilities_enabled'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['role_abilities_enabled'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		RoleConnectionEntryPoint::save( $role_connections_enabled, $role_connection_roles );
		RoleAbilitiesPolicy::set_editing_enabled( $role_abilities_enabled );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'aculect-ai-companion',
					'tab'            => 'advanced',
					'advanced_saved' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Stream sanitized plugin settings as a JSON download.
	 */
	public function handle_export_settings(): void {
		$this->guard_action( 'aculect_ai_companion_export_settings' );

		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header(
				'Content-Disposition: attachment; filename="' .
				sanitize_file_name( 'aculect-ai-companion-settings-' . gmdate( 'Y-m-d' ) . '.json' ) .
				'"'
			);
		}

		echo ( new SettingsTransfer() )->export_json(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is encoded by SettingsTransfer::export_json().
		exit;
	}

	/**
	 * Stream the exact MCP tools/list manifest for support diagnostics.
	 */
	public function handle_export_mcp_tool_manifest(): void {
		$this->guard_action( 'aculect_ai_companion_export_mcp_tool_manifest' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		$session    = $this->active_session_by_id( $session_id );
		$exporter   = new McpToolManifest();
		$manifest   = array() !== $session
			? $exporter->export_for_user( (int) ( $session['user_id'] ?? 0 ), $session )
			: $exporter->export_for_current_user();

		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header(
				'Content-Disposition: attachment; filename="' .
				sanitize_file_name( 'aculect-ai-companion-mcp-tools-' . gmdate( 'Y-m-d' ) . '.json' ) .
				'"'
			);
		}

		$json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		echo is_string( $json ) ? $json : '{}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is encoded immediately above.
		exit;
	}

	/**
	 * Import sanitized plugin settings from a JSON upload.
	 */
	public function handle_import_settings(): void {
		$this->guard_action( 'aculect_ai_companion_import_settings' );

		$transfer = new SettingsTransfer();
		$imported = false;
		$json     = $this->uploaded_settings_json();

		if ( '' !== $json ) {
			$imported = $transfer->import_payload( $transfer->decode_json( $json ) );
		}

		$this->redirect_to_advanced(
			array(
				'settings_imported' => $imported ? '1' : '0',
			)
		);
	}

	/**
	 * Restore plugin settings to defaults.
	 */
	public function handle_reset_settings(): void {
		$this->guard_action( 'aculect_ai_companion_reset_settings' );

		( new SettingsTransfer() )->reset();

		$this->redirect_to_advanced(
			array(
				'settings_reset' => '1',
			)
		);
	}

	/**
	 * Find one active connector session by admin-visible ID.
	 *
	 * @param int $session_id Access-token table primary key.
	 * @return array<string, mixed>
	 */
	private function active_session_by_id( int $session_id ): array {
		if ( $session_id <= 0 ) {
			return array();
		}

		foreach ( ( new AccessTokenRepository() )->list_active_sessions() as $session ) {
			if ( (int) ( $session['id'] ?? 0 ) === $session_id ) {
				return $session;
			}
		}

		return array();
	}

	/**
	 * Return uploaded settings JSON when the file passes basic safety checks.
	 */
	private function uploaded_settings_json(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this upload read.
		if ( empty( $_FILES['settings_file'] ) || ! is_array( $_FILES['settings_file'] ) ) {
			return '';
		}

		$file  = $_FILES['settings_file'];
		$error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
		if ( ! is_scalar( $error ) || UPLOAD_ERR_OK !== (int) $error ) {
			return '';
		}

		$size = $file['size'] ?? 0;
		if ( ! is_scalar( $size ) ) {
			return '';
		}

		$size = absint( $size );
		if ( $size <= 0 || $size > SettingsTransfer::MAX_IMPORT_BYTES ) {
			return '';
		}

		$tmp_name = $file['tmp_name'] ?? '';
		if ( ! is_scalar( $tmp_name ) ) {
			return '';
		}

		$tmp_name = (string) $tmp_name;
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a bounded PHP-uploaded temporary file.
		$json = file_get_contents( $tmp_name, false, null, 0, SettingsTransfer::MAX_IMPORT_BYTES + 1 );

		return is_string( $json ) && strlen( $json ) <= SettingsTransfer::MAX_IMPORT_BYTES ? $json : '';
	}

	/**
	 * Redirect back to Advanced with a status flag.
	 *
	 * @param array<string, string> $args Additional query args.
	 */
	private function redirect_to_advanced( array $args ): void {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page' => 'aculect-ai-companion',
						'tab'  => 'advanced',
					),
					$args
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Persist brand profile settings from the admin form.
	 */
	public function handle_save_brand(): void {
		$this->guard_action( 'aculect_ai_companion_save_brand' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$profile = isset( $_POST['brand_profile'] ) && is_array( $_POST['brand_profile'] ) ? (array) wp_unslash( $_POST['brand_profile'] ) : array();

		( new BrandProfile() )->save( $profile );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'aculect-ai-companion',
					'tab'         => 'brand',
					'brand_saved' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Review an MCP-submitted learning suggestion.
	 */
	public function handle_review_learning_suggestion(): void {
		$this->guard_action( 'aculect_ai_companion_review_learning_suggestion' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before these reads.
		$suggestion_id = isset( $_POST['suggestion_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['suggestion_id'] ) ) : '';
		$action        = isset( $_POST['learning_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['learning_action'] ) ) : '';
		$review_note   = isset( $_POST['review_note'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['review_note'] ) ) : '';
		$suggestion    = isset( $_POST['learning_suggestion'] ) && is_array( $_POST['learning_suggestion'] )
			? (array) wp_unslash( $_POST['learning_suggestion'] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$repository = new LearningSuggestionRepository();
		$updated    = 'update' === $action
			? $repository->update( $suggestion_id, $suggestion, $review_note )
			: $repository->review( $suggestion_id, $action, $review_note );
		$status     = $updated
			? match ( $action ) {
				'approve' => 'approved',
				'dismiss' => 'dismissed',
				'update'  => 'updated',
				default   => 'not_updated',
			}
			: 'not_updated';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'aculect-ai-companion',
					'tab'               => 'learning',
					'learning_reviewed' => $status,
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Review or edit one durable Aculect memory item.
	 */
	public function handle_review_memory_item(): void {
		$this->guard_action( 'aculect_ai_companion_review_memory_item' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before these reads.
		$action       = isset( $_POST['memory_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['memory_action'] ) ) : '';
		$original_key = isset( $_POST['memory_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['memory_key'] ) ) : '';
		$memory_item  = isset( $_POST['memory_item'] ) && is_array( $_POST['memory_item'] )
			? (array) wp_unslash( $_POST['memory_item'] )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$repository = new ContentIndexRepository();
		$updated    = false;

		if ( 'delete' === $action ) {
			$result  = $repository->delete_memory( $original_key );
			$updated = 'success' === ( $result['status'] ?? '' );
		} else {
			$status = match ( $action ) {
				'approve' => 'approved',
				'dismiss' => 'dismissed',
				default => sanitize_key( (string) ( $memory_item['status'] ?? 'pending' ) ),
			};
			$key = sanitize_text_field( (string) ( $memory_item['key'] ?? $original_key ) );

			$result = $repository->upsert_memory(
				array(
					'key'        => $key,
					'domain'     => $memory_item['domain'] ?? 'content',
					'value'      => $memory_item['value'] ?? '',
					'evidence'   => $memory_item['evidence'] ?? '',
					'confidence' => $memory_item['confidence'] ?? 'medium',
					'status'     => $status,
					'source'     => $memory_item['source'] ?? 'admin',
				)
			);

			$updated = 'success' === ( $result['status'] ?? '' );
			if ( $updated && '' !== $original_key && $key !== $original_key ) {
				$repository->delete_memory( $original_key );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'aculect-ai-companion',
					'tab'             => 'learning',
					'memory_reviewed' => $updated ? $action : 'not_updated',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Clear diagnostic logs from the admin form.
	 */
	public function handle_clear_logs(): void {
		$this->guard_action( 'aculect_ai_companion_clear_logs' );

		( new LogRepository() )->clear();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'aculect-ai-companion',
					'tab'          => 'logs',
					'logs_cleared' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Temporarily pause or resume all connected AI access.
	 */
	public function handle_set_lockdown(): void {
		$this->guard_action( 'aculect_ai_companion_set_lockdown' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$paused = isset( $_POST['access_paused'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['access_paused'] ) );

		AccessLockdown::set_paused( $paused );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'aculect-ai-companion',
					'tab'             => 'connections',
					'access_lockdown' => $paused ? 'paused' : 'resumed',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Set the admin-managed access level for one active connector session.
	 */
	public function handle_set_session_access_level(): void {
		$this->guard_action( 'aculect_ai_companion_set_session_access_level' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$session_id   = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		$access_level = isset( $_POST['session_access_level'] )
			? ConnectionAccessLevel::normalize( wp_unslash( (string) $_POST['session_access_level'] ) )
			: ConnectionAccessLevel::DEFAULT;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$access_tokens = new AccessTokenRepository();
		$before        = $session_id > 0 ? $access_tokens->session_summary( $session_id ) : array();
		$updated       = $session_id > 0 && $access_tokens->set_access_level( $session_id, $access_level );
		if ( $updated ) {
			$this->record_session_access_change( $session_id, $before, $access_level );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => 'aculect-ai-companion',
					'tab'                  => 'connections',
					'session_access_level' => $updated ? 'updated' : 'not_updated',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Toggle direct write permission for one active connector session.
	 */
	public function handle_set_session_write_permission(): void {
		$this->guard_action( 'aculect_ai_companion_set_session_write_permission' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		$enabled    = isset( $_POST['write_permission_enabled'] )
			&& '1' === sanitize_text_field( wp_unslash( (string) $_POST['write_permission_enabled'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$access_tokens = new AccessTokenRepository();
		$before        = $session_id > 0 ? $access_tokens->session_summary( $session_id ) : array();
		$access_level  = ConnectionAccessLevel::from_write_permission( $enabled );
		$updated       = $session_id > 0 && $access_tokens->set_write_permission( $session_id, $enabled );
		if ( $updated ) {
			$this->record_session_access_change( $session_id, $before, $access_level );
		}
		$status = $updated ? ( $enabled ? 'enabled' : 'disabled' ) : 'not_updated';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                     => 'aculect-ai-companion',
					'tab'                      => 'connections',
					'session_write_permission' => $status,
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Record a sanitized activity entry for admin-managed connection trust changes.
	 *
	 * @param int                  $session_id       Access-token table primary key.
	 * @param array<string, mixed> $previous_session Session metadata before the update.
	 * @param string               $new_access_level New access level.
	 */
	private function record_session_access_change( int $session_id, array $previous_session, string $new_access_level ): void {
		$old_access_level = ConnectionAccessLevel::normalize( (string) ( $previous_session['access_level'] ?? '' ) );
		$new_access_level = ConnectionAccessLevel::normalize( $new_access_level );

		( new ActivityLogger() )->record_user_access_event(
			'connection_access.update',
			(int) ( $previous_session['user_id'] ?? 0 ),
			get_current_user_id(),
			__( 'Connection trust updated for assistant session.', 'aculect-ai-companion' ),
			array(
				'session_id'       => $session_id,
				'client_id'        => (string) ( $previous_session['client_id'] ?? '' ),
				'client_name'      => (string) ( $previous_session['client_name'] ?? '' ),
				'provider'         => (string) ( $previous_session['provider'] ?? 'mcp' ),
				'old_access_level' => $old_access_level,
				'new_access_level' => $new_access_level,
				'old_direct_write' => ConnectionAccessLevel::allows_direct_write( $old_access_level ) ? 1 : 0,
				'new_direct_write' => ConnectionAccessLevel::allows_direct_write( $new_access_level ) ? 1 : 0,
			)
		);
	}

	/**
	 * Run connection health diagnostics from the admin screen.
	 */
	public function handle_run_connection_diagnostics(): void {
		$this->guard_action( 'aculect_ai_companion_run_connection_diagnostics' );

		( new ConnectionHealth() )->run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'aculect-ai-companion',
					'tab'             => 'diagnostics',
					'diagnostics_run' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Run one bounded stale index sweep and refresh diagnostics.
	 */
	public function handle_run_content_index_sweep(): void {
		$this->guard_action( 'aculect_ai_companion_run_content_index_sweep' );

		( new ContentIndexer() )->run_stale_sweep();
		( new ConnectionHealth() )->run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'aculect-ai-companion',
					'tab'             => 'diagnostics',
					'index_sweep_run' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Revoke a single active connector session.
	 */
	public function handle_revoke_session(): void {
		$this->guard_action( 'aculect_ai_companion_revoke_session' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard_action() verifies the nonce before this read.
		$session_id = isset( $_POST['session_id'] ) ? absint( $_POST['session_id'] ) : 0;
		if ( $session_id > 0 ) {
			( new AccessTokenRepository() )->revoke_session( $session_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'aculect-ai-companion',
					'revoked' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Revoke every active connector session.
	 */
	public function handle_revoke_all_sessions(): void {
		$this->guard_action( 'aculect_ai_companion_revoke_all_sessions' );
		( new AccessTokenRepository() )->revoke_all();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'aculect-ai-companion',
					'revoked_all' => '1',
				),
				$this->settings_url()
			)
		);
		exit;
	}

	/**
	 * Return provider setup definitions for the React settings app.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function providers(): array {
		return ( new ProviderRegistry() )->setup_definitions( Helpers::mcp_resource() );
	}

	/**
	 * Return local connector logo URLs for admin UI badges.
	 *
	 * @return array<string, string>
	 */
	private function connector_logo_urls(): array {
		$base_url = ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/images/connectors/';

		return array(
			'cursor' => esc_url_raw( $base_url . 'cursor.svg' ),
			'gemini' => esc_url_raw( $base_url . 'gemini.svg' ),
			'mcp'    => esc_url_raw( $base_url . 'mcp-client.svg' ),
		);
	}

	/**
	 * Return the Phase 1 connection request shape without faking pending data.
	 *
	 * @return array<string, mixed>
	 */
	private function connection_requests(): array {
		return array(
			'approvalMode'        => 'interactive_oauth',
			'approvalModeEnabled' => false,
			'queueAvailable'      => false,
			'status'              => 'disabled',
			'pendingCount'        => 0,
			'items'               => array(),
		);
	}

	/**
	 * Return diagnostic settings and the current log page for the React app.
	 *
	 * @param bool $include_logs Whether to load paginated log rows.
	 * @return array<string, mixed>
	 */
	private function diagnostics( bool $include_logs = false ): array {
		$enabled = LogSettings::is_enabled();

		return array(
			'loggingEnabled' => $enabled,
			'retentionDays'  => LogSettings::retention_days(),
			'logs'           => $enabled && $include_logs
				? $this->logs_payload()
				: $this->empty_logs_payload(),
		);
	}

	/**
	 * Return a paginated AI activity payload.
	 *
	 * @return array<string, mixed>
	 */
	private function activity_payload(): array {
		$repository  = new ActivityRepository();
		$per_page    = 50;
		$filters     = $this->activity_filters();
		$total       = $repository->count( $filters );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( max( 1, (int) $filters['page'] ), $total_pages );
		$filters     = array_merge(
			$filters,
			array(
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		return array(
			'summary'    => $repository->summary( $filters ),
			'items'      => $repository->list( $filters ),
			'total'      => $total,
			'page'       => $page,
			'perPage'    => $per_page,
			'totalPages' => $total_pages,
			'filters'    => $filters,
			'prevUrl'    => $page > 1 ? $this->activity_page_url( $filters, $page - 1 ) : '',
			'nextUrl'    => $page < $total_pages
				? $this->activity_page_url( $filters, $page + 1 )
				: '',
		);
	}

	/**
	 * Return the default empty AI activity payload.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_activity_payload(): array {
		return array(
			'summary'    => array(),
			'items'      => array(),
			'total'      => 0,
			'page'       => 1,
			'perPage'    => 50,
			'totalPages' => 1,
			'filters'    => array(
				'page'      => 1,
				'action'    => '',
				'status'    => '',
				'user_id'   => 0,
				'assistant' => '',
				'search'    => '',
				'range'     => '7d',
			),
			'prevUrl'    => '',
			'nextUrl'    => '',
		);
	}

	/**
	 * Return sanitized activity filters from the current admin URL.
	 *
	 * @return array<string, mixed>
	 */
	private function activity_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin filters.
		$range = isset( $_GET['activity_range'] ) ? sanitize_key( wp_unslash( (string) $_GET['activity_range'] ) ) : '7d';
		if ( ! in_array( $range, array( '24h', '7d', '30d', '90d', 'all' ), true ) ) {
			$range = '7d';
		}

		return array(
			'page'      => isset( $_GET['activity_page'] ) ? max( 1, absint( $_GET['activity_page'] ) ) : 1,
			'action'    => isset( $_GET['activity_action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['activity_action'] ) ) : '',
			'status'    => isset( $_GET['activity_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['activity_status'] ) ) : '',
			'user_id'   => isset( $_GET['activity_user'] ) ? absint( $_GET['activity_user'] ) : 0,
			'assistant' => isset( $_GET['activity_assistant'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['activity_assistant'] ) ) : '',
			'search'    => isset( $_GET['activity_search'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['activity_search'] ) ) : '',
			'range'     => $range,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Build an Activity tab pagination URL.
	 *
	 * @param array<string, mixed> $filters Activity filters.
	 * @param int                  $page    Page number.
	 */
	private function activity_page_url( array $filters, int $page ): string {
		return add_query_arg(
			array_filter(
				array(
					'page'               => 'aculect-ai-companion',
					'tab'                => 'activity',
					'activity_page'      => max( 1, $page ),
					'activity_action'    => (string) ( $filters['action'] ?? '' ),
					'activity_status'    => (string) ( $filters['status'] ?? '' ),
					'activity_user'      => (int) ( $filters['user_id'] ?? 0 ),
					'activity_assistant' => (string) ( $filters['assistant'] ?? '' ),
					'activity_search'    => (string) ( $filters['search'] ?? '' ),
					'activity_range'     => (string) ( $filters['range'] ?? '7d' ),
				),
				static fn( mixed $value ): bool => '' !== $value && 0 !== $value
			),
			$this->settings_url()
		);
	}

	/**
	 * Return a paginated diagnostic log payload.
	 *
	 * @return array<string, mixed>
	 */
	private function logs_payload(): array {
		$repository = new LogRepository();
		$per_page   = 50;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter.
		$page        = isset( $_GET['logs_page'] ) ? max( 1, absint( $_GET['logs_page'] ) ) : 1;
		$total       = $repository->count();
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( $page, $total_pages );

		return array(
			'items'      => $repository->list( $page, $per_page ),
			'total'      => $total,
			'page'       => $page,
			'perPage'    => $per_page,
			'totalPages' => $total_pages,
			'prevUrl'    => $page > 1 ? $this->logs_page_url( $page - 1 ) : '',
			'nextUrl'    => $page < $total_pages ? $this->logs_page_url( $page + 1 ) : '',
		);
	}

	/**
	 * Return an empty log payload when logging is disabled.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_logs_payload(): array {
		return array(
			'items'      => array(),
			'total'      => 0,
			'page'       => 1,
			'perPage'    => 50,
			'totalPages' => 1,
			'prevUrl'    => '',
			'nextUrl'    => '',
		);
	}

	/**
	 * Build a Logs tab pagination URL.
	 *
	 * @param int $page Log page.
	 */
	private function logs_page_url( int $page ): string {
		return add_query_arg(
			array(
				'page'      => 'aculect-ai-companion',
				'tab'       => 'logs',
				'logs_page' => max( 1, $page ),
			),
			$this->settings_url()
		);
	}

	/**
	 * Return the admin URL for this settings app.
	 *
	 * @param array<string, mixed> $args Additional query args.
	 */
	private function settings_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE_SLUG,
				),
				$args
			),
			admin_url( self::SETTINGS_PARENT_FILE )
		);
	}

	/**
	 * Return the visible settings tabs rendered inside the settings app.
	 *
	 * @return array<int, array{tab:string, title:string, menu_title:string}>
	 */
	private function settings_tabs(): array {
		$tabs = array(
			array(
				'tab'        => 'overview',
				'title'      => __( 'Overview', 'aculect-ai-companion' ),
				'menu_title' => __( 'Overview', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'connect',
				'title'      => __( 'Connect', 'aculect-ai-companion' ),
				'menu_title' => __( 'Connect', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'connections',
				'title'      => __( 'Connections', 'aculect-ai-companion' ),
				'menu_title' => __( 'Connections', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'abilities',
				'title'      => __( 'Abilities', 'aculect-ai-companion' ),
				'menu_title' => __( 'Abilities', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'activity',
				'title'      => __( 'Activity', 'aculect-ai-companion' ),
				'menu_title' => __( 'Activity', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'links-map',
				'title'      => __( 'Internal Links', 'aculect-ai-companion' ),
				'menu_title' => __( 'Internal Links', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'learning',
				'title'      => __( 'Learning', 'aculect-ai-companion' ),
				'menu_title' => __( 'Learning', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'diagnostics',
				'title'      => __( 'Diagnostics', 'aculect-ai-companion' ),
				'menu_title' => __( 'Diagnostics', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'advanced',
				'title'      => __( 'Advanced', 'aculect-ai-companion' ),
				'menu_title' => __( 'Advanced', 'aculect-ai-companion' ),
			),
			array(
				'tab'        => 'changelog',
				'title'      => __( 'Changelog', 'aculect-ai-companion' ),
				'menu_title' => __( 'Changelog', 'aculect-ai-companion' ),
			),
		);

		return $tabs;
	}

	/**
	 * Determine whether the current admin screen belongs to this settings app.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 */
	private function is_settings_admin_screen( string $hook_suffix ): bool {
		if ( str_contains( $hook_suffix, 'aculect-ai-companion' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing flag.
		return isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( (string) $_GET['page'] ) );
	}

	/**
	 * Keep the WordPress Settings menu highlighted for tab subpages.
	 *
	 * @param string|null $parent_file Current parent file.
	 */
	public function highlight_parent_menu( ?string $parent_file ): ?string {
		return $this->is_current_settings_page() ? self::SETTINGS_PARENT_FILE : $parent_file;
	}

	/**
	 * Keep the AI Companion settings submenu highlighted for internal tabs.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 */
	public function highlight_submenu( ?string $submenu_file ): ?string {
		if ( ! $this->is_current_settings_page() ) {
			return $submenu_file;
		}

		return self::PAGE_SLUG;
	}

	/**
	 * Determine whether the current request is for the settings app.
	 */
	private function is_current_settings_page(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing flag.
		return isset( $_GET['page'] ) && self::PAGE_SLUG === sanitize_key( wp_unslash( (string) $_GET['page'] ) );
	}

	/**
	 * Return the normalized tab used for server-side payload hydration.
	 */
	private function current_payload_tab(): string {
		return $this->normalize_payload_tab( $this->requested_tab() );
	}

	/**
	 * Normalize a requested tab to a tab that can be server-hydrated.
	 *
	 * @param string $tab Requested tab.
	 */
	private function normalize_payload_tab( string $tab ): string {
		$normalized_tab = $this->normalize_requested_tab( $tab );

		return in_array( $normalized_tab, $this->payload_tabs(), true ) ? $normalized_tab : 'overview';
	}

	/**
	 * Return tabs that have complete data in the current localized payload.
	 *
	 * @param string $payload_tab Normalized payload tab.
	 * @return list<string>
	 */
	private function hydrated_tabs( string $payload_tab ): array {
		$tabs = array( 'overview', 'connect', 'diagnostics', 'advanced' );

		$tab_specific_payloads = array(
			'connections',
			'abilities',
			'activity',
			'links-map',
			'learning',
			'brand',
			'logs',
			'changelog',
		);

		if ( in_array( $payload_tab, $tab_specific_payloads, true ) ) {
			$tabs[] = $payload_tab;
		}

		return array_values( array_unique( $tabs ) );
	}

	/**
	 * Return every tab name that can be represented by localized data.
	 *
	 * @return list<string>
	 */
	private function payload_tabs(): array {
		return array_merge( array_column( $this->settings_tabs(), 'tab' ), array( 'brand', 'logs' ) );
	}

	/**
	 * Return the raw requested tab normalized for legacy aliases.
	 */
	private function requested_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab routing flag.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview';

		return $this->normalize_requested_tab( $tab );
	}

	/**
	 * Normalize legacy tab aliases.
	 *
	 * @param string $tab Requested tab.
	 */
	private function normalize_requested_tab( string $tab ): string {
		return match ( $tab ) {
			'about' => 'overview',
			'connectors' => 'connect',
			default => $tab,
		};
	}

	/**
	 * Return admin status flags from the current request.
	 */
	private function status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['advanced_saved'] ) ) {
			return 'advanced_saved';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['settings_imported'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
			return '1' === sanitize_key( wp_unslash( (string) $_GET['settings_imported'] ) ) ? 'settings_imported' : 'settings_import_failed';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['settings_reset'] ) ) {
			return 'settings_reset';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['brand_saved'] ) ) {
			return 'brand_saved';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['learning_reviewed'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
			return match ( sanitize_key( wp_unslash( (string) $_GET['learning_reviewed'] ) ) ) {
				'approved'     => 'learning_suggestion_approved',
				'dismissed'    => 'learning_suggestion_dismissed',
				'updated'      => 'learning_suggestion_updated',
				default        => 'learning_suggestion_not_updated',
			};
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['memory_reviewed'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
			return match ( sanitize_key( wp_unslash( (string) $_GET['memory_reviewed'] ) ) ) {
				'approve' => 'memory_approved',
				'dismiss' => 'memory_dismissed',
				'delete'  => 'memory_deleted',
				'update'  => 'memory_updated',
				default   => 'memory_not_updated',
			};
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['logs_cleared'] ) ) {
			return 'logs_cleared';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['access_lockdown'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
			return 'paused' === sanitize_key( wp_unslash( (string) $_GET['access_lockdown'] ) ) ? 'access_paused' : 'access_resumed';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['session_access_level'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
			return 'updated' === sanitize_key( wp_unslash( (string) $_GET['session_access_level'] ) ) ? 'session_access_level_updated' : 'session_access_level_not_updated';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['session_write_permission'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
			$status = sanitize_key( wp_unslash( (string) $_GET['session_write_permission'] ) );

			return match ( $status ) {
				'enabled'  => 'session_write_permission_enabled',
				'disabled' => 'session_write_permission_disabled',
				default    => 'session_write_permission_not_updated',
			};
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['diagnostics_run'] ) ) {
			return 'diagnostics_run';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['index_sweep_run'] ) ) {
			return 'index_sweep_run';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['abilities_saved'] ) ) {
			return 'abilities_saved';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['role_abilities_saved'] ) ) {
			return 'role_abilities_saved';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['role_abilities_not_enabled'] ) ) {
			return 'role_abilities_not_enabled';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['revoked_all'] ) ) {
			return 'revoked_all';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin notice flag.
		if ( isset( $_GET['revoked'] ) ) {
			return 'revoked';
		}

		return '';
	}

	/**
	 * Load the bundled changelog data.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function load_changelog(): array {
		if ( null !== self::$changelog_cache ) {
			return self::$changelog_cache;
		}

		$file = ACULECT_AI_COMPANION_PLUGIN_DIR . 'changelog.json';
		if ( ! file_exists( $file ) ) {
			self::$changelog_cache = array();
			return self::$changelog_cache;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file read.
		$json = file_get_contents( $file );
		if ( false === $json || '' === $json ) {
			self::$changelog_cache = array();
			return self::$changelog_cache;
		}

		$decoded               = json_decode( $json, true );
		self::$changelog_cache = is_array( $decoded ) ? $decoded : array();
		return self::$changelog_cache;
	}

	/**
	 * Return plugin metadata used by the changelog screen.
	 *
	 * @return array<string, string>
	 */
	private function plugin_metadata(): array {
		if ( null !== self::$plugin_metadata_cache ) {
			return self::$plugin_metadata_cache;
		}

		$plugin_data = function_exists( 'get_file_data' )
			? get_file_data(
				ACULECT_AI_COMPANION_PLUGIN_FILE,
				array(
					'version'         => 'Version',
					'requiresAtLeast' => 'Requires at least',
					'requiresPhp'     => 'Requires PHP',
				),
				'plugin'
			)
			: array();
		$readme_data = $this->readme_headers();

		self::$plugin_metadata_cache = array(
			'version'          => sanitize_text_field( (string) ( $plugin_data['version'] ?? ACULECT_AI_COMPANION_VERSION ) ),
			'requiresAtLeast'  => sanitize_text_field( (string) ( $plugin_data['requiresAtLeast'] ?? '' ) ),
			'requiresPhp'      => sanitize_text_field( (string) ( $plugin_data['requiresPhp'] ?? '' ) ),
			'testedUpTo'       => sanitize_text_field( (string) ( $readme_data['Tested up to'] ?? '' ) ),
			'stableTag'        => sanitize_text_field( (string) ( $readme_data['Stable tag'] ?? '' ) ),
			'documentationUrl' => esc_url_raw( 'https://wordpress.org/plugins/aculect-ai-companion/' ),
			'wordpressOrgUrl'  => esc_url_raw( 'https://wordpress.org/plugins/aculect-ai-companion/#developers' ),
			'supportUrl'       => esc_url_raw( 'https://wordpress.org/support/plugin/aculect-ai-companion/' ),
			'reviewUrl'        => esc_url_raw( 'https://wordpress.org/support/plugin/aculect-ai-companion/reviews/#new-post' ),
		);

		return self::$plugin_metadata_cache;
	}

	/**
	 * Parse readme headers without duplicating version requirements in JS.
	 *
	 * @return array<string, string>
	 */
	private function readme_headers(): array {
		if ( null !== self::$readme_headers_cache ) {
			return self::$readme_headers_cache;
		}

		$file = ACULECT_AI_COMPANION_PLUGIN_DIR . 'readme.txt';
		if ( ! file_exists( $file ) ) {
			self::$readme_headers_cache = array();
			return self::$readme_headers_cache;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file read.
		$contents = file_get_contents( $file );
		if ( false === $contents || '' === $contents ) {
			self::$readme_headers_cache = array();
			return self::$readme_headers_cache;
		}

		$headers = array();
		$lines   = preg_split( '/\R/', $contents );
		$lines   = false === $lines ? array() : $lines;

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line && array() !== $headers ) {
				break;
			}

			if ( str_starts_with( $line, '==' ) ) {
				if ( array() !== $headers ) {
					break;
				}

				continue;
			}

			if ( preg_match( '/^([^:]+):\s*(.+)$/', $line, $matches ) ) {
				$headers[ trim( $matches[1] ) ] = sanitize_text_field( trim( $matches[2] ) );
			}
		}

		self::$readme_headers_cache = $headers;
		return self::$readme_headers_cache;
	}

	/**
	 * Determine whether the settings page should render OAuth consent.
	 */
	private function is_oauth_consent_view(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing flag for the settings page.
		return isset( $_GET['view'] ) && 'oauth-consent' === sanitize_key( wp_unslash( (string) $_GET['view'] ) );
	}

	/**
	 * Require manage_options and verify the form nonce.
	 *
	 * @param string $nonce_action Expected nonce action.
	 */
	private function guard_action( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'aculect-ai-companion' ) );
		}

		check_admin_referer( $nonce_action );
	}
}

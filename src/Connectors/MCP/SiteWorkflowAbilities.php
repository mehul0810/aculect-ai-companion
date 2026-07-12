<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Connectors\Helpers;

/**
 * Read-only site management workflows.
 */
final class SiteWorkflowAbilities extends AbstractAbilityService {

	/**
	 * Return a bounded site audit for assistant planning.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function audit( array $args = array() ): array {
		unset( $args );

		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'status'  => 'error',
				'error'   => 'forbidden',
				'message' => 'You do not have permission to run the site audit workflow.',
			);
		}

		$site_info = ( new SiteAbilities() )->get_site_info();
		$health    = ( new SiteAbilities() )->get_site_health();
		if ( isset( $health['error'] ) ) {
			return array(
				'status'  => 'error',
				'error'   => (string) $health['error'],
				'message' => (string) ( $health['message'] ?? 'Site health information is unavailable.' ),
			);
		}

		$findings = array(
			$this->https_finding( $site_info ),
			$this->rest_api_finding( $site_info ),
			$this->permalinks_finding(),
			$this->environment_finding( $site_info ),
			$this->active_theme_finding( $site_info ),
			$this->updates_finding( $health ),
			$this->cron_finding(),
			$this->connector_finding(),
		);

		$next_actions = $this->next_actions( $findings );
		$tool_names   = array_values(
			array_unique(
				array_filter(
					array_merge(
						array_column( $findings, 'required_tool' ),
						array_column( $next_actions, 'tool' )
					)
				)
			)
		);

		return array(
			'status'            => 'ready',
			'workflow'          => ( new AbilitiesRegistry() )->tool_name( 'site_workflow.audit' ),
			'summary'           => $this->summary( $findings ),
			'findings'          => $findings,
			'operation_entries' => $this->operation_entries_for_tools( $tool_names ),
			'next_actions'      => $next_actions,
		);
	}

	/**
	 * Build an HTTPS finding.
	 *
	 * @param array<string, mixed> $site_info Site info payload.
	 * @return array<string, mixed>
	 */
	private function https_finding( array $site_info ): array {
		$using_https = function_exists( 'wp_is_using_https' ) ? wp_is_using_https() : str_starts_with( (string) ( $site_info['home_url'] ?? '' ), 'https://' );

		return $this->finding(
			'https',
			'HTTPS',
			$using_https ? 'healthy' : 'critical',
			array(
				'using_https' => $using_https,
				'home_url'    => (string) ( $site_info['home_url'] ?? '' ),
				'site_url'    => (string) ( $site_info['site_url'] ?? '' ),
			),
			$using_https ? 'Keep HTTPS enabled for OAuth and remote MCP clients.' : 'Enable HTTPS before relying on remote OAuth or MCP clients.',
			'site_get_info'
		);
	}

	/**
	 * Build a REST API finding.
	 *
	 * @param array<string, mixed> $site_info Site info payload.
	 * @return array<string, mixed>
	 */
	private function rest_api_finding( array $site_info ): array {
		$rest_url = (string) ( $site_info['rest_url'] ?? '' );

		return $this->finding(
			'rest_api',
			'REST API',
			'' === $rest_url ? 'critical' : 'healthy',
			array(
				'rest_url_present' => '' !== $rest_url,
			),
			'' === $rest_url ? 'Restore REST API availability before connecting assistant clients.' : 'REST API discovery is available for connector and site checks.',
			'site_get_info'
		);
	}

	/**
	 * Build a permalink finding.
	 *
	 * @return array<string, mixed>
	 */
	private function permalinks_finding(): array {
		$structure = (string) get_option( 'permalink_structure', '' );

		return $this->finding(
			'permalinks',
			'Permalinks',
			'' === $structure ? 'warning' : 'healthy',
			array(
				'pretty_permalinks' => '' !== $structure,
			),
			'' === $structure ? 'Configure pretty permalinks to reduce routing ambiguity for site and content URLs.' : 'Pretty permalinks are configured.',
			'site_get_settings'
		);
	}

	/**
	 * Build an environment finding.
	 *
	 * @param array<string, mixed> $site_info Site info payload.
	 * @return array<string, mixed>
	 */
	private function environment_finding( array $site_info ): array {
		$wordpress   = is_array( $site_info['wordpress'] ?? null ) ? $site_info['wordpress'] : array();
		$environment = (string) ( $wordpress['environment_type'] ?? 'production' );

		return $this->finding(
			'environment',
			'Environment',
			'production' === $environment ? 'healthy' : 'warning',
			array(
				'environment_type' => $environment,
				'wordpress'        => (string) ( $wordpress['version'] ?? '' ),
				'php'              => PHP_VERSION,
			),
			'production' === $environment ? 'Production environment is reported by WordPress.' : 'Confirm this is the intended non-production environment before making site-management decisions.',
			'site_get_info'
		);
	}

	/**
	 * Build an active theme finding.
	 *
	 * @param array<string, mixed> $site_info Site info payload.
	 * @return array<string, mixed>
	 */
	private function active_theme_finding( array $site_info ): array {
		$theme = is_array( $site_info['active_theme'] ?? null ) ? $site_info['active_theme'] : array();
		$name  = (string) ( $theme['name'] ?? '' );

		return $this->finding(
			'active_theme',
			'Active Theme',
			'' === $name ? 'critical' : 'healthy',
			array(
				'name'       => $name,
				'stylesheet' => (string) ( $theme['stylesheet'] ?? '' ),
				'template'   => (string) ( $theme['template'] ?? '' ),
				'version'    => (string) ( $theme['version'] ?? '' ),
			),
			'' === $name ? 'Inspect theme state before planning template or site-editor changes.' : 'Active theme metadata is available for planning.',
			'site_list_themes'
		);
	}

	/**
	 * Build an updates finding.
	 *
	 * @param array<string, mixed> $health Site health payload.
	 * @return array<string, mixed>
	 */
	private function updates_finding( array $health ): array {
		$counts = is_array( $health['checks']['updates']['counts'] ?? null )
			? $health['checks']['updates']['counts']
			: array(
				'core'    => 0,
				'plugins' => 0,
				'themes'  => 0,
				'total'   => 0,
			);
		$total  = (int) ( $counts['total'] ?? 0 );

		return $this->finding(
			'updates',
			'Cached Updates',
			0 === $total ? 'healthy' : 'warning',
			array(
				'core'    => (int) ( $counts['core'] ?? 0 ),
				'plugins' => (int) ( $counts['plugins'] ?? 0 ),
				'themes'  => (int) ( $counts['themes'] ?? 0 ),
				'total'   => $total,
			),
			0 === $total ? 'Cached update data does not report pending updates.' : 'Review available updates before planning maintenance or compatibility work.',
			'site_get_health'
		);
	}

	/**
	 * Build a cron finding.
	 *
	 * @return array<string, mixed>
	 */
	private function cron_finding(): array {
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$events   = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$next     = is_array( $events ) && array() !== $events ? min( array_map( 'intval', array_keys( $events ) ) ) : 0;

		if ( $disabled ) {
			$severity = 'warning';
			$action   = 'WP-Cron is disabled by constant; confirm an external cron runner is configured.';
		} elseif ( 0 === $next ) {
			$severity = 'warning';
			$action   = 'No scheduled cron events were visible; verify scheduled tasks and indexing jobs before relying on automation.';
		} else {
			$severity = 'healthy';
			$action   = 'Cron events are scheduled.';
		}

		return $this->finding(
			'cron',
			'Cron Signal',
			$severity,
			array(
				'disabled_by_constant' => $disabled,
				'next_event_gmt'       => 0 < $next ? gmdate( 'c', $next ) : '',
			),
			$action,
			'site_get_health'
		);
	}

	/**
	 * Build a connector readiness finding.
	 *
	 * @return array<string, mixed>
	 */
	private function connector_finding(): array {
		$endpoint = Helpers::mcp_resource();
		$scheme   = is_string( wp_parse_url( $endpoint, PHP_URL_SCHEME ) ) ? (string) wp_parse_url( $endpoint, PHP_URL_SCHEME ) : '';
		$severity = '' === $endpoint ? 'critical' : ( 'https' === $scheme ? 'healthy' : 'warning' );

		return $this->finding(
			'connector_readiness',
			'Connector Readiness',
			$severity,
			array(
				'mcp_endpoint_present' => '' !== $endpoint,
				'mcp_endpoint_scheme'  => $scheme,
				'abilities_api'        => function_exists( 'wp_get_abilities' ),
			),
			'https' === $scheme ? 'MCP endpoint is available over HTTPS.' : 'Use a reachable HTTPS MCP endpoint for remote assistant clients.',
			'site_get_info'
		);
	}

	/**
	 * Build one normalized audit finding.
	 *
	 * @param string               $id                 Stable finding ID.
	 * @param string               $title              Finding title.
	 * @param string               $severity           healthy, warning, or critical.
	 * @param array<string, mixed> $evidence           Bounded evidence.
	 * @param string               $recommended_action Recommended next action.
	 * @param string               $required_tool      Public MCP tool name.
	 * @return array<string, mixed>
	 */
	private function finding( string $id, string $title, string $severity, array $evidence, string $recommended_action, string $required_tool ): array {
		return array(
			'id'                 => $id,
			'title'              => $title,
			'severity'           => $severity,
			'evidence'           => $evidence,
			'recommended_action' => $recommended_action,
			'required_tool'      => $required_tool,
		);
	}

	/**
	 * Summarize audit findings.
	 *
	 * @param list<array<string, mixed>> $findings Audit findings.
	 * @return array<string, mixed>
	 */
	private function summary( array $findings ): array {
		$counts = array(
			'healthy'  => 0,
			'warning'  => 0,
			'critical' => 0,
		);
		foreach ( $findings as $finding ) {
			$severity            = (string) ( $finding['severity'] ?? 'warning' );
			$counts[ $severity ] = (int) ( $counts[ $severity ] ?? 0 ) + 1;
		}

		return array(
			'overall_severity' => 0 < $counts['critical'] ? 'critical' : ( 0 < $counts['warning'] ? 'warning' : 'healthy' ),
			'counts'           => $counts,
		);
	}

	/**
	 * Build recommended next actions.
	 *
	 * @param list<array<string, mixed>> $findings Audit findings.
	 * @return list<array<string, string>>
	 */
	private function next_actions( array $findings ): array {
		$actions = array();
		foreach ( $findings as $finding ) {
			if ( 'healthy' === (string) ( $finding['severity'] ?? '' ) ) {
				continue;
			}

			$actions[] = array(
				'finding' => (string) $finding['id'],
				'tool'    => (string) $finding['required_tool'],
				'action'  => (string) $finding['recommended_action'],
			);
		}

		return array() === $actions
			? array(
				array(
					'finding' => 'monitoring',
					'tool'    => 'site_get_health',
					'action'  => 'Continue monitoring site health before large maintenance workflows.',
				),
			)
			: $actions;
	}

	/**
	 * Return operations manifest entries for referenced public tool names.
	 *
	 * @param array<string> $tool_names Public MCP tool names.
	 * @return array<string, array<string, mixed>>
	 */
	private function operation_entries_for_tools( array $tool_names ): array {
		$wanted     = array_flip( $tool_names );
		$operations = ( new McpToolAvailability() )->operations_manifest_for_current_user();
		$entries    = array();

		foreach ( $operations as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			foreach ( $group as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$tool = (string) ( $entry['tool'] ?? '' );
				if ( isset( $wanted[ $tool ] ) ) {
					$entries[ $tool ] = $entry;
				}
			}
		}

		return $entries;
	}
}

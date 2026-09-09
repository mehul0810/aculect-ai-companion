<?php
/**
 * Connection-aware capability discovery.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Combines direct tools and native abilities without duplicating mirrors.
 */
final class CapabilityCatalog {

	/**
	 * Shared discovery input properties for the existing directory tool.
	 *
	 * @return array<string, mixed>
	 */
	public static function input_properties(): array {
		return array(
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 1000000,
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
			),
			'search'   => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'detail'   => array(
				'type'        => 'string',
				'enum'        => array( 'summary', 'full' ),
				'description' => 'Use summary for concise guidance or full for legacy operation details. Catalog entries are always paginated.',
			),
		);
	}

	/**
	 * Return a bounded page of capabilities available to the active connection.
	 *
	 * @param array<string, mixed> $args Pagination and search filters.
	 * @return array<string, mixed>
	 */
	public function discover( array $args = array() ): array {
		$page       = max( 1, min( 1000000, (int) ( $args['page'] ?? 1 ) ) );
		$per_page   = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );
		$search     = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$registry   = new AbilitiesRegistry();
		$policy     = ( new McpToolAvailability() )->ability_policy_for_current_user( $registry );
		$scopes     = ! empty( $policy['scope_aware'] ) ? (array) $policy['granted_scopes'] : null;
		$modules    = ( new McpToolAvailability() )->tool_modules_for_user( (int) $policy['user_id'], $registry, null, $scopes );
		$direct     = $this->direct_entries( $modules, $registry, $search );
		$offset     = ( $page - 1 ) * $per_page;
		$items      = array_slice( $direct, $offset, $per_page );
		$external   = array(
			'items' => array(),
			'total' => 0,
		);
		$can_browse = isset( $modules['wp_abilities.discover'] );
		if ( $can_browse ) {
			$external = $this->native_page( max( 0, $offset - count( $direct ) ), $per_page, $search );
			if ( $offset + $per_page > count( $direct ) ) {
				$items = array_merge( $items, array_slice( $external['items'], 0, $per_page - count( $items ) ) );
			}
		}
		$total = count( $direct ) + $external['total'];
		foreach ( $items as &$item ) {
			if ( 'native_ability' === $item['execution']['route'] ) {
				$item['requiredScopes'] = $registry->required_scopes( 'wp_abilities.run' );
				if ( ! isset( $modules['wp_abilities.run'] ) ) {
					$item['availability'] = 'execution_route_unavailable';
				}
			}
		}
		unset( $item );

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'has_more' => $offset + count( $items ) < $total,
			'guidance' => 'Use each entry execution tool. Native abilities require arguments validated by WordPress and its permission callback at execution. Third-party exposure is managed in Abilities; WordPress and Aculect capabilities are enabled by default subject to connection permissions.',
		);
	}

	/**
	 * Build direct entries from the authorized module set.
	 *
	 * @param array<string, AbilityModuleInterface> $modules Authorized modules.
	 * @param AbilitiesRegistry                     $registry Name resolver.
	 * @param string                                $search Text filter.
	 * @return list<array<string, mixed>>
	 */
	private function direct_entries( array $modules, AbilitiesRegistry $registry, string $search ): array {
		$items = array();
		foreach ( $modules as $module ) {
			if ( '' !== $search && ! str_contains( strtolower( $module->id() . ' ' . $module->title() . ' ' . $module->description() ), strtolower( $search ) ) ) {
				continue;
			}
			$items[] = array(
				'id'             => $module->id(),
				'title'          => $module->title(),
				'description'    => $module->description(),
				'provider'       => 'aculect-ai-companion',
				'category'       => $module->group(),
				'readOnly'       => $module->is_read_only(),
				'requiredScopes' => $module->required_scopes(),
				'availability'   => 'available',
				'execution'      => array(
					'tool'  => $registry->tool_name( $module->id() ),
					'route' => 'direct',
				),
			);
		}
		usort( $items, static fn( array $a, array $b ): int => strcmp( $a['id'], $b['id'] ) );
		return $items;
	}

	/**
	 * Read at most two bridge pages for an arbitrary combined-catalog offset.
	 *
	 * @param int    $offset Native entry offset.
	 * @param int    $limit Maximum entries.
	 * @param string $search Text filter.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 */
	private function native_page( int $offset, int $limit, string $search ): array {
		$bridge = new WordPressAbilitiesBridge();
		$page   = intdiv( $offset, $limit ) + 1;
		$result = $bridge->discover(
			array(
				'page'     => $page,
				'per_page' => $limit,
				'search'   => $search,
			)
		);
		$total  = (int) ( $result['total'] ?? 0 );
		$items  = array_slice( (array) ( $result['items'] ?? array() ), $offset % $limit );
		if ( count( $items ) < $limit && $page * $limit < $total ) {
			$next  = $bridge->discover(
				array(
					'page'     => $page + 1,
					'per_page' => $limit,
					'search'   => $search,
				)
			);
			$items = array_merge( $items, array_slice( (array) ( $next['items'] ?? array() ), 0, $limit - count( $items ) ) );
		}
		foreach ( $items as &$item ) {
			$item['provider']     = explode( '/', (string) $item['id'], 2 )[0];
			$item['availability'] = 'enabled_permission_checked_on_execution';
			$item['execution']    = array(
				'tool'      => 'wp_abilities_run',
				'route'     => 'native_ability',
				'arguments' => array( 'id' => $item['id'] ),
			);
		}
		unset( $item );
		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

/**
 * Keep server-hydrated settings tabs independent of the admin page controller.
 */
final class SettingsTabHydration {
	/**
	 * Return tabs fully hydrated by the initial settings payload.
	 *
	 * @param string $payload_tab Normalized requested tab.
	 * @return list<string>
	 */
	public function hydrated_tabs( string $payload_tab ): array {
		$tabs                  = array( 'overview', 'connect', 'diagnostics', 'advanced' );
		$tab_specific_payloads = array(
			'connections',
			'abilities',
			'activity',
			'learning',
			'skills',
			'brand',
			'logs',
			'changelog',
		);

		if ( in_array( $payload_tab, $tab_specific_payloads, true ) ) {
			$tabs[] = $payload_tab;
		}

		return array_values( array_unique( $tabs ) );
	}
}

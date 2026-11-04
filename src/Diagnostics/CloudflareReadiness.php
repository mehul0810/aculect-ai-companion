<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

/**
 * Keeps edge observations separate from the owner-run connector release gate.
 */
final class CloudflareReadiness {

	/**
	 * Summarize existing public probes without performing additional requests.
	 *
	 * @param array<string,mixed>|null ...$probes Public endpoint observations.
	 * @return array<string,mixed>
	 */
	public function item( ?array ...$probes ): array {
		$detected = false;
		$blocked  = false;
		$observed = 0;
		foreach ( $probes as $probe ) {
			if ( null === $probe ) {
				continue;
			}
			++$observed;
			$status   = (int) ( $probe['status'] ?? 0 );
			$detected = $detected || true === ( $probe['cloudflare_detected'] ?? false );
			$blocked  = $blocked || 0 === $status || in_array( $status, array( 403, 429 ), true ) || ! empty( $probe['edge_challenge'] );
		}
		return array(
			'id'       => 'cloudflare_connector',
			'status'   => $blocked ? 'externally_blocked' : 'warning',
			'message'  => 'Public endpoint observations cannot verify an OAuth connection or ongoing MCP traffic. Cloudflare compatibility requires owner-run proof of discovery, registration, consent, token exchange, refresh and authenticated calls on the approved hostname with its actual protections enabled.',
			'evidence' => array(
				'cloudflareDetected'            => $detected,
				'endpointBlocked'               => $blocked,
				'publicProbeObserved'           => 0 < $observed,
				'connectorReachabilityVerified' => false,
			),
		);
	}
}

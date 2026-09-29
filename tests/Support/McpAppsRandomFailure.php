<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Namespace fallback used to prove session signing fails closed on entropy errors.
 *
 * @package Aculect\AICompanion\Tests\Support
 * @param int $length Requested random byte count.
 * @throws \RuntimeException Simulated entropy failure.
 */
function random_bytes( int $length ): string {
	if ( ! empty( $GLOBALS['aculect_ai_companion_test_random_bytes_failure'] ) ) {
		throw new \RuntimeException( 'Test entropy source failure.' );
	}

	return \random_bytes( $length );
}

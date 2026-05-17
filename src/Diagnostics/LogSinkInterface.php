<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

/**
 * Receives normalized diagnostic log entries.
 */
interface LogSinkInterface {

	/**
	 * Persist one diagnostic log entry.
	 *
	 * @param array<string, mixed> $entry Log entry data.
	 */
	public function insert( array $entry ): bool;

	/**
	 * Prune expired diagnostic log entries.
	 *
	 * @param int $retention_days Retention window.
	 */
	public function prune( int $retention_days ): int;
}

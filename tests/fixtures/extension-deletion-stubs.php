<?php
/**
 * Injectable extension deletion doubles; these never access the filesystem.
 *
 * @package Aculect\AICompanion\Tests\Fixtures
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Fixtures;

use Aculect\AICompanion\Connectors\MCP\ExtensionDeletionAbilities;
use Aculect\AICompanion\Connectors\MCP\ExtensionDeletionPolicy;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Related no-filesystem test doubles share this fixture.

/**
 * Policy fixture with a fixed target and mutable current binding.
 */
final class ExtensionDeletionPolicyStub extends ExtensionDeletionPolicy {
	/**
	 * Current resolved plan.
	 *
	 * @var array<string, mixed>
	 */
	public array $plan = array();

	/**
	 * Whether the target still exists after the injected delete call.
	 *
	 * @var bool
	 */
	public bool $installed = false;

	/**
	 * Return the configured plan without inspecting any real WordPress paths.
	 *
	 * @param string $kind       Extension kind.
	 * @param mixed  $raw_target Requested extension identifier.
	 * @return array<string, mixed>
	 */
	public function inspect( string $kind, mixed $raw_target ): array {
		unset( $kind, $raw_target );
		return $this->plan;
	}

	/**
	 * Report only injected post-delete state.
	 *
	 * @param string $kind   Extension kind.
	 * @param string $target Extension identifier.
	 */
	public function is_installed( string $kind, string $target ): bool {
		unset( $kind, $target );
		return $this->installed;
	}
}

/**
 * Deletion ability double that records calls instead of invoking WordPress core.
 */
final class ExtensionDeletionAbilitiesStub extends ExtensionDeletionAbilities {
	/**
	 * Number of attempted core deletions.
	 *
	 * @var int
	 */
	public int $delete_calls = 0;

	/**
	 * Core result returned to the service.
	 *
	 * @var mixed
	 */
	public mixed $delete_result = true;

	/**
	 * Whether the simulated core call throws.
	 *
	 * @var bool
	 */
	public bool $throw_on_delete = false;

	/**
	 * Mark the injected no-op adapter as available.
	 *
	 * @param string $kind Extension kind.
	 */
	protected function core_delete_available( string $kind ): bool {
		unset( $kind );
		return true;
	}

	/**
	 * Record the fixed target without changing filesystem state.
	 *
	 * @param string $kind    Extension kind.
	 * @param string $target  Extension identifier.
	 * @param string $context Filesystem context, unused by the fixture.
	 * @return mixed
	 * @throws \RuntimeException Simulated failure after entering the core delete call.
	 */
	protected function delete_core( string $kind, string $target, string $context ): mixed {
		unset( $kind, $target, $context );
		++$this->delete_calls;
		if ( $this->throw_on_delete ) {
			throw new \RuntimeException( 'Fixture failure must never escape to the response.' );
		}
		return $this->delete_result;
	}
}

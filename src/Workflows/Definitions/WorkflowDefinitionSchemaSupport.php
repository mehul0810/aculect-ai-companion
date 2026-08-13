<?php
/**
 * Workflow definition schema-version support policy.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use InvalidArgumentException;

/**
 * Reports the truthful current/current-minus-one compatibility window.
 */
final readonly class WorkflowDefinitionSchemaSupport {

	public const CURRENT           = 'current';
	public const PREVIOUS          = 'previous';
	public const UNSUPPORTED_OLDER = 'unsupported_older';
	public const UNSUPPORTED_NEWER = 'unsupported_newer';

	/**
	 * Create a schema support policy.
	 *
	 * @param int $current Current schema version.
	 * @throws InvalidArgumentException When the current version is not positive.
	 */
	public function __construct( private int $current = WorkflowDefinitionSchema::VERSION ) {
		if ( $current < 1 ) {
			throw new InvalidArgumentException( 'Current workflow schema version must be positive.' );
		}
	}

	/**
	 * Return the current schema version.
	 */
	public function current(): int {
		return $this->current;
	}

	/**
	 * Return the previous supported version, or null before v2 exists.
	 */
	public function previous(): ?int {
		return $this->current > 1 ? $this->current - 1 : null;
	}

	/**
	 * Return exact supported versions newest first.
	 *
	 * @return list<int>
	 */
	public function supported_versions(): array {
		$previous = $this->previous();

		return null === $previous ? array( $this->current ) : array( $this->current, $previous );
	}

	/**
	 * Classify one schema version relative to the support window.
	 *
	 * @param int $version Schema version to classify.
	 * @throws InvalidArgumentException When the candidate version is not positive.
	 */
	public function classify( int $version ): string {
		if ( $version < 1 ) {
			throw new InvalidArgumentException( 'Workflow schema version must be positive.' );
		}

		if ( $version === $this->current ) {
			return self::CURRENT;
		}
		if ( $version === $this->previous() ) {
			return self::PREVIOUS;
		}

		return $version < $this->current ? self::UNSUPPORTED_OLDER : self::UNSUPPORTED_NEWER;
	}

	/**
	 * Return whether one version is in the exact support window.
	 *
	 * @param int $version Schema version to inspect.
	 */
	public function supports( int $version ): bool {
		if ( $version < 1 ) {
			return false;
		}

		return in_array( $version, $this->supported_versions(), true );
	}
}

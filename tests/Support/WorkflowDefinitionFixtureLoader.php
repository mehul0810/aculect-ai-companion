<?php
/**
 * Test-only rooted workflow definition fixture loader.
 *
 * @package Aculect\AICompanion\Tests\Support
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Support;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionSchema;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionSchemaSupport;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionValidationException;
use Closure;
use JsonException;
use stdClass;

/**
 * Loads bounded JSON fixtures without permitting filesystem traversal.
 */
final class WorkflowDefinitionFixtureLoader {

	private readonly string $root;
	private readonly ?Closure $before_open;

	/**
	 * Create a rooted fixture loader.
	 *
	 * @param string|null  $root        Explicit fixture root for isolated tests.
	 * @param Closure|null $before_open Test-only hook invoked after lstat and before fopen.
	 * @throws WorkflowDefinitionFixtureException When the root is invalid.
	 */
	public function __construct( ?string $root = null, ?Closure $before_open = null ) {
		$root           = $root ?? dirname( __DIR__ ) . '/fixtures/workflows/definitions';
		$root_for_check = rtrim( $root, '/\\' );
		if ( '' === $root_for_check ) {
			$root_for_check = DIRECTORY_SEPARATOR;
		}
		if ( is_link( $root_for_check ) ) {
			throw new WorkflowDefinitionFixtureException( 'invalid_fixture_root' );
		}

		$real = realpath( $root_for_check );
		if ( false === $real || ! is_dir( $real ) ) {
			throw new WorkflowDefinitionFixtureException( 'invalid_fixture_root' );
		}

		$this->root        = $real;
		$this->before_open = $before_open;
	}

	/**
	 * Load one supported workflow definition fixture.
	 *
	 * @param string $name Fixture basename.
	 * @throws WorkflowDefinitionFixtureException When loading fails.
	 */
	public function load( string $name ): WorkflowDefinition {
		$file = $this->resolve( $name );
		$json = $this->read( $file );

		try {
			$decoded = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new WorkflowDefinitionFixtureException( 'malformed_fixture' );
		}

		if ( ! $decoded instanceof stdClass || ! is_int( $decoded->definition_schema_version ?? null ) ) {
			throw new WorkflowDefinitionFixtureException( 'malformed_fixture' );
		}

		if ( ! ( new WorkflowDefinitionSchemaSupport() )->supports( $decoded->definition_schema_version ) ) {
			throw new WorkflowDefinitionFixtureException( 'unsupported_fixture_version' );
		}

		try {
			return WorkflowDefinition::from_json( $json );
		} catch ( WorkflowDefinitionValidationException ) {
			throw new WorkflowDefinitionFixtureException( 'invalid_fixture_definition' );
		}
	}

	/**
	 * Load the exact fixture manifest as detached JSON data.
	 *
	 * @return array<string, mixed>
	 * @throws WorkflowDefinitionFixtureException When manifest loading fails.
	 */
	public function manifest(): array {
		$json = $this->read( $this->resolve( 'manifest.json' ) );

		try {
			$manifest = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new WorkflowDefinitionFixtureException( 'malformed_fixture' );
		}

		if ( ! is_array( $manifest ) || array_is_list( $manifest ) ) {
			throw new WorkflowDefinitionFixtureException( 'malformed_fixture' );
		}

		return $manifest;
	}

	/**
	 * Return JSON definition basenames in deterministic order.
	 *
	 * @return list<string>
	 */
	public function definition_files(): array {
		$files = glob( $this->root . '/*.json' );
		if ( false === $files ) {
			return array();
		}

		$names = array_values(
			array_filter(
				array_map( 'basename', $files ),
				static fn ( string $name ): bool => 'manifest.json' !== $name
			)
		);
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * Resolve one safe JSON basename beneath the fixed root.
	 *
	 * @param string $name Fixture basename.
	 * @throws WorkflowDefinitionFixtureException When resolution fails.
	 */
	private function resolve( string $name ): string {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]*\.json$/', $name ) ) {
			throw new WorkflowDefinitionFixtureException( str_ends_with( strtolower( $name ), '.json' ) ? 'invalid_fixture_path' : 'invalid_fixture_extension' );
		}

		return $this->root . '/' . $name;
	}

	/**
	 * Read one bounded, readable fixture.
	 *
	 * @param string $file Rooted fixture path.
	 * @throws WorkflowDefinitionFixtureException When reading fails.
	 */
	private function read( string $file ): string {
		clearstatcache( true, $file );
		$path_stat = @lstat( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing fixtures are converted to a stable loader error.
		if ( false === $path_stat ) {
			throw new WorkflowDefinitionFixtureException( 'fixture_not_found' );
		}
		if ( $this->is_symlink( $path_stat ) ) {
			throw new WorkflowDefinitionFixtureException( 'fixture_symlink_not_allowed' );
		}
		if ( ! $this->is_regular_file( $path_stat ) ) {
			throw new WorkflowDefinitionFixtureException( 'fixture_not_found' );
		}
		if ( 0 === ( $path_stat['mode'] & 0444 ) ) {
			throw new WorkflowDefinitionFixtureException( 'fixture_unreadable' );
		}

		if ( null !== $this->before_open ) {
			( $this->before_open )( $file );
		}

		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Test-only descriptor-bound read converts open failures to a stable error.
		if ( false === $handle ) {
			throw new WorkflowDefinitionFixtureException( 'fixture_unreadable' );
		}

		try {
			$descriptor_stat = fstat( $handle );
			if ( false === $descriptor_stat || ! $this->is_regular_file( $descriptor_stat ) ) {
				throw new WorkflowDefinitionFixtureException( 'fixture_unreadable' );
			}
			if ( ! $this->is_same_file( $path_stat, $descriptor_stat ) ) {
				throw new WorkflowDefinitionFixtureException( 'fixture_changed_during_open' );
			}
			if ( $descriptor_stat['size'] > WorkflowDefinitionSchema::MAX_ENCODED_BYTES ) {
				throw new WorkflowDefinitionFixtureException( 'fixture_too_large' );
			}

			$json  = '';
			$limit = WorkflowDefinitionSchema::MAX_ENCODED_BYTES + 1;
			while ( ! feof( $handle ) ) {
				$remaining = $limit - strlen( $json );
				if ( $remaining <= 0 ) {
					throw new WorkflowDefinitionFixtureException( 'fixture_too_large' );
				}

				$chunk = fread( $handle, min( 8192, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Test-only bounded descriptor read.
				if ( false === $chunk || ( '' === $chunk && ! feof( $handle ) ) ) {
					throw new WorkflowDefinitionFixtureException( 'fixture_unreadable' );
				}
				$json .= $chunk;
				if ( strlen( $json ) > WorkflowDefinitionSchema::MAX_ENCODED_BYTES ) {
					throw new WorkflowDefinitionFixtureException( 'fixture_too_large' );
				}
			}

			$post_read_stat = fstat( $handle );
			if ( false === $post_read_stat || $post_read_stat['size'] > WorkflowDefinitionSchema::MAX_ENCODED_BYTES ) {
				throw new WorkflowDefinitionFixtureException( 'fixture_too_large' );
			}

			return $json;
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test-only descriptor cleanup.
		}
	}

	/**
	 * Determine whether a stat result describes a regular file.
	 *
	 * @param array<int|string, int> $stat Stat result.
	 */
	private function is_regular_file( array $stat ): bool {
		return isset( $stat['mode'] ) && 0100000 === ( $stat['mode'] & 0170000 );
	}

	/**
	 * Determine whether a stat result describes a symbolic link.
	 *
	 * @param array<int|string, int> $stat Stat result.
	 */
	private function is_symlink( array $stat ): bool {
		return isset( $stat['mode'] ) && 0120000 === ( $stat['mode'] & 0170000 );
	}

	/**
	 * Compare path and descriptor identities when the platform exposes them.
	 *
	 * @param array<int|string, int> $path_stat       Pre-open path stat.
	 * @param array<int|string, int> $descriptor_stat Open descriptor stat.
	 */
	private function is_same_file( array $path_stat, array $descriptor_stat ): bool {
		foreach ( array( 'dev', 'ino' ) as $key ) {
			if ( isset( $path_stat[ $key ], $descriptor_stat[ $key ] ) && $path_stat[ $key ] !== $descriptor_stat[ $key ] ) {
				return false;
			}
		}

		return true;
	}
}

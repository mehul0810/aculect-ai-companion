<?php
/**
 * Tests for the rooted workflow-definition fixture corpus.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Definitions;

use Aculect\AICompanion\Tests\Support\WorkflowDefinitionFixtureException;
use Aculect\AICompanion\Tests\Support\WorkflowDefinitionFixtureLoader;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionCompatibilityMetadata;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionSchema;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionSchemaSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/WorkflowDefinitionFixtureException.php';
require_once dirname( __DIR__, 3 ) . '/Support/WorkflowDefinitionFixtureLoader.php';

/**
 * Verifies fixture loading is rooted, bounded, complete, and deterministic.
 */
final class WorkflowDefinitionFixtureLoaderTest extends TestCase {

	public function test_manifest_pins_every_supported_fixture_exactly_once(): void {
		$loader   = new WorkflowDefinitionFixtureLoader();
		$manifest = $loader->manifest();
		$files    = array_column( $manifest['fixtures'], 'file' );

		self::assertSame( 1, $manifest['manifest_version'] );
		self::assertSame( $loader->definition_files(), $files );
		self::assertSame( count( $files ), count( array_unique( $files ) ) );

		foreach ( $manifest['fixtures'] as $entry ) {
			$definition = $loader->load( $entry['file'] );
			$metadata   = ( new WorkflowDefinitionCompatibilityMetadata() )->for_definition( $definition );

			self::assertSame( $entry['workflow_id'], $metadata['workflow_id'] );
			self::assertSame( $entry['workflow_version'], $metadata['workflow_version'] );
			self::assertSame( $entry['checksum'], $definition->checksum() );
			self::assertSame( $entry['metadata'], $metadata );
			self::assertSame(
				$entry['classification'],
				( new WorkflowDefinitionSchemaSupport() )->classify( $metadata['definition_schema_version'] )
			);
		}
	}

	public function test_two_v1_fixtures_cover_minimal_and_ordered_representative_shapes(): void {
		$loader         = new WorkflowDefinitionFixtureLoader();
		$proposal       = $loader->load( 'proposal-only-v1.json' )->to_array();
		$representative = $loader->load( 'ordered-multi-step-v1.json' )->to_array();

		self::assertSame( 'proposal_only', $proposal['write_policy']->mode );
		self::assertCount( 1, $proposal['steps'] );
		self::assertSame( array( 'read', 'proposal', 'write' ), array_map( static fn ( object $step ): string => $step->kind, $representative['steps'] ) );
		self::assertSame( array( 'create_draft' ), $representative['approval_gates'] );
	}

	/**
	 * Verify one unsafe fixture name.
	 *
	 * @param string $name          Unsafe fixture name.
	 * @param string $expected_code Expected error code.
	 */
	#[DataProvider( 'unsafe_name_provider' )]
	public function test_loader_rejects_unsafe_paths_and_extensions( string $name, string $expected_code ): void {
		$this->expect_fixture_error( $expected_code, static fn (): mixed => ( new WorkflowDefinitionFixtureLoader() )->load( $name ) );
	}

	/**
	 * Return unsafe fixture names and expected codes.
	 *
	 * @return iterable<string, array{name:string,expected_code:string}>
	 */
	public static function unsafe_name_provider(): iterable {
		yield 'traversal' => array(
			'name'          => '../proposal-only-v1.json',
			'expected_code' => 'invalid_fixture_path',
		);
		yield 'nested traversal' => array(
			'name'          => 'nested/../proposal-only-v1.json',
			'expected_code' => 'invalid_fixture_path',
		);
		yield 'absolute' => array(
			'name'          => '/tmp/proposal-only-v1.json',
			'expected_code' => 'invalid_fixture_path',
		);
		yield 'windows absolute' => array(
			'name'          => 'C:\\fixture.json',
			'expected_code' => 'invalid_fixture_path',
		);
		yield 'wrong extension' => array(
			'name'          => 'proposal-only-v1.txt',
			'expected_code' => 'invalid_fixture_extension',
		);
		yield 'missing' => array(
			'name'          => 'missing.json',
			'expected_code' => 'fixture_not_found',
		);
	}

	public function test_loader_rejects_symlink_unreadable_oversized_malformed_and_unsupported_files(): void {
		$root = $this->temporary_root();
		try {
			$valid = dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/proposal-only-v1.json';
			self::assertTrue( copy( $valid, $root . '/valid.json' ) );
			self::assertTrue( symlink( $root . '/valid.json', $root . '/linked.json' ) );
			$this->expect_fixture_error( 'fixture_symlink_not_allowed', static fn (): mixed => ( new WorkflowDefinitionFixtureLoader( $root ) )->load( 'linked.json' ) );

			self::assertNotFalse( file_put_contents( $root . '/unreadable.json', '{}' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated test fixture.
			self::assertTrue( chmod( $root . '/unreadable.json', 0200 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Isolated test fixture.
			$this->expect_fixture_error( 'fixture_unreadable', static fn (): mixed => ( new WorkflowDefinitionFixtureLoader( $root ) )->load( 'unreadable.json' ) );

			self::assertNotFalse( file_put_contents( $root . '/large.json', str_repeat( 'x', WorkflowDefinitionSchema::MAX_ENCODED_BYTES + 1 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated test fixture.
			$this->expect_fixture_error( 'fixture_too_large', static fn (): mixed => ( new WorkflowDefinitionFixtureLoader( $root ) )->load( 'large.json' ) );

			self::assertNotFalse( file_put_contents( $root . '/malformed.json', '{invalid' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated test fixture.
			$this->expect_fixture_error( 'malformed_fixture', static fn (): mixed => ( new WorkflowDefinitionFixtureLoader( $root ) )->load( 'malformed.json' ) );

			$unsupported                              = json_decode( file_get_contents( $valid ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned local fixture.
			$unsupported['definition_schema_version'] = 2;
			self::assertNotFalse( file_put_contents( $root . '/unsupported.json', json_encode( $unsupported, JSON_THROW_ON_ERROR ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Isolated JSON fixture.
			$this->expect_fixture_error( 'unsupported_fixture_version', static fn (): mixed => ( new WorkflowDefinitionFixtureLoader( $root ) )->load( 'unsupported.json' ) );
		} finally {
			$this->remove_directory( $root );
		}
	}

	public function test_loader_rejects_invalid_definition_and_invalid_or_symlinked_roots(): void {
		$root = $this->temporary_root();
		try {
			self::assertNotFalse( file_put_contents( $root . '/invalid.json', '{"definition_schema_version":1}' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Isolated test fixture.
			$this->expect_fixture_error( 'invalid_fixture_definition', static fn (): mixed => ( new WorkflowDefinitionFixtureLoader( $root ) )->load( 'invalid.json' ) );

			$link = $root . '-link';
			self::assertTrue( symlink( $root, $link ) );
			$this->expect_fixture_error( 'invalid_fixture_root', static fn (): mixed => new WorkflowDefinitionFixtureLoader( $link ) );
			$this->expect_fixture_error( 'invalid_fixture_root', static fn (): mixed => new WorkflowDefinitionFixtureLoader( $link . '/' ) );
			$this->expect_fixture_error( 'invalid_fixture_root', static fn (): mixed => new WorkflowDefinitionFixtureLoader( $link . '//' ) );
			unlink( $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated test symlink cleanup.
			$this->expect_fixture_error( 'invalid_fixture_root', static fn (): mixed => new WorkflowDefinitionFixtureLoader( $root . '/missing' ) );
		} finally {
			$this->remove_directory( $root );
		}
	}

	public function test_loader_rejects_fixture_swapped_between_lstat_and_open(): void {
		$root = $this->temporary_root();
		try {
			$valid = dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/proposal-only-v1.json';
			self::assertTrue( copy( $valid, $root . '/swapped.json' ) );
			self::assertTrue( copy( $valid, $root . '/replacement.tmp' ) );

			$loader = new WorkflowDefinitionFixtureLoader(
				$root,
				static function ( string $file ) use ( $root ): void {
					self::assertTrue( rename( $root . '/replacement.tmp', $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Simulates an atomic path swap after lstat.
				}
			);

			$this->expect_fixture_error( 'fixture_changed_during_open', static fn (): mixed => $loader->load( 'swapped.json' ) );
		} finally {
			$this->remove_directory( $root );
		}
	}

	public function test_manifest_rejects_path_swap_between_lstat_and_open(): void {
		$root = $this->temporary_root();
		try {
			$manifest = dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/manifest.json';
			self::assertTrue( copy( $manifest, $root . '/manifest.json' ) );
			self::assertTrue( copy( $manifest, $root . '/replacement.tmp' ) );

			$loader = new WorkflowDefinitionFixtureLoader(
				$root,
				static function ( string $file ) use ( $root ): void {
					self::assertTrue( rename( $root . '/replacement.tmp', $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Simulates an atomic path swap after lstat.
				}
			);

			$this->expect_fixture_error( 'fixture_changed_during_open', static fn (): mixed => $loader->manifest() );
		} finally {
			$this->remove_directory( $root );
		}
	}

	public function test_loader_rejects_file_that_grows_beyond_limit_before_open(): void {
		$root = $this->temporary_root();
		try {
			$valid = dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/proposal-only-v1.json';
			self::assertTrue( copy( $valid, $root . '/growing.json' ) );

			$loader = new WorkflowDefinitionFixtureLoader(
				$root,
				static function ( string $file ): void {
					self::assertNotFalse( file_put_contents( $file, str_repeat( 'x', WorkflowDefinitionSchema::MAX_ENCODED_BYTES + 1 ), FILE_APPEND ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Simulates file growth after lstat.
				}
			);

			$this->expect_fixture_error( 'fixture_too_large', static fn (): mixed => $loader->load( 'growing.json' ) );
		} finally {
			$this->remove_directory( $root );
		}
	}

	public function test_distribution_excludes_test_fixture_corpus(): void {
		$distignore = file_get_contents( dirname( __DIR__, 4 ) . '/.distignore' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned local file.
		self::assertNotFalse( $distignore );
		self::assertMatchesRegularExpression( '/^tests\/$/m', $distignore );
	}

	/**
	 * Assert a callback fails with one fixture-loader code.
	 *
	 * @param string   $expected_code Expected code.
	 * @param callable $callback      Callback to invoke.
	 */
	private function expect_fixture_error( string $expected_code, callable $callback ): void {
		try {
			$callback();
			self::fail( 'Expected fixture loading to fail.' );
		} catch ( WorkflowDefinitionFixtureException $exception ) {
			self::assertSame( $expected_code, $exception->error_code() );
		}
	}

	/**
	 * Create an isolated fixture root.
	 */
	private function temporary_root(): string {
		$root = sys_get_temp_dir() . '/aculect-workflow-fixtures-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $root, 0755, true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Isolated test fixture.

		return $root;
	}

	/**
	 * Remove an isolated fixture root.
	 *
	 * @param string $root Root directory.
	 */
	private function remove_directory( string $root ): void {
		if ( ! is_dir( $root ) ) {
			return;
		}
		$files = glob( $root . '/*' );
		foreach ( false === $files ? array() : $files as $file ) {
			if ( is_link( $file ) || is_file( $file ) ) {
				chmod( $file, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Isolated test cleanup.
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Isolated test cleanup.
			}
		}
		rmdir( $root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Isolated test cleanup.
	}
}

#!/usr/bin/env php
<?php
/**
 * Verify that a prepared plugin package contains production files only.
 *
 * @package Aculect_AI_Companion
 */

declare(strict_types=1);

$root_dir    = dirname( __DIR__ );
$release_dir = $argv[1] ?? $root_dir . '/release';
$release_dir = rtrim( (string) $release_dir, '/\\' );

if ( '' === $release_dir || ! is_dir( $release_dir ) ) {
	fwrite( STDERR, "Release directory does not exist: {$release_dir}\n" );
	exit( 1 );
}

$failures = array();

$forbidden_paths = array(
	'.codex',
	'.distignore',
	'.editorconfig',
	'.git',
	'.github',
	'.gitignore',
	'.nvmrc',
	'AGENTS.md',
	'CONTRIBUTING.md',
	'bin',
	'composer.lock',
	'eslint.config.cjs',
	'node_modules',
	'package-lock.json',
	'package.json',
	'phpcs.xml.dist',
	'phpstan-bootstrap.php',
	'phpstan.neon.dist',
	'phpunit.xml.dist',
	'tests',
	'vendor/bin',
);

foreach ( $forbidden_paths as $path ) {
	if ( file_exists( $release_dir . '/' . $path ) ) {
		$failures[] = "Development-only path is present: {$path}";
	}
}

$forbidden_file_names = array(
	'build-phar.sh',
	'build_phar.php',
	'phpstan.neon',
	'phpstan.neon.dist',
	'phpunit.xml',
	'phpunit.xml.dist',
	'psalm.xml',
);

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $release_dir, FilesystemIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
		continue;
	}

	$file_name = $file->getFilename();
	if ( in_array( $file_name, $forbidden_file_names, true ) ) {
		$relative_path = ltrim( str_replace( $release_dir, '', $file->getPathname() ), '/\\' );
		$failures[]    = "Development-only file is present: {$relative_path}";
	}
}

$root_composer_file      = $root_dir . '/composer.json';
$installed_composer_file = $release_dir . '/vendor/composer/installed.json';

if ( file_exists( $root_composer_file ) && file_exists( $installed_composer_file ) ) {
	$root_composer = json_decode( (string) file_get_contents( $root_composer_file ), true );
	$installed     = json_decode( (string) file_get_contents( $installed_composer_file ), true );

	if ( is_array( $root_composer ) && is_array( $installed ) ) {
		$dev_packages       = array_keys( $root_composer['require-dev'] ?? array() );
		$installed_packages = $installed['packages'] ?? $installed;
		$installed_names    = array();

		if ( is_array( $installed_packages ) ) {
			foreach ( $installed_packages as $package ) {
				if ( is_array( $package ) && isset( $package['name'] ) ) {
					$installed_names[] = (string) $package['name'];
				}
			}
		}

		$shipped_dev_packages = array_values( array_intersect( $dev_packages, $installed_names ) );
		if ( array() !== $shipped_dev_packages ) {
			$failures[] = 'Composer require-dev packages are present: ' . implode( ', ', $shipped_dev_packages );
		}
	}
}

if ( array() !== $failures ) {
	fwrite( STDERR, "Production package verification failed:\n" );
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "- {$failure}\n" );
	}
	exit( 1 );
}

echo "Production package verification passed.\n";

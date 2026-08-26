<?php

declare(strict_types=1);

/**
 * Check repository modularity budgets and dependency boundaries.
 *
 * This checker is deliberately dependency-free so it can run before Composer
 * installation. Legacy exceptions are visible in every report and form a
 * ratchet: a file may not exceed its recorded ceiling.
 */

$root = dirname( __DIR__ );
$config_path = '.codex/modularity-rules.php';
$changed_from = null;

foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( str_starts_with( $argument, '--config=' ) ) {
		$config_path = substr( $argument, 9 );
	} elseif ( str_starts_with( $argument, '--changed-from=' ) ) {
		$changed_from = substr( $argument, 15 );
	}
}

$config_file = $root . '/' . ltrim( $config_path, '/' );
if ( ! is_file( $config_file ) ) {
	fwrite( STDERR, "Modularity config not found: {$config_path}\n" );
	exit( 2 );
}

$config = require $config_file;
if ( ! is_array( $config ) ) {
	fwrite( STDERR, "Modularity config must return an array.\n" );
	exit( 2 );
}

$exceptions = array();
foreach ( (array) ( $config['exceptions'] ?? array() ) as $exception ) {
	if ( ! is_array( $exception ) || ! isset( $exception['path'], $exception['max_lines'], $exception['owner'], $exception['reason'], $exception['issue'], $exception['target'] ) ) {
		fwrite( STDERR, "Every modularity exception must define path, max_lines, owner, reason, issue and target.\n" );
		exit( 2 );
	}

	$exceptions[ (string) $exception['path'] ] = $exception;
}

$files = array();
foreach ( array( 'production' => (array) ( $config['production_roots'] ?? array() ), 'tests' => (array) ( $config['test_roots'] ?? array() ) ) as $kind => $paths ) {
	foreach ( $paths as $path ) {
		$absolute = $root . '/' . ltrim( (string) $path, '/' );
		if ( is_file( $absolute ) ) {
			$files[ $kind ][ (string) $path ] = $absolute;
			continue;
		}

		if ( ! is_dir( $absolute ) ) {
			fwrite( STDERR, "Modularity root not found: {$path}\n" );
			exit( 2 );
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $absolute, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( (string) $file->getExtension() );
			if ( ! in_array( $extension, array( 'php', 'js', 'mjs', 'scss' ), true ) ) {
				continue;
			}

			$relative = str_replace( $root . '/', '', $file->getPathname() );
			$files[ $kind ][ $relative ] = $file->getPathname();
		}
	}
}

$violations = array();
$legacy = array();
$reviews = array();
$budgets = (array) ( $config['budgets'] ?? array() );
$method_exceptions = (array) ( $config['method_exceptions'] ?? array() );

foreach ( $files as $kind => $kind_files ) {
	$budget = (array) ( $budgets[ $kind ] ?? array() );
	foreach ( $kind_files as $relative => $absolute ) {
		$lines = count( file( $absolute, FILE_IGNORE_NEW_LINES ) ?: array() );
		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
		$exception = $exceptions[ $relative ] ?? null;
		$hard_limit = (int) ( $budget['line_hard'] ?? 1200 );

		if ( is_array( $exception ) ) {
			if ( $lines > (int) $exception['max_lines'] ) {
				$violations[] = sprintf( '%s has grown beyond its exception ceiling (%d > %d).', $relative, $lines, (int) $exception['max_lines'] );
			}

			if ( null !== $changed_from ) {
				$base_lines = git_file_line_count( $root, $changed_from, $relative );
				if ( null !== $base_lines && $lines > $base_lines ) {
					$violations[] = sprintf( '%s grew on %s (%d > %d); split the hotspot or update its exception deliberately.', $relative, $changed_from, $lines, $base_lines );
				}
			}

			$legacy[] = sprintf( '%s (%d lines; target <= %d; owner: %s; issue: %s)', $relative, $lines, (int) $exception['target'], (string) $exception['owner'], (string) $exception['issue'] );
		} elseif ( $lines > $hard_limit ) {
			$violations[] = sprintf( '%s exceeds the %s-line hard limit (%d > %d).', $relative, $kind, $lines, $hard_limit );
		} elseif ( $lines > (int) ( $budget['line_review'] ?? $hard_limit ) ) {
			$reviews[] = sprintf( '%s is above the %s-line review threshold (%d > %d).', $relative, $kind, $lines, (int) $budget['line_review'] );
		}

		if ( 'php' === $extension ) {
			$method_limit = (int) ( $budget['method_hard'] ?? 120 );
			$file_method_exceptions = (array) ( $method_exceptions[ $relative ] ?? array() );
			foreach ( php_method_lengths( $absolute ) as $method => $method_lines ) {
				$allowed_method_limit = isset( $file_method_exceptions[ $method ] )
					? (int) $file_method_exceptions[ $method ]
					: $method_limit;
				if ( $method_lines > $allowed_method_limit ) {
					$violations[] = sprintf( '%s::%s exceeds its method ceiling (%d > %d).', $relative, $method, $method_lines, $allowed_method_limit );
				} elseif ( $method_lines > (int) ( $budget['method_review'] ?? $method_limit ) && ! isset( $file_method_exceptions[ $method ] ) ) {
					$reviews[] = sprintf( '%s::%s is above the method review threshold (%d > %d).', $relative, $method, $method_lines, (int) $budget['method_review'] );
				}
			}
		}
	}
}

foreach ( (array) ( $config['dependency_rules'] ?? array() ) as $rule ) {
	$root_path = (string) ( $rule['root'] ?? '' );
	$root_absolute = $root . '/' . $root_path;
	if ( '' === $root_path || ! is_dir( $root_absolute ) ) {
		continue;
	}

	$exceptions_by_path = (array) ( $rule['exceptions'] ?? array() );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root_absolute, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() || 'php' !== strtolower( (string) $file->getExtension() ) ) {
			continue;
		}

		$relative = str_replace( $root . '/', '', $file->getPathname() );
		if ( isset( $exceptions_by_path[ $relative ] ) ) {
			continue;
		}

		$contents = (string) file_get_contents( $file->getPathname() );
		foreach ( (array) ( $rule['forbidden'] ?? array() ) as $forbidden ) {
			if ( str_contains( $contents, (string) $forbidden ) ) {
				$violations[] = sprintf( '%s imports forbidden dependency %s.', $relative, $forbidden );
			}
		}
	}
}

echo "Modularity report\n";
echo "=================\n";
if ( array() === $legacy ) {
	echo "No legacy exceptions.\n";
} else {
	echo "Legacy hotspots (ratcheted ceilings):\n";
	foreach ( $legacy as $item ) {
		echo "- {$item}\n";
	}
}

if ( array() !== $reviews ) {
	echo "Review items:\n";
	foreach ( array_values( array_unique( $reviews ) ) as $review ) {
		echo "- {$review}\n";
	}
}

if ( array() === $violations ) {
	echo "Result: PASS\n";
	exit( 0 );
}

echo "Violations:\n";
foreach ( array_values( array_unique( $violations ) ) as $violation ) {
	echo "- {$violation}\n";
}
echo "Result: FAIL\n";
exit( 1 );

/**
 * Return line counts for PHP methods/functions using the tokenizer.
 *
 * @return array<string, int>
 */
function php_method_lengths( string $path ): array {
	$tokens = token_get_all( (string) file_get_contents( $path ) );
	$pending = false;
	$pending_name = 'anonymous';
	$pending_line = 1;
	$brace_depth = 0;
	$active = array();
	$result = array();
	$current_line = 1;

	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			$current_line = (int) $token[2];
			if ( T_FUNCTION === $token[0] ) {
				$pending = true;
				$pending_name = 'anonymous';
				$pending_line = $current_line;
			} elseif ( $pending && T_STRING === $token[0] && 'anonymous' === $pending_name ) {
				$pending_name = (string) $token[1];
			}
			continue;
		}

		if ( '{' === $token ) {
			++$brace_depth;
			if ( $pending ) {
				$active[] = array(
					'name'  => $pending_name,
					'depth' => $brace_depth,
					'line'  => $pending_line,
				);
				$pending = false;
			}
		} elseif ( '}' === $token ) {
			if ( array() !== $active && $brace_depth === $active[ count( $active ) - 1 ]['depth'] ) {
				$method = array_pop( $active );
				$name = (string) $method['name'];
				$result[ $name ] = max( $result[ $name ] ?? 0, $current_line - (int) $method['line'] + 1 );
			}
			$brace_depth = max( 0, $brace_depth - 1 );
		}

		$current_line += substr_count( $token, "\n" );
	}

	return $result;
}

/**
 * Resolve a file's line count from a git revision.
 */
function git_file_line_count( string $root, string $revision, string $path ): ?int {
	$command = sprintf( 'git -C %s show %s:%s 2>/dev/null', escapeshellarg( $root ), escapeshellarg( $revision ), escapeshellarg( $path ) );
	$output = array();
	$status = 0;
	exec( $command, $output, $status );
	return 0 === $status ? count( $output ) : null;
}

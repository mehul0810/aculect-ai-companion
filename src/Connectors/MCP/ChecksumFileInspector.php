<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Reads only regular manifest-listed files within a fixed installed root.
 */
final class ChecksumFileInspector {

	private int $remaining_bytes = 26214400;

	public function __construct( private readonly string $root ) {}

	/**
	 * Reject traversal, absolute paths, wrappers and control bytes.
	 *
	 * @param string $path Manifest-relative path.
	 */
	public static function valid_path( string $path ): bool {
		return '' !== $path && strlen( $path ) <= 500 && 1 === preg_match( '//u', $path )
			&& ! str_starts_with( $path, '/' ) && ! preg_match( '/[\x00-\x1f\x7f\\\\:]/', $path )
			&& ! in_array( '..', explode( '/', $path ), true )
			&& ! in_array( '.', explode( '/', $path ), true )
			&& ! in_array( '', explode( '/', $path ), true );
	}

	/**
	 * Hash one bounded regular file; return relative paths and outcomes only.
	 *
	 * @param string $path Manifest-relative path.
	 * @param string $expected Official MD5 digest.
	 * @return array<string,string>
	 */
	public function inspect( string $path, string $expected ): array {
		$result = array(
			'path'   => $path,
			'result' => 'unreadable',
		);
		if ( ! self::valid_path( $path ) || ! preg_match( '/^[a-f0-9]{32}$/Di', $expected ) ) {
			$result['result'] = 'unsafe_path';
			return $result;
		}
		$root = realpath( $this->root );
		if ( false === $root || is_link( rtrim( $this->root, '/' ) ) ) {
			return $result;
		}
		$file = $root;
		foreach ( explode( '/', $path ) as $segment ) {
			$file .= '/' . $segment;
			if ( is_link( $file ) ) {
				$result['result'] = 'symlink_skipped';
				return $result;
			}
		}
		if ( ! file_exists( $file ) ) {
			$result['result'] = 'missing';
			return $result;
		}
		$resolved = realpath( $file );
		if ( false === $resolved || ! str_starts_with( $resolved, $root . '/' ) || ! is_file( $resolved ) || ! is_readable( $resolved ) ) {
			return $result;
		}
		$size = filesize( $resolved );
		if ( false === $size || $size > 5242880 || $size > $this->remaining_bytes ) {
			$result['result'] = 'size_limit_skipped';
			return $result;
		}
		// A capped read also bounds a file that grows after the stat call.
		$limit = min( 5242880, $this->remaining_bytes );
		$bytes = file_get_contents( $resolved, false, null, 0, $limit + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local integrity check, never a URL.
		if ( false !== $bytes && strlen( $bytes ) <= $limit ) {
			$this->remaining_bytes -= strlen( $bytes );
			$result['result']       = hash_equals( strtolower( $expected ), md5( $bytes ) ) ? 'matches' : 'modified';
		}
		return $result;
	}
}

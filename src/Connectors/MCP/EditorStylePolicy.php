<?php
/**
 * Finite theme.json override paths, without arbitrary CSS or file writes.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Validates one scalar global style or layout override. */
final class EditorStylePolicy {

	public const PATHS = array(
		'styles.color.text',
		'styles.color.background',
		'styles.typography.fontSize',
		'styles.typography.lineHeight',
		'styles.typography.fontStyle',
		'styles.typography.fontWeight',
		'styles.spacing.blockGap',
		'styles.spacing.padding.top',
		'styles.spacing.padding.right',
		'styles.spacing.padding.bottom',
		'styles.spacing.padding.left',
		'settings.layout.contentSize',
		'settings.layout.wideSize',
	);

	/**
	 * Reject arbitrary expressions, URLs, custom CSS and unknown paths.
	 *
	 * @param mixed $path Fixed override path.
	 * @param mixed $value Scalar candidate or null to remove the override.
	 */
	public function valid( mixed $path, mixed $value ): bool {
		if ( ! is_string( $path ) || ! in_array( $path, self::PATHS, true ) ) {
			return false;
		}
		if ( null === $value ) {
			return true;
		}
		if ( ! is_string( $value ) || strlen( $value ) > 64 ) {
			return false;
		}
		if ( str_starts_with( $path, 'styles.color.' ) ) {
			return 1 === preg_match( '/\A#(?:[a-fA-F0-9]{3}|[a-fA-F0-9]{4}|[a-fA-F0-9]{6}|[a-fA-F0-9]{8})\z/', $value );
		}
		if ( 'styles.typography.fontStyle' === $path ) {
			return in_array( $value, array( 'normal', 'italic' ), true );
		}
		if ( 'styles.typography.fontWeight' === $path ) {
			return 1 === preg_match( '/\A(?:normal|bold|[1-9]00)\z/', $value );
		}
		if ( 'styles.typography.lineHeight' === $path ) {
			return 1 === preg_match( '/\A(?:[1-3](?:\.[0-9]{1,2})?|4(?:\.0{1,2})?)\z/', $value );
		}
		if ( '0' === $value ) {
			return true;
		}
		if ( 1 !== preg_match( '/\A((?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,2})?)(px|rem|em|%)\z/', $value, $parts ) ) {
			return false;
		}
		$limits = array(
			'px'  => 3000,
			'rem' => 200,
			'em'  => 200,
			'%'   => 100,
		);
		return (float) $parts[1] <= $limits[ $parts[2] ];
	}

	/**
	 * Read only allowlisted scalar overrides, not arbitrary theme.json data.
	 *
	 * @param array<string,mixed> $config User theme.json data.
	 * @return array<string,mixed>
	 */
	public function values( array $config ): array {
		$result = array();
		foreach ( self::PATHS as $path ) {
			$value = $config;
			foreach ( explode( '.', $path ) as $key ) {
				$value = is_array( $value ) ? ( $value[ $key ] ?? null ) : null;
			}
			$result[ $path ] = is_string( $value ) || is_numeric( $value ) ? $value : null;
		}
		return $result;
	}

	/**
	 * Restore only finite overrides, never unrelated historical CSS or settings.
	 *
	 * @param array<string,mixed> $current Current native style record.
	 * @param array<string,mixed> $historical Selected native style revision.
	 * @throws \InvalidArgumentException When either record has a malformed path.
	 */
	public function restorable( array $current, array $historical ): bool {
		foreach ( self::PATHS as $path ) {
			$value = $this->path_value( $historical, $path );
			if ( $value !== $this->path_value( $current, $path ) && ! $this->recoverable( $historical, $path ) ) {
				return false;
			}
			$current    = $this->apply( $current, $path, null );
			$historical = $this->apply( $historical, $path, null );
		}
		return $this->normalized( $current ) === $this->normalized( $historical );
	}

	/**
	 * Preserve safe native preset references when undoing a supported override.
	 *
	 * @param array<string,mixed> $config Existing native user styles.
	 * @param string              $path Validated finite override path.
	 */
	public function recoverable( array $config, string $path ): bool {
		$value = $this->path_value( $config, $path );
		if ( $this->valid( $path, is_int( $value ) || is_float( $value ) ? (string) $value : $value ) ) {
			return true;
		}
		$category = str_starts_with( $path, 'styles.color.' ) ? 'color' : ( 'styles.typography.fontSize' === $path ? 'font-size' : ( str_starts_with( $path, 'styles.spacing.' ) ? 'spacing' : '' ) );
		return '' !== $category && is_string( $value ) && strlen( $value ) <= 100 && 1 === preg_match( '/\Avar:preset\|' . $category . '\|[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value );
	}

	/**
	 * Read one finite path without exposing unrelated data.
	 *
	 * @param array<string,mixed> $config Native style map.
	 * @param string              $path Fixed path.
	 * @return mixed
	 */
	private function path_value( array $config, string $path ): mixed {
		$value = $config;
		foreach ( explode( '.', $path ) as $key ) {
			$value = is_array( $value ) ? ( $value[ $key ] ?? null ) : null;
		}
		return $value;
	}

	/**
	 * Compare maps independent of property ordering and empty containers.
	 *
	 * @param array<string,mixed> $config Bounded native JSON map.
	 * @return array<string,mixed>
	 */
	private function normalized( array $config ): array {
		foreach ( $config as $key => $value ) {
			if ( is_array( $value ) ) {
				$config[ $key ] = $this->normalized( $value );
				if ( array() === $config[ $key ] ) {
					unset( $config[ $key ] );
				}
			}
		}
		ksort( $config );
		return $config;
	}

	/**
	 * Apply one validated path while preserving unrelated user data.
	 *
	 * @param array<string,mixed> $config Existing user theme.json data.
	 * @param string              $path Validated fixed path.
	 * @param string|null         $value Validated value or removal.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When a path crosses malformed existing data.
	 */
	public function apply( array $config, string $path, ?string $value ): array {
		$keys   = explode( '.', $path );
		$leaf   = array_pop( $keys );
		$cursor = &$config;
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $cursor ) && ! is_array( $cursor[ $key ] ) ) {
				if ( null === $value ) {
					return $config;
				}
				throw new \InvalidArgumentException( 'Style path crosses a non-object value.' );
			}
			if ( ! array_key_exists( $key, $cursor ) ) {
				if ( null === $value ) {
					return $config;
				}
				$cursor[ $key ] = array();
			}
			$cursor = &$cursor[ $key ];
		}
		if ( null === $value ) {
			unset( $cursor[ $leaf ] );
		} else {
			$cursor[ $leaf ] = $value;
		}
		return $config;
	}
}

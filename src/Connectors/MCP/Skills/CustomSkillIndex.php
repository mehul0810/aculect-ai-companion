<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Skills;

/**
 * Bounds and serializes the site-local custom Skill index.
 */
final class CustomSkillIndex {

	public const MAX_SKILLS = 100;

	private const INDEX_OPTION       = 'aculect_ai_companion_custom_mcp_skill_index';
	private const INDEX_LOCK_OPTION  = 'aculect_ai_companion_custom_mcp_skill_lock';
	private const INDEX_LOCK_SECONDS = 15;

	private CustomSkillOptionStore $options;
	private CustomSkillValidator $validator;

	public function __construct( CustomSkillOptionStore $options, CustomSkillValidator $validator ) {
		$this->options   = $options;
		$this->validator = $validator;
	}

	/**
	 * Read a bounded index and retain only records that pass owner validation.
	 *
	 * @param \Closure $is_valid_record Validator supplied by the record owner.
	 * @phpstan-param \Closure(string):bool $is_valid_record Validator supplied by the record owner.
	 * @return list<string>
	 */
	public function ids( \Closure $is_valid_record ): array {
		$stored = $this->options->get( self::INDEX_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$ids      = array();
		$examined = 0;
		foreach ( $stored as $id ) {
			if ( 2 * self::MAX_SKILLS <= $examined || self::MAX_SKILLS <= count( $ids ) ) {
				break;
			}
			++$examined;
			if ( is_string( $id ) && $this->validator->valid_id( $id ) && $is_valid_record( $id ) ) {
				$ids[] = $id;
			}
		}

		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_STRING );
		return $ids;
	}

	/**
	 * Claim the per-site index mutex.
	 */
	public function acquire_lock(): ?string {
		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return null;
		}

		$existing = $this->options->get( self::INDEX_LOCK_OPTION, null );
		if ( is_array( $existing ) && is_int( $existing['time'] ?? null ) && time() - $existing['time'] > self::INDEX_LOCK_SECONDS ) {
			$this->options->delete( self::INDEX_LOCK_OPTION );
		}

		if ( ! $this->options->add(
			self::INDEX_LOCK_OPTION,
			array(
				'token' => $token,
				'time'  => time(),
			)
		) ) {
			return null;
		}

		return $token;
	}

	/**
	 * Release this caller's mutex token.
	 *
	 * @param string $token Lock token.
	 */
	public function release_lock( string $token ): void {
		$lock = $this->options->get( self::INDEX_LOCK_OPTION, null );
		if ( is_array( $lock ) && hash_equals( (string) ( $lock['token'] ?? '' ), $token ) ) {
			$this->options->delete( self::INDEX_LOCK_OPTION );
		}
	}

	/**
	 * Update and verify the index while the caller holds the lock.
	 *
	 * @param string   $id Custom Skill ID.
	 * @param bool     $add Whether to add or remove the ID.
	 * @param \Closure $is_valid_record Validator supplied by the record owner.
	 * @phpstan-param \Closure(string):bool $is_valid_record Validator supplied by the record owner.
	 */
	public function write_locked( string $id, bool $add, \Closure $is_valid_record ): bool {
		$ids = $this->ids( $is_valid_record );
		$ids = $add ? array_merge( $ids, array( $id ) ) : array_values( array_diff( $ids, array( $id ) ) );
		$ids = array_values( array_unique( $ids ) );
		sort( $ids, SORT_STRING );
		if ( ! $this->options->update( self::INDEX_OPTION, $ids ) && $ids !== $this->options->get( self::INDEX_OPTION, array() ) ) {
			return false;
		}

		return in_array( $id, $this->options->get( self::INDEX_OPTION, array() ), true ) === $add;
	}
}

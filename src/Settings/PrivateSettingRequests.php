<?php
/**
 * User-bound private input requests. No input values enter request metadata.
 *
 * @package Aculect\AICompanion\Settings
 */

declare(strict_types=1);
namespace Aculect\AICompanion\Settings;

/** One active request per administrator, with a ten-minute lifetime. */
final class PrivateSettingRequests {
	private const META = '_aculect_private_setting_request';
	public function __construct( private readonly PrivateSettingTargets $targets = new PrivateSettingTargets() ) {}

	/**
	 * Prepare input without accepting a value.
	 *
	 * @param string $target Fixed target identifier.
	 * @return array<string,mixed>
	 */
	public function begin( string $target ): array {
		if ( ! current_user_can( 'manage_options' ) || ! is_ssl() || ! $this->targets->available( $target ) ) {
			return $this->failure( 'unavailable' );
		}
		$record = array(
			'id'       => bin2hex( random_bytes( 16 ) ),
			'blog_id'  => get_current_blog_id(),
			'target'   => $target,
			'expires'  => time() + 600,
			'status'   => 'pending',
			'expected' => $this->state( $target ),
		);
		if ( ! update_user_meta( get_current_user_id(), self::META, $record ) ) {
			return $this->failure( 'request_failed' );
		}
		return array(
			'status'     => 'input_required',
			'request_id' => $record['id'],
			'expires_in' => 600,
			'form_url'   => add_query_arg(
				array(
					'action'     => 'aculect_private_setting',
					'request_id' => $record['id'],
				),
				admin_url( 'admin-post.php', 'https' )
			),
			'message'    => 'Open the WordPress form yourself. Enter the value there, never in chat or a tool call. Only status is returned to the assistant.',
		);
	}

	/**
	 * Find this administrator's live request.
	 *
	 * @param string $id Request identifier.
	 * @return array<string,mixed>
	 */
	public function lookup( string $id ): array {
		if ( ! current_user_can( 'manage_options' ) || ! preg_match( '/^[a-f0-9]{32}$/', $id ) ) {
			return array();
		}
		$record = get_user_meta( get_current_user_id(), self::META, true );
		return is_array( $record ) && ( $record['blog_id'] ?? null ) === get_current_blog_id() && hash_equals( (string) ( $record['id'] ?? '' ), $id ) && (int) ( $record['expires'] ?? 0 ) > time() ? $record : array();
	}

	/**
	 * Return a value-free outcome.
	 *
	 * @param string $id Request identifier.
	 * @return array<string,mixed>
	 */
	public function status( string $id ): array {
		$record = $this->lookup( $id );
		return array() === $record ? $this->failure( 'request_unavailable' ) : array(
			'status'     => $record['status'],
			'target'     => $record['target'],
			'request_id' => $record['id'],
		);
	}

	/** Browser-only entry point; callers must verify the session-bound nonce first.
	 *
	 * @param string $id Request identifier.
	 * @param mixed  $value Private browser input.
	 *
	 * @return array<string,mixed>
	 */
	public function submit( string $id, mixed $value ): array {
		$record = $this->lookup( $id );
		if ( ! is_ssl() || array() === $record || 'pending' !== $record['status'] || ! is_string( $value ) ) {
			return $this->failure( 'request_unavailable' );
		}
		$claimed = array_merge( $record, array( 'status' => 'processing' ) );
		// WordPress compares serialized previous metadata in SQL: one submit wins.
		if ( ! update_user_meta( get_current_user_id(), self::META, $claimed, $record ) ) {
			return $this->failure( 'request_unavailable' );
		}
		$status = 'failed';
		$lock   = new PrivateSettingLock();
		try {
			if ( ! $lock->acquire( $record['target'] ) ) {
				$status = 'busy';
			} elseif ( ! hash_equals( $record['expected'], $this->state( $record['target'] ) ) ) {
				$status = 'stale';
			} else {
				$validated = $this->targets->validate( $record['target'], $value );
				if ( null !== $validated && $lock->valid() && $this->still_current( $id, $claimed ) ) {
					$target = $this->targets->get( $record['target'] );
					$option = $target['option'] ?? '';
					if ( (string) get_option( $option, '' ) === (string) $validated || update_option( $option, $validated, false ) ) {
						$status = (string) get_option( $option, '' ) === (string) $validated ? 'updated' : 'failed';
					}
				}
			}
		} catch ( \Throwable ) {
			$status = 'failed';
		} finally {
			$lock->release();
		}
		// Compare-and-set prevents an older request from replacing a new one.
		update_user_meta( get_current_user_id(), self::META, array_merge( $claimed, array( 'status' => $status ) ), $claimed );
		return array( 'status' => $status );
	}

	/**
	 * Recheck expiry, identity and target state after provider validation.
	 *
	 * @param string              $id Request identifier.
	 * @param array<string,mixed> $claimed Claimed request.
	 */
	private function still_current( string $id, array $claimed ): bool {
		return $claimed === $this->lookup( $id ) && hash_equals( $claimed['expected'], $this->state( $claimed['target'] ) );
	}

	/** Read current state; option hooks and other writers may change it between calls.
	 *
	 * @param string $id Fixed target identifier.
	 * @phpstan-impure
	 */
	private function state( string $id ): string {
		$target = $this->targets->get( $id );
		return hash_hmac( 'sha256', (string) get_option( $target['option'] ?? '', '' ), wp_salt( 'auth' ) );
	}

	/**
	 * Return sanitized recovery guidance.
	 *
	 * @param string $code Error code.
	 * @return array<string,mixed>
	 */
	private function failure( string $code ): array {
		return array(
			'status'  => 'error',
			'error'   => $code,
			'message' => 'Use an HTTPS WordPress administrator session and request a fresh form for a supported setting.',
		);
	}
}

<?php
/**
 * Encrypted bounded payload storage for durable workflow runs.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

use Aculect\AICompanion\Connectors\OAuth\Server\SecretsVault;
use RuntimeException;

/**
 * Keeps workflow input and step results encrypted at rest.
 *
 * This wrapper deliberately exposes only JSON strings. Callers must validate
 * their own value contracts before sealing, and decryption never returns a
 * partially decoded value.
 */
final class WorkflowPayloadVault {

	public const MAX_BYTES = 262144;

	/**
	 * Encrypt one bounded JSON document.
	 *
	 * @param string $json Canonical JSON payload.
	 * @return string Versioned ciphertext.
	 * @throws RuntimeException When the payload or vault is unavailable.
	 */
	public function seal( string $json ): string {
		if ( '' === $json || strlen( $json ) > self::MAX_BYTES ) {
			throw new RuntimeException( 'Workflow payload exceeds the bounded storage contract.' );
		}

		$encrypted = SecretsVault::encrypt( $json );
		if ( '' === $encrypted ) {
			throw new RuntimeException( 'Encrypted workflow payload storage is unavailable.' );
		}

		return $encrypted;
	}

	/**
	 * Decrypt one bounded JSON document.
	 *
	 * @param string $ciphertext Versioned ciphertext.
	 * @return string Plaintext JSON.
	 * @throws RuntimeException When the ciphertext cannot be decrypted safely.
	 */
	public function open( string $ciphertext ): string {
		if ( '' === $ciphertext || ! SecretsVault::is_encrypted( $ciphertext ) ) {
			throw new RuntimeException( 'Workflow payload is not encrypted.' );
		}

		$json = SecretsVault::decrypt( $ciphertext );
		if ( '' === $json || strlen( $json ) > self::MAX_BYTES ) {
			throw new RuntimeException( 'Workflow payload cannot be decrypted safely.' );
		}

		return $json;
	}
}

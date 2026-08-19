<?php
/**
 * Bounded internal workflow adapter result.
 *
 * @package Aculect\AICompanion\Workflows\Adapters
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Adapters;

use Aculect\AICompanion\Workflows\Planning\WorkflowPlanningCanonicalizer;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanningException;
use stdClass;

/**
 * Carries only a closed code and an optional detached, bounded output object.
 *
 * Raw arguments, authentication context, and exception messages never enter
 * this value.
 */
final readonly class WorkflowAdapterResult {

	public const CODE_SUCCESS                   = 'success';
	public const CODE_STEP_NOT_FOUND            = 'step_not_found';
	public const CODE_ADAPTER_NOT_REGISTERED    = 'adapter_not_registered';
	public const CODE_STEP_CONTRACT_MISMATCH    = 'step_contract_mismatch';
	public const CODE_ABILITY_CONTRACT_MISMATCH = 'ability_contract_mismatch';
	public const CODE_INVALID_ARGUMENTS         = 'invalid_arguments';
	public const CODE_GATEWAY_REJECTED          = 'gateway_rejected';
	public const CODE_ABILITY_FAILED            = 'ability_failed';
	public const CODE_OUTPUT_NOT_AVAILABLE      = 'output_not_available';
	public const CODE_EXECUTION_NOT_AVAILABLE   = 'execution_not_available';

	private const FAILURE_CODES = array(
		self::CODE_STEP_NOT_FOUND,
		self::CODE_ADAPTER_NOT_REGISTERED,
		self::CODE_STEP_CONTRACT_MISMATCH,
		self::CODE_ABILITY_CONTRACT_MISMATCH,
		self::CODE_INVALID_ARGUMENTS,
		self::CODE_GATEWAY_REJECTED,
		self::CODE_ABILITY_FAILED,
		self::CODE_OUTPUT_NOT_AVAILABLE,
		self::CODE_EXECUTION_NOT_AVAILABLE,
	);

	/**
	 * Create a closed result value.
	 *
	 * @param string   $code   Stable internal code.
	 * @param stdClass $output Detached bounded output object.
	 */
	private function __construct(
		private string $code,
		private stdClass $output = new stdClass()
	) {
	}

	/**
	 * Create a success result after bounding and detaching output.
	 *
	 * @param array<string, mixed> $output Ability output.
	 * @throws WorkflowPlanningException When output is not bounded JSON data.
	 */
	public static function success( array $output ): self {
		$canonicalizer = new WorkflowPlanningCanonicalizer();
		$detached      = $canonicalizer->normalize_and_encode( (object) $output )['value'];
		if ( ! $detached instanceof stdClass ) {
			throw new WorkflowPlanningException( 'non_json_input', '$' );
		}

		return new self( self::CODE_SUCCESS, $detached );
	}

	/**
	 * Create one of the closed failure results.
	 *
	 * @param string $code Closed failure code.
	 */
	public static function failure( string $code ): self {
		if ( ! in_array( $code, self::FAILURE_CODES, true ) ) {
			$code = self::CODE_EXECUTION_NOT_AVAILABLE;
		}

		return new self( $code, new stdClass() );
	}

	/**
	 * Whether execution produced a bounded output.
	 */
	public function succeeded(): bool {
		return self::CODE_SUCCESS === $this->code;
	}

	/**
	 * Return the closed result code.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Return a detached output object matching the adapter output schema.
	 */
	public function output(): stdClass {
		$copy = ( new WorkflowPlanningCanonicalizer() )->copy( $this->output );

		return $copy instanceof stdClass ? $copy : new stdClass();
	}

	/**
	 * Return a bounded internal status envelope.
	 *
	 * Empty successful output remains a JSON object (`{}`), never a list (`[]`).
	 *
	 * @return array{status:string,code:string,output?:stdClass}
	 */
	public function to_array(): array {
		$result = array(
			'status' => $this->succeeded() ? 'succeeded' : 'failed',
			'code'   => $this->code,
		);
		if ( $this->succeeded() ) {
			$result['output'] = $this->output();
		}

		return $result;
	}
}

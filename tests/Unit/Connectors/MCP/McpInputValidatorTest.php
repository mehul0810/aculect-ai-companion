<?php
/**
 * Tests for bounded MCP request and argument validation.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpInputValidator;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * Verifies resource budgets and schema limits fail before tool execution.
 */
final class McpInputValidatorTest extends TestCase {

	public function test_rejects_content_length_above_raw_body_limit(): void {
		$request = new WP_REST_Request(
			array(),
			array( 'content-length' => (string) ( McpInputValidator::max_body_bytes() + 1 ) )
		);

		self::assertSame(
			'request_body_too_large',
			( new McpInputValidator() )->request_error( $request )['code'] ?? ''
		);
	}

	public function test_rejects_actual_body_above_raw_body_limit(): void {
		$request = new WP_REST_Request(
			array(),
			array(),
			array(),
			'POST',
			'/aculect-ai-companion/v1/mcp',
			str_repeat( 'x', McpInputValidator::max_body_bytes() + 1 )
		);

		self::assertSame(
			'request_body_too_large',
			( new McpInputValidator() )->request_error( $request )['code'] ?? ''
		);
	}

	public function test_rejects_schema_string_and_collection_limits(): void {
		$validator = new McpInputValidator();
		$schema    = array(
			'type'                 => 'object',
			'properties'           => array(
				'content' => array(
					'type'      => 'string',
					'maxLength' => 5,
				),
				'ids'     => array(
					'type'     => 'array',
					'maxItems' => 2,
					'items'    => array(
						'type' => 'integer',
					),
				),
			),
			'required'             => array( 'content' ),
			'additionalProperties' => false,
		);

		self::assertSame(
			'argument_string_too_large',
			$validator->arguments_error( array( 'content' => '123456' ), $schema )['code'] ?? ''
		);
		self::assertSame(
			'argument_collection_too_large',
			$validator->arguments_error(
				array(
					'content' => 'valid',
					'ids'     => array( 1, 2, 3 ),
				),
				$schema
			)['code'] ?? ''
		);
		self::assertSame(
			'unexpected_argument',
			$validator->arguments_error(
				array(
					'content' => 'valid',
					'unknown' => true,
				),
				$schema
			)['code'] ?? ''
		);
	}

	public function test_rejects_invalid_types_and_missing_required_arguments(): void {
		$validator = new McpInputValidator();
		$schema    = array(
			'type'       => 'object',
			'properties' => array(
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 10,
				),
			),
			'required'   => array( 'limit' ),
		);

		self::assertSame(
			'missing_required_argument',
			$validator->arguments_error( array(), $schema )['code'] ?? ''
		);
		self::assertSame(
			'invalid_argument_type',
			$validator->arguments_error( array( 'limit' => '5' ), $schema )['code'] ?? ''
		);
		self::assertSame(
			'argument_number_too_large',
			$validator->arguments_error( array( 'limit' => 11 ), $schema )['code'] ?? ''
		);
		self::assertNull( $validator->arguments_error( array( 'limit' => 5 ), $schema ) );
	}

	public function test_rejects_excessive_argument_depth(): void {
		$value = 'leaf';
		for ( $depth = 0; $depth < 18; ++$depth ) {
			$value = array( 'nested' => $value );
		}

		self::assertSame(
			'argument_depth_exceeded',
			( new McpInputValidator() )->arguments_error(
				array( 'value' => $value ),
				array(
					'type'                 => 'object',
					'additionalProperties' => true,
				)
			)['code'] ?? ''
		);
	}

	public function test_rejects_oversized_property_names_without_reflecting_them(): void {
		$oversized_key = str_repeat( 'sensitive', 32 );
		$error         = ( new McpInputValidator() )->arguments_error(
			array( $oversized_key => true ),
			array(
				'type'                 => 'object',
				'additionalProperties' => false,
			)
		);

		self::assertSame( 'argument_key_too_large', $error['code'] ?? '' );
		self::assertLessThan( 200, strlen( $error['message'] ?? '' ) );
		self::assertStringNotContainsString( $oversized_key, $error['message'] ?? '' );
	}

	public function test_rejects_global_collection_and_complexity_budgets(): void {
		$validator = new McpInputValidator();
		$schema    = array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);

		self::assertSame(
			'argument_collection_too_large',
			$validator->arguments_error(
				array( 'items' => array_fill( 0, 1001, true ) ),
				$schema
			)['code'] ?? ''
		);

		$groups = array_fill(
			0,
			1000,
			array(
				'a' => true,
				'b' => true,
				'c' => true,
			)
		);
		self::assertSame(
			'argument_complexity_exceeded',
			$validator->arguments_error( array( 'groups' => $groups ), $schema )['code'] ?? ''
		);
	}
}

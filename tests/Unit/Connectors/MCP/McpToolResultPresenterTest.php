<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpAppsPostUpdateResult;
use Aculect\AICompanion\Connectors\MCP\McpToolResultPresenter;
use PHPUnit\Framework\TestCase;

final class McpToolResultPresenterTest extends TestCase {

	public function test_unnegotiated_post_update_keeps_the_existing_json_text_contract(): void {
		$result = array(
			'workflow' => 'content_workflow_update_post',
			'status'   => 'success',
			'id'       => 42,
			'fields'   => array( 'title' => 'Updated title' ),
			'warnings' => array( 'A field was skipped.' ),
		);

		$presented = ( new McpToolResultPresenter() )->present( $result );

		self::assertSame( $result, json_decode( $presented['content'][0]['text'], true ) );
		self::assertSame( $result, $presented['structuredContent'] );
		self::assertCount( 1, $presented['content'] );
	}

	public function test_negotiated_post_update_has_a_useful_prose_fallback(): void {
		$result = array(
			'schema'  => McpAppsPostUpdateResult::SCHEMA,
			'outcome' => 'unavailable',
			'status'  => 'unavailable',
			'content' => array(),
			'links'   => array(),
		);

		$presented = ( new McpToolResultPresenter() )->present( $result );

		self::assertStringContainsString( 'Content update unavailable.', $presented['content'][0]['text'] );
		self::assertSame( $result, $presented['structuredContent'] );
	}
}

<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpAppsSiteAuditResource;
use Aculect\AICompanion\Connectors\MCP\McpAppsSiteAuditResult;
use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\McpResourceRegistry;
use Aculect\AICompanion\Connectors\MCP\McpToolResultPresenter;
use PHPUnit\Framework\TestCase;

final class McpAppsSiteAuditResourceTest extends TestCase {
	public function test_resource_is_static_and_network_isolated(): void {
		$resource = new McpAppsSiteAuditResource();
		self::assertSame( 'ui://aculect/site-audit/v1/site-audit.html', $resource->descriptor()['uri'] );
		$content = $resource->read()['contents'][0];
		self::assertSame( array(), $content['_meta']['ui']['csp']['connectDomains'] );
		self::assertSame( array(), $content['_meta']['ui']['csp']['resourceDomains'] );
		self::assertStringContainsString( "connect-src 'none'", $content['text'] );
		self::assertStringNotContainsString( 'fetch(', $content['text'] );
	}

	public function test_audit_resource_requires_negotiation_and_site_management_permission(): void {
		$previous = $GLOBALS['aculect_ai_companion_test_capability_callback'] ?? null;
		$registry = new McpResourceRegistry();
		$uri      = McpAppsNegotiation::SITE_AUDIT_URI;
		try {
			$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn (): bool => true;
			self::assertNotContains( $uri, array_column( $registry->list_resources( false )['resources'], 'uri' ) );
			self::assertSame( 'resource_not_found', $registry->read_resource( array( 'uri' => $uri ), false )['error'] );
			self::assertContains( $uri, array_column( $registry->list_resources( true )['resources'], 'uri' ) );
			self::assertSame( $uri, $registry->read_resource( array( 'uri' => $uri ), true )['contents'][0]['uri'] );
			$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn ( string $capability ): bool => 'manage_options' !== $capability;
			self::assertNotContains( $uri, array_column( $registry->list_resources( true )['resources'], 'uri' ) );
			self::assertSame( 'resource_not_found', $registry->read_resource( array( 'uri' => $uri ), true )['error'] );
			self::assertArrayNotHasKey( 'ui', McpAppsNegotiation::tool_metadata( 'site_workflow.audit', array(), '', '', false ) );
			self::assertSame( $uri, McpAppsNegotiation::tool_metadata( 'site_workflow.audit', array(), '', '', true )['ui']['resourceUri'] );
		} finally {
			if ( null === $previous ) {
				unset( $GLOBALS['aculect_ai_companion_test_capability_callback'] );
			} else {
				$GLOBALS['aculect_ai_companion_test_capability_callback'] = $previous;
			}
		}
	}

	public function test_text_fallback_preserves_evidence_and_recommendations_without_claiming_execution(): void {
		$result    = array(
			'workflow'     => 'site_workflow_audit',
			'status'       => 'ready',
			'findings'     => array(
				array(
					'title'              => 'Cached Updates',
					'severity'           => 'warning',
					'evidence'           => array(
						'counts_available' => false,
						'freshness'        => 'unknown',
					),
					'recommended_action' => 'Inspect update status.',
					'required_tool'      => 'site_get_health',
				),
			),
			'next_actions' => array(
				array(
					'action' => 'Inspect update status.',
					'tool'   => 'site_get_health',
				),
			),
		);
		$text      = McpAppsSiteAuditResult::text_fallback( $result );
		$presented = ( new McpToolResultPresenter() )->present( $result );
		self::assertSame( $result, $presented['structuredContent'] );
		self::assertSame( $text, $presented['content'][0]['text'] );
		self::assertStringContainsString( 'Cached Updates — warning', $text );
		self::assertStringContainsString( 'counts_available: no', $text );
		self::assertStringContainsString( 'freshness: unknown', $text );
		self::assertStringContainsString( 'Inspect update status. (site_get_health)', $text );
		self::assertStringContainsString( 'No changes were made.', $text );
		self::assertNull( McpAppsSiteAuditResult::text_fallback( array() ) );
		$result['error'] = 'forbidden';
		self::assertNull( McpAppsSiteAuditResult::text_fallback( $result ) );
	}
}

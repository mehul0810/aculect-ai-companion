<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\Skills\AgentSkillsDiscoveryCatalog;
use PHPUnit\Framework\TestCase;

/** Verifies the private foundation without creating a public discovery route. */
final class AgentSkillsDiscoveryCatalogTest extends TestCase {

	public function test_no_documents_are_selected_without_an_explicit_allowlist(): void {
		self::assertSame( array(), ( new AgentSkillsDiscoveryCatalog() )->documents() );
	}

	public function test_selected_core_document_matches_packaged_bytes_and_digest(): void {
		$documents = ( new AgentSkillsDiscoveryCatalog() )->documents( array( 'wordpress-site-audit' ) );
		self::assertCount( 1, $documents );
		$document = $documents[0];
		self::assertSame( 'wordpress-site-audit', $document['id'] );
		self::assertSame( strlen( $document['content'] ), $document['byte_count'] );
		self::assertSame( hash( 'sha256', $document['content'] ), $document['sha256'] );
		self::assertSame( file_get_contents( ACULECT_AI_COMPANION_PLUGIN_DIR . 'skills/core/wordpress-site-audit/SKILL.md' ), $document['content'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Fixed packaged test fixture.
	}

	public function test_unknown_duplicate_or_site_authored_sources_are_not_candidates(): void {
		$catalog = new AgentSkillsDiscoveryCatalog();
		self::assertSame( array(), $catalog->documents( array( 'custom-site-guide' ) ) );
		self::assertSame( array(), $catalog->documents( array( 'plugin-third-party' ) ) );
		self::assertSame( array(), $catalog->documents( array( 'wordpress-site-audit', 'wordpress-site-audit' ) ) );
		self::assertSame( array(), $catalog->documents( array( 'wordpress-site-audit', '../secret' ) ) );
		self::assertSame( array(), $catalog->documents( array( 'wordpress-site-audit', array() ) ) ); // @phpstan-ignore argument.type (Runtime validation deliberately probes malformed input.)
		self::assertSame( array(), $catalog->documents( array( 'wordpress-site-audit', new \stdClass() ) ) ); // @phpstan-ignore argument.type (Runtime validation deliberately probes malformed input.)
	}
}

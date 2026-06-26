<?php
/**
 * Tests for Rank Math redirect and 404 workflow abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Commenting.DocComment.MissingShort, Squiz.Commenting.FunctionComment.MissingParamTag -- Rank Math stubs must live in their plugin namespaces for isolated adapter tests.

namespace RankMath {
	final class Helper {
		/** @var array<string, bool> */
		public static array $caps = array();

		public static function has_cap( string $capability ): bool {
			return self::$caps[ $capability ] ?? false;
		}
	}
}

namespace RankMath\Redirections {
	final class DB {
		/** @var list<array<string, mixed>> */
		public static array $redirections = array();

		/** @var array<string, list<array<string, mixed>>> */
		public static array $matches = array();

		/** @var array<string, mixed> */
		public static array $last_query = array();

		/**
		 * @param array<string, mixed> $args Query args.
		 * @return array<string, mixed>
		 */
		public static function get_redirections( array $args = array() ): array {
			self::$last_query = $args;

			return array(
				'redirections' => self::$redirections,
				'count'        => count( self::$redirections ),
			);
		}

		/**
		 * @return list<array<string, mixed>>
		 */
		public static function match_redirections_source( string $source ): array {
			return self::$matches[ $source ] ?? array();
		}
	}
}

namespace RankMath\Monitor {
	final class DB {
		/** @var list<array<string, mixed>> */
		public static array $logs = array();

		/** @var array<string, mixed> */
		public static array $last_query = array();

		/**
		 * @param array<string, mixed> $args Query args.
		 * @return array<string, mixed>
		 */
		public static function get_logs( array $args ): array {
			self::$last_query = $args;

			return array(
				'logs'  => self::$logs,
				'count' => count( self::$logs ),
			);
		}
	}
}

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP {

	use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
	use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
	use Aculect\AICompanion\Connectors\MCP\RankMathRedirectAbilities;
	use PHPUnit\Framework\TestCase;
	use RankMath\Helper;
	use RankMath\Monitor\DB as MonitorDB;
	use RankMath\Redirections\DB as RedirectionsDB;

	/**
	 * Verifies Rank Math redirect and recent 404 abilities.
	 */
	final class RankMathRedirectAbilitiesTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();

			$GLOBALS['aculect_ai_companion_test_options']     = array(
				'active_plugins' => array( 'seo-by-rank-math/rank-math.php' ),
			);
			$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();

			Helper::$caps                 = array();
			RedirectionsDB::$matches      = array();
			RedirectionsDB::$last_query   = array();
			RedirectionsDB::$redirections = array(
				array(
					'id'            => 12,
					'sources'       => 'a:1:{i:0;a:3:{s:7:"pattern";s:8:"old-page";s:10:"comparison";s:5:"exact";s:6:"ignore";s:4:"case";}}',
					'url_to'        => 'https://example.com/new-page',
					'header_code'   => 301,
					'status'        => 'active',
					'hits'          => 3,
					'created'       => '2026-06-26 04:00:00',
					'updated'       => '2026-06-26 04:05:00',
					'last_accessed' => '2026-06-26 04:10:00',
				),
			);
			MonitorDB::$last_query        = array();
			MonitorDB::$logs              = array(
				array(
					'id'             => 44,
					'uri'            => '/missing-page?token=secret&preview=true',
					'times_accessed' => 8,
					'accessed'       => '2026-06-26 04:11:00',
					'referer'        => 'https://example.com/source',
					'user_agent'     => 'Mozilla/5.0',
				),
			);

			AbilitiesRegistry::reset_module_cache();
		}

		public function test_redirect_and_404_abilities_are_registered_and_manifested(): void {
			$registry = new AbilitiesRegistry();

			foreach ( array( 'redirects.list', 'redirects.validate', 'not_found.list_recent' ) as $ability_id ) {
				self::assertArrayHasKey( $ability_id, $registry->definitions() );
				self::assertTrue( $registry->is_known( $registry->tool_name( $ability_id ) ) );
				self::assertSame( array( 'content:read' ), $registry->required_scopes( $ability_id ) );
				self::assertTrue( $registry->is_read_only( $ability_id ) );
			}

			$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 1, $registry );
			self::assertArrayHasKey( 'redirects', $operations );
			self::assertSame( 'redirects_list', $operations['redirects']['list']['tool'] );
			self::assertSame( 'redirects_validate', $operations['redirects']['validate']['tool'] );
			self::assertSame( 'not_found_list_recent', $operations['redirects']['list_recent_404s']['tool'] );
		}

		public function test_list_redirects_returns_bounded_rank_math_rows(): void {
			$result = ( new RankMathRedirectAbilities() )->list_redirects(
				array(
					'per_page' => 99,
					'orderby'  => 'hits',
					'order'    => 'ASC',
				)
			);

			self::assertSame( 'ready', $result['status'] );
			self::assertSame( 'rank_math', $result['source'] );
			self::assertSame( 50, $result['per_page'] );
			self::assertSame( 'hits', RedirectionsDB::$last_query['orderby'] );
			self::assertSame( 'ASC', RedirectionsDB::$last_query['order'] );
			self::assertSame( 'old-page', $result['items'][0]['sources'][0]['pattern'] );
			self::assertSame( 'https://example.com/new-page', $result['items'][0]['destination'] );
		}

		public function test_recent_404_list_redacts_query_strings(): void {
			$result = ( new RankMathRedirectAbilities() )->list_recent_404s( array() );

			self::assertSame( 'ready', $result['status'] );
			self::assertSame( '404_monitor', $result['module'] );
			self::assertSame( '/missing-page?[redacted]', $result['items'][0]['uri'] );
			self::assertTrue( $result['items'][0]['query_redacted'] );
			self::assertSame( 8, $result['items'][0]['times_accessed'] );
			self::assertSame( 'accessed', MonitorDB::$last_query['orderby'] );
		}

		public function test_validate_redirect_reports_conflicts(): void {
			RedirectionsDB::$matches['old-page'] = RedirectionsDB::$redirections;

			$result = ( new RankMathRedirectAbilities() )->validate_redirect(
				array(
					'source'      => '/old-page',
					'destination' => '/new-page',
					'match_type'  => 'exact',
				)
			);

			self::assertSame( 'conflict', $result['status'] );
			self::assertFalse( $result['can_create'] );
			self::assertSame( 'old-page', $result['proposal']['source'] );
			self::assertSame( 'https://example.com/new-page', $result['proposal']['destination'] );
			self::assertSame( 12, $result['conflicts'][0]['id'] );
		}

		public function test_validate_redirect_rejects_unsafe_destination(): void {
			$result = ( new RankMathRedirectAbilities() )->validate_redirect(
				array(
					'source'      => '/old-page',
					'destination' => 'javascript:alert(1)',
				)
			);

			self::assertSame( 'unsafe_destination', $result['error'] );
		}

		public function test_abilities_require_rank_math_plugin_and_caps(): void {
			$GLOBALS['aculect_ai_companion_test_options']['active_plugins'] = array();

			$result = ( new RankMathRedirectAbilities() )->list_redirects( array() );
			self::assertSame( 'plugin_unavailable', $result['error'] );

			$GLOBALS['aculect_ai_companion_test_options']['active_plugins'] = array( 'seo-by-rank-math/rank-math.php' );
			$GLOBALS['aculect_ai_companion_test_denied_caps']               = array( 'rank_math_redirections' );
			Helper::$caps = array( 'redirections' => false );

			$result = ( new RankMathRedirectAbilities() )->list_redirects( array() );
			self::assertSame( 'forbidden', $result['error'] );
		}
	}
}

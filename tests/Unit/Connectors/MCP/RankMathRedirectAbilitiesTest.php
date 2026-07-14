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

	final class Redirection {
		/** @var list<array<string, mixed>> */
		public static array $saved = array();

		/** @var array<string, mixed> */
		private array $data;

		/**
		 * @param array<string, mixed> $data Redirection data.
		 */
		public function __construct( array $data ) {
			$this->data = $data;
		}

		public function add_source( string $pattern, string $comparison, string $ignore = '' ): void {
			$this->data['sources'][] = array(
				'pattern'    => $pattern,
				'comparison' => $comparison,
				'ignore'     => $ignore,
			);
		}

		public function add_destination( string $url ): void {
			$this->data['url_to'] = $url;
		}

		public function is_infinite_loop(): bool {
			return false;
		}

		public function save(): int {
			$id                 = count( self::$saved ) + 100;
			$this->data['id']   = $id;
			self::$saved[]      = $this->data;
			DB::$redirections[] = array(
				'id'          => $id,
				'sources'     => $this->data['sources'],
				'url_to'      => $this->data['url_to'],
				'header_code' => $this->data['header_code'],
				'status'      => $this->data['status'],
			);

			return $id;
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
	use Aculect\AICompanion\Intelligence\InternalLinkTargetInspector;
	use PHPUnit\Framework\TestCase;
	use RankMath\Helper;
	use RankMath\Monitor\DB as MonitorDB;
	use RankMath\Redirections\DB as RedirectionsDB;
	use RankMath\Redirections\Redirection;

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
			Redirection::$saved           = array();
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

			foreach ( array( 'redirects.list', 'redirects.validate', 'redirects.create', 'not_found.list_recent' ) as $ability_id ) {
				self::assertArrayHasKey( $ability_id, $registry->definitions() );
				self::assertTrue( $registry->is_known( $registry->tool_name( $ability_id ) ) );
			}

			self::assertSame( array( 'content:read' ), $registry->required_scopes( 'redirects.list' ) );
			self::assertSame( array( 'content:read' ), $registry->required_scopes( 'redirects.validate' ) );
			self::assertSame( array( 'content:draft' ), $registry->required_scopes( 'redirects.create' ) );
			self::assertTrue( $registry->is_read_only( 'redirects.list' ) );
			self::assertTrue( $registry->is_read_only( 'redirects.validate' ) );
			self::assertFalse( $registry->is_read_only( 'redirects.create' ) );

			$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 1, $registry );
			self::assertArrayHasKey( 'redirects', $operations );
			self::assertSame( 'redirects_list', $operations['redirects']['list']['tool'] );
			self::assertSame( 'redirects_validate', $operations['redirects']['validate']['tool'] );
			self::assertSame( 'redirects_create', $operations['redirects']['create']['tool'] );
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

		public function test_list_redirects_decodes_serialized_rank_math_sources_without_unserialize(): void {
			RedirectionsDB::$redirections[0]['sources'] = 'a:2:{i:0;a:3:{s:7:"pattern";s:8:"old-page";s:10:"comparison";s:5:"exact";s:6:"ignore";s:4:"case";}i:1;a:3:{s:7:"pattern";s:11:"legacy-page";s:10:"comparison";s:5:"start";s:6:"ignore";s:0:"";}}';

			$result = ( new RankMathRedirectAbilities() )->list_redirects( array() );

			self::assertSame(
				array(
					array(
						'pattern'    => 'old-page',
						'match_type' => 'exact',
						'ignore'     => 'case',
					),
					array(
						'pattern'    => 'legacy-page',
						'match_type' => 'start',
						'ignore'     => '',
					),
				),
				$result['items'][0]['sources']
			);
		}

		public function test_list_redirects_rejects_serialized_object_payloads(): void {
			RedirectionsDB::$redirections[0]['sources'] = 'a:1:{i:0;O:8:"stdClass":1:{s:7:"pattern";s:8:"old-page";}}';

			$result = ( new RankMathRedirectAbilities() )->list_redirects( array() );

			self::assertSame( array(), $result['items'][0]['sources'] );
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

		public function test_internal_link_target_inspector_reports_rank_math_redirects(): void {
			RedirectionsDB::$matches['old-page'] = RedirectionsDB::$redirections;

			$result = ( new InternalLinkTargetInspector() )->inspect(
				array(
					'target_id'  => 0,
					'target_url' => 'https://example.com/old-page',
				)
			);

			self::assertSame( 'redirected', $result['state'] );
			self::assertSame( 'old-page', $result['evidence']['redirect_source'] );
			self::assertSame( 'https://example.com/new-page', $result['evidence']['redirect_destination'] );
			self::assertSame( 301, $result['evidence']['redirect_code'] );
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

		public function test_create_redirect_dry_run_returns_preview_without_saving(): void {
			$result = ( new RankMathRedirectAbilities() )->create_redirect(
				array(
					'source'      => '/old-page',
					'destination' => '/new-page',
					'match_type'  => 'exact',
					'dry_run'     => true,
				)
			);

			self::assertSame( 'preview', $result['status'] );
			self::assertTrue( $result['dry_run'] );
			self::assertSame( 1, $result['valid'] );
			self::assertSame( 0, $result['errors'] );
			self::assertSame( 'old-page', $result['results'][0]['proposal']['source'] );
			self::assertSame( 'https://example.com/new-page', $result['results'][0]['proposal']['destination'] );
			self::assertSame( array(), Redirection::$saved );
		}

		public function test_create_redirect_uses_rank_math_object_path(): void {
			$result = ( new RankMathRedirectAbilities() )->create_redirect(
				array(
					'source'        => '/old-page',
					'destination'   => '/new-page',
					'redirect_code' => 302,
					'match_type'    => 'start',
					'ignore_case'   => true,
				)
			);

			self::assertSame( 'completed', $result['status'] );
			self::assertSame( 100, $result['id'] );
			self::assertSame( 100, $result['results'][0]['id'] );
			self::assertSame( 'old-page', Redirection::$saved[0]['sources'][0]['pattern'] );
			self::assertSame( 'start', Redirection::$saved[0]['sources'][0]['comparison'] );
			self::assertSame( 'case', Redirection::$saved[0]['sources'][0]['ignore'] );
			self::assertSame( 'https://example.com/new-page', Redirection::$saved[0]['url_to'] );
			self::assertSame( '302', Redirection::$saved[0]['header_code'] );
		}

		public function test_create_redirect_rejects_validation_failures_and_conflicts(): void {
			RedirectionsDB::$matches['old-page'] = RedirectionsDB::$redirections;

			$result = ( new RankMathRedirectAbilities() )->create_redirect(
				array(
					'items' => array(
						array(
							'source'      => '/old-page',
							'destination' => '/new-page',
						),
						array(
							'source'      => '/other-page',
							'destination' => 'javascript:alert(1)',
						),
					),
				)
			);

			self::assertSame( 'failed', $result['status'] );
			self::assertSame( 2, $result['errors'] );
			self::assertSame( 'redirect_conflict', $result['results'][0]['error'] );
			self::assertSame( 'unsafe_destination', $result['results'][1]['error'] );
			self::assertSame( array(), Redirection::$saved );
		}

		public function test_create_redirect_returns_partial_batch_results(): void {
			$result = ( new RankMathRedirectAbilities() )->create_redirect(
				array(
					'items' => array(
						array(
							'source'      => '/old-page',
							'destination' => '/new-page',
						),
						array(
							'source'      => '/bad-page',
							'destination' => 'ftp://example.com/file',
						),
					),
				)
			);

			self::assertSame( 'partial', $result['status'] );
			self::assertSame( 1, $result['created'] );
			self::assertSame( 1, $result['errors'] );
			self::assertSame( 'created', $result['results'][0]['status'] );
			self::assertSame( 'unsafe_destination', $result['results'][1]['error'] );
			self::assertCount( 1, Redirection::$saved );
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

			$result = ( new RankMathRedirectAbilities() )->create_redirect(
				array(
					'source'      => '/old-page',
					'destination' => '/new-page',
				)
			);
			self::assertSame( 'forbidden', $result['error'] );
		}
	}
}

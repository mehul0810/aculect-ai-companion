<?php
/**
 * Tests for read-only agent-readiness diagnostics.
 *
 * @package Aculect\AICompanion\Tests\Unit\Diagnostics
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Diagnostics;

use Aculect\AICompanion\Diagnostics\AgentReadinessDiagnostics;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Diagnostics\LogSettings;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Verifies bounded probes, safe evidence, and explicit result classifications.
 */
final class AgentReadinessDiagnosticsTest extends TestCase {

	protected function tearDown(): void {
		delete_option( AgentReadinessDiagnostics::OPTION_LAST_RESULT );
		parent::tearDown();
	}

	public function test_run_saves_redacted_results_and_uses_bounded_same_origin_requests(): void {
		$requests = array();
		$checker  = new AgentReadinessDiagnostics(
			static function ( string $url, array $args ) use ( &$requests ): array {
				$requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				$status     = 'POST' === $args['method'] ? 401 : 200;
				$headers    = array(
					'Content-Type'   => 'text/html',
					'Link'           => '<https://private.example/path>; rel="example"',
					'Content-Signal' => 'ai-train=no',
					'X-Private-Data' => 'private-header-value',
				);
				$body       = '';
				if ( Helpers::protected_resource_metadata_url() === $url ) {
					$headers['Content-Type'] = 'application/json';
					$body                    = wp_json_encode(
						array(
							'resource'              => Helpers::mcp_resource(),
							'authorization_servers' => array( Helpers::authorization_server_issuer() ),
						)
					);
				} elseif ( Helpers::authorization_metadata_url() === $url ) {
					$headers['Content-Type'] = 'application/json';
					$body                    = wp_json_encode(
						array(
							'issuer'                 => Helpers::authorization_server_issuer(),
							'authorization_endpoint' => Helpers::authorization_endpoint(),
							'token_endpoint'         => Helpers::token_endpoint(),
							'registration_endpoint'  => Helpers::registration_endpoint(),
							'code_challenge_methods_supported' => array( 'S256' ),
						)
					);
				} elseif ( 'POST' === $args['method'] ) {
					$headers['WWW-Authenticate'] = 'Bearer resource_metadata="' . Helpers::protected_resource_metadata_url( Helpers::mcp_resource() ) . '", scope="content:read"';
				} elseif ( str_ends_with( $url, '/robots.txt' ) ) {
					$headers['Content-Type'] = 'text/plain';
					$body                    = "User-agent: GPTBot\nDisallow: /private private-body-value";
				} elseif ( str_ends_with( $url, '/wp-sitemap.xml' ) ) {
					$headers['Content-Type'] = 'application/xml';
					$body                    = '<urlset></urlset>';
				} elseif ( str_ends_with( $url, '/llms.txt' ) ) {
					$headers['Content-Type'] = 'text/plain';
					$body                    = '# llms';
				} elseif ( 'text/markdown' === ( $args['headers']['Accept'] ?? '' ) ) {
					$headers['Content-Type'] = 'text/markdown';
					$body                    = '# Home';
				}

				return array(
					'headers'  => $headers,
					'response' => array(
						'code'    => $status,
						'message' => 'test',
					),
					'body'     => $body,
				);
			}
		);

		$result = $checker->run();
		$ids    = array_column( $result['items'], 'id' );
		$stored = (string) wp_json_encode( get_option( AgentReadinessDiagnostics::OPTION_LAST_RESULT ) );

		self::assertContains( 'oauth_discovery', $ids );
		self::assertContains( 'mcp_authentication_challenge', $ids );
		self::assertContains( 'robots_txt', $ids );
		self::assertContains( 'content_signals', $ids );
		self::assertSame( Helpers::protected_resource_metadata_url(), $requests[0]['url'] );
		self::assertSame( 'pass', $this->item( $result, 'mcp_authentication_challenge' )['status'] );
		self::assertSame( 'pass', $this->item( $result, 'oauth_discovery' )['status'], (string) wp_json_encode( $this->item( $result, 'oauth_discovery' ) ) );
		self::assertSame( 'pass', $this->item( $result, 'oauth_authorization_metadata' )['status'] );
		self::assertSame( 'owner_decision_required', $this->item( $result, 'content_signals' )['status'] );
		self::assertCount( 8, $requests );
		self::assertStringNotContainsString( 'private.example', $stored );
		self::assertStringNotContainsString( 'private-header-value', $stored );
		self::assertStringNotContainsString( 'private-body-value', $stored );

		foreach ( $requests as $request ) {
			self::assertSame( 2, $request['args']['timeout'] );
			self::assertSame( 0, $request['args']['redirection'] );
			self::assertSame( 65536, $request['args']['limit_response_size'] );
		}
	}

	public function test_cross_origin_redirect_is_reported_as_external_block_without_following_it(): void {
		$requested_urls = array();
		$checker        = new AgentReadinessDiagnostics(
			static function ( string $url, array $args ) use ( &$requested_urls ): array|WP_Error {
				self::assertSame( 2, $args['timeout'] );
				$requested_urls[] = $url;
				if ( str_ends_with( $url, '/.well-known/oauth-protected-resource' ) ) {
					return array(
						'headers'  => array( 'Location' => 'https://elsewhere.invalid/metadata' ),
						'response' => array(
							'code'    => 302,
							'message' => 'redirect',
						),
						'body'     => '',
					);
				}
				return new \WP_Error( 'blocked', 'Simulated loopback failure.' );
			}
		);

		$result = $checker->run();
		self::assertSame( 'externally_blocked', $this->item( $result, 'oauth_discovery' )['status'] );
		self::assertCount( 8, $requested_urls );
		self::assertNotContains( 'https://elsewhere.invalid/metadata', $requested_urls );
		self::assertStringNotContainsString( 'elsewhere.invalid', (string) wp_json_encode( $result ) );
	}

	public function test_last_result_returns_a_safe_empty_state_before_first_run(): void {
		$result = ( new AgentReadinessDiagnostics() )->last_result();
		self::assertSame( 'not_run', $result['summary'] );
		self::assertSame( array(), $result['items'] );
	}

	public function test_cloudflare_challenge_html_with_http_200_is_not_reported_as_a_pass(): void {
		$checker = new AgentReadinessDiagnostics(
			static function ( string $url, array $args ): array {
				self::assertSame( 'https', wp_parse_url( $url, PHP_URL_SCHEME ) );
				self::assertSame( 2, $args['timeout'] );
				return array(
					'headers'  => array(
						'Content-Type' => 'text/html',
						'Server'       => 'cloudflare',
						'CF-Mitigated' => 'challenge',
						'Link'         => '<https://site.example/target>; rel="alternate"',
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => 'Just a moment...',
				);
			}
		);

		$result = $checker->run();
		self::assertSame( 'externally_blocked', $this->item( $result, 'oauth_discovery' )['status'] );
		self::assertSame( 'externally_blocked', $this->item( $result, 'robots_txt' )['status'] );
		self::assertSame( 'externally_blocked', $this->item( $result, 'sitemap' )['status'] );
		self::assertSame( 'externally_blocked', $this->item( $result, 'link_headers' )['status'] );
	}

	public function test_ordinary_cloudflare_html_is_not_misclassified_as_an_edge_challenge(): void {
		$checker = new AgentReadinessDiagnostics(
			static function ( string $url, array $args ): array {
				self::assertStringStartsWith( 'https://', $url );
				self::assertSame( 2, $args['timeout'] );
				return array(
					'headers'  => array(
						'Content-Type' => 'text/html; charset=UTF-8',
						'Server'       => 'cloudflare',
						'Link'         => '<https://site.example/wp-json/>; rel="https://api.w.org/"',
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => '<!doctype html><html><body>Example site</body></html>',
				);
			}
		);

		$result = $checker->run();
		self::assertNotSame( 'externally_blocked', $this->item( $result, 'content_signals' )['status'] );
		self::assertSame( 'owner_decision_required', $this->item( $result, 'link_headers' )['status'] );
		self::assertSame( 'warning', $this->item( $result, 'markdown_negotiation' )['status'] );
		self::assertNotSame( 'externally_blocked', $this->item( $result, 'oauth_discovery' )['status'] );
	}

	public function test_unexpected_auth_scheme_and_failed_markdown_status_do_not_pass(): void {
		$checker = new AgentReadinessDiagnostics(
			static function ( string $url, array $args ): array {
				self::assertStringStartsWith( 'https://', $url );
				self::assertSame( 2, $args['timeout'] );
				$is_markdown = 'text/markdown' === ( $args['headers']['Accept'] ?? '' );
				$is_mcp      = 'POST' === $args['method'];
				return array(
					'headers'  => array(
						'Content-Type'     => $is_markdown ? 'text/markdown' : 'application/json',
						'WWW-Authenticate' => $is_mcp ? 'Basic realm="mcp"' : '',
					),
					'response' => array(
						'code'    => $is_markdown ? 403 : ( $is_mcp ? 401 : 200 ),
						'message' => 'test',
					),
					'body'     => '{}',
				);
			}
		);

		$result = $checker->run();
		self::assertSame( 'fail', $this->item( $result, 'mcp_authentication_challenge' )['status'] );
		self::assertSame( 'warning', $this->item( $result, 'markdown_negotiation' )['status'] );
	}

	public function test_mcp_challenge_probe_is_skipped_when_it_would_create_an_opt_in_log_entry(): void {
		$previous_logging = get_option( LogSettings::OPTION_ENABLED, '0' );
		$methods          = array();
		update_option( LogSettings::OPTION_ENABLED, '1' );

		try {
			$checker = new AgentReadinessDiagnostics(
				static function ( string $url, array $args ) use ( &$methods ): array {
					self::assertStringStartsWith( 'https://', $url );
					$methods[] = $args['method'];
					return array(
						'headers'  => array( 'Content-Type' => 'application/json' ),
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
						'body'     => '{}',
					);
				}
			);
			$result  = $checker->run();
		} finally {
			if ( false === $previous_logging ) {
				delete_option( LogSettings::OPTION_ENABLED );
			} else {
				update_option( LogSettings::OPTION_ENABLED, $previous_logging );
			}
		}

		self::assertNotContains( 'POST', $methods );
		self::assertSame( 'warning', $this->item( $result, 'mcp_authentication_challenge' )['status'] );
	}

	/**
	 * Find an item by stable identifier.
	 *
	 * @param array<string,mixed> $result Readiness result.
	 * @param string              $id     Item ID.
	 * @return array<string,mixed>
	 */
	private function item( array $result, string $id ): array {
		foreach ( $result['items'] as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}

		self::fail( 'Expected readiness item was not found: ' . $id );
	}
}

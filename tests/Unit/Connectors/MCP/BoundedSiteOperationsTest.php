<?php
/**
 * Guardrails for public HTML, integrity evidence, and native maintenance.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.WP.GlobalVariablesOverride.Prohibited -- Process-isolated native fixtures require real temporary files and a controlled installed-version identity.

use Aculect\AICompanion\Connectors\MCP\BoundedSiteFixturePost;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionRequest;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Tests\Support\InMemoryExecutionClaimStore;
use Aculect\AICompanion\Connectors\MCP\ChecksumFileInspector;
use Aculect\AICompanion\Connectors\MCP\IntegrityChecksumAbilities;
use Aculect\AICompanion\Connectors\MCP\RenderedPageEvidence;
use Aculect\AICompanion\Connectors\MCP\RenderedPageInspectionAbilities;
use Aculect\AICompanion\Connectors\MCP\TargetedMaintenanceAbilities;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Native API doubles never leak into unrelated test processes.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class BoundedSiteOperationsTest extends TestCase {
	private string $fixture_root;

	protected function setUp(): void {
		parent::setUp();
		require_once dirname( __DIR__, 3 ) . '/Support/BoundedSiteOperationFunctions.php';
		$this->fixture_root = sys_get_temp_dir() . '/aculect-bounded-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->fixture_root );
		define( 'WP_PLUGIN_DIR', $this->fixture_root );
		$GLOBALS['bounded_site_posts']             = array(
			101 => new BoundedSiteFixturePost(
				array(
					'ID'          => 101,
					'post_type'   => 'page',
					'post_status' => 'publish',
				)
			),
		);
		$GLOBALS['bounded_site_denied']            = array();
		$GLOBALS['bounded_site_cap_checks']        = array();
		$GLOBALS['bounded_site_permalink']         = 'https://example.org/page/';
		$GLOBALS['bounded_site_requests']          = array();
		$GLOBALS['bounded_site_response']          = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
			'body'     => '<html><head><title>Page</title></head><body><h1>Hello</h1></body></html>',
		);
		$GLOBALS['bounded_site_multisite']         = false;
		$GLOBALS['bounded_site_super_admin']       = true;
		$GLOBALS['bounded_site_locale']            = 'en_US';
		$GLOBALS['bounded_site_plugins']           = array(
			'example/example.php' => array(
				'Version'   => '1.2.3',
				'UpdateURI' => '',
			),
		);
		$GLOBALS['_wp_suspend_cache_invalidation'] = false;
		$GLOBALS['bounded_site_loaded']            = true;
		$GLOBALS['bounded_site_cleaned']           = array();
		$GLOBALS['bounded_site_flushes']           = array();
		$GLOBALS['wp_version']                     = '6.8.2';
	}

	protected function tearDown(): void {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->fixture_root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			if ( $file->isDir() && ! $file->isLink() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}
		rmdir( $this->fixture_root );
		parent::tearDown();
	}

	public function test_public_fetch_has_no_credentials_or_redirects(): void {
		$result = ( new RenderedPageInspectionAbilities() )->inspect( array( 'post_id' => 101 ) );
		self::assertSame( 'success', $result['status'] );
		self::assertFalse( $result['authenticated_fetch'] );
		$request = $GLOBALS['bounded_site_requests'][0];
		self::assertSame( 'https://example.org/page/?p=101', $request['url'] );
		self::assertSame( 5, $request['args']['timeout'] );
		self::assertSame( 0, $request['args']['redirection'] );
		self::assertSame( 524289, $request['args']['limit_response_size'] );
		self::assertSame( array(), $request['args']['cookies'] );
		self::assertSame( array( 'Accept' => 'text/html' ), $request['args']['headers'] );
		self::assertTrue( $request['args']['sslverify'] );
	}

	public function test_rendered_fetch_rejects_private_password_missing_or_unauthorized_posts(): void {
		$service = new RenderedPageInspectionAbilities();
		foreach ( array( null, array(), new \stdClass(), '101', 0, -1 ) as $id ) {
			self::assertSame( 'invalid_post_id', $service->inspect( array( 'post_id' => $id ) )['error'] );
		}
		self::assertSame( 'unavailable', $service->inspect( array( 'post_id' => 999 ) )['error'] );
		$GLOBALS['bounded_site_denied'] = array( 'read_post' );
		self::assertSame( 'unavailable', $service->inspect( array( 'post_id' => 101 ) )['error'] );
		$GLOBALS['bounded_site_denied']                  = array();
		$GLOBALS['bounded_site_posts'][101]->post_status = 'private';
		self::assertSame( 'unavailable', $service->inspect( array( 'post_id' => 101 ) )['error'] );
		$GLOBALS['bounded_site_posts'][101]->post_status   = 'publish';
		$GLOBALS['bounded_site_posts'][101]->post_password = 'secret';
		self::assertSame( 'unavailable', $service->inspect( array( 'post_id' => 101 ) )['error'] );
		self::assertSame( array(), $GLOBALS['bounded_site_requests'] );
	}

	public function test_foreign_origin_or_credentials_are_never_fetched(): void {
		foreach ( array( 'http://example.org/', 'https://other.org/', 'https://example.org:444/', 'https://user:pass@example.org/', 'https://example.org/#fragment' ) as $url ) {
			$GLOBALS['bounded_site_permalink'] = $url;
			self::assertSame( 'unsafe_permalink', ( new RenderedPageInspectionAbilities() )->inspect( array( 'post_id' => 101 ) )['error'] );
		}
		self::assertSame( array(), $GLOBALS['bounded_site_requests'] );
	}

	public function test_fetch_failures_nonhtml_and_body_caps_are_explicit(): void {
		$service = new RenderedPageInspectionAbilities();
		foreach ( array( 301, 403, 500 ) as $status ) {
			$GLOBALS['bounded_site_response']['response']['code'] = $status;
			self::assertSame( 'unexpected_http_status', $service->inspect( array( 'post_id' => 101 ) )['error'] );
		}
		$GLOBALS['bounded_site_response']['response']['code'] = 200;
		foreach ( array( '', 'application/json', array( 'text/html' ) ) as $type ) {
			$GLOBALS['bounded_site_response']['headers']['content-type'] = $type;
			self::assertSame( 'unsupported_response', $service->inspect( array( 'post_id' => 101 ) )['error'] );
		}
		$GLOBALS['bounded_site_response']['headers']['content-type'] = 'text/html';
		$GLOBALS['bounded_site_response']['body']                    = str_repeat( 'x', 524289 );
		self::assertSame( 'response_too_large', $service->inspect( array( 'post_id' => 101 ) )['error'] );
		$GLOBALS['bounded_site_response'] = new \WP_Error( 'timeout' );
		self::assertSame( 'fetch_unavailable', $service->inspect( array( 'post_id' => 101 ) )['error'] );
	}

	public function test_dom_projection_excludes_forms_scripts_and_bounds_collections(): void {
		$html   = '<html><head><title>Example</title><meta name="description" content="Description"><meta name="robots" content="noindex"><link rel="canonical" href="https://example.org/"></head><body><form><h1>PRIVATE FORM</h1><a href="/private">PRIVATE LINK</a><input value="SECRET"></form><script>SECRET SCRIPT</script><template><h2>HIDDEN</h2></template>' . str_repeat( '<h2>Heading</h2><a href="/safe">Link</a>', 51 ) . '</body></html>';
		$result = ( new RenderedPageEvidence() )->extract( $html );
		self::assertSame( 'Example', $result['title'] );
		self::assertSame( 'Description', $result['description'] );
		self::assertSame( 'noindex', $result['robots'] );
		self::assertSame( 'https://example.org/', $result['canonical'] );
		self::assertCount( 50, $result['headings']['items'] );
		self::assertCount( 50, $result['links']['items'] );
		self::assertTrue( $result['headings']['truncated'] );
		self::assertTrue( $result['links']['truncated'] );
		self::assertStringNotContainsString( 'SECRET', json_encode( $result ) );
		self::assertStringNotContainsString( 'PRIVATE', json_encode( $result ) );
		self::assertStringNotContainsString( 'HIDDEN', json_encode( $result ) );
	}

	public function test_dom_preserves_unicode_without_a_charset_declaration(): void {
		$result = ( new RenderedPageEvidence() )->extract( '<html><head><title>Café — नमस्ते</title></head><body><h1>日本語</h1></body></html>' );
		self::assertSame( 'Café — नमस्ते', $result['title'] );
		self::assertSame( '日本語', $result['headings']['items'][0]['text'] );
	}

	public function test_dom_omits_executable_links_and_nested_active_content(): void {
		$result = ( new RenderedPageEvidence() )->extract( '<html><body><h1>Visible<script>SECRET</script><style>HIDDEN</style></h1><a href="javascript:alert(1)">Bad</a><a href="https://user:pass@example.org/">Credential</a></body></html>' );
		self::assertSame( 'Visible', $result['headings']['items'][0]['text'] );
		self::assertSame( '', $result['links']['items'][0]['url'] );
		self::assertSame( '', $result['links']['items'][1]['url'] );
		self::assertStringNotContainsString( 'SECRET', json_encode( $result ) );
		self::assertSame( 'html_parser_unavailable', ( new RenderedPageEvidence() )->extract( '' )['error'] );
	}

	public function test_checksum_file_comparison_and_safe_paths(): void {
		file_put_contents( $this->fixture_root . '/file.php', 'trusted fixture' );
		$inspector = new ChecksumFileInspector( $this->fixture_root );
		self::assertSame( 'matches', $inspector->inspect( 'file.php', md5( 'trusted fixture' ) )['result'] );
		self::assertSame( 'modified', $inspector->inspect( 'file.php', md5( 'other bytes' ) )['result'] );
		self::assertSame( 'missing', $inspector->inspect( 'missing.php', md5( '' ) )['result'] );
		foreach ( array( '../file.php', '/file.php', 'php://filter', 'sub/../file.php', 'sub//file.php', './file.php', "file\x00.php" ) as $path ) {
			self::assertFalse( ChecksumFileInspector::valid_path( $path ) );
			self::assertSame( 'unsafe_path', $inspector->inspect( $path, md5( '' ) )['result'] );
		}
		self::assertSame( 'unsafe_path', $inspector->inspect( 'file.php', 'not-md5' )['result'] );
	}

	public function test_checksum_skips_symlinks_and_respects_file_and_total_byte_caps(): void {
		file_put_contents( $this->fixture_root . '/file.php', str_repeat( 'x', 5242880 ) );
		symlink( $this->fixture_root . '/file.php', $this->fixture_root . '/link.php' );
		$inspector = new ChecksumFileInspector( $this->fixture_root );
		self::assertSame( 'symlink_skipped', $inspector->inspect( 'link.php', md5( '' ) )['result'] );
		$hash = md5( str_repeat( 'x', 5242880 ) );
		for ( $i = 0; $i < 5; ++$i ) {
			self::assertSame( 'matches', $inspector->inspect( 'file.php', $hash )['result'] );
		}
		self::assertSame( 'size_limit_skipped', $inspector->inspect( 'file.php', $hash )['result'] );
		file_put_contents( $this->fixture_root . '/large.php', str_repeat( 'x', 5242881 ) );
		self::assertSame( 'size_limit_skipped', ( new ChecksumFileInspector( $this->fixture_root ) )->inspect( 'large.php', $hash )['result'] );
	}

	private function manifest( array $files = array( 'example.php' => array( 'md5' => 'd41d8cd98f00b204e9800998ecf8427e' ) ) ): void {
		$GLOBALS['bounded_site_response']['body'] = json_encode(
			array(
				'plugin'  => 'example',
				'version' => '1.2.3',
				'files'   => $files,
			)
		);
	}

	public function test_plugin_manifest_compares_real_fixture_and_fixed_identity(): void {
		mkdir( $this->fixture_root . '/example' );
		file_put_contents( $this->fixture_root . '/example/example.php', '' );
		$this->manifest();
		$result = ( new IntegrityChecksumAbilities() )->plugin( array( 'plugin' => 'example/example.php' ) );
		self::assertSame( 'success', $result['status'] );
		self::assertSame( 'matches', $result['items'][0]['result'] );
		self::assertTrue( $result['read_only'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $result['manifest_state'] );
		self::assertSame( 'https://downloads.wordpress.org/plugin-checksums/example/1.2.3.json', $GLOBALS['bounded_site_requests'][0]['url'] );
		self::assertSame( 0, $GLOBALS['bounded_site_requests'][0]['args']['redirection'] );
		self::assertSame( 1048577, $GLOBALS['bounded_site_requests'][0]['args']['limit_response_size'] );
	}

	public function test_core_manifest_filters_noncore_files_and_returns_bounded_page(): void {
		$GLOBALS['bounded_site_response']['body'] = json_encode(
			array(
				'checksums' => array(
					'composer.json'                  => md5_file( ABSPATH . 'composer.json' ),
					'missing-core.php'               => md5( '' ),
					'wp-config.php'                  => md5( '' ),
					'wp-content/plugins/example.php' => md5( '' ),
				),
			)
		);
		$service                                  = new IntegrityChecksumAbilities();
		$first                                    = $service->core( array( 'per_page' => 1 ) );
		self::assertSame( 'success', $first['status'] );
		self::assertSame( 2, $first['total'] );
		self::assertCount( 1, $first['items'] );
		self::assertSame( 'matches', $first['items'][0]['result'] );
		self::assertTrue( $first['has_more'] );
		$second = $service->core(
			array(
				'per_page' => 1,
				'page'     => 2,
			)
		);
		self::assertSame( 'missing', $second['items'][0]['result'] );
		self::assertFalse( $second['has_more'] );
		self::assertSame( $first['manifest_state'], $second['manifest_state'] );
		self::assertSame( 'https://api.wordpress.org/core/checksums/1.0/?version=6.8.2&locale=en_US', $GLOBALS['bounded_site_requests'][0]['url'] );
	}

	public function test_checksum_authorization_identity_and_pagination(): void {
		$service                        = new IntegrityChecksumAbilities();
		$GLOBALS['bounded_site_denied'] = array( 'update_plugins', 'update_core' );
		self::assertSame( 'forbidden', $service->plugin( array() )['error'] );
		self::assertSame( 'forbidden', $service->core( array() )['error'] );
		$GLOBALS['bounded_site_denied']      = array();
		$GLOBALS['bounded_site_multisite']   = true;
		$GLOBALS['bounded_site_super_admin'] = false;
		self::assertSame( 'forbidden', $service->core( array() )['error'] );
		$GLOBALS['bounded_site_multisite'] = false;
		foreach ( array( null, array(), '../example.php', 'example.php' ) as $plugin ) {
			self::assertSame( 'invalid_plugin', $service->plugin( array( 'plugin' => $plugin ) )['error'] );
		}
		self::assertSame( 'not_found', $service->plugin( array( 'plugin' => 'missing/missing.php' ) )['error'] );
		$GLOBALS['bounded_site_plugins']['example/example.php']['UpdateURI'] = 'https://vendor.example/';
		self::assertSame( 'checksums_unsupported', $service->plugin( array( 'plugin' => 'example/example.php' ) )['error'] );
		$GLOBALS['bounded_site_plugins']['example/example.php']['UpdateURI'] = '';
		$this->manifest();
		self::assertSame(
			'invalid_pagination',
			$service->plugin(
				array(
					'plugin'   => 'example/example.php',
					'per_page' => 51,
				)
			)['error']
		);
		$GLOBALS['wp_version'] = '6.9-beta1';
		self::assertSame( 'checksums_unavailable', $service->core( array() )['error'] );
	}

	public function test_manifest_failures_are_not_integrity_passes(): void {
		$service = new IntegrityChecksumAbilities();
		$args    = array( 'plugin' => 'example/example.php' );
		$GLOBALS['bounded_site_response']['response']['code'] = 404;
		self::assertSame( 'checksums_unavailable', $service->plugin( $args )['error'] );
		$GLOBALS['bounded_site_response']['response']['code'] = 200;
		foreach ( array( 'not-json', '{"plugin":"other","version":"1.2.3","files":{}}' ) as $body ) {
			$GLOBALS['bounded_site_response']['body'] = $body;
			self::assertSame( 'invalid_manifest', $service->plugin( $args )['error'] );
		}
		$this->manifest( array( '../outside.php' => array( 'md5' => md5( '' ) ) ) );
		self::assertSame( 'invalid_manifest', $service->plugin( $args )['error'] );
		$this->manifest( array( 'example.php' => array( 'md5' => new \stdClass() ) ) );
		self::assertSame( 'invalid_manifest', $service->plugin( $args )['error'] );
		$GLOBALS['bounded_site_response']['body'] = str_repeat( 'x', 1048577 );
		self::assertSame( 'manifest_too_large', $service->plugin( $args )['error'] );
	}

	public function test_maintenance_previews_have_no_side_effects_and_cache_is_targeted(): void {
		$service = new TargetedMaintenanceAbilities();
		self::assertSame(
			'preview',
			$service->clean_post_cache(
				array(
					'post_id' => 101,
					'dry_run' => true,
				)
			)['status']
		);
		self::assertSame( 'preview', $service->flush_rewrite_rules( array( 'dry_run' => true ) )['status'] );
		self::assertSame( array(), $GLOBALS['bounded_site_cleaned'] );
		self::assertFalse( $GLOBALS['_wp_suspend_cache_invalidation'] );
		self::assertSame( array(), $GLOBALS['bounded_site_flushes'] );
		$result = $service->clean_post_cache( array( 'post_id' => 101 ) );
		self::assertSame( array( 101 ), $GLOBALS['bounded_site_cleaned'] );
		self::assertFalse( $GLOBALS['_wp_suspend_cache_invalidation'] );
		self::assertFalse( $result['page_cache_purged'] );
		self::assertSame( 'success', $service->flush_rewrite_rules( array() )['status'] );
		self::assertSame( array( false ), $GLOBALS['bounded_site_flushes'] );
	}

	public function test_maintenance_authorization_suspend_and_loaded_gates(): void {
		$service                        = new TargetedMaintenanceAbilities();
		$GLOBALS['bounded_site_denied'] = array( 'manage_options' );
		self::assertSame( 'forbidden', $service->clean_post_cache( array( 'post_id' => 101 ) )['error'] );
		self::assertSame( 'forbidden', $service->flush_rewrite_rules( array() )['error'] );
		$GLOBALS['bounded_site_denied'] = array( 'edit_post' );
		self::assertSame( 'unavailable', $service->clean_post_cache( array( 'post_id' => 101 ) )['error'] );
		$GLOBALS['bounded_site_denied'] = array();
		self::assertSame( 'invalid_post_id', $service->clean_post_cache( array( 'post_id' => new \stdClass() ) )['error'] );
		$GLOBALS['_wp_suspend_cache_invalidation'] = true;
		self::assertSame( 'cache_invalidation_suspended', $service->clean_post_cache( array( 'post_id' => 101 ) )['error'] );
		self::assertTrue( $GLOBALS['_wp_suspend_cache_invalidation'] );
		$GLOBALS['bounded_site_loaded'] = false;
		self::assertSame( 'runtime_not_ready', $service->flush_rewrite_rules( array() )['error'] );
		self::assertSame( array(), $GLOBALS['bounded_site_cleaned'] );
		self::assertSame( array(), $GLOBALS['bounded_site_flushes'] );
	}

	public function test_gateway_requires_confirmation_and_replays_without_repeating_cache_write(): void {
		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_transients']      = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Admin',
				'user_login'   => 'admin',
			),
		);
		$gateway = new AbilityExecutionGateway( null, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
		$auth    = array(
			'user_id'                  => 1,
			'client_id'                => 'bounded-site-test',
			'provider'                 => 'chatgpt',
			'scopes'                   => array( 'content:read', 'content:draft' ),
			'profile'                  => 'full_access',
			'access_level'             => 'write',
			'write_permission_enabled' => true,
		);
		$args    = array(
			'post_id'         => 101,
			'idempotency_key' => 'bounded-cache-write',
		);
		$call    = static function ( array $arguments, array $context ) use ( $gateway ) {
			return $gateway->execute(
				new AbilityExecutionRequest(
					array(
						'name'      => 'maintenance_clean_post_cache',
						'arguments' => $arguments,
					),
					$context
				)
			);
		};
		$initial = $call( $args, $auth );
		self::assertSame( 'confirmation_required', $initial->data['result']['status'] ?? $initial->data );
		self::assertSame( array(), $GLOBALS['bounded_site_cleaned'] );
		$args['confirmation_token'] = $initial->data['result']['confirmation_token'];
		$confirmed                  = $call( $args, $auth );
		self::assertSame( 'success', $confirmed->data['result']['status'] ?? $confirmed->data );
		self::assertSame( array( 101 ), $GLOBALS['bounded_site_cleaned'] );
		$replayed = $call( $args, $auth );
		self::assertTrue( $replayed->data['result']['replayed'] ?? false );
		self::assertSame( array( 101 ), $GLOBALS['bounded_site_cleaned'] );
		$changed            = $args;
		$changed['post_id'] = 102;
		self::assertArrayHasKey( 'error', $call( $changed, $auth )->data['result'] );
		$denied           = $auth;
		$denied['scopes'] = array();
		self::assertSame( AbilityExecutionGateway::OUTCOME_AUTH_CHALLENGE, $call( $args, $denied )->type );
		$GLOBALS['bounded_site_denied'] = array( 'manage_options' );
		$result                         = $call( array( 'post_id' => 101 ), $auth );
		self::assertSame( AbilityExecutionGateway::OUTCOME_TOOL_ERROR, $result->type );
		self::assertStringContainsString( 'not available', $result->data['message'] );
		self::assertSame( array( 101 ), $GLOBALS['bounded_site_cleaned'] );
	}
}

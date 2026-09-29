<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\SkillsRestController;
use Aculect\AICompanion\Connectors\MCP\Skills\CustomMcpSkillStore;
use Aculect\AICompanion\Tests\Support\ScopedSkillOptions;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once dirname( __DIR__, 2 ) . '/Support/ScopedSkillOptions.php';

/**
 * Covers admin-only custom Skills REST operations and settings payload wiring.
 */
final class SkillsRestControllerTest extends TestCase {

	private SkillsRestController $controller;

	protected function setUp(): void {
		parent::setUp();
		ScopedSkillOptions::reset();
		$GLOBALS['aculect_ai_companion_test_blog_id']     = 1;
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		$this->controller                                 = new SkillsRestController( new CustomMcpSkillStore( new ScopedSkillOptions() ) );
	}

	public function test_registers_every_route_with_manage_options_permission(): void {
		$GLOBALS['aculect_ai_companion_test_rest_routes'] = array();
		$this->controller->register_rest_routes();
		$routes = $GLOBALS['aculect_ai_companion_test_rest_routes'];

		self::assertCount( 9, $routes );
		foreach ( $routes as $route ) {
			self::assertSame( 'aculect-ai-companion/v1', $route['namespace'] );
			self::assertSame( array( $this->controller, 'can_manage_skills' ), $route['args']['permission_callback'] );
		}
		self::assertSame( '/skills/import', $routes[8]['route'] );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		self::assertFalse( $this->controller->can_manage_skills() );
	}

	public function test_admin_can_create_list_edit_enable_duplicate_export_import_and_delete(): void {
		$created = $this->controller->create_skill( $this->request( array(), $this->input() ) );
		self::assertInstanceOf( WP_REST_Response::class, $created );
		self::assertSame( 'custom-editorial-review', $created->get_data()['skill']['id'] );
		self::assertSame( 'custom', $created->get_data()['skill']['provider'] );

		$listed = $this->controller->list_skills( $this->request() )->get_data();
		self::assertCount( 4, $listed['skills'] );
		$providers = array_count_values( array_column( $listed['skills'], 'provider' ) );
		self::assertSame( 3, $providers['core'] );
		self::assertSame( 1, $providers['custom'] );
		$custom = array_values( array_filter( $listed['skills'], static fn ( array $skill ): bool => 'custom-editorial-review' === $skill['id'] ) );
		self::assertCount( 1, $custom );
		self::assertSame( 'Reviews draft clarity.', $custom[0]['description'] );
		foreach ( $listed['skills'] as $skill ) {
			self::assertArrayNotHasKey( 'skill_md', $skill );
			self::assertArrayNotHasKey( 'documents', $skill );
		}

		$get = $this->controller->get_skill( $this->request( array( 'id' => 'custom-editorial-review' ) ) );
		self::assertSame( $this->markdown(), $get->get_data()['skill']['skill_md'] );

		$update_input             = $this->input();
		$update_input['skill_md'] = str_replace( 'Write plainly.', 'Write clearly.', $update_input['skill_md'] );
		$update_input             = array_merge( $update_input, $this->revision( $created->get_data()['skill'] ) );
		$updated                  = $this->controller->update_skill( $this->request( array( 'id' => 'custom-editorial-review' ), $update_input ) );
		self::assertSame( 2, $updated->get_data()['skill']['version'] );

		$enabled = $this->controller->set_enabled( $this->request( array( 'id' => 'custom-editorial-review' ), array_merge( array( 'enabled' => false ), $this->revision( $updated->get_data()['skill'] ) ) ) );
		self::assertFalse( $enabled->get_data()['skill']['enabled'] );

		$duplicated = $this->controller->duplicate_skill( $this->request( array( 'id' => 'custom-editorial-review' ), array( 'id' => 'custom-editorial-review-copy' ) ) );
		self::assertSame( 'custom-editorial-review-copy', $duplicated->get_data()['skill']['id'] );

		$exported = $this->controller->export_skill( $this->request( array( 'id' => 'custom-editorial-review' ) ) )->get_data();
		self::assertSame( 'aculect-mcp-skill', json_decode( $exported['package'], true )['format'] );
		self::assertInstanceOf(
			WP_REST_Response::class,
			$this->controller->import_skill(
				$this->request(
					array(),
					array(
						'package' => $exported['package'],
						'replace' => true,
						...$this->revision( $enabled->get_data()['skill'] ),
					)
				)
			)
		);

		$deleted = $this->controller->delete_skill( $this->request( array( 'id' => 'custom-editorial-review-copy' ), $this->revision( $duplicated->get_data()['skill'] ) ) );
		self::assertTrue( $deleted->get_data()['success'] );
	}

	public function test_mutations_reject_missing_or_stale_revision_without_changing_a_skill(): void {
		$created = $this->controller->create_skill( $this->request( array(), $this->input() ) );
		$skill   = $created->get_data()['skill'];
		$id      = array( 'id' => $skill['id'] );

		self::assertInstanceOf( WP_Error::class, $this->controller->update_skill( $this->request( $id, $this->input() ) ) );
		self::assertInstanceOf( WP_Error::class, $this->controller->set_enabled( $this->request( $id, array( 'enabled' => false ) ) ) );
		self::assertInstanceOf( WP_Error::class, $this->controller->delete_skill( $this->request( $id ) ) );

		$first = $this->controller->set_enabled( $this->request( $id, array_merge( array( 'enabled' => false ), $this->revision( $skill ) ) ) );
		self::assertInstanceOf( WP_REST_Response::class, $first );
		$stale = $this->controller->delete_skill( $this->request( $id, $this->revision( $skill ) ) );
		self::assertInstanceOf( WP_Error::class, $stale );
		self::assertSame( 'skill_conflict', $stale->get_error_code() );
		self::assertSame( 409, $stale->get_error_data()['status'] );
		self::assertInstanceOf( WP_REST_Response::class, $this->controller->get_skill( $this->request( $id ) ) );
	}

	public function test_route_id_cannot_be_replaced_by_a_higher_priority_json_id(): void {
		$created = $this->controller->create_skill( $this->request( array(), $this->input() ) )->get_data()['skill'];
		$request = new class( array( 'id' => 'custom-editorial-review' ), array( 'id' => 'custom-editorial-copy' ) ) extends WP_REST_Request {
			public function __construct( array $route, array $json ) {
				parent::__construct( $route, array(), $json );
			}

			public function get_param( string $key ): mixed {
				$json = $this->get_json_params();
				return $json[ $key ] ?? parent::get_param( $key );
			}
		};
		$duplicate = $this->controller->duplicate_skill( $request );
		self::assertInstanceOf( WP_REST_Response::class, $duplicate );
		self::assertSame( 'custom-editorial-copy', $duplicate->get_data()['skill']['id'] );
		self::assertSame( str_replace( 'custom-editorial-review', 'custom-editorial-copy', $created['skill_md'] ), $duplicate->get_data()['skill']['skill_md'] );

		$wrong_body             = $this->input();
		$wrong_body['id']       = 'custom-editorial-copy';
		$wrong_body['skill_md'] = str_replace( 'custom-editorial-review', 'custom-editorial-copy', $wrong_body['skill_md'] );
		$wrong_body             = array_merge( $wrong_body, $this->revision( $duplicate->get_data()['skill'] ) );
		$update_request         = new class( array( 'id' => 'custom-editorial-review' ), $wrong_body ) extends WP_REST_Request {
			public function __construct( array $route, array $json ) {
				parent::__construct( $route, array(), $json );
			}

			public function get_param( string $key ): mixed {
				$json = $this->get_json_params();
				return $json[ $key ] ?? parent::get_param( $key );
			}
		};
		$update = $this->controller->update_skill( $update_request );
		self::assertInstanceOf( WP_Error::class, $update );
		self::assertSame( 'rest_invalid_param', $update->get_error_code() );
		self::assertSame( $created, $this->controller->get_skill( $this->request( array( 'id' => 'custom-editorial-review' ) ) )->get_data()['skill'] );
	}

	public function test_maximum_valid_newline_heavy_skill_round_trips_through_the_admin_import_request(): void {
		$input             = $this->input();
		$input['skill_md'] = $this->markdown() . str_repeat( "\n", 32768 - strlen( $this->markdown() ) );
		$input['references'] = array();
		for ( $index = 1; $index <= 6; ++$index ) {
			$input['references'][ 'references/part-' . $index . '.md' ] = str_repeat( "\n", 16384 );
		}
		$created = $this->controller->create_skill( $this->request( array(), $input ) );
		self::assertInstanceOf( WP_REST_Response::class, $created );
		$exported = $this->controller->export_skill( $this->request( array( 'id' => 'custom-editorial-review' ) ) )->get_data()['package'];
		self::assertGreaterThan( 135168, strlen( $exported ) );
		self::assertLessThanOrEqual( 270336, strlen( $exported ) );

		$GLOBALS['aculect_ai_companion_test_blog_id'] = 2;
		$payload                                      = array(
			'package' => $exported,
			'replace' => false,
		);
		$body = (string) wp_json_encode( $payload );
		self::assertGreaterThan( 262144, strlen( $body ) );
		self::assertLessThanOrEqual( 600000, strlen( $body ) );
		$imported = $this->controller->import_skill( $this->request( array(), $payload, $body ) );
		self::assertInstanceOf( WP_REST_Response::class, $imported );
		self::assertSame( $input['skill_md'], $imported->get_data()['skill']['skill_md'] );
	}

	public function test_write_payloads_are_bounded_and_strictly_typed(): void {
		$bad_id       = $this->input();
		$bad_id['id'] = 'core-wordpress-site-audit';
		self::assertInstanceOf( WP_Error::class, $this->controller->create_skill( $this->request( array(), $bad_id ) ) );

		$bad_toggle = $this->controller->set_enabled( $this->request( array( 'id' => 'custom-missing' ), array( 'enabled' => 'false' ) ) );
		self::assertInstanceOf( WP_Error::class, $bad_toggle );

		$oversized              = $this->input();
		$oversized['skill_md'] .= str_repeat( 'x', 32768 );
		self::assertInstanceOf( WP_Error::class, $this->controller->create_skill( $this->request( array(), $oversized ) ) );

		self::assertInstanceOf( WP_Error::class, $this->controller->import_skill( $this->request( array(), array( 'package' => str_repeat( 'x', 270337 ) ) ) ) );
		self::assertInstanceOf( WP_Error::class, $this->controller->create_skill( $this->request( array(), $this->input(), str_repeat( ' ', 600001 ) ) ) );
	}

	/**
	 * Build one dashboard-compatible request.
	 *
	 * @param array<string, mixed> $params Route/query parameters.
	 * @param array<string, mixed> $json JSON object body.
	 * @param string               $body Raw request body.
	 */
	private function request( array $params = array(), array $json = array(), string $body = '' ): WP_REST_Request {
		return new WP_REST_Request( $params, array(), $json, 'GET', '/aculect-ai-companion/v1/skills', $body );
	}

	/**
	 * Valid minimal custom Skill input.
	 *
	 * @return array<string, mixed>
	 */
	private function input(): array {
		return array(
			'id'         => 'custom-editorial-review',
			'skill_md'   => $this->markdown(),
			'references' => array( 'references/checklist.md' => "Check headings and links.\n" ),
			'enabled'    => true,
		);
	}

	private function markdown(): string {
		return "---\nname: custom-editorial-review\ndescription: Reviews draft clarity.\n---\nWrite plainly.\n";
	}

	/**
	 * Return the optimistic revision pair for one stored Skill.
	 *
	 * @param array<string,mixed> $skill Stored Skill.
	 * @return array{expected_version:int,expected_digest:string}
	 */
	private function revision( array $skill ): array {
		return array(
			'expected_version' => $skill['version'],
			'expected_digest'  => $skill['digest'],
		);
	}
}

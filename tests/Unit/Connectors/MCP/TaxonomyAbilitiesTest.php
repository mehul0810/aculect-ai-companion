<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\TaxonomyAbilities;
use Aculect\AICompanion\Connectors\MCP\TaxonomyAssignmentAbilities;
use PHPUnit\Framework\TestCase;

/**
 * Covers taxonomy capability routing and pagination semantics.
 */
final class TaxonomyAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_taxonomies']                = array(
			'product_group' => new \WP_Taxonomy(
				'product_group',
				array(
					'label'        => 'Product groups',
					'hierarchical' => true,
					'cap'          => array(
						'manage_terms' => 'manage_product_groups',
						'edit_terms'   => 'edit_product_groups',
						'delete_terms' => 'delete_product_groups',
						'assign_terms' => 'assign_product_groups',
					),
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_terms']                     = array(
			'product_group' => array(
				1 => new \WP_Term(
					array(
						'term_id'  => 1,
						'name'     => 'News',
						'slug'     => 'news',
						'taxonomy' => 'product_group',
					)
				),
				2 => new \WP_Term(
					array(
						'term_id'  => 2,
						'name'     => 'Guides',
						'slug'     => 'guides',
						'taxonomy' => 'product_group',
					)
				),
			),
		);
		$GLOBALS['aculect_ai_companion_test_posts']                     = array(
			100 => new \WP_Post(
				array(
					'ID'             => 100,
					'post_type'      => 'attachment',
					'post_mime_type' => 'image/png',
				)
			),
			200 => new \WP_Post(
				array(
					'ID'                => 200,
					'post_type'         => 'post',
					'post_title'        => 'Taxonomy assignment target',
					'post_modified_gmt' => '2026-09-04 08:30:00',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_object_terms']              = array(
			200 => array( $GLOBALS['aculect_ai_companion_test_terms']['product_group'][1] ),
		);
		$GLOBALS['aculect_ai_companion_test_set_object_terms_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_term_meta']                 = array();
		$GLOBALS['aculect_ai_companion_test_update_term_meta_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_delete_term_meta_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_denied_caps']               = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback']       = null;
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_capability_callback']       = null;
		$GLOBALS['aculect_ai_companion_test_denied_caps']               = array();
		$GLOBALS['aculect_ai_companion_test_update_term_meta_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_delete_term_meta_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_set_object_terms_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_object_terms']              = array();

		parent::tearDown();
	}

	public function test_assign_terms_replaces_existing_terms_by_slug_and_id(): void {
		$result = ( new TaxonomyAssignmentAbilities() )->assign_terms(
			array(
				'post_id'  => 200,
				'taxonomy' => 'product_group',
				'terms'    => array( 'guides', 1 ),
			)
		);

		self::assertSame( 'success', $result['status'] );
		self::assertTrue( $result['changed'] );
		self::assertSame( array( 1, 2 ), $result['term_ids'] );
		self::assertSame( array( 1, 2 ), array_column( $GLOBALS['aculect_ai_companion_test_object_terms'][200], 'term_id' ) );
	}

	public function test_assign_terms_empty_list_clears_taxonomy(): void {
		$result = ( new TaxonomyAssignmentAbilities() )->assign_terms(
			array(
				'post_id'  => 200,
				'taxonomy' => 'product_group',
				'terms'    => array(),
			)
		);

		self::assertTrue( $result['changed'] );
		self::assertSame( array(), $result['term_ids'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_object_terms'][200] );
	}

	public function test_assign_terms_dry_run_previews_without_writing(): void {
		$result = ( new TaxonomyAssignmentAbilities() )->assign_terms(
			array(
				'post_id'  => 200,
				'taxonomy' => 'product_group',
				'terms'    => array( 2 ),
				'dry_run'  => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'taxonomy.assign_terms', $result['action'] );
		self::assertSame( 'update', $result['risk_level'] );
		self::assertSame( array( 1 ), array_column( $GLOBALS['aculect_ai_companion_test_object_terms'][200], 'term_id' ) );
	}

	public function test_assign_terms_noop_does_not_write(): void {
		$writes = 0;
		$GLOBALS['aculect_ai_companion_test_set_object_terms_callback'] = static function () use ( &$writes ): array {
			++$writes;

			return array();
		};

		$result = ( new TaxonomyAssignmentAbilities() )->assign_terms(
			array(
				'post_id'  => 200,
				'taxonomy' => 'product_group',
				'terms'    => array( 1 ),
			)
		);

		self::assertFalse( $result['changed'] );
		self::assertSame( 0, $writes );
	}

	public function test_assign_terms_enforces_post_and_taxonomy_capabilities(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_post' );
		$post_denied                                      = ( new TaxonomyAssignmentAbilities() )->assign_terms(
			array(
				'post_id'  => 200,
				'taxonomy' => 'product_group',
				'terms'    => array( 2 ),
			)
		);

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'assign_product_groups' );
		$taxonomy_denied                                  = ( new TaxonomyAssignmentAbilities() )->assign_terms(
			array(
				'post_id'  => 200,
				'taxonomy' => 'product_group',
				'terms'    => array( 2 ),
			)
		);

		self::assertSame( 'forbidden', $post_denied['error'] );
		self::assertSame( 'forbidden', $taxonomy_denied['error'] );
	}

	public function test_assign_terms_rejects_taxonomy_not_registered_for_post_type(): void {
		$GLOBALS['aculect_ai_companion_test_taxonomies']['product_group']->object_type = array( 'page' );

		$result = ( new TaxonomyAssignmentAbilities() )->assign_terms(
			array(
				'post_id'  => 200,
				'taxonomy' => 'product_group',
				'terms'    => array( 2 ),
			)
		);

		self::assertSame( 'invalid_taxonomy', $result['error'] );
	}

	public function test_assign_terms_rejects_unknown_term_and_stale_content(): void {
		$unknown  = ( new TaxonomyAssignmentAbilities() )->assign_terms(
			array(
				'post_id'  => 200,
				'taxonomy' => 'product_group',
				'terms'    => array( 'missing' ),
			)
		);
		$conflict = ( new TaxonomyAssignmentAbilities() )->assign_terms(
			array(
				'post_id'               => 200,
				'taxonomy'              => 'product_group',
				'terms'                 => array( 2 ),
				'expected_modified_gmt' => '2026-09-04 08:29:00',
			)
		);

		self::assertSame( 'term_not_found', $unknown['error'] );
		self::assertSame( 'conflict', $conflict['error'] );
	}

	public function test_assign_terms_reports_wordpress_write_failure(): void {
		$GLOBALS['aculect_ai_companion_test_set_object_terms_callback'] = static fn(): \WP_Error => new \WP_Error( 'term_backend_failure', 'Term backend unavailable.' );

		$result = ( new TaxonomyAssignmentAbilities() )->assign_terms(
			array(
				'post_id'  => 200,
				'taxonomy' => 'product_group',
				'terms'    => array( 2 ),
			)
		);

		self::assertSame( 'term_backend_failure', $result['error'] );
	}

	public function test_list_taxonomies_reports_operation_specific_capabilities(): void {
		$calls = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability ) use ( &$calls ): bool {
			$calls[] = $capability;

			return true;
		};

		$result = ( new TaxonomyAbilities() )->list_taxonomies();

		self::assertTrue( $result[0]['can_create'] );
		self::assertTrue( $result[0]['can_update'] );
		self::assertTrue( $result[0]['can_delete'] );
		self::assertTrue( $result[0]['can_assign'] );
		self::assertSame(
			array( 'manage_product_groups', 'edit_product_groups', 'delete_product_groups', 'assign_product_groups' ),
			$calls
		);
	}

	public function test_list_terms_applies_search_to_items_and_total(): void {
		$result = ( new TaxonomyAbilities() )->list_terms(
			array(
				'taxonomy' => 'product_group',
				'search'   => 'news',
				'per_page' => 1,
			)
		);

		self::assertSame( 1, $result['total'] );
		self::assertSame( 'News', $result['items'][0]['name'] );
	}

	public function test_invalid_taxonomy_returns_explicit_error_instead_of_empty_success(): void {
		$result = ( new TaxonomyAbilities() )->list_terms( array( 'taxonomy' => 'missing' ) );

		self::assertSame( 'invalid_taxonomy', $result['error'] );
	}

	public function test_term_writes_use_their_specific_capabilities(): void {
		$calls = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static function ( string $capability ) use ( &$calls ): bool {
			$calls[] = $capability;

			return true;
		};

		$service = new TaxonomyAbilities();
		$created = $service->create_term(
			array(
				'taxonomy' => 'product_group',
				'name'     => 'Releases',
			)
		);
		$updated = $service->update_term(
			array(
				'taxonomy' => 'product_group',
				'term_id'  => 1,
				'name'     => 'Newsroom',
			)
		);
		$deleted = $service->delete_term(
			array(
				'taxonomy' => 'product_group',
				'term_id'  => 2,
			)
		);

		self::assertSame( 3, $created['id'] );
		self::assertSame( 'Newsroom', $updated['name'] );
		self::assertSame( 'deleted', $deleted['status'] );
		self::assertSame( array( 'edit_product_groups', 'edit_product_groups', 'delete_product_groups' ), $calls );
	}

	public function test_term_image_update_failure_is_reported(): void {
		$GLOBALS['aculect_ai_companion_test_update_term_meta_callback'] = static fn(): bool => false;
		$GLOBALS['aculect_ai_companion_test_capability_callback']       = static fn(): bool => true;

		$result = ( new TaxonomyAbilities() )->set_term_image(
			array(
				'taxonomy' => 'product_group',
				'term_id'  => 1,
				'image_id' => 100,
			)
		);

		self::assertSame( 'term_image_write_failed', $result['error'] );
	}

	public function test_term_image_wp_error_is_reported(): void {
		$GLOBALS['aculect_ai_companion_test_update_term_meta_callback'] = static fn(): \WP_Error => new \WP_Error( 'meta_failure', 'Metadata backend unavailable.' );
		$GLOBALS['aculect_ai_companion_test_capability_callback']       = static fn(): bool => true;

		$result = ( new TaxonomyAbilities() )->set_term_image(
			array(
				'taxonomy' => 'product_group',
				'term_id'  => 1,
				'image_id' => 100,
			)
		);

		self::assertSame( 'term_image_write_failed', $result['error'] );
	}

	public function test_term_image_delete_failure_is_reported(): void {
		$GLOBALS['aculect_ai_companion_test_term_meta'][1]['aculect_ai_companion_term_image_id'] = 100;
		$GLOBALS['aculect_ai_companion_test_delete_term_meta_callback']                          = static fn(): bool => false;
		$GLOBALS['aculect_ai_companion_test_capability_callback']                                = static fn(): bool => true;

		$result = ( new TaxonomyAbilities() )->set_term_image(
			array(
				'taxonomy'    => 'product_group',
				'term_id'     => 1,
				'clear_image' => true,
			)
		);

		self::assertSame( 'term_image_write_failed', $result['error'] );
	}

	public function test_term_image_write_exception_is_reported(): void {
		$GLOBALS['aculect_ai_companion_test_update_term_meta_callback'] = static function (): never {
			throw new \RuntimeException( 'metadata backend unavailable' );
		};
		$GLOBALS['aculect_ai_companion_test_capability_callback']       = static fn(): bool => true;

		$result = ( new TaxonomyAbilities() )->set_term_image(
			array(
				'taxonomy' => 'product_group',
				'term_id'  => 1,
				'image_id' => 100,
			)
		);

		self::assertSame( 'term_image_write_failed', $result['error'] );
	}

	public function test_term_image_postcondition_mismatch_is_reported(): void {
		$GLOBALS['aculect_ai_companion_test_update_term_meta_callback'] = static fn(): bool => true;
		$GLOBALS['aculect_ai_companion_test_capability_callback']       = static fn(): bool => true;

		$result = ( new TaxonomyAbilities() )->set_term_image(
			array(
				'taxonomy' => 'product_group',
				'term_id'  => 1,
				'image_id' => 100,
			)
		);

		self::assertSame( 'term_image_write_failed', $result['error'] );
	}

	public function test_term_image_update_persists_exact_value(): void {
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn(): bool => true;

		$result = ( new TaxonomyAbilities() )->set_term_image(
			array(
				'taxonomy' => 'product_group',
				'term_id'  => 1,
				'image_id' => 100,
			)
		);

		self::assertSame( 1, $result['id'] );
		self::assertSame( 100, get_term_meta( 1, 'aculect_ai_companion_term_image_id', true ) );
	}

	public function test_term_image_update_noop_does_not_write(): void {
		$GLOBALS['aculect_ai_companion_test_term_meta'][1]['aculect_ai_companion_term_image_id'] = 100;
		$writes = 0;
		$GLOBALS['aculect_ai_companion_test_update_term_meta_callback'] = static function () use ( &$writes ): bool {
			++$writes;

			return false;
		};
		$GLOBALS['aculect_ai_companion_test_capability_callback']       = static fn(): bool => true;

		$result = ( new TaxonomyAbilities() )->set_term_image(
			array(
				'taxonomy' => 'product_group',
				'term_id'  => 1,
				'image_id' => 100,
			)
		);

		self::assertSame( 0, $writes );
		self::assertSame( 1, $result['id'] );
	}

	public function test_term_image_clear_removes_metadata(): void {
		$GLOBALS['aculect_ai_companion_test_term_meta'][1]['aculect_ai_companion_term_image_id'] = 100;
		$GLOBALS['aculect_ai_companion_test_capability_callback']                                = static fn(): bool => true;

		$result = ( new TaxonomyAbilities() )->set_term_image(
			array(
				'taxonomy'    => 'product_group',
				'term_id'     => 1,
				'clear_image' => true,
			)
		);

		self::assertSame( 1, $result['id'] );
		self::assertFalse( metadata_exists( 'term', 1, 'aculect_ai_companion_term_image_id' ) );
	}

	public function test_term_image_clear_removes_malformed_existing_metadata(): void {
		$GLOBALS['aculect_ai_companion_test_term_meta'][1]['aculect_ai_companion_term_image_id'] = 'legacy';
		$GLOBALS['aculect_ai_companion_test_capability_callback']                                = static fn(): bool => true;

		$result = ( new TaxonomyAbilities() )->set_term_image(
			array(
				'taxonomy'    => 'product_group',
				'term_id'     => 1,
				'clear_image' => true,
			)
		);

		self::assertSame( 1, $result['id'] );
		self::assertFalse( metadata_exists( 'term', 1, 'aculect_ai_companion_term_image_id' ) );
	}
}

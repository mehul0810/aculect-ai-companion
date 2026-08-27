<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Closure;

/**
 * Builds the taxonomy-focused first-party ability modules.
 */
final class TaxonomyAbilityModules {

	/**
	 * Return taxonomy modules in the stable public-surface order.
	 *
	 * @param AbilityModuleFactory $factory Module factory.
	 * @return list<AbilityModuleInterface>
	 */
	public static function all( AbilityModuleFactory $factory ): array {
		return array(
			self::list_taxonomies( $factory ),
			self::list_terms( $factory ),
			self::get_term( $factory ),
			self::create_term( $factory ),
			self::delete_term( $factory ),
			self::update_term( $factory ),
			self::set_term_image( $factory ),
		);
	}

	private static function list_taxonomies( AbilityModuleFactory $factory ): AbilityModuleInterface {
		return self::module( $factory, 'taxonomy.list_taxonomies', 'List Content Groups', 'List available categories, tags, and custom content groups.', 'content:read', true, self::empty_schema(), static fn (): array => array( 'items' => ( new TaxonomyAbilities() )->list_taxonomies() ) );
	}

	private static function list_terms( AbilityModuleFactory $factory ): AbilityModuleInterface {
		return self::module(
			$factory,
			'taxonomy.list_terms',
			'List Categories and Tags',
			'List categories, tags, or custom content groups with pagination.',
			'content:read',
			true,
			self::object_schema(
				array(
					'taxonomy'   => array( 'type' => 'string' ),
					'page'       => self::page_schema(),
					'per_page'   => self::per_page_schema( 100, 'Terms per page. Defaults to 50.' ),
					'search'     => array( 'type' => 'string' ),
					'hide_empty' => array( 'type' => 'boolean' ),
				),
				array( 'taxonomy' )
			),
			static fn ( array $args ): array => ( new TaxonomyAbilities() )->list_terms( $args )
		);
	}

	private static function get_term( AbilityModuleFactory $factory ): AbilityModuleInterface {
		return self::module(
			$factory,
			'taxonomy.get_term',
			'Get a Category or Tag',
			'Read one category, tag, or custom content group by taxonomy and term ID.',
			'content:read',
			true,
			self::object_schema(
				array(
					'taxonomy' => array( 'type' => 'string' ),
					'term_id'  => array( 'type' => 'integer' ),
				),
				array( 'taxonomy', 'term_id' )
			),
			static fn ( array $args ): array => ( new TaxonomyAbilities() )->get_term( $args )
		);
	}

	private static function create_term( AbilityModuleFactory $factory ): AbilityModuleInterface {
		return self::module(
			$factory,
			'taxonomy.create_term',
			'Create a Category or Tag',
			'Create a category, tag, or custom content group.',
			'content:draft',
			false,
			self::object_schema(
				array(
					'taxonomy'    => array( 'type' => 'string' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'parent'      => array( 'type' => 'integer' ),
				),
				array( 'taxonomy', 'name' )
			),
			static fn ( array $args ): array => ( new TaxonomyAbilities() )->create_term( $args )
		);
	}

	private static function delete_term( AbilityModuleFactory $factory ): AbilityModuleInterface {
		return self::module(
			$factory,
			'taxonomy.delete_term',
			'Delete a Category or Tag',
			'Delete a category, tag, or custom content group after capability and confirmation checks.',
			'content:draft',
			false,
			self::object_schema(
				array(
					'taxonomy' => array( 'type' => 'string' ),
					'term_id'  => array( 'type' => 'integer' ),
				),
				array( 'taxonomy', 'term_id' )
			),
			static fn ( array $args ): array => ( new TaxonomyAbilities() )->delete_term( $args )
		);
	}

	private static function update_term( AbilityModuleFactory $factory ): AbilityModuleInterface {
		return self::module(
			$factory,
			'taxonomy.update_term',
			'Update a Category or Tag',
			'Update a category, tag, or custom content group.',
			'content:draft',
			false,
			self::object_schema(
				array(
					'taxonomy'    => array( 'type' => 'string' ),
					'term_id'     => array( 'type' => 'integer' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'parent'      => array( 'type' => 'integer' ),
				),
				array( 'taxonomy', 'term_id' )
			),
			static fn ( array $args ): array => ( new TaxonomyAbilities() )->update_term( $args )
		);
	}

	private static function set_term_image( AbilityModuleFactory $factory ): AbilityModuleInterface {
		$properties = array(
			'taxonomy'    => array( 'type' => 'string' ),
			'term_id'     => array( 'type' => 'integer' ),
			'image_id'    => array(
				'type'        => 'integer',
				'description' => 'Existing image attachment ID to assign as the term image.',
			),
			'clear_image' => array(
				'type'        => 'boolean',
				'description' => 'Set true to intentionally clear the term image.',
			),
			'meta_key'    => array(
				'type'        => 'string',
				'description' => 'Allowlisted term meta key. Defaults to aculect_ai_companion_term_image_id.',
			),
		);

		return self::module( $factory, 'taxonomy.set_term_image', 'Set Category or Tag Image', 'Assign or clear an image attachment for an allowlisted taxonomy term image meta key.', 'content:draft', false, self::object_schema( $properties, array( 'taxonomy', 'term_id' ) ), static fn ( array $args ): array => ( new TaxonomyAbilities() )->set_term_image( $args ) );
	}

	/**
	 * Create one taxonomy module.
	 *
	 * @param AbilityModuleFactory $factory     Module factory.
	 * @param string               $id          Internal ability ID.
	 * @param string               $title       Admin-facing title.
	 * @param string               $description Assistant-facing description.
	 * @param string               $scope       Required OAuth scope.
	 * @param bool                 $read_only   Whether the ability is read-only.
	 * @param array<string, mixed> $schema      Input schema.
	 * @param Closure              $handler     Execution callback.
	 */
	private static function module( AbilityModuleFactory $factory, string $id, string $title, string $description, string $scope, bool $read_only, array $schema, Closure $handler ): AbilityModuleInterface {
		return $factory->create( $id, $title, $description, 'Content Groups', $scope, $read_only, $schema, $handler );
	}

	/**
	 * Build an object schema.
	 *
	 * @param array<string, mixed> $properties Schema properties.
	 * @param array                $required   Required property names.
	 * @phpstan-param list<string> $required
	 * @return array<string, mixed>
	 */
	private static function object_schema( array $properties, array $required = array() ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);

		if ( array() !== $required ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Build an empty object schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_schema(): array {
		return self::object_schema( array() );
	}

	/**
	 * Build a bounded page-number schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function page_schema(): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'One-based page number. Defaults to 1.',
		);
	}

	/**
	 * Build a bounded per-page schema.
	 *
	 * @param int    $maximum     Maximum accepted value.
	 * @param string $description Schema description.
	 * @return array<string, mixed>
	 */
	private static function per_page_schema( int $maximum, string $description ): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'maximum'     => $maximum,
			'description' => $description,
		);
	}
}

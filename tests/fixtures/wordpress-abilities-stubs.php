<?php
/**
 * WordPress Abilities API stubs for unit tests.
 *
 * @package Aculect\AICompanion\Tests\Fixtures
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- PHPUnit stubs for future WordPress core functions.
if ( ! function_exists( 'wp_register_ability_category' ) ) {
	/**
	 * Record a test WordPress Ability category registration.
	 *
	 * @param string               $slug Category slug.
	 * @param array<string, mixed> $args Category args.
	 */
	function wp_register_ability_category( string $slug, array $args ): object {
		$GLOBALS['aculect_ai_companion_test_wp_ability_categories'][] = array(
			'slug' => $slug,
			'args' => $args,
		);

		return (object) compact( 'slug', 'args' );
	}
}

if ( ! function_exists( 'wp_register_ability' ) ) {
	/**
	 * Record a test WordPress Ability registration.
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Ability args.
	 */
	function wp_register_ability( string $name, array $args ): object {
		$GLOBALS['aculect_ai_companion_test_wp_abilities'][] = array(
			'name' => $name,
			'args' => $args,
		);

		return (object) compact( 'name', 'args' );
	}
}

if ( ! function_exists( 'wp_get_abilities' ) ) {
	/**
	 * Return registered test WordPress Abilities.
	 *
	 * @return list<object>
	 */
	function wp_get_abilities(): array {
		return array_values(
			array_map(
				static function ( array $ability ): object {
					return new class( (string) $ability['name'], is_array( $ability['args'] ?? null ) ? $ability['args'] : array() ) {

						/**
						 * Ability name.
						 *
						 * @var string
						 */
						private string $name;

						/**
						 * Registration args.
						 *
						 * @var array<string, mixed>
						 */
						private array $args;

						/**
						 * Constructor.
						 *
						 * @param string               $name Ability name.
						 * @param array<string, mixed> $args Registration args.
						 */
						public function __construct( string $name, array $args ) {
							$this->name = $name;
							$this->args = $args;
						}

						/**
						 * Return ability name.
						 */
						public function get_name(): string {
							return $this->name;
						}

						/**
						 * Return ability label.
						 */
						public function get_label(): string {
							return is_scalar( $this->args['label'] ?? null ) ? (string) $this->args['label'] : '';
						}

						/**
						 * Return ability description.
						 */
						public function get_description(): string {
							return is_scalar( $this->args['description'] ?? null ) ? (string) $this->args['description'] : '';
						}

						/**
						 * Return ability category.
						 */
						public function get_category(): string {
							return is_scalar( $this->args['category'] ?? null ) ? (string) $this->args['category'] : '';
						}

						/**
						 * Return input schema.
						 *
						 * @return array<string, mixed>
						 */
						public function get_input_schema(): array {
							return is_array( $this->args['input_schema'] ?? null ) ? $this->args['input_schema'] : array();
						}

						/**
						 * Return output schema.
						 *
						 * @return array<string, mixed>
						 */
						public function get_output_schema(): array {
							return is_array( $this->args['output_schema'] ?? null ) ? $this->args['output_schema'] : array();
						}

						/**
						 * Return permission callback.
						 */
						public function get_permission_callback(): mixed {
							return $this->args['permission_callback'] ?? null;
						}

						/**
						 * Return ability meta.
						 *
						 * @return array<string, mixed>
						 */
						public function get_meta(): array {
							return is_array( $this->args['meta'] ?? null ) ? $this->args['meta'] : array();
						}

						/**
						 * Execute the ability callback.
						 *
						 * @param array<string, mixed> $input Ability input.
						 * @return mixed
						 */
						public function execute( array $input = array() ): mixed {
							return is_callable( $this->args['execute_callback'] ?? null )
								? call_user_func( $this->args['execute_callback'], $input )
								: null;
						}
					};
				},
				is_array( $GLOBALS['aculect_ai_companion_test_wp_abilities'] ?? null ) ? $GLOBALS['aculect_ai_companion_test_wp_abilities'] : array()
			)
		);
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound

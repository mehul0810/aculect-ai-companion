<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Closure;

/**
 * Builds the plugin lifecycle ability modules.
 */
final class PluginLifecycleAbilityModules {

	/**
	 * Return plugin lifecycle modules in stable public-surface order.
	 *
	 * @param AbilityModuleFactory $factory Module factory.
	 * @return list<AbilityModuleInterface>
	 */
	public static function all( AbilityModuleFactory $factory ): array {
		return array(
			self::module(
				$factory,
				'plugin_lifecycle.list_plugins',
				'List Plugin Lifecycle Status',
				'List installed WordPress plugins with lifecycle status, active/network-active state, cached update availability, recovery pause state, multisite context, and capability blockers. This tool is read-only and never installs, updates, activates, deactivates, deletes, edits, or executes plugins.',
				'content:read',
				true,
				self::list_schema(),
				static fn ( array $args ): array => ( new PluginLifecycleAbilities() )->list_plugins( $args )
			),
			self::module(
				$factory,
				'plugin_lifecycle.get_plugin',
				'Get Plugin Lifecycle Status',
				'Read one installed WordPress plugin lifecycle status record with safe update and recovery metadata. This tool is read-only and never installs, updates, activates, deactivates, deletes, edits, or executes plugins.',
				'content:read',
				true,
				self::get_schema(),
				static fn ( array $args ): array => ( new PluginLifecycleAbilities() )->get_plugin( $args )
			),
			self::module(
				$factory,
				'plugin_lifecycle.install_plugin',
				'Install a WordPress.org Plugin',
				'Install one inactive plugin from WordPress.org through the WordPress core plugin upgrader after a dry-run preview, capability check, and explicit confirmation.',
				'content:draft',
				false,
				self::install_schema(),
				static fn ( array $args ): array => ( new PluginLifecycleAbilities() )->install_plugin( $args )
			),
			self::module(
				$factory,
				'plugin_lifecycle.update_plugin',
				'Update an Installed Plugin',
				'Update one installed WordPress plugin through the core plugin upgrader using cached update metadata, a dry-run preview, capability checks, and explicit confirmation. Remote update checks are never forced.',
				'content:draft',
				false,
				self::mutation_schema(),
				static fn ( array $args ): array => ( new PluginLifecycleAbilities() )->update_plugin( $args )
			),
			self::module(
				$factory,
				'plugin_lifecycle.activate_plugin',
				'Activate an Installed Plugin',
				'Activate one already-installed WordPress plugin on the current site with dry-run preview, confirmation-token gating, capability checks, and structured results. Network-wide activation remains out of scope.',
				'content:draft',
				false,
				self::mutation_schema(),
				static fn ( array $args ): array => ( new PluginLifecycleAbilities() )->activate_plugin( $args )
			),
			self::module(
				$factory,
				'plugin_lifecycle.deactivate_plugin',
				'Deactivate an Installed Plugin',
				'Deactivate one already-installed WordPress plugin on the current site with dry-run preview, confirmation-token gating, capability checks, and structured results. Network-wide deactivation remains out of scope.',
				'content:draft',
				false,
				self::mutation_schema(),
				static fn ( array $args ): array => ( new PluginLifecycleAbilities() )->deactivate_plugin( $args )
			),
		);
	}

	/**
	 * Create one plugin lifecycle module.
	 *
	 * @param AbilityModuleFactory $factory     Module factory.
	 * @param string               $id          Internal ID.
	 * @param string               $title       Admin-facing title.
	 * @param string               $description Assistant-facing description.
	 * @param string               $scope       Required OAuth scope.
	 * @param bool                 $read_only   Whether the ability is read-only.
	 * @param array<string, mixed> $schema      Input schema.
	 * @param Closure              $handler     Execution callback.
	 */
	private static function module( AbilityModuleFactory $factory, string $id, string $title, string $description, string $scope, bool $read_only, array $schema, Closure $handler ): AbilityModuleInterface {
		return $factory->create( $id, $title, $description, 'Plugin Lifecycle', $scope, $read_only, $schema, $handler );
	}

	/**
	 * Build the list schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function list_schema(): array {
		return self::object_schema(
			array(
				'status'   => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'active', 'inactive', 'network_active', 'update_available', 'paused' ),
					'description' => 'Optional lifecycle status filter. Defaults to all.',
				),
				'page'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'One-based result page. Defaults to 1.',
				),
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => 'Maximum plugins to return. Defaults to 50 and caps at 100.',
				),
			)
		);
	}

	/**
	 * Build the installed plugin lookup schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_schema(): array {
		return self::object_schema(
			array(
				'plugin' => array(
					'type'        => 'string',
					'description' => 'Installed plugin basename, for example example-plugin/example-plugin.php.',
				),
			),
			array( 'plugin' )
		);
	}

	/**
	 * Build the WordPress.org install schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function install_schema(): array {
		return self::object_schema(
			array(
				'slug' => array(
					'type'        => 'string',
					'description' => 'WordPress.org plugin slug, for example classic-editor.',
				),
			),
			array( 'slug' )
		);
	}

	/**
	 * Build a plugin mutation schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function mutation_schema(): array {
		return self::object_schema(
			array(
				'plugin' => array(
					'type'        => 'string',
					'description' => 'Installed plugin basename, for example example-plugin/example-plugin.php.',
				),
			),
			array( 'plugin' )
		);
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
}

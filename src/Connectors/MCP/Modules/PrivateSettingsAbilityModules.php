<?php
/**
 * Secret-free discovery and handoff to authenticated WordPress forms.
 *
 * @package Aculect\AICompanion\Connectors\MCP\Modules
 */

declare(strict_types=1);
namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Settings\PrivateSettingRequests;
use Aculect\AICompanion\Settings\PrivateSettingTargets;

/** No tool accepts a setting value, secret or arbitrary option name. */
final class PrivateSettingsAbilityModules {
	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Declare discovery, request and status tools.
	 *
	 * @return array<string,AbilityModuleInterface>
	 */
	public function all(): array {
		$modules = array();
		foreach ( array( 'targets', 'input', 'status' ) as $action ) {
			$id         = 'settings.private_' . $action;
			$properties = match ( $action ) {
				'input' => array(
					'target' => array(
						'type' => 'string',
						'enum' => array_keys( ( new PrivateSettingTargets() )->all() ),
					),
				),
				'status' => array(
					'request_id' => array(
						'type'    => 'string',
						'pattern' => '^[a-f0-9]{32}$',
					),
				),
				default => array(),
			};
			$modules[ $id ] = $this->factory->create(
				$id,
				'Private Settings ' . ucfirst( $action ),
				match ( $action ) {
					'input' => 'Prepare a ten-minute WordPress-hosted input form for one allowlisted setting. The user must open and fill it manually; never ask for, enter, read, or capture the value through chat or browser automation. Returns a URL, not a secret. A new request replaces this administrator\'s previous request.',
					'status' => 'Read only the outcome of this administrator\'s private settings request. Never returns the entered value or a credential fragment.',
					default => 'List supported private settings forms and availability without reading setting values. Permalinks use the native WordPress screen.',
				},
				'Site Settings',
				'input' === $action ? 'content:draft' : 'content:read',
				'input' !== $action,
				array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => $properties,
					'required'             => array_keys( $properties ),
				),
				static fn( array $args ): array => self::execute( $action, $args )
			);
		}
		return $modules;
	}

	/**
	 * Execute a value-free tool.
	 *
	 * @param string              $action Fixed operation.
	 * @param array<string,mixed> $args Tool arguments.
	 * @return array<string,mixed>
	 */
	private static function execute( string $action, array $args ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'error' => 'forbidden' );
		}
		$requests = new PrivateSettingRequests();
		if ( 'input' === $action ) {
			if ( true === ( $args['dry_run'] ?? false ) ) {
				return array(
					'status'  => 'preview',
					'message' => 'The user will enter and confirm the value on WordPress. No setting changes until they submit the form.',
				);
			}
			return $requests->begin( is_string( $args['target'] ?? null ) ? $args['target'] : '' );
		}
		if ( 'status' === $action ) {
			return $requests->status( is_string( $args['request_id'] ?? null ) ? $args['request_id'] : '' );
		}
		$targets = new PrivateSettingTargets();
		$items   = array();
		foreach ( $targets->all() as $id => $target ) {
			$items[] = array(
				'target'    => $id,
				'label'     => $target['label'],
				'group'     => $target['group'],
				'sensitive' => $target['secret'],
				'available' => is_ssl() && $targets->available( $id ),
			);
		}
		return array(
			'items'          => $items,
			'permalinks_url' => admin_url( 'options-permalink.php' ),
			'message'        => 'Secret entry is available only in the WordPress form. Availability requires HTTPS and, for connector keys, an installed provider without environment or constant overrides.',
		);
	}
}

<?php
/**
 * Explicit client-driven memory exchange over the existing authenticated MCP transport.
 *
 * @package Aculect\AICompanion\Connectors\MCP\Modules
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Intelligence\Memory\Sync\SiteMemorySyncAdapter;
use RuntimeException;

/** Keeps memory navigation metadata separate from mandatory authorization and opt-in. */
final class MemorySyncAbilityModules {
	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Return the two bounded exchange endpoints.
	 *
	 * @return array<string,AbilityModuleInterface>
	 */
	public function all(): array {
		$modules = array();
		foreach ( array( 'pull', 'push' ) as $direction ) {
			$id             = 'memory.sync_' . $direction;
			$read           = 'pull' === $direction;
			$modules[ $id ] = $this->factory->create(
				$id,
				$read ? 'Pull Site Memory Changes' : 'Propose Synced Memory',
				$read ? 'Opt-in administrator-only exchange of approved, non-sensitive site context and content-free invalidations. This does not read ChatGPT or Claude personal memory.' : 'Import up to 20 versioned external proposals for explicit admin review. Replays cannot overwrite approved site guidance. Requires explicit confirmation and site opt-in.',
				'Aculect Memory',
				$read ? 'content:read' : 'content:draft',
				$read,
				$this->schema( $read ),
				static fn ( array $args ): array => self::execute( $direction, $args )
			);
		}
		return $modules;
	}

	/**
	 * Dispatch only validated identity to the neutral adapter.
	 *
	 * @param string              $direction Exchange direction.
	 * @param array<string,mixed> $args Tool arguments.
	 * @return array<string,mixed>
	 */
	private static function execute( string $direction, array $args ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'status' => 'error',
				'error'  => 'forbidden',
			);
		}
		foreach ( array( 'connector', 'namespace', 'cursor' ) as $key ) {
			if ( isset( $args[ $key ] ) && ! is_string( $args[ $key ] ) ) {
				return array(
					'status' => 'error',
					'error'  => 'invalid_memory_sync_identity',
				);
			}
		}
		$connector = 'user:' . get_current_user_id() . ':' . ( $args['connector'] ?? 'mcp' );
		$adapter   = new SiteMemorySyncAdapter( $connector, $args['namespace'] ?? 'site' );
		if ( 'push' === $direction && true === ( $args['dry_run'] ?? false ) ) {
			return array(
				'status'  => 'preview',
				'mutates' => false,
				'message' => 'Incoming memories will be private pending proposals; existing site guidance will not be overwritten.',
			);
		}
		try {
			$result             = 'pull' === $direction
				? $adapter->pull( $args['cursor'] ?? '', is_int( $args['limit'] ?? null ) ? $args['limit'] : 20 )
				: $adapter->push( is_array( $args['items'] ?? null ) ? $args['items'] : array(), $args['cursor'] ?? '' );
			$result['status']   = empty( $result['rejected'] ) ? 'success' : 'partial';
			$result['protocol'] = array(
				'source_of_truth'       => 'site',
				'refresh_after_seconds' => 300,
				'replica_policy'        => 'Do not use expired records or a replica older than five minutes. Restart with an empty cursor on refresh; apply remove operations without retaining their content.',
			);
			return $result;
		} catch ( RuntimeException $error ) {
			return array(
				'status'  => 'error',
				'error'   => $error->getMessage(),
				'message' => 'Check opt-in, permissions and cursor validity. Do not advance a failed batch checkpoint.',
			);
		}
	}

	/**
	 * Describe closed batch and cursor contracts.
	 *
	 * @param bool $read Read-only direction.
	 * @return array<string,mixed>
	 */
	private function schema( bool $read ): array {
		$properties = array(
			'connector' => array(
				'type'        => 'string',
				'pattern'     => '^[a-zA-Z0-9:_\-.]{1,80}$',
				'description' => 'Stable application identifier scoped to the authenticated WordPress user.',
			),
			'namespace' => array(
				'type'    => 'string',
				'pattern' => '^[a-zA-Z0-9:_\-.]{1,191}$',
			),
			'cursor'    => array(
				'type'      => 'string',
				'maxLength' => $read ? 1024 : 191,
			),
		);
		if ( $read ) {
			$properties['limit'] = array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 20,
			);
		} else {
			$properties['items'] = array(
				'type'     => 'array',
				'minItems' => 1,
				'maxItems' => 20,
				'items'    => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'id', 'version', 'value' ),
					'properties'           => array(
						'id'      => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 191,
						),
						'version' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'value'   => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 4000,
						),
						'domain'  => array(
							'type' => 'string',
							'enum' => array( 'brand', 'site', 'content', 'developer', 'seo', 'workflow' ),
						),
					),
				),
			);
		}
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => $properties,
			'required'             => $read ? array() : array( 'items' ),
		);
	}
}

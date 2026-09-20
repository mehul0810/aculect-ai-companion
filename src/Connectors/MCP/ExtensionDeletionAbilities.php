<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Performs explicitly confirmed, single-site extension deletions through WordPress core.
 */
class ExtensionDeletionAbilities extends AbstractAbilityService {

	private const DELETE_WARNINGS = array(
		'WordPress may run uninstall hooks that permanently delete extension data.',
		'No rollback is available; review the installed state in WordPress if deletion is interrupted.',
		'WordPress does not lock out concurrent native extension changes during this operation.',
	);

	/**
	 * Target and confirmation-state policy.
	 *
	 * @var ExtensionDeletionPolicy
	 */
	private ExtensionDeletionPolicy $policy;

	/**
	 * Create the deletion ability with an isolated target policy.
	 *
	 * @param ExtensionDeletionPolicy|null $policy Target and binding policy.
	 */
	public function __construct( ?ExtensionDeletionPolicy $policy = null ) {
		$this->policy = $policy ?? new ExtensionDeletionPolicy();
	}

	/**
	 * Preview or delete one installed plugin or theme with an exact gateway binding.
	 *
	 * @param string               $kind Plugin or theme.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function delete( string $kind, array $args ): array {
		try {
			return $this->delete_checked( $kind, $args );
		} catch ( \Throwable ) {
			return $this->error( 'extension_delete_failed', 'WordPress could not safely inspect this extension deletion request.' );
		}
	}

	/**
	 * Run validation, build a preview, or execute the bound core deletion.
	 *
	 * @param string               $kind Plugin or theme.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function delete_checked( string $kind, array $args ): array {
		$definition = $this->definition( $kind );
		if ( null === $definition ) {
			return $this->error( 'invalid_extension_kind', 'Only plugins and themes can be deleted through this workflow.' );
		}
		if ( ! current_user_can( $definition['capability'] ) ) {
			return $this->error( 'forbidden', 'You do not have permission to delete this extension.' );
		}
		if ( ! function_exists( 'is_multisite' ) || is_multisite() ) {
			return $this->error( 'multisite_scope', 'Extension deletion is disabled on multisite.' );
		}
		if ( ! function_exists( 'wp_is_file_mod_allowed' ) || ! wp_is_file_mod_allowed( $kind ) ) {
			return $this->error( 'file_modifications_disabled', 'WordPress file modifications are disabled.' );
		}

		$target_arg = 'plugin' === $kind ? 'plugin' : 'stylesheet';
		$plan       = $this->policy->inspect( $kind, $args[ $target_arg ] ?? null );
		if (
			isset( $plan['error'] )
			|| ! is_array( $plan['target'] ?? null )
			|| ! is_string( $plan['target']['id'] ?? null )
			|| ! is_array( $plan['binding'] ?? null )
			|| ! is_string( $plan['filesystem_context'] ?? null )
			|| '' === $plan['filesystem_context']
		) {
			return is_array( $plan ) && isset( $plan['error'] ) ? $plan : $this->error( 'invalid_extension_target', 'Requested extension could not be safely resolved.' );
		}
		if ( ! $this->direct_filesystem_available( $plan['filesystem_context'] ) ) {
			return $this->error( 'direct_filesystem_required', 'Deletion requires the direct filesystem method; credential prompts are not supported.' );
		}

		if ( $this->is_dry_run( $args ) ) {
			$preview = $this->preview_response(
				$definition['operation'],
				$args,
				$plan['target'],
				array( $this->change( 'installed', true, false ) ),
				self::DELETE_WARNINGS
			);
			return $this->policy->with_confirmation_binding( $preview, $plan['binding'] );
		}

		$binding = $this->policy->requested_confirmation_binding( $args );
		if ( null === $binding ) {
			return $this->error( 'confirmation_required', 'Preview this deletion and confirm the exact target before execution.' );
		}
		if ( ! $this->policy->binding_matches( $binding, $plan['binding'] ) ) {
			return $this->error( 'invalid_confirmation_binding', 'The installed extension state changed after preview; create a new preview before confirming.' );
		}
		if ( ! $this->core_delete_available( $kind ) ) {
			return $this->error( 'extension_delete_unavailable', 'WordPress deletion APIs are unavailable for this extension.' );
		}

		return $this->execute_delete( $kind, $definition, $plan );
	}

	/**
	 * Enter the core deletion boundary and make uncertain outcomes terminal.
	 *
	 * @param string                                    $kind       Extension kind.
	 * @param array{capability:string,operation:string} $definition Fixed tool metadata.
	 * @param array<string, mixed>                      $plan       Current validated deletion plan.
	 * @return array<string, mixed>
	 */
	private function execute_delete( string $kind, array $definition, array $plan ): array {
		$target = $plan['target'];
		try {
			$result = $this->delete_core( $kind, (string) $target['id'], $plan['filesystem_context'] );
			if ( true !== $result || $this->policy->is_installed( $kind, (string) $target['id'] ) ) {
				return $this->partial_write_result( $kind, $target );
			}
		} catch ( \Throwable ) {
			return $this->partial_write_result( $kind, $plan['target'] );
		}

		return array(
			'status'                => 'deleted',
			'operation'             => $definition['operation'],
			'target'                => $plan['target'],
			'changed'               => true,
			'verified'              => true,
			'warnings'              => self::DELETE_WARNINGS,
			'confirmation_required' => false,
		);
	}

	/**
	 * Return fixed tool metadata for supported deletion kinds.
	 *
	 * @param string $kind Extension kind.
	 * @return array{capability:string,operation:string}|null
	 */
	private function definition( string $kind ): ?array {
		return match ( $kind ) {
			'plugin' => array(
				'capability' => 'delete_plugins',
				'operation'  => 'plugin_lifecycle.delete_plugin',
			),
			'theme'  => array(
				'capability' => 'delete_themes',
				'operation'  => 'theme_lifecycle.delete_theme',
			),
			default  => null,
		};
	}

	/**
	 * Require direct filesystem access without ever allowing a credentials prompt.
	 *
	 * @param string $context Extension root.
	 */
	private function direct_filesystem_available( string $context ): bool {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			if ( defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
		}
		return function_exists( 'get_filesystem_method' ) && 'direct' === get_filesystem_method( array(), $context );
	}

	/**
	 * Ensure the requested WordPress core deletion API and filter surface exist.
	 *
	 * @param string $kind Extension kind.
	 */
	protected function core_delete_available( string $kind ): bool {
		if ( defined( 'ABSPATH' ) ) {
			$file = 'plugin' === $kind ? 'plugin.php' : 'theme.php';
			$path = ABSPATH . 'wp-admin/includes/' . $file;
			if ( is_file( $path ) ) {
				require_once $path;
			}
			$filesystem_path = ABSPATH . 'wp-admin/includes/file.php';
			if ( is_file( $filesystem_path ) ) {
				require_once $filesystem_path;
			}
		}
		return function_exists( 'add_filter' )
			&& function_exists( 'remove_filter' )
			&& function_exists( 'request_filesystem_credentials' )
			&& function_exists( 'get_filesystem_method' )
			&& ( 'plugin' === $kind ? function_exists( 'delete_plugins' ) : function_exists( 'delete_theme' ) );
	}

	/**
	 * Run exactly one WordPress core delete API with scoped credential and output handling.
	 *
	 * @param string $kind    Extension kind.
	 * @param string $target  Exact validated plugin basename or theme stylesheet.
	 * @param string $context Trusted extension root.
	 * @return mixed Core result.
	 */
	protected function delete_core( string $kind, string $target, string $context ): mixed {
		$credential_filter = static function ( mixed $credentials ) use ( $context ): bool {
			unset( $credentials );
			try {
				return function_exists( 'get_filesystem_method' ) && 'direct' === get_filesystem_method( array(), $context );
			} catch ( \Throwable ) {
				return false;
			}
		};
		$buffer_level      = ob_get_level();
		add_filter( 'request_filesystem_credentials', $credential_filter, PHP_INT_MAX, 1 );
		ob_start();
		try {
			return 'plugin' === $kind ? delete_plugins( array( $target ) ) : delete_theme( $target );
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
			remove_filter( 'request_filesystem_credentials', $credential_filter, PHP_INT_MAX );
		}
	}

	/**
	 * Report an uncertain post-invocation result as terminal to prevent replaying uninstall hooks.
	 *
	 * @param string               $kind   Extension kind.
	 * @param array<string, mixed> $target Safe target metadata, when available.
	 * @return array<string, mixed>
	 */
	private function partial_write_result( string $kind, array $target ): array {
		return array(
			'error'     => 'partial_write',
			'message'   => 'WordPress began extension deletion but its final state could not be verified. Review the extension in WordPress before retrying.',
			'terminal'  => true,
			'operation' => 'plugin' === $kind ? 'plugin_lifecycle.delete_plugin' : 'theme_lifecycle.delete_theme',
			'target'    => $target,
		);
	}
}

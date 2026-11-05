<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Connectors\MCP\McpSkillsRegistry;
use Aculect\AICompanion\Connectors\MCP\Skills\CustomMcpSkillStore;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only CRUD API for site-local custom Skills.
 */
final class SkillsRestController {

	private const ROUTE       = '/skills';
	private const MAX_PACKAGE = 270336;
	private const MAX_REQUEST = 600000;

	private CustomMcpSkillStore $store;

	public function __construct( ?CustomMcpSkillStore $store = null ) {
		$this->store = $store ?? new CustomMcpSkillStore();
	}

	/**
	 * Register the Skills REST API.
	 */
	public function register_rest_routes(): void {
		register_rest_route( 'aculect-ai-companion/v1', self::ROUTE, $this->route( 'GET', 'list_skills' ) );
		register_rest_route( 'aculect-ai-companion/v1', self::ROUTE, $this->route( 'POST', 'create_skill' ) );
		register_rest_route( 'aculect-ai-companion/v1', self::ROUTE . '/(?P<id>[a-z0-9-]{1,64})', $this->route( 'GET', 'get_skill' ) );
		register_rest_route( 'aculect-ai-companion/v1', self::ROUTE . '/(?P<id>[a-z0-9-]{1,64})', $this->route( 'PUT', 'update_skill' ) );
		register_rest_route( 'aculect-ai-companion/v1', self::ROUTE . '/(?P<id>[a-z0-9-]{1,64})', $this->route( 'DELETE', 'delete_skill' ) );
		register_rest_route( 'aculect-ai-companion/v1', self::ROUTE . '/(?P<id>[a-z0-9-]{1,64})/enabled', $this->route( 'POST', 'set_enabled' ) );
		register_rest_route( 'aculect-ai-companion/v1', self::ROUTE . '/(?P<id>[a-z0-9-]{1,64})/duplicate', $this->route( 'POST', 'duplicate_skill' ) );
		register_rest_route( 'aculect-ai-companion/v1', self::ROUTE . '/(?P<id>[a-z0-9-]{1,64})/export', $this->route( 'GET', 'export_skill' ) );
		register_rest_route( 'aculect-ai-companion/v1', self::ROUTE . '/import', $this->route( 'POST', 'import_skill' ) );
	}

	/**
	 * Require administrative access for every registered route.
	 */
	public function can_manage_skills(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return all local skill sources without exposing Markdown bodies.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress REST callbacks supply the request object by contract.
	public function list_skills( WP_REST_Request $request ): WP_REST_Response {
		$skills = array_map(
			static function ( array $skill ): array {
				$skill['provider']            = $skill['source'];
				$skill['availability_reason'] = empty( $skill['available'] )
					? ( empty( $skill['enabled'] ) ? __( 'Disabled on this site.', 'aculect-ai-companion' ) : __( 'Dependencies are unavailable on this site.', 'aculect-ai-companion' ) )
					: '';
				return $skill;
			},
			( new McpSkillsRegistry( $this->store ) )->admin_catalog()
		);

		return new WP_REST_Response( array( 'skills' => $skills ) );
	}

	/**
	 * Retrieve one custom Skill document.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function get_skill( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->store_response( $this->store->get( $this->request_id( $request ) ) );
	}

	/**
	 * Create a validated custom Skill.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function create_skill( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = $this->document_input( $request );
		return is_wp_error( $input ) ? $input : $this->store_response( $this->store->create( $input ) );
	}

	/**
	 * Replace the documents of an existing custom Skill.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function update_skill( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = $this->document_input( $request );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		if ( $input['id'] !== $this->request_id( $request ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The Skill ID must match the requested URL.', 'aculect-ai-companion' ), array( 'status' => 400 ) );
		}
		$revision = $this->expected_revision( $input, true );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		unset( $input['expected_version'], $input['expected_digest'] );

		return $this->store_response( $this->store->update( $this->request_id( $request ), $input, $revision['version'], $revision['digest'] ) );
	}

	/**
	 * Delete a custom Skill after the dashboard's explicit confirmation.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function delete_skill( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = $this->json_input( $request );
		if ( null === $input && '' === $request->get_body() ) {
			$input = array();
		}
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'expected_version', 'expected_digest' ) ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The delete request contains unsupported fields.', 'aculect-ai-companion' ), array( 'status' => 400 ) );
		}
		$revision = $this->expected_revision( $input, true );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		return $this->store_response( $this->store->delete( $this->request_id( $request ), $revision['version'], $revision['digest'] ) );
	}

	/**
	 * Change only the activation state of a custom Skill.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function set_enabled( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = $this->json_input( $request );
		if ( ! is_array( $input ) || ! array_key_exists( 'enabled', $input ) || ! is_bool( $input['enabled'] ) || array_diff( array_keys( $input ), array( 'enabled', 'expected_version', 'expected_digest' ) ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Provide a boolean enabled value and optional matching revision.', 'aculect-ai-companion' ), array( 'status' => 400 ) );
		}
		$revision = $this->expected_revision( $input, true );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}

		return $this->store_response( $this->store->set_enabled( $this->request_id( $request ), $input['enabled'], $revision['version'], $revision['digest'] ) );
	}

	/**
	 * Duplicate a custom Skill to a caller-selected custom ID.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function duplicate_skill( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = $this->json_input( $request );
		if ( ! is_array( $input ) || 1 !== count( $input ) || ! is_string( $input['id'] ?? null ) || ! $this->valid_custom_id( $input['id'] ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Provide a valid custom Skill ID.', 'aculect-ai-companion' ), array( 'status' => 400 ) );
		}

		return $this->store_response( $this->store->duplicate( $this->request_id( $request ), $input['id'] ) );
	}

	/**
	 * Export a custom Skill as its versioned JSON package.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function export_skill( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->store_response( $this->store->export( $this->request_id( $request ) ) );
	}

	/**
	 * Import a bounded versioned package; replacement must be explicitly selected.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function import_skill( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = $this->json_input( $request );
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'package', 'replace', 'expected_version', 'expected_digest' ) ) || ! is_string( $input['package'] ?? null ) || strlen( $input['package'] ) > self::MAX_PACKAGE || ( isset( $input['replace'] ) && ! is_bool( $input['replace'] ) ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Provide a valid Skill package and optional boolean replacement choice.', 'aculect-ai-companion' ), array( 'status' => 400 ) );
		}
		$revision = $this->expected_revision( $input );
		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		$package = json_decode( $input['package'], true, 12 );
		$id      = is_array( $package ) && is_array( $package['skill'] ?? null ) ? ( $package['skill']['id'] ?? null ) : null;
		if ( true === ( $input['replace'] ?? false ) && is_string( $id ) && ! empty( $this->store->get( $id )['success'] ) && null === $revision['version'] ) {
			return new WP_Error( 'skill_conflict', __( 'Reload the existing Skill before replacing it.', 'aculect-ai-companion' ), array( 'status' => 409 ) );
		}

		return $this->store_response( $this->store->import( $input['package'], $input['replace'] ?? false, $revision['version'], $revision['digest'] ) );
	}

	/**
	 * Build a route declaration with the shared administrative permission check.
	 *
	 * @param string $method HTTP method.
	 * @param string $callback Controller method.
	 * @return array<string, mixed>
	 */
	private function route( string $method, string $callback ): array {
		return array(
			'methods'             => $method,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'can_manage_skills' ),
		);
	}

	/**
	 * Accept only a JSON object with fields supported by the custom store.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function document_input( WP_REST_Request $request ): array|WP_Error {
		$input = $this->json_input( $request );
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'id', 'skill_md', 'references', 'enabled', 'required_abilities', 'required_plugins', 'expected_version', 'expected_digest' ) ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The Skill document payload contains unsupported fields.', 'aculect-ai-companion' ), array( 'status' => 400 ) );
		}

		if ( ! is_string( $input['id'] ?? null ) || ! $this->valid_custom_id( $input['id'] ) || ! is_string( $input['skill_md'] ?? null ) || strlen( $input['skill_md'] ) > 32768 || ! is_array( $input['references'] ?? array() ) || ( isset( $input['enabled'] ) && ! is_bool( $input['enabled'] ) ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The Skill ID, Markdown, references, or enabled value is invalid.', 'aculect-ai-companion' ), array( 'status' => 400 ) );
		}

		return $input;
	}

	/**
	 * Validate an optional optimistic revision pair.
	 *
	 * @param array<string,mixed> $input Request data.
	 * @param bool                $required Whether a mutation must prove its read version.
	 * @return array{version:int|null,digest:string|null}|WP_Error
	 */
	private function expected_revision( array $input, bool $required = false ): array|WP_Error {
		$has_version = array_key_exists( 'expected_version', $input );
		$has_digest  = array_key_exists( 'expected_digest', $input );
		if ( $has_version !== $has_digest ) {
			return new WP_Error( 'rest_invalid_param', __( 'Provide both the expected Skill version and digest.', 'aculect-ai-companion' ), array( 'status' => 400 ) );
		}
		if ( ! $has_version ) {
			if ( $required ) {
				return new WP_Error( 'rest_invalid_param', __( 'Reload the Skill to provide its current version and digest.', 'aculect-ai-companion' ), array( 'status' => 400 ) );
			}
			return array(
				'version' => null,
				'digest'  => null,
			);
		}
		$version = $input['expected_version'];
		$digest  = $input['expected_digest'];
		if ( ! is_int( $version ) || 1 > $version || ! is_string( $digest ) || 1 !== preg_match( '/\Asha256:[a-f0-9]{64}\z/', $digest ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'The expected Skill revision is invalid.', 'aculect-ai-companion' ), array( 'status' => 400 ) );
		}
		return array(
			'version' => $version,
			'digest'  => $digest,
		);
	}

	/**
	 * Read and bound the decoded JSON request object.
	 *
	 * @param WP_REST_Request $request REST request.
	 *
	 * @return mixed
	 */
	private function json_input( WP_REST_Request $request ): mixed {
		if ( self::MAX_REQUEST < strlen( $request->get_body() ) ) {
			return null;
		}

		return $request->get_json_params();
	}

	/**
	 * Convert a store result to an HTTP response and stable status code.
	 *
	 * @param array<string, mixed> $result Store result.
	 */
	private function store_response( array $result ): WP_REST_Response|WP_Error {
		if ( ! empty( $result['success'] ) ) {
			if ( isset( $result['skill'] ) && is_array( $result['skill'] ) ) {
				$result['skill']['provider'] = 'custom';
			}
			return new WP_REST_Response( $result );
		}

		$code   = (string) ( $result['error'] ?? 'skill_request_failed' );
		$status = match ( $code ) {
			'skill_not_found' => 404,
			'skill_exists', 'skill_conflict', 'skill_version_limit' => 409,
			'storage_unavailable' => 500,
			default => 400,
		};

		return new WP_Error( $code, (string) ( $result['message'] ?? __( 'The Skill request failed.', 'aculect-ai-companion' ) ), array( 'status' => $status ) );
	}

	/**
	 * Resolve the route ID without accepting arbitrary request data.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private function request_id( WP_REST_Request $request ): string {
		$url_params = $request->get_url_params();
		$id         = $url_params['id'] ?? null;
		return is_string( $id ) && $this->valid_custom_id( $id ) ? $id : '';
	}

	/**
	 * Validate the exact ID grammar reserved for site-local custom Skills.
	 *
	 * @param string $id Custom Skill ID.
	 */
	private function valid_custom_id( string $id ): bool {
		return 1 === preg_match( '/\Acustom-[a-z0-9]+(?:-[a-z0-9]+)*\z/', $id ) && 64 >= strlen( $id );
	}
}

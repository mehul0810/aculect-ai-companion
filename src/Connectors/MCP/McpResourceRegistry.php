<?php
/**
 * Bounded MCP resources for clients that prefer resource reads over tool calls.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Exposes compact Aculect Intelligence context through MCP resources.
 */
final class McpResourceRegistry {

	private const MIME_TYPE = 'application/json';

	/**
	 * Return available MCP resources.
	 *
	 * @return array{resources: list<array<string, string>>}
	 */
	public function list_resources(): array {
		return array(
			'resources' => array(
				$this->resource( 'aculect://capabilities/directory', 'Aculect Capability Directory', 'Current WordPress MCP abilities, workflows, intelligence surfaces, and blockers.' ),
				$this->resource( 'aculect://site/summary', 'Aculect Site Summary', 'Stable site, theme, locale, and connector context.' ),
				$this->resource( 'aculect://site-editor/context', 'Aculect Site Editor Context', 'Theme-aware Appearance > Editor context, templates, template parts, global settings, styles, blocks, and patterns.' ),
				$this->resource( 'aculect://admin/menu', 'Aculect Admin Menu Context', 'Visible WordPress admin navigation, settings surfaces, and safe task routing metadata.' ),
				$this->resource( 'aculect://content/model', 'Aculect Content Model', 'Content types, taxonomy, block, pattern, and authoring constraints.' ),
				$this->resource( 'aculect://brand/profile', 'Aculect Brand Profile', 'Saved and detected brand guidance for content and design decisions.' ),
				$this->resource( 'aculect://workflow/guides', 'Aculect Workflow Guides', 'Compact policy-aware workflow guide summaries.' ),
				$this->resource( 'aculect://memory/approved', 'Aculect Approved Memory', 'Approved durable local memory items used by future workflows.' ),
			),
		);
	}

	/**
	 * Read one MCP resource.
	 *
	 * @param array<string, mixed> $args JSON-RPC params.
	 * @return array<string, mixed>
	 */
	public function read_resource( array $args ): array {
		$uri = is_scalar( $args['uri'] ?? null ) ? (string) $args['uri'] : '';
		if ( '' === $uri ) {
			return $this->error( 'invalid_resource_uri', 'Provide a resource URI returned by resources/list.' );
		}

		$data = match ( $uri ) {
			'aculect://capabilities/directory' => ( new IntelligenceContext() )->capabilities( array( 'detail' => 'summary' ) ),
			'aculect://site/summary' => ( new IntelligenceContext() )->site(),
			'aculect://site-editor/context' => ( new SiteEditorAbilities() )->get_context(),
			'aculect://admin/menu' => ( new AdminMenuAbilities() )->get_context(),
			'aculect://content/model' => ( new IntelligenceContext() )->content(),
			'aculect://brand/profile' => ( new IntelligenceContext() )->brand(),
			'aculect://workflow/guides' => ( new WorkflowGuideRegistry() )->list_guides( array( 'detail' => 'summary' ) ),
			'aculect://memory/approved' => $this->approved_memory(),
			default => $this->unknown_resource( $uri ),
		};

		if ( isset( $data['error'] ) && 'resource_not_found' === $data['error'] ) {
			return $data;
		}

		return array(
			'contents' => array(
				array(
					'uri'      => $uri,
					'mimeType' => self::MIME_TYPE,
					'text'     => $this->json( $data ),
				),
			),
		);
	}

	/**
	 * Build one resource descriptor.
	 *
	 * @param string $uri Resource URI.
	 * @param string $name Resource display name.
	 * @param string $description Resource description.
	 * @return array<string, string>
	 */
	private function resource( string $uri, string $name, string $description ): array {
		return array(
			'uri'         => $uri,
			'name'        => $name,
			'description' => $description,
			'mimeType'    => self::MIME_TYPE,
		);
	}

	/**
	 * Return approved memory with runtime guards.
	 *
	 * @return array<string, mixed>
	 */
	private function approved_memory(): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array(
				'status'  => 'unavailable',
				'items'   => array(),
				'total'   => 0,
				'message' => 'The local Aculect memory store is unavailable in this runtime.',
			);
		}

		return ( new IntelligenceIndexAbilities() )->list_memories(
			array(
				'status'   => 'approved',
				'per_page' => 50,
			)
		);
	}

	/**
	 * Return an unknown resource payload.
	 *
	 * @param string $uri Resource URI.
	 * @return array<string, mixed>
	 */
	private function unknown_resource( string $uri ): array {
		return $this->error(
			'resource_not_found',
			sprintf( 'No MCP resource exists for %s.', sanitize_text_field( $uri ) )
		);
	}

	/**
	 * Return an error payload.
	 *
	 * @param string $error Error code.
	 * @param string $message Error message.
	 * @return array<string, mixed>
	 */
	private function error( string $error, string $message ): array {
		return array(
			'error'   => $error,
			'message' => $message,
		);
	}

	/**
	 * Encode resource data as stable JSON.
	 *
	 * @param array<string, mixed> $data Resource data.
	 */
	private function json( array $data ): string {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return false === $json ? '{}' : $json;
	}
}

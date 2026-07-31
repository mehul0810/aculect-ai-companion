<?php
/**
 * Client-side MCP tool filtering guidance.
 *
 * @package Aculect\AICompanion\Connectors\Providers
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\Providers;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;

/**
 * Builds concise, provider-specific client filtering examples from one
 * canonical set of Aculect ability IDs.
 *
 * Client filtering reduces client context and accidental tool selection. It is
 * deliberately not an authorization boundary: OAuth scopes, role policy,
 * WordPress capabilities, and runtime safety checks remain authoritative.
 */
final class ClientToolFilterGuidance {

	/**
	 * Return whether a provider has documented client-side filtering support.
	 *
	 * @param string $provider_id Provider slug.
	 */
	public function supports_provider( string $provider_id ): bool {
		return in_array( $provider_id, array( 'chatgpt', 'gemini', 'claude', 'cursor', 'xai', 'grok' ), true );
	}

	/**
	 * Return advanced setup metadata for one supported provider.
	 *
	 * @param string $provider_id Provider slug.
	 * @return array<string, mixed>
	 */
	public function section_for_provider( string $provider_id ): array {
		$tool_sets = $this->recommended_tool_sets();
		$default   = $tool_sets['read_only_content_audit'];

		$section = array(
			'title'          => 'Optional tool filtering',
			'description'    => 'Start with the read-only audit set. Client-side filtering can reduce model context and accidental tool selection, but it is not an authorization boundary and does not grant or revoke WordPress authorization.',
			'warning'        => 'Aculect OAuth scopes, connection profiles, role policy, WordPress capabilities, and runtime safety checks remain the authorization boundary. Omitting a client allowlist can load the full exposed tool catalog.',
			'defaultToolSet' => $default['id'],
			'toolSets'       => array_values( $tool_sets ),
			'copyFields'     => array(),
		);

		switch ( $provider_id ) {
			case 'chatgpt':
				$section['providerNote'] = 'For an OpenAI Responses API integration, add allowed_tools to the MCP tool configuration. ChatGPT app setup may manage available tools in its own interface.';
				$section['copyFields']   = array(
					$this->copy_field( 'OpenAI Responses API allowed_tools', $this->json_snippet( 'allowed_tools', $default['toolNames'] ) ),
				);
				break;
			case 'gemini':
				$section['providerNote'] = 'Gemini CLI supports includeTools and excludeTools on an MCP server entry. Keep trust disabled; filtering does not bypass confirmations.';
				$section['copyFields']   = array(
					$this->copy_field( 'Gemini includeTools', $this->json_snippet( 'includeTools', $default['toolNames'] ) ),
				);
				break;
			case 'claude':
				$section['providerNote'] = 'For a Claude API MCP connector, use an allowlist. In Claude connector settings, keep only the tools needed for the current conversation enabled.';
				$section['copyFields']   = array(
					$this->copy_field( 'Claude MCP allowed_tools', $this->json_snippet( 'allowed_tools', $default['toolNames'] ) ),
				);
				break;
			case 'cursor':
				$section['providerNote'] = 'Cursor documents available-tool inspection and selection in its MCP settings. Inspect the server first, then select only the read-only tools needed for the task.';
				$section['copyFields']   = array(
					$this->copy_field( 'Cursor MCP inspection command', 'cursor-agent mcp list-tools aculect-ai-companion' ),
				);
				break;
			case 'xai':
			case 'grok':
				$section['providerNote'] = 'For an xAI API remote MCP integration, pass an allowed_tools allowlist. xAI does not support OpenAI Responses require_approval, so keep Aculect server-side policy and your application approval flow in place.';
				$section['copyFields']   = array(
					$this->copy_field( 'xAI allowed_tools', $this->json_snippet( 'allowed_tools', $default['toolNames'] ) ),
					$this->copy_field( 'xAI SDK allowed_tool_names', $this->php_array_snippet( 'allowed_tool_names', $default['toolNames'] ) ),
				);
				break;
		}

		return $section;
	}

	/**
	 * Return named recommended tool sets with public names derived from the
	 * canonical abilities registry.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function recommended_tool_sets(): array {
		$registry = new AbilitiesRegistry();
		$sets     = array(
			'connection_diagnostics'   => array(
				'id'                       => 'connection_diagnostics',
				'label'                    => 'Connection and diagnostics',
				'description'              => 'Verify the connection and inspect safe site readiness before a broader task.',
				'readOnlyDefault'          => true,
				'requiresExplicitApproval' => false,
				'toolIds'                  => array( 'workflow.route_request', 'core_schema.discover', 'site.get_info', 'site.get_health' ),
			),
			'read_only_content_audit'  => array(
				'id'                       => 'read_only_content_audit',
				'label'                    => 'Read-only content audit',
				'description'              => 'Search and review WordPress content without site changes. This is the recommended starting set.',
				'readOnlyDefault'          => true,
				'requiresExplicitApproval' => false,
				'toolIds'                  => array( 'search', 'fetch', 'content.list_items', 'content.get_item', 'content.get_seo' ),
			),
			'draft_content_workflow'   => array(
				'id'                       => 'draft_content_workflow',
				'label'                    => 'Draft content workflow',
				'description'              => 'Prepare and create or update content drafts after the user explicitly chooses a content workflow.',
				'readOnlyDefault'          => false,
				'requiresExplicitApproval' => true,
				'toolIds'                  => array( 'search', 'fetch', 'content_workflow.prepare_post', 'content_workflow.create_draft', 'content_workflow.update_post', 'content.update_seo' ),
			),
			'approved_site_management' => array(
				'id'                       => 'approved_site_management',
				'label'                    => 'Approved site management',
				'description'              => 'Use only for a reviewed site-management task with explicit approval for each intended change.',
				'readOnlyDefault'          => false,
				'requiresExplicitApproval' => true,
				'toolIds'                  => array( 'site.get_info', 'site.get_health', 'plugin_lifecycle.list_plugins', 'plugin_lifecycle.activate_plugin', 'plugin_lifecycle.deactivate_plugin', 'theme_lifecycle.list_themes', 'theme_lifecycle.switch_theme' ),
			),
		);

		foreach ( $sets as $key => $set ) {
			$sets[ $key ]['toolNames'] = array_map(
				static fn ( string $id ): string => $registry->tool_name( $id ),
				$set['toolIds']
			);
		}

		return $sets;
	}

	/**
	 * Return one safe copy-field definition.
	 *
	 * @param string $label Copy-field label.
	 * @param string $value Copy-field value.
	 * @return array{label:string,value:string}
	 */
	private function copy_field( string $label, string $value ): array {
		return array(
			'label' => $label,
			'value' => $value,
		);
	}

	/**
	 * Build a JSON property fragment without a server URL or credentials.
	 *
	 * @param string        $key        Client configuration property name.
	 * @param array<string> $tool_names Public MCP tool names.
	 */
	private function json_snippet( string $key, array $tool_names ): string {
		$json = wp_json_encode( array( $key => $tool_names ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Build a PHP SDK property fragment without a server URL or credentials.
	 *
	 * @param string        $key        Client configuration property name.
	 * @param array<string> $tool_names Public MCP tool names.
	 */
	private function php_array_snippet( string $key, array $tool_names ): string {
		$items = array_map( static fn ( string $name ): string => sprintf( "'%s'", $name ), $tool_names );

		return $key . ' => array( ' . implode( ', ', $items ) . ' )';
	}
}

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
			'title'           => __( 'Optional tool filtering', 'aculect-ai-companion' ),
			'advancedLabel'   => __( 'Advanced', 'aculect-ai-companion' ),
			'description'     => __( 'Start with the read-only audit set. Client-side filtering can reduce model context and accidental tool selection, but it is not an authorization boundary and does not grant or revoke WordPress authorization.', 'aculect-ai-companion' ),
			'warning'         => __( 'Aculect OAuth scopes, connection profiles, role policy, WordPress capabilities, and runtime safety checks remain the authorization boundary. Omitting a client allowlist can load the full exposed tool catalog.', 'aculect-ai-companion' ),
			'readOnlyLabel'   => __( 'Recommended start: read-only audit.', 'aculect-ai-companion' ),
			'approvalLabel'   => __( 'Explicit approval required before using write-capable tools.', 'aculect-ai-companion' ),
			'copyButtonLabel' => __( 'Copy', 'aculect-ai-companion' ),
			'defaultToolSet'  => $default['id'],
			'toolSets'        => array_values( $tool_sets ),
			'copyFields'      => array(),
		);

		switch ( $provider_id ) {
			case 'chatgpt':
				$section['providerNote'] = __( 'For an OpenAI Responses API integration, add allowed_tools to the MCP tool configuration. ChatGPT app setup may manage available tools in its own interface.', 'aculect-ai-companion' );
				$section['copyFields']   = array(
					$this->copy_field( __( 'OpenAI Responses API allowed_tools', 'aculect-ai-companion' ), $this->json_snippet( 'allowed_tools', $default['toolNames'] ) ),
				);
				break;
			case 'gemini':
				$section['providerNote'] = __( 'Gemini CLI supports includeTools and excludeTools on an MCP server entry. Keep trust disabled; filtering does not bypass confirmations.', 'aculect-ai-companion' );
				$section['copyFields']   = array(
					$this->copy_field( __( 'Gemini includeTools', 'aculect-ai-companion' ), $this->json_snippet( 'includeTools', $default['toolNames'] ) ),
				);
				break;
			case 'claude':
				$section['providerNote'] = __( 'For the Claude API, use an mcp_toolset with tools disabled by default and enable only the tools needed for the current task. Claude connector settings may also provide tool selection controls.', 'aculect-ai-companion' );
				$section['copyFields']   = array(
					$this->copy_field( __( 'Claude API mcp_toolset', 'aculect-ai-companion' ), $this->claude_toolset_snippet( $default['toolNames'] ) ),
				);
				break;
			case 'cursor':
				$section['providerNote'] = __( 'Cursor documents available-tool inspection and selection in its MCP settings. Inspect the server first, then select only the read-only tools needed for the task.', 'aculect-ai-companion' );
				$section['copyFields']   = array(
					$this->copy_field( __( 'Cursor MCP inspection command', 'aculect-ai-companion' ), 'cursor-agent mcp list-tools aculect-ai-companion' ),
				);
				break;
			case 'xai':
			case 'grok':
				$section['providerNote'] = __( 'For an xAI API remote MCP integration, pass an allowed_tools allowlist. xAI does not support OpenAI Responses require_approval, so keep Aculect server-side policy and your application approval flow in place.', 'aculect-ai-companion' );
				$section['copyFields']   = array(
					$this->copy_field( __( 'xAI allowed_tools', 'aculect-ai-companion' ), $this->json_snippet( 'allowed_tools', $default['toolNames'] ) ),
					$this->copy_field( __( 'xAI SDK allowed_tool_names', 'aculect-ai-companion' ), $this->php_array_snippet( 'allowed_tool_names', $default['toolNames'] ) ),
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
				'label'                    => __( 'Connection and diagnostics', 'aculect-ai-companion' ),
				'description'              => __( 'Verify the connection and inspect safe site readiness before a broader task.', 'aculect-ai-companion' ),
				'readOnlyDefault'          => true,
				'requiresExplicitApproval' => false,
				'toolIds'                  => array( 'workflow.route_request', 'core_schema.discover', 'site.get_info', 'site.get_health' ),
			),
			'read_only_content_audit'  => array(
				'id'                       => 'read_only_content_audit',
				'label'                    => __( 'Read-only content audit', 'aculect-ai-companion' ),
				'description'              => __( 'Search and review WordPress content without site changes. This is the recommended starting set.', 'aculect-ai-companion' ),
				'readOnlyDefault'          => true,
				'requiresExplicitApproval' => false,
				'toolIds'                  => array( 'search', 'fetch', 'content.list_items', 'content.get_item', 'content.get_seo' ),
			),
			'draft_content_workflow'   => array(
				'id'                       => 'draft_content_workflow',
				'label'                    => __( 'Draft content workflow', 'aculect-ai-companion' ),
				'description'              => __( 'Prepare and create or update content drafts after the user explicitly chooses a content workflow.', 'aculect-ai-companion' ),
				'readOnlyDefault'          => false,
				'requiresExplicitApproval' => true,
				'toolIds'                  => array( 'search', 'fetch', 'content_workflow.prepare_post', 'content_workflow.create_draft', 'content_workflow.update_post', 'content.update_seo' ),
			),
			'approved_site_management' => array(
				'id'                       => 'approved_site_management',
				'label'                    => __( 'Approved site management', 'aculect-ai-companion' ),
				'description'              => __( 'Use only for a reviewed site-management task with explicit approval for each intended change.', 'aculect-ai-companion' ),
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
	 * @return array{label:string,value:string,copiedMessage:string}
	 */
	private function copy_field( string $label, string $value ): array {
		return array(
			'label'         => $label,
			'value'         => $value,
			/* translators: %s: copy field label. */
			'copiedMessage' => sprintf( __( '%s copied.', 'aculect-ai-companion' ), $label ),
		);
	}

	/**
	 * Build the current Claude API MCP toolset shape.
	 *
	 * Tools are disabled by default and enabled one at a time by their public
	 * MCP names. The snippet intentionally excludes server URLs and secrets.
	 *
	 * @param array<string> $tool_names Public MCP tool names.
	 */
	private function claude_toolset_snippet( array $tool_names ): string {
		$configs = array();

		foreach ( $tool_names as $tool_name ) {
			$configs[ $tool_name ] = array( 'enabled' => true );
		}

		$json = wp_json_encode(
			array(
				'type'            => 'mcp_toolset',
				'mcp_server_name' => 'aculect-ai-companion',
				'default_config'  => array( 'enabled' => false ),
				'configs'         => $configs,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return is_string( $json ) ? $json : '';
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

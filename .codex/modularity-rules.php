<?php

declare(strict_types=1);

/**
 * Repository-local modularity budgets.
 *
 * The exceptions are a ratchet: legacy hotspots may not grow beyond the
 * recorded ceiling, and each exception names the owner, reason and target for
 * removal. New files must satisfy the normal budget immediately.
 *
 * @return array<string, mixed>
 */
return array(
	'production_roots' => array( 'src', 'aculect-ai-companion.php' ),
	'test_roots'       => array( 'tests' ),
	'budgets'          => array(
		'production' => array(
			'line_review'   => 500,
			'line_hard'     => 1200,
			'method_review' => 80,
			'method_hard'   => 120,
		),
		'tests'      => array(
			'line_review'   => 800,
			'line_hard'     => 1800,
			'method_review' => 100,
			'method_hard'   => 180,
		),
	),
	'exceptions'       => array(
		array(
			'path'      => 'src/index.js',
			'max_lines' => 9016,
			'owner'     => 'Admin UI',
			'reason'    => 'Legacy tab composition root; extract per-tab components.',
			'issue'     => '#430',
			'target'    => 1200,
		),
		array(
			'path'      => 'src/style.scss',
			'max_lines' => 6111,
			'owner'     => 'Admin UI',
			'reason'    => 'Legacy style entrypoint; split into tab partials.',
			'issue'     => '#430',
			'target'    => 1200,
		),
		array(
			'path'      => 'tests/bootstrap.php',
			'max_lines' => 2755,
			'owner'     => 'Test infrastructure',
			'reason'    => 'Legacy WordPress-light stubs; move domain fixtures into Support.',
			'issue'     => '#430',
			'target'    => 1500,
		),
		array(
			'path'      => 'tests/Unit/Connectors/MCP/McpControllerTest.php',
			'max_lines' => 2460,
			'owner'     => 'MCP',
			'reason'    => 'Legacy transport/discovery/schema test composition; split by contract.',
			'issue'     => '#430',
			'target'    => 1200,
		),
		array(
			'path'      => 'src/Connectors/MCP/FirstPartyAbilityModules.php',
			'max_lines' => 2540,
			'owner'     => 'MCP',
			'reason'    => 'Legacy ability composition root; extract domain providers.',
			'issue'     => '#430',
			'target'    => 1200,
		),
		array(
			'path'      => 'src/Connectors/MCP/IntelligenceIndexAbilities.php',
			'max_lines' => 2517,
			'owner'     => 'Intelligence',
			'reason'    => 'Search, link and memory responsibilities are being separated.',
			'issue'     => '#430',
			'target'    => 1200,
		),
		array(
			'path'      => 'src/Intelligence/ContentIndexRepository.php',
			'max_lines' => 1893,
			'owner'     => 'Intelligence',
			'reason'    => 'Index persistence and projections need bounded collaborators.',
			'issue'     => '#430',
			'target'    => 1200,
		),
		array(
			'path'      => 'src/Admin/SettingsPage.php',
			'max_lines' => 1789,
			'owner'     => 'Admin UI',
			'reason'    => 'Legacy composition page; extract tab payload/action handlers.',
			'issue'     => '#430',
			'target'    => 1000,
		),
		array(
			'path'      => 'src/Connectors/MCP/McpController.php',
			'max_lines' => 1650,
			'owner'     => 'MCP',
			'reason'    => 'Transport, schemas and result adaptation need separate services.',
			'issue'     => '#430',
			'target'    => 1000,
		),
		array(
			'path'      => 'src/Intelligence/ContentIndexer.php',
			'max_lines' => 1608,
			'owner'     => 'Intelligence',
			'reason'    => 'Index extraction and queue orchestration need separation.',
			'issue'     => '#430',
			'target'    => 1000,
		),
		array(
			'path'      => 'src/Connectors/MCP/BlockKnowledgeAbilities.php',
			'max_lines' => 1412,
			'owner'     => 'MCP',
			'reason'    => 'Block validation and discovery should be distinct services.',
			'issue'     => '#430',
			'target'    => 1000,
		),
		array(
			'path'      => 'src/Connectors/MCP/ContentWorkflowAbilities.php',
			'max_lines' => 1401,
			'owner'     => 'Workflows',
			'reason'    => 'Workflow orchestration should use bounded workflow steps.',
			'issue'     => '#430',
			'target'    => 1000,
		),
		array(
			'path'      => 'src/Workflows/Adapters/ContentPlannerAdapter.php',
			'max_lines' => 1243,
			'owner'     => 'Workflows',
			'reason'    => 'Planner validation and projection should be split.',
			'issue'     => '#430',
			'target'    => 1000,
		),
		array(
			'path'      => 'src/Connectors/MCP/SiteMaintenanceReports.php',
			'max_lines' => 1205,
			'owner'     => 'MCP',
			'reason'    => 'Maintenance report collectors need bounded report adapters.',
			'issue'     => '#430',
			'target'    => 900,
		),
		array(
			'path'      => 'src/Connectors/OAuth/AuthorizationController.php',
			'max_lines' => 1200,
			'owner'     => 'OAuth',
			'reason'    => 'Authorization flow and consent rendering need separate collaborators.',
			'issue'     => '#430',
			'target'    => 900,
		),
	),
	'method_exceptions' => array(
		'src/Connectors/OAuth/ClientRegistrationController.php' => array( 'register_client' => 157 ),
		'src/Connectors/OAuth/TokenController.php' => array( 'token' => 150 ),
		'src/Connectors/OAuth/AuthorizationController.php' => array( 'handle_decision' => 136 ),
		'src/Connectors/MCP/IntelligenceRegistry.php' => array( 'build_modules' => 320 ),
		'src/Connectors/MCP/IntelligenceIndexAbilities.php' => array( 'find_internal_links' => 132 ),
		'src/Connectors/MCP/McpToolAvailability.php' => array( 'operations_manifest_for_user' => 233 ),
		'src/Connectors/MCP/BlockKnowledgeAbilities.php' => array( 'analyze_block_structure' => 149 ),
		'src/Connectors/MCP/ContentAbilities.php' => array(
			'create_item'  => 132,
			'update_item'  => 190,
			'update_block' => 152,
		),
		'src/Connectors/MCP/FirstPartyAbilityModules.php' => array( 'all' => 1286 ),
		'src/Connectors/MCP/McpController.php' => array(
			'handle_rpc'            => 173,
			'output_schema_for_module' => 207,
		),
		'src/Connectors/MCP/McpSchemaCompatibility.php' => array( 'current_schema_error' => 172 ),
		'src/Connectors/MCP/WordPressAbilitiesPolicy.php' => array( 'valid_schema_node' => 175 ),
		'src/Intelligence/ContentIndexer.php' => array( 'run_queued_refresh_slice' => 151 ),
		'src/Workflows/Adapters/ContentPlannerAdapter.php' => array( 'output_schema' => 187 ),
		'tests/Unit/PluginContentIndexSaveFlowTest.php' => array( 'setUp' => 186 ),
		'tests/Unit/Connectors/MCP/WordPressAbilitiesPolicyTest.php' => array( 'test_fresh_policy_defaults_only_valid_read_only_abilities_on' => 261 ),
		'tests/Unit/Workflows/Definitions/WorkflowDefinitionTest.php' => array( 'invalid_definition_provider' => 376 ),
	),
	'dependency_rules' => array(
		array(
			'root'       => 'src/Intelligence',
			'forbidden'  => array( 'Aculect\\AICompanion\\Connectors\\MCP\\' ),
			'exceptions' => array(
				'src/Intelligence/InternalLinkSuggestionRepository.php' => 'Existing adapter edge; extract a neutral content port.',
			),
		),
		array(
			'root'       => 'src/Activity',
			'forbidden'  => array( 'Aculect\\AICompanion\\Connectors\\MCP\\' ),
			'exceptions' => array(
				'src/Activity/ActivityLogger.php' => 'Existing risk classification edge; inject a neutral risk contract.',
			),
		),
		array(
			'root'       => 'src/Workflows/Definitions',
			'forbidden'  => array( 'Aculect\\AICompanion\\Connectors\\', 'Aculect\\AICompanion\\Admin\\', 'Aculect\\AICompanion\\Intelligence\\' ),
			'exceptions' => array(),
		),
		array(
			'root'       => 'src/Workflows/Planning',
			'forbidden'  => array( 'Aculect\\AICompanion\\Connectors\\', 'Aculect\\AICompanion\\Admin\\', 'Aculect\\AICompanion\\Intelligence\\' ),
			'exceptions' => array(),
		),
	),
);

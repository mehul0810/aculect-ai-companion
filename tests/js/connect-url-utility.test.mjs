import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const ADMIN_APP_SOURCE = readFileSync(
	new URL( '../../src/index.js', import.meta.url ),
	'utf8'
);

test( 'connect tab renders a simplified persistent MCP endpoint utility outside the wizard', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/function ConnectMcpUrlUtility\( \{ mcpUrl, health, onCopy \} \)/
	);
	assert.match( ADMIN_APP_SOURCE, /<h2>Your MCP endpoint<\/h2>/ );
	assert.match( ADMIN_APP_SOURCE, /<CopyField\s+label="MCP endpoint"/ );
	assert.match( ADMIN_APP_SOURCE, /visuallyHiddenLabel=\{ true \}/ );
	assert.match(
		ADMIN_APP_SOURCE,
		/<ConnectMcpUrlUtility\s+[\s\S]*mcpUrl=\{ mcpUrl \}[\s\S]*health=\{ connectionHealth \}[\s\S]*onCopy=\{ copyValue \}/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/<ConnectMcpUrlUtility[\s\S]*\/>\s*<SetupWizard/s
	);
} );

test( 'persistent MCP endpoint utility presents local and verified states from endpoint diagnostics', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/function persistentMcpUrlStatus\( mcpUrl, health \)/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/The canonical MCP Server URL is unavailable for this\s+site right now\./s
	);
	assert.match( ADMIN_APP_SOURCE, /'mcp_auth_challenge'/ );
	assert.match(
		ADMIN_APP_SOURCE,
		/endpointChecks\.every\( \( item \) => item\?\.status === 'pass' \)/
	);
	assert.match( ADMIN_APP_SOURCE, /Local site detected/ );
	assert.match(
		ADMIN_APP_SOURCE,
		/Hosted assistants need a public HTTPS URL\./
	);
	assert.match( ADMIN_APP_SOURCE, /Ready to connect/ );
	assert.match(
		ADMIN_APP_SOURCE,
		/Your public HTTPS endpoint is available for hosted assistants\./
	);
	assert.match( ADMIN_APP_SOURCE, /label="Verified"/ );
	assert.match(
		ADMIN_APP_SOURCE,
		/Safe to share — this link contains no secrets\./
	);
} );

test( 'connect wizard keeps one current task, help, documentation, and a continue action visible', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/className="aculect-ai-companion-wizard-current-task"/
	);
	assert.match( ADMIN_APP_SOURCE, /Current task/ );
	assert.match( ADMIN_APP_SOURCE, /Need help\?/ );
	assert.match( ADMIN_APP_SOURCE, /View documentation/ );
	assert.match( ADMIN_APP_SOURCE, />\s*Continue\s*</ );
} );

test( 'wizard progress labels are rendered as wrapping text rather than ellipsized emphasis', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/<span className="aculect-ai-companion-wizard-progress__title">/
	);
} );

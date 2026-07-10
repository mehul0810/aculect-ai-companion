import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const ADMIN_APP_SOURCE = readFileSync(
	new URL( '../../src/index.js', import.meta.url ),
	'utf8'
);

test( 'connect tab renders a persistent MCP Server URL utility outside the wizard', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/function ConnectMcpUrlUtility\( \{ mcpUrl, health, onCopy \} \ )/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/<h2>MCP Server URL<\/h2>/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/<CopyField\s+label="MCP Server URL"/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/<ConnectMcpUrlUtility\s+[\s\S]*mcpUrl=\{ mcpUrl \}[\s\S]*health=\{ connectionHealth \}[\s\S]*onCopy=\{ copyValue \}/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/<ConnectMcpUrlUtility[\s\S]*\/>\s*<SetupWizard/s
	);
} );

test( 'persistent MCP Server URL utility covers unavailable and diagnostic-backed states', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/function persistentMcpUrlStatus\( mcpUrl, health \ )/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/'The canonical MCP Server URL is unavailable for this\s+site right now\.'/s
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/'Run Connection Diagnostics to verify HTTPS, route shape, and the authorization challenge\.'/s
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/This endpoint never includes secrets, tokens, nonces, or\s+user-specific approval material\./s
	);
} );

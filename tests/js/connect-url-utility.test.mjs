import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const ADMIN_APP_SOURCE = readFileSync(
	new URL( '../../src/index.js', import.meta.url ),
	'utf8'
);
const ADMIN_STYLE_SOURCE = readFileSync(
	new URL( '../../src/style.scss', import.meta.url ),
	'utf8'
);

test( 'connect tab renders a persistent connection link before the lean app picker', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/function ConnectMcpUrlUtility\( \{ mcpUrl, health, onCopy \} \)/
	);
	assert.match( ADMIN_APP_SOURCE, /<h2>Connection link<\/h2>/ );
	assert.match( ADMIN_APP_SOURCE, /<CopyField\s+label="Connection link"/ );
	assert.match( ADMIN_APP_SOURCE, /copyButtonLabel="Copy link"/ );
	assert.match( ADMIN_APP_SOURCE, /visuallyHiddenLabel=\{ true \}/ );
	assert.match(
		ADMIN_APP_SOURCE,
		/<ConnectMcpUrlUtility\s+[\s\S]*mcpUrl=\{ mcpUrl \}[\s\S]*health=\{ connectionHealth \}[\s\S]*onCopy=\{ copyValue \}/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/<ConnectMcpUrlUtility[\s\S]*\/>\s*<ConnectAppPicker/s
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
	assert.match( ADMIN_APP_SOURCE, /This link contains no secrets\./ );
} );

test( 'local endpoint warning keeps its icon and text vertically aligned in a compact row', () => {
	assert.match(
		ADMIN_STYLE_SOURCE,
		/\.aculect-ai-companion-connect-info-message\.is-warn\s*\{[\s\S]*align-items:\s*center;[\s\S]*padding:\s*10px;/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/aculect-ai-companion-connect-info-message__icon/
	);
} );

test( 'MCP endpoint safety note does not add browser-default paragraph spacing', () => {
	assert.match(
		ADMIN_STYLE_SOURCE,
		/\.aculect-ai-companion-connect-secure-note\s*\{\s*margin:\s*0;/
	);
} );

test( 'connect picker shows ChatGPT, Claude, and a generic MCP-compatible choice', () => {
	assert.match( ADMIN_APP_SOURCE, /const CONNECT_APP_OPTIONS = \[/ );
	assert.match( ADMIN_APP_SOURCE, /label: 'ChatGPT'/ );
	assert.match( ADMIN_APP_SOURCE, /brand: 'OpenAI'/ );
	assert.match( ADMIN_APP_SOURCE, /label: 'Claude'/ );
	assert.match( ADMIN_APP_SOURCE, /brand: 'Anthropic'/ );
	assert.match( ADMIN_APP_SOURCE, /label: 'Other AI app'/ );
	assert.match( ADMIN_APP_SOURCE, /brand: 'MCP compatible'/ );
} );

test( 'connect tab keeps client tool filtering advanced and provider-driven', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/function ConnectToolFilteringGuidance\( \{ providers, onCopy \} \)/
	);
	assert.match( ADMIN_APP_SOURCE, /provider\.toolFiltering/ );
	assert.match( ADMIN_APP_SOURCE, /<details>/ );
	assert.match( ADMIN_APP_SOURCE, /Explicit approval required/ );
} );

test( 'connect picker puts the setup guide before the provider action without rendering a step flow', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/<a[\s\S]*>\s*Setup guide\s*<\/a>[\s\S]*<Button[\s\S]*selectedOption\.actionLabel/s
	);
	const pickerSource = ADMIN_APP_SOURCE.slice(
		ADMIN_APP_SOURCE.indexOf( 'function ConnectAppPicker' ),
		ADMIN_APP_SOURCE.indexOf( 'function ConnectReadinessBadge' )
	);
	assert.doesNotMatch( pickerSource, /Step \{\s*stepIndex/ );
	assert.doesNotMatch( pickerSource, /WizardProgress/ );
} );

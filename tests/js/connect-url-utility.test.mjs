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
const RELEASE_UI_SMOKE_SOURCE = readFileSync(
	new URL( '../../scripts/smoke/release-ui.mjs', import.meta.url ),
	'utf8'
);

test( 'connect tab renders endpoint and setup cards before the lean app picker', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/function ConnectMcpUrlUtility\( \{ mcpUrl, health, onCopy \} \)/
	);
	assert.match( ADMIN_APP_SOURCE, /<h2>Connection endpoint<\/h2>/ );
	assert.match( ADMIN_APP_SOURCE, /<CopyField\s+label="Connection link"/ );
	assert.match( ADMIN_APP_SOURCE, /copyButtonLabel="Copy endpoint"/ );
	assert.match( ADMIN_APP_SOURCE, /visuallyHiddenLabel=\{ true \}/ );
	assert.match( ADMIN_APP_SOURCE, /function ConnectSetupSteps\(\)/ );
	assert.match( ADMIN_APP_SOURCE, /<h2>Finish setup<\/h2>/ );
	assert.match(
		ADMIN_APP_SOURCE,
		/<ConnectMcpUrlUtility\s+[\s\S]*mcpUrl=\{ mcpUrl \}[\s\S]*health=\{ connectionHealth \}[\s\S]*onCopy=\{ copyValue \}/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/<ConnectMcpUrlUtility[\s\S]*\/>\s*<ConnectSetupSteps[\s\S]*<ConnectAppPicker/s
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
	assert.match( ADMIN_APP_SOURCE, />\s*Verified\s*<\/span>/ );
	assert.match( ADMIN_APP_SOURCE, /Contains no secrets/ );
	assert.match( ADMIN_APP_SOURCE, /This endpoint contains no secrets\./ );
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

test( 'connect picker shows ChatGPT, Claude, Grok, Cursor, and a generic MCP-compatible choice', () => {
	assert.match( ADMIN_APP_SOURCE, /const CONNECT_APP_OPTIONS = \[/ );
	assert.match( ADMIN_APP_SOURCE, /label: 'ChatGPT'/ );
	assert.match( ADMIN_APP_SOURCE, /brand: 'OpenAI'/ );
	assert.match( ADMIN_APP_SOURCE, /label: 'Claude'/ );
	assert.match( ADMIN_APP_SOURCE, /brand: 'Anthropic'/ );
	assert.match( ADMIN_APP_SOURCE, /label: 'Grok'/ );
	assert.match( ADMIN_APP_SOURCE, /brand: 'xAI'/ );
	assert.match( ADMIN_APP_SOURCE, /https:\/\/docs\.x\.ai\/grok\/connectors/ );
	assert.match( ADMIN_APP_SOURCE, /label: 'Cursor'/ );
	assert.match( ADMIN_APP_SOURCE, /brand: 'Anysphere'/ );
	assert.match(
		ADMIN_APP_SOURCE,
		/guideUrl: 'https:\/\/cursor\.com\/docs\/mcp'/
	);
	assert.match( ADMIN_APP_SOURCE, /label: 'Other AI app'/ );
	assert.match( ADMIN_APP_SOURCE, /brand: 'MCP compatible'/ );
} );

test( 'connect picker uses roving radio focus and keyboard navigation', () => {
	assert.match( ADMIN_APP_SOURCE, /role="radiogroup"/ );
	assert.match( ADMIN_APP_SOURCE, /aria-orientation="horizontal"/ );
	assert.match( ADMIN_APP_SOURCE, /role="radio"/ );
	assert.match( ADMIN_APP_SOURCE, /aria-checked=\{ isSelected \}/ );
	assert.match( ADMIN_APP_SOURCE, /tabIndex=\{ tabIndex \}/ );
	assert.match( ADMIN_APP_SOURCE, /onKeyDown=\{ handleOptionKeyDown \}/ );
	assert.match( ADMIN_APP_SOURCE, /connectAppNavigationTarget\(/ );
	assert.match( ADMIN_APP_SOURCE, /connectAppPickerState\(/ );
	assert.match( ADMIN_APP_SOURCE, /data-connect-option-id/ );
	assert.match( ADMIN_APP_SOURCE, /\.focus\(\)/ );
	assert.match( RELEASE_UI_SMOKE_SOURCE, /verifyConnectPickerKeyboard/ );
	assert.match(
		RELEASE_UI_SMOKE_SOURCE,
		/page\.keyboard\.press\( 'ArrowRight' \)/
	);
	assert.match(
		RELEASE_UI_SMOKE_SOURCE,
		/page\.keyboard\.press\( 'Home' \)/
	);
	assert.match( RELEASE_UI_SMOKE_SOURCE, /page\.keyboard\.press\( 'End' \)/ );
} );

test( 'connect tab removes advanced tool filtering and shows a native permissions disclosure', () => {
	assert.doesNotMatch( ADMIN_APP_SOURCE, /ConnectToolFilteringGuidance/ );
	assert.doesNotMatch( ADMIN_APP_SOURCE, /Advanced tool filtering/ );
	assert.match( ADMIN_APP_SOURCE, /function ConnectPermissionsSummary/ );
	assert.match( ADMIN_APP_SOURCE, /<details open>/ );
	assert.match( ADMIN_APP_SOURCE, /OAuth consent required/ );
	assert.match( ADMIN_APP_SOURCE, /WordPress permissions enforced/ );
	assert.match( ADMIN_APP_SOURCE, /Read-only by default/ );
	assert.match( ADMIN_APP_SOURCE, /content:read/ );
	assert.match( ADMIN_APP_SOURCE, /content:draft/ );
	assert.match( ADMIN_APP_SOURCE, /Core OAuth scopes/ );
	assert.match(
		ADMIN_APP_SOURCE,
		/This\s+site may support additional scopes\./
	);
	assert.doesNotMatch( ADMIN_APP_SOURCE, /Available OAuth scopes/ );
} );

test( 'five-option connect picker keeps complete responsive separators', () => {
	assert.match(
		ADMIN_STYLE_SOURCE,
		/\.aculect-ai-companion-connect-app-picker__options\s*\{[\s\S]*grid-template-columns:\s*repeat\(5, minmax\(0, 1fr\)\);/
	);
	assert.match(
		ADMIN_STYLE_SOURCE,
		/@media \(max-width: 900px\)[\s\S]*\.aculect-ai-companion-connect-app-option:nth-child\(-n \+ 4\)\s*\{[\s\S]*border-bottom:\s*1px solid[\s\S]*\.aculect-ai-companion-connect-app-option:nth-child\(odd\):not\(:last-child\)\s*\{[\s\S]*border-right:\s*1px solid/
	);
} );

test( 'connect picker puts the setup guide before the provider action', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/<a[\s\S]*>\s*Setup guide\s*<\/a>[\s\S]*<Button[\s\S]*selectedOption\.actionLabel/s
	);
	assert.match( ADMIN_APP_SOURCE, /Pick an app to see next steps\./ );
} );

test( 'permissions disclosure remains responsive and keyboard focusable', () => {
	assert.match(
		ADMIN_STYLE_SOURCE,
		/\.aculect-ai-companion-connect-permissions__summary:focus-visible[\s\S]*box-shadow:/
	);
	assert.match(
		ADMIN_STYLE_SOURCE,
		/@media \(max-width: 900px\)[\s\S]*\.aculect-ai-companion-connect-permissions__grid\s*\{\s*grid-template-columns:\s*1fr;/
	);
	assert.match(
		ADMIN_STYLE_SOURCE,
		/@media \(max-width: 600px\)[\s\S]*\.aculect-ai-companion-connect-permissions__actions \.components-button\s*\{[\s\S]*width:\s*100%;/
	);
} );

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const html = readFileSync(
	new URL(
		'../../assets/mcp-apps/image-upload/v1/image-upload.html',
		import.meta.url
	),
	'utf8'
);
const template = readFileSync(
	new URL(
		'../../widgets/image-upload/v1/image-upload.template.html',
		import.meta.url
	),
	'utf8'
);
const css = readFileSync(
	new URL(
		'../../widgets/image-upload/v1/image-upload.css',
		import.meta.url
	),
	'utf8'
);
const bridge = readFileSync(
	new URL( '../../widgets/runtime/v1/bridge.js', import.meta.url ),
	'utf8'
);
const widget = readFileSync(
	new URL( '../../widgets/image-upload/v1/widget.js', import.meta.url ),
	'utf8'
);
const resource = readFileSync(
	new URL(
		'../../src/Connectors/MCP/McpAppsImageUploadResource.php',
		import.meta.url
	),
	'utf8'
);

test( 'image upload app is a credential-free, explicit WordPress human handoff', () => {
	assert.match( template, /Open WordPress upload/ );
	assert.match( template, /WordPress will show an attachment ID/ );
	assert.match( template, /upload it directly to this site’s Media Library/ );
	assert.match( html, /bridge\.openLink\( url \)/ );
	assert.match( widget, /No image was uploaded by this widget/ );
	assert.match( html, /application\/json/ );
	assert.match( resource, /current_user_can\( 'upload_files' \)/ );
	assert.match( resource, /admin_url\( 'admin\.php\?page=' \./ );
	assert.match(
		resource,
		/JSON_HEX_TAG \| JSON_HEX_AMP \| JSON_HEX_APOS \| JSON_HEX_QUOT/
	);
	assert.doesNotMatch(
		resource,
		/wp_create_nonce|oauth|access_token|refresh_token/i
	);
	assert.doesNotMatch( html, /fetch\s*\(|XMLHttpRequest|form action/i );
} );

test( 'image upload app has a network-isolated policy and does not claim upload completion', () => {
	assert.match( html, /connect-src 'none'/ );
	assert.match( html, /form-action 'none'/ );
	assert.match(
		template,
		/No file has been selected or uploaded by this widget/
	);
	assert.doesNotMatch( html, /<input[^>]+type=["']file/i );
} );

test( 'packaged HTML is the deterministic build of template, styles, and shared runtime', () => {
	const expected = template
		.replace( '__ACULECT_WIDGET_CSS__', css )
		.replace( '__ACULECT_WIDGET_JS__', `${ bridge }\n${ widget }` );

	assert.equal( html, expected );
	assert.equal( ( html.match( /AculectWidgetBridge =/g ) || [] ).length, 1 );
	assert.doesNotMatch( widget, /function createBridge/ );
} );

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from '@playwright/test';

// Isolated browser harness for the packaged HTML view, not proof of a real MCP host.
const html = readFileSync(
	new URL( '../../../assets/mcp-apps/site-info.html', import.meta.url ),
	'utf8'
);
const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 560, height: 680 } } );
const pageErrors = [];
const remoteRequests = [];
page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
page.on( 'request', ( request ) => {
	if ( /^https?:/i.test( request.url() ) ) remoteRequests.push( request.url() );
} );

try {
	await page.goto( 'about:blank' );
	await page.evaluate( ( documentHtml ) => {
		const iframe = document.createElement( 'iframe' );
		iframe.id = 'app';
		iframe.title = 'Aculect Site Information';
		iframe.style.width = '100%';
		iframe.style.height = '600px';
		window.hostMessages = [];
		window.addEventListener( 'message', ( event ) => {
			if ( event.source !== iframe.contentWindow ) return;
			window.hostMessages.push( event.data );
			if ( event.data.method === 'ui/initialize' ) {
				iframe.contentWindow.postMessage(
					{
						jsonrpc: '2.0',
						id: event.data.id,
						result: {
							protocolVersion: '2026-01-26',
							hostInfo: { name: 'Fixture Host', version: '1' },
							hostContext: { theme: 'light' },
						},
					},
					'*'
				);
			}
		} );
		iframe.srcdoc = documentHtml;
		document.body.append( iframe );
	}, html );

	await page.waitForFunction( () =>
		window.hostMessages.some(
			( message ) => message.method === 'ui/notifications/initialized'
		)
	);
	const app = page.frameLocator( '#app' );
	assert.match( await app.locator( '#status' ).innerText(), /Connecting/ );

	await page.evaluate( () => {
		document.querySelector( '#app' ).contentWindow.postMessage(
			{
				jsonrpc: '2.0',
				method: 'ui/notifications/tool-result',
				params: {
					structuredContent: {
						name: '<img src=x onerror="window.hacked=true">',
						home_url: 'https://example.test/',
						wordpress: { version: '6.9' },
						active_theme: { name: 'Twenty Twenty-Six' },
						locale: 'en_US',
						timezone: 'Asia/Kolkata',
					},
				},
			},
			'*'
		);
	} );
	await app.locator( '#site-details' ).waitFor( { state: 'visible' } );
	assert.equal(
		await app.locator( '#title' ).innerText(),
		'<img src=x onerror="window.hacked=true">'
	);
	assert.equal( await app.locator( 'img' ).count(), 0 );
	assert.equal( await app.locator( '[data-field="wordpress"]' ).innerText(), '6.9' );
	assert.equal( await app.locator( '#site-url' ).innerText(), 'https://example.test/' );
	await page.evaluate( () => {
		document.querySelector( '#app' ).contentWindow.postMessage(
			{
				jsonrpc: '2.0',
				method: 'ui/notifications/tool-result',
				params: {
					structuredContent: {
						name: 'Example WordPress Site',
						home_url: 'https://example.test/',
						wordpress: { version: '6.9' },
						active_theme: { name: 'Twenty Twenty-Six' },
						locale: 'en_US',
						timezone: 'Asia/Kolkata',
					},
				},
			},
			'*'
		);
	} );
	await app.getByRole( 'heading', { name: 'Example WordPress Site' } ).waitFor();
	const appFrame = page.frames().find( ( frame ) => frame !== page.mainFrame() );
	assert.ok( appFrame, 'The isolated view must have its own frame.' );
	await appFrame.addScriptTag( {
		path: new URL( '../../../node_modules/axe-core/axe.min.js', import.meta.url ).pathname,
	} );
	const accessibility = await appFrame.evaluate( () =>
		window.axe.run( document, {
			runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21aa' ] },
		} )
	);
	assert.deepEqual(
		accessibility.violations.map( ( violation ) => violation.id ),
		[],
		'The populated view should have no axe WCAG A/AA violations.'
	);
	if ( process.env.ACULECT_MCP_APPS_SCREENSHOT ) {
		await page.screenshot( { path: process.env.ACULECT_MCP_APPS_SCREENSHOT } );
	}

	await page.evaluate( () => {
		document.querySelector( '#app' ).contentWindow.postMessage(
			{
				jsonrpc: '2.0',
				method: 'ui/notifications/tool-cancelled',
			},
			'*'
		);
	} );
	await app.locator( '#site-details' ).waitFor( { state: 'hidden' } );
	assert.match( await app.locator( '#status' ).innerText(), /cancelled/ );

	await page.evaluate( () => {
		document.querySelector( '#app' ).contentWindow.postMessage(
			{
				jsonrpc: '2.0',
				method: 'ui/notifications/host-context-changed',
				params: { theme: 'dark' },
			},
			'*'
		);
	} );
	await app.locator( 'html[data-theme="dark"]' ).waitFor();
	await page.setViewportSize( { width: 320, height: 680 } );
	assert.equal( await app.locator( 'main' ).count(), 1 );
	assert.deepEqual( pageErrors, [] );
	assert.deepEqual( remoteRequests, [] );
	process.stdout.write( 'PASS isolated MCP Apps Site Information browser harness\n' );
} finally {
	await browser.close();
}

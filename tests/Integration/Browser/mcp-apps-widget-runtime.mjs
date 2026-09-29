import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from '@playwright/test';

// Isolated sandbox fixture, not a real MCP host or server resource read.
const html = readFileSync(
	new URL(
		'../../../assets/mcp-apps/runtime/v1/fixture.html',
		import.meta.url
	),
	'utf8'
);
const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 560, height: 700 } } );
const errors = [];
const remoteRequests = [];
page.on( 'pageerror', ( error ) => errors.push( error.message ) );
page.on( 'request', ( request ) => {
	if ( /^https?:/i.test( request.url() ) ) {
		remoteRequests.push( request.url() );
	}
} );

async function hostSend( message ) {
	await page.evaluate( ( payload ) => {
		document
			.querySelector( '#app' )
			.contentWindow.postMessage( payload, '*' );
	}, message );
}

try {
	await page.goto( 'about:blank' );
	await page.evaluate( ( markup ) => {
		const iframe = document.createElement( 'iframe' );
		iframe.id = 'app';
		iframe.title = 'Aculect widget fixture';
		iframe.setAttribute( 'sandbox', 'allow-scripts' );
		iframe.style.cssText = 'width:100%;height:650px;border:0';
		window.hostMessages = [];
		window.addEventListener( 'message', ( event ) => {
			if ( event.source !== iframe.contentWindow ) {
				return;
			}
			window.hostMessages.push( event.data );
			if ( event.data.method === 'ui/initialize' ) {
				iframe.contentWindow.postMessage(
					{
						jsonrpc: '2.0',
						id: event.data.id,
						result: {
							protocolVersion: '2026-01-26',
							hostInfo: { name: 'Fixture Host', version: '1' },
							hostContext: {
								theme: 'light',
								displayMode: 'inline',
								availableDisplayModes: [
									'inline',
									'fullscreen',
								],
							},
						},
					},
					'*'
				);
			}
			if ( event.data.method === 'tools/call' ) {
				iframe.contentWindow.postMessage(
					{
						jsonrpc: '2.0',
						id: event.data.id,
						result: {
							structuredContent: { name: 'Refreshed site' },
						},
					},
					'*'
				);
			}
			if (
				[
					'ui/open-link',
					'ui/update-model-context',
					'ui/request-display-mode',
				].includes( event.data.method )
			) {
				iframe.contentWindow.postMessage(
					{
						jsonrpc: '2.0',
						id: event.data.id,
						result: {},
					},
					'*'
				);
			}
		} );
		iframe.srcdoc = markup;
		document.body.append( iframe );
	}, html );
	await page.waitForFunction( () =>
		window.hostMessages.some(
			( item ) => item.method === 'ui/notifications/initialized'
		)
	);
	const app = page.frameLocator( '#app' );
	const frame = page.frames().find( ( item ) => item !== page.mainFrame() );
	assert.ok( frame );
	assert.equal(
		await frame.evaluate( () => typeof window.openai ),
		'undefined'
	);
	assert.equal(
		await app.getByRole( 'button', { name: 'Refresh' } ).isEnabled(),
		true
	);

	await hostSend( {
		jsonrpc: '2.0',
		method: 'ui/notifications/tool-input',
		params: { arguments: {} },
	} );
	await hostSend( {
		jsonrpc: '2.0',
		method: 'ui/notifications/tool-result',
		params: {
			structuredContent: {
				name: '<img src=x onerror="window.hacked=true">',
				home_url: 'https://example.test/',
				wordpress: { version: '7.1' },
				active_theme: { name: 'Fixture Theme' },
				locale: 'en_US',
				timezone: 'Asia/Kolkata',
			},
		},
	} );
	await app.locator( '#details' ).waitFor( { state: 'visible' } );
	assert.equal(
		await app.locator( '#title' ).innerText(),
		'<img src=x onerror="window.hacked=true">'
	);
	assert.equal( await app.locator( 'img' ).count(), 0 );
	assert.equal( await frame.evaluate( () => window.hacked ), undefined );
	assert.equal(
		await app.locator( '[data-field="wordpress"]' ).innerText(),
		'7.1'
	);
	await app.getByRole( 'button', { name: 'Open site' } ).click();
	await page.waitForFunction( () =>
		window.hostMessages.some(
			( item ) =>
				item.method === 'ui/open-link' &&
				item.params.url === 'https://example.test/'
		)
	);
	await app
		.getByRole( 'button', { name: 'Share site name with assistant' } )
		.click();
	await page.waitForFunction( () =>
		window.hostMessages.some(
			( item ) => item.method === 'ui/update-model-context'
		)
	);
	assert.equal(
		await frame.evaluate( async () => {
			try {
				await fetch( 'https://example.invalid/widget-csp-probe' );
				return false;
			} catch {
				return true;
			}
		} ),
		true
	);

	await app.getByRole( 'button', { name: 'Refresh' } ).click();
	await app.getByRole( 'heading', { name: 'Refreshed site' } ).waitFor();
	assert.equal(
		await page.evaluate( () => window.hostMessages.at( -1 ).method ),
		'tools/call'
	);
	assert.equal(
		await page.evaluate( () => window.hostMessages.at( -1 ).params.name ),
		'site_get_info'
	);

	await app.getByRole( 'button', { name: 'About this view' } ).click();
	assert.equal(
		await app
			.getByRole( 'button', { name: 'Close' } )
			.evaluate(
				( button ) => button === button.ownerDocument.activeElement
			),
		true
	);
	await app.getByRole( 'button', { name: 'Close' } ).press( 'Escape' );
	assert.equal( await app.getByRole( 'dialog' ).isVisible(), false );
	assert.equal(
		await app
			.getByRole( 'button', { name: 'About this view' } )
			.evaluate(
				( button ) => button === button.ownerDocument.activeElement
			),
		true
	);

	for ( const kind of [
		'loading',
		'empty',
		'error',
		'stale',
		'disabled',
		'approval-pending',
		'completed',
	] ) {
		await frame.evaluate(
			( state ) =>
				window.AculectWidgetFixture.showState(
					state,
					state === 'completed' ? { name: 'Fixture site' } : null
				),
			kind
		);
		assert.equal(
			await app.locator( '#status' ).getAttribute( 'data-state' ),
			kind
		);
	}
	await hostSend( {
		jsonrpc: '2.0',
		method: 'ui/notifications/host-context-changed',
		params: { theme: 'dark', displayMode: 'fullscreen' },
	} );
	await app
		.locator( 'html[data-theme="dark"][data-display-mode="fullscreen"]' )
		.waitFor();
	await hostSend( {
		jsonrpc: '2.0',
		method: 'ui/notifications/host-context-changed',
		params: { availableDisplayModes: [ 'inline' ], displayMode: 'inline' },
	} );
	await app.locator( '#expand' ).waitFor( { state: 'hidden' } );
	await page.setViewportSize( { width: 320, height: 700 } );
	assert.equal(
		await frame.evaluate(
			() => document.documentElement.scrollWidth <= window.innerWidth
		),
		true
	);
	await page.emulateMedia( { reducedMotion: 'reduce' } );
	assert.equal(
		await frame.evaluate(
			() =>
				window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
		),
		true
	);

	await frame.addScriptTag( {
		path: new URL(
			'../../../node_modules/axe-core/axe.min.js',
			import.meta.url
		).pathname,
	} );
	const violations = await frame.evaluate( () =>
		window.axe.run( document, {
			runOnly: {
				type: 'tag',
				values: [ 'wcag2a', 'wcag2aa', 'wcag21aa' ],
			},
		} )
	);
	assert.deepEqual(
		violations.violations.map( ( item ) => item.id ),
		[]
	);
	assert.deepEqual( errors, [] );
	assert.deepEqual( remoteRequests, [] );
	process.stdout.write( 'PASS sandboxed MCP Apps widget runtime fixture\n' );
} finally {
	await browser.close();
}

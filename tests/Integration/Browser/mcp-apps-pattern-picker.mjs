import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from '@playwright/test';

const html = readFileSync(
	new URL(
		'../../../assets/mcp-apps/pattern-picker/v1/pattern-picker.html',
		import.meta.url
	),
	'utf8'
);
const browser = await chromium.launch();
const page = await browser.newPage( { viewport: { width: 760, height: 760 } } );
const errors = [];
const remoteRequests = [];
page.on( 'pageerror', ( error ) => errors.push( error.message ) );
page.on( 'request', ( request ) => {
	if ( /^https?:/i.test( request.url() ) ) {
		remoteRequests.push( request.url() );
	}
} );

const candidate = ( id, extra = {} ) => ( {
	id,
	title: `Pattern ${ id }`,
	category: 'Text',
	source: 'core',
	compatibility: 'compatible',
	supported_blocks: [ 'core/heading', 'core/paragraph' ],
	...extra,
} );
const result = ( items, extra = {} ) => ( {
	structuredContent: { schema: 'aculect.pattern-picker.v1', items, ...extra },
} );
async function hostSend( method, params ) {
	await page.evaluate(
		( message ) =>
			document
				.querySelector( '#app' )
				.contentWindow.postMessage(
					{ jsonrpc: '2.0', ...message },
					'*'
				),
		{ method, params }
	);
}

try {
	await page.goto( 'about:blank' );
	await page.evaluate( ( markup ) => {
		const iframe = document.createElement( 'iframe' );
		iframe.id = 'app';
		iframe.title = 'Pattern picker';
		iframe.setAttribute( 'sandbox', 'allow-scripts' );
		iframe.style.cssText = 'width:100%;height:760px;border:0';
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
							hostContext: { theme: 'light' },
						},
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
			( message ) => message.method === 'ui/notifications/initialized'
		)
	);
	const app = page.frameLocator( '#app' );
	const frame = page.frames().find( ( item ) => item !== page.mainFrame() );
	assert.ok( frame );
	assert.match( await app.locator( '#notice' ).innerText(), /Waiting/ );

	const payload = result(
		[
			candidate( 'core/hero', {
				title: '<img src=x onerror="window.hacked=true">',
				supported_blocks: [
					'<script>window.hacked=true</script>',
					'core/paragraph',
				],
				content: '<script>window.hacked=true</script>',
			} ),
			candidate( 'theme/feature', {
				source: 'theme',
				category: 'Feature',
			} ),
			candidate( 'plugin/legacy', {
				source: 'plugin',
				compatibility: 'incompatible',
				compatibility_message: 'A required block is unavailable.',
			} ),
			candidate( 'site/new', {
				source: 'site',
				compatibility: 'unknown',
			} ),
			...Array.from( { length: 16 }, ( _, index ) =>
				candidate( `core/section-${ index }` )
			),
		],
		{ total: 35 }
	);
	await hostSend( 'ui/notifications/tool-result', payload );
	await app
		.getByRole( 'heading', {
			name: payload.structuredContent.items[ 0 ].title,
		} )
		.waitFor();
	assert.equal( await app.locator( 'img,script[src]' ).count(), 0 );
	assert.equal( await frame.evaluate( () => window.hacked ), undefined );
	assert.equal( await app.locator( '.card' ).count(), 12 );
	assert.match(
		await app.locator( '#count' ).innerText(),
		/35 server matches.*next discovery page/
	);
	assert.equal( await app.locator( '#next' ).isEnabled(), true );
	await app.getByRole( 'button', { name: 'Next' } ).click();
	assert.match( await app.locator( '#page-label' ).innerText(), /Page 2/ );
	await app.getByRole( 'button', { name: 'Previous' } ).click();

	assert.ok(
		( await app.locator( '#source option' ).allTextContents() ).includes(
			'Plugin'
		)
	);
	await app.locator( '#source' ).selectOption( 'plugin' );
	assert.equal( await app.locator( '.card' ).count(), 1 );
	assert.equal(
		await app
			.getByRole( 'button', { name: 'Choose Pattern plugin/legacy' } )
			.isDisabled(),
		true
	);
	await app.locator( '#source' ).selectOption( '' );
	await app.locator( '#compatibility' ).selectOption( 'unknown' );
	assert.equal(
		await app
			.getByRole( 'button', { name: 'Choose Pattern site/new' } )
			.isDisabled(),
		true
	);
	await app.locator( '#compatibility' ).selectOption( '' );
	await app.locator( '#search' ).fill( 'theme/feature' );
	assert.equal( await app.locator( '.card' ).count(), 1 );
	const choice = app.getByRole( 'button', {
		name: 'Choose Pattern theme/feature',
	} );
	await choice.focus();
	await choice.press( 'Enter' );
	await page.waitForFunction( () =>
		window.hostMessages.some(
			( message ) => message.method === 'ui/update-model-context'
		)
	);
	const selection = await page.evaluate( () =>
		window.hostMessages.find(
			( message ) => message.method === 'ui/update-model-context'
		)
	);
	assert.deepEqual( selection.params.structuredContent, {
		schema: 'aculect.pattern-selection.v1',
		pattern_id: 'theme/feature',
	} );
	assert.equal(
		await page.evaluate( () =>
			window.hostMessages.some(
				( message ) => message.method === 'tools/call'
			)
		),
		false
	);
	await page.evaluate(
		( id ) =>
			document
				.querySelector( '#app' )
				.contentWindow.postMessage(
					{ jsonrpc: '2.0', id, result: {} },
					'*'
				),
		selection.id
	);
	await app.getByText( /Nothing has been inserted/ ).waitFor();
	assert.equal(
		await app
			.getByRole( 'button', { name: 'Selected Pattern theme/feature' } )
			.getAttribute( 'aria-pressed' ),
		'true'
	);
	await app
		.getByRole( 'button', { name: 'Selected Pattern theme/feature' } )
		.click();
	await page.waitForFunction(
		() =>
			window.hostMessages.filter(
				( message ) => message.method === 'ui/update-model-context'
			).length === 2
	);
	const rejected = await page.evaluate(
		() =>
			window.hostMessages.filter(
				( message ) => message.method === 'ui/update-model-context'
			)[ 1 ]
	);
	await page.evaluate(
		( id ) =>
			document.querySelector( '#app' ).contentWindow.postMessage(
				{
					jsonrpc: '2.0',
					id,
					error: { code: -32000, message: 'Declined' },
				},
				'*'
			),
		rejected.id
	);
	await app.getByText( /could not accept this selection/ ).waitFor();

	await app.locator( '#search' ).fill( 'no matching pattern' );
	await app.getByText( /No patterns match/ ).waitFor();
	await app.locator( '#search' ).fill( '' );
	await hostSend( 'ui/notifications/tool-result', {
		structuredContent: {
			items: [
				{
					id: 'theme/existing',
					name: 'theme/existing',
					title: 'Existing discovery pattern',
					categories: [ 'featured' ],
					category_labels: { featured: 'Featured' },
					source: 'registered',
					content_blocks: [ 'core/heading' ],
					post_types: [ 'page' ],
				},
			],
		},
	} );
	await app
		.getByRole( 'heading', { name: 'Existing discovery pattern' } )
		.waitFor();
	assert.equal(
		await app
			.getByRole( 'button', {
				name: 'Choose Existing discovery pattern',
			} )
			.isDisabled(),
		true
	);
	assert.match( await app.locator( '.badge' ).innerText(), /Not verified/ );
	await hostSend(
		'ui/notifications/tool-result',
		result( [], { status: 'stale' } )
	);
	await app.getByText( /out of date/ ).waitFor();
	assert.equal( await app.locator( '#picker' ).isHidden(), true );
	await hostSend( 'ui/notifications/tool-result', { isError: true } );
	await app.getByText( /could not be displayed/ ).waitFor();
	await hostSend( 'ui/notifications/tool-cancelled', {} );
	await app.getByText( /cancelled/ ).waitFor();

	await hostSend( 'ui/notifications/tool-result', payload );
	await app.locator( '#search' ).fill( '' );
	await hostSend( 'ui/notifications/host-context-changed', {
		theme: 'dark',
	} );
	await app.locator( 'html[data-theme="dark"]' ).waitFor();
	await page.setViewportSize( { width: 320, height: 700 } );
	assert.equal(
		await frame.evaluate(
			() => document.documentElement.scrollWidth <= window.innerWidth
		),
		true
	);
	if ( process.env.ACULECT_PATTERN_PICKER_SCREENSHOT ) {
		await app.locator( 'body' ).screenshot( {
			path: process.env.ACULECT_PATTERN_PICKER_SCREENSHOT,
		} );
	}
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
	process.stdout.write( 'PASS sandboxed pattern picker fixture\n' );
} finally {
	await browser.close();
}

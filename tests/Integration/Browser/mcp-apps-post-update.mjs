import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from '@playwright/test';

const packagedHtml = readFileSync(
	new URL(
		'../../../assets/mcp-apps/post-update/v1/post-update.html',
		import.meta.url
	),
	'utf8'
);
const source = ( path ) =>
	readFileSync( new URL( path, import.meta.url ), 'utf8' );
const html =
	process.env.ACULECT_POST_UPDATE_SOURCE_FIXTURE === '1'
		? source( '../../../widgets/post-update/v1/post-update.template.html' )
				.replace(
					'__ACULECT_WIDGET_CSS__',
					source( '../../../widgets/post-update/v1/post-update.css' )
				)
				.replace(
					'__ACULECT_WIDGET_JS__',
					[
						source( '../../../widgets/runtime/v1/bridge.js' ),
						source( '../../../widgets/post-update/v1/contract.js' ),
						source( '../../../widgets/post-update/v1/widget.js' ),
					].join( '\n' )
				)
		: packagedHtml;
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

const update = {
	structuredContent: {
		schema: 'aculect.post-update.v1',
		outcome: 'success',
		content: {
			title: '<img src=x onerror="window.hacked=true">',
			type: 'Page',
			status: 'draft',
			completed_at: '2026-09-25T10:30:00Z',
			revision: 'Revision 42',
		},
		links: {
			view: 'https://site.example/example-draft/',
			edit: 'https://site.example/wp-admin/post.php?post=123&action=edit',
			compare: 'https://site.example/wp-admin/revision.php?revision=42',
			undo: 'https://site.example/wp-admin/revision.php?revision=40',
		},
		undo: {
			available: true,
			safe_snapshot: true,
			post_id: 123,
			revision_id: 40,
			reason: 'wordpress_revision_review',
		},
	},
};

async function hostSend( method, params ) {
	await page.evaluate(
		( message ) => {
			document
				.querySelector( '#app' )
				.contentWindow.postMessage(
					{ jsonrpc: '2.0', ...message },
					'*'
				);
		},
		{ method, params }
	);
}

try {
	await page.goto( 'about:blank' );
	await page.evaluate( ( markup ) => {
		const iframe = document.createElement( 'iframe' );
		iframe.id = 'app';
		iframe.title = 'Post-update result';
		iframe.setAttribute( 'sandbox', 'allow-scripts' );
		iframe.style.cssText = 'width:100%;height:700px;border:0';
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
			( item ) => item.method === 'ui/notifications/initialized'
		)
	);
	const app = page.frameLocator( '#app' );
	const frame = page.frames().find( ( item ) => item !== page.mainFrame() );
	assert.ok( frame );
	assert.equal(
		await app.locator( '#status' ).getAttribute( 'data-state' ),
		'loading'
	);
	await hostSend( 'ui/notifications/tool-input', { arguments: {} } );
	await hostSend( 'ui/notifications/tool-result', update );
	await app
		.getByRole( 'heading', {
			name: update.structuredContent.content.title,
		} )
		.waitFor();
	assert.equal(
		await app.locator( '#status' ).getAttribute( 'data-state' ),
		'success'
	);
	assert.equal( await app.locator( '#post-status' ).innerText(), 'draft' );
	assert.equal( await app.locator( '#revision' ).innerText(), 'Revision 42' );
	assert.equal( await app.locator( 'img' ).count(), 0 );
	assert.equal( await frame.evaluate( () => window.hacked ), undefined );
	assert.match(
		await app.locator( '#undo-hint' ).innerText(),
		/opening it does not restore anything/i
	);
	assert.equal(
		await app.getByRole( 'button', { name: /Undo/ } ).count(),
		0
	);
	assert.equal(
		await app
			.getByRole( 'button', { name: 'Review prior revision' } )
			.count(),
		1
	);
	if ( process.env.ACULECT_POST_UPDATE_SCREENSHOT ) {
		await frame.evaluate(
			( payload ) =>
				window.AculectPostUpdateFixture.showResult( payload ),
			{
				structuredContent: {
					...update.structuredContent,
					content: {
						...update.structuredContent.content,
						title: 'Homepage design plan',
					},
				},
			}
		);
		await app.locator( 'body' ).screenshot( {
			path: process.env.ACULECT_POST_UPDATE_SCREENSHOT,
		} );
	}

	const view = app.getByRole( 'button', { name: 'View page' } );
	await view.focus();
	await view.press( 'Enter' );
	await page.waitForFunction( () =>
		window.hostMessages.some( ( item ) => item.method === 'ui/open-link' )
	);
	assert.equal( await view.isDisabled(), true );
	await view.click( { force: true } );
	assert.equal(
		await page.evaluate(
			() =>
				window.hostMessages.filter(
					( item ) => item.method === 'ui/open-link'
				).length
		),
		1
	);
	assert.equal(
		await page.evaluate(
			() =>
				window.hostMessages.find(
					( item ) => item.method === 'ui/open-link'
				).params.url
		),
		'https://site.example/example-draft/'
	);
	await page.evaluate( () => {
		const iframe = document.querySelector( '#app' );
		const request = window.hostMessages.find(
			( item ) => item.method === 'ui/open-link'
		);
		iframe.contentWindow.postMessage(
			{ jsonrpc: '2.0', id: request.id, result: {} },
			'*'
		);
	} );
	await app.locator( 'button[data-link="view"]:not([disabled])' ).waitFor();
	assert.equal( await view.isEnabled(), true );
	await app.getByRole( 'button', { name: 'Edit in WordPress' } ).click();
	await page.waitForFunction(
		() =>
			window.hostMessages.filter(
				( item ) => item.method === 'ui/open-link'
			).length === 2
	);
	await page.evaluate( () => {
		const iframe = document.querySelector( '#app' );
		const request = window.hostMessages.filter(
			( item ) => item.method === 'ui/open-link'
		)[ 1 ];
		iframe.contentWindow.postMessage(
			{
				jsonrpc: '2.0',
				id: request.id,
				error: { code: -32000, message: 'Denied' },
			},
			'*'
		);
	} );
	await app.getByText( /host could not open that link/ ).waitFor();
	assert.equal(
		await app.locator( '#status' ).getAttribute( 'data-state' ),
		'success'
	);
	const review = app.getByRole( 'button', { name: 'Review prior revision' } );
	await review.click();
	await page.waitForFunction(
		() =>
			window.hostMessages.filter(
				( item ) => item.method === 'ui/open-link'
			).length === 3
	);
	assert.equal(
		await page.evaluate(
			() =>
				window.hostMessages.filter(
					( item ) => item.method === 'ui/open-link'
				)[ 2 ].params.url
		),
		'https://site.example/wp-admin/revision.php?revision=40'
	);
	assert.equal( await review.isDisabled(), true );
	await review.click( { force: true } );
	assert.equal(
		await page.evaluate(
			() =>
				window.hostMessages.filter(
					( item ) => item.method === 'ui/open-link'
				).length
		),
		3
	);
	await page.evaluate( () => {
		const iframe = document.querySelector( '#app' );
		const request = window.hostMessages.filter(
			( item ) => item.method === 'ui/open-link'
		)[ 2 ];
		iframe.contentWindow.postMessage(
			{ jsonrpc: '2.0', id: request.id, result: {} },
			'*'
		);
	} );

	for ( const outcome of [ 'partial', 'stale', 'unavailable', 'error' ] ) {
		await frame.evaluate(
			( payload ) =>
				window.AculectPostUpdateFixture.showResult( payload ),
			{ structuredContent: { ...update.structuredContent, outcome } }
		);
		assert.equal(
			await app.locator( '#status' ).getAttribute( 'data-state' ),
			outcome
		);
		assert.equal( await review.isHidden(), true );
	}
	await frame.evaluate(
		( payload ) => window.AculectPostUpdateFixture.showResult( payload ),
		{
			structuredContent: {
				...update.structuredContent,
				undo: { available: false },
			},
		}
	);
	assert.match(
		await app.locator( '#undo-hint' ).innerText(),
		/unavailable/
	);
	await hostSend( 'ui/notifications/tool-cancelled', {
		reason: 'host cancelled',
	} );
	await app.locator( '#status[data-state="cancelled"]' ).waitFor();
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
	assert.equal(
		await page.evaluate( () =>
			window.hostMessages.some( ( item ) => item.method === 'tools/call' )
		),
		false
	);
	assert.deepEqual( errors, [] );
	assert.deepEqual( remoteRequests, [] );
	process.stdout.write( 'PASS sandboxed post-update widget fixture\n' );
} finally {
	await browser.close();
}

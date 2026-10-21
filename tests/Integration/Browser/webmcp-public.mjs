import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { chromium } from '@playwright/test';
import { minify } from 'terser';

// Native browser proof. No modelContext mock, credentials, or OAuth requests.
const external = process.env.ACULECT_WEBMCP_PUBLIC_URL;
const assetPath =
	process.env.ACULECT_WEBMCP_ASSET ||
	new URL( '../../../assets/js/webmcp.js', import.meta.url );
const expectedHash = process.env.ACULECT_WEBMCP_SHA256;
const toolName = 'aculect_get_page_context';
const receipt = {
	mode: external ? 'public-site' : 'synthetic-page',
	checks: [],
};
let server;
let browser;

async function bounded( promise ) {
	let timer;
	try {
		return await Promise.race( [
			promise,
			new Promise( ( resolve, reject ) => {
				timer = setTimeout(
					() => reject( new Error( 'Probe deadline exceeded.' ) ),
					5000
				);
			} ),
		] );
	} finally {
		clearTimeout( timer );
	}
}

async function assetDigest( url ) {
	const response = await fetch( url, {
		redirect: 'error',
		signal: AbortSignal.timeout( 5000 ),
		credentials: 'omit',
	} );
	if ( ! response.ok || ! response.body ) {
		return null;
	}
	const hash = createHash( 'sha256' );
	let bytes = 0;
	for await ( const chunk of response.body ) {
		bytes += chunk.length;
		if ( bytes > 262144 ) {
			throw new Error( 'Asset exceeds probe budget.' );
		}
		hash.update( chunk );
	}
	return hash.digest( 'hex' );
}

function publicTarget( value ) {
	const url = new URL( value );
	const local =
		process.env.ACULECT_WEBMCP_FIXTURE === '1' &&
		[ '127.0.0.1', 'localhost' ].includes( url.hostname );
	assert.ok(
		url.protocol === 'https:' || ( local && url.protocol === 'http:' ),
		'Public probes require HTTPS; HTTP is reserved for opted-in loopback fixtures.'
	);
	assert.ok( ! url.username && ! url.password && ! url.search && ! url.hash );
	assert.ok(
		! /\/(wp-admin|wp-login\.php|wp-json)(\/|$)/i.test( url.pathname )
	);
	return url.href;
}

function fixture( asset = '/webmcp.js' ) {
	return `<!doctype html><html lang="en"><head><title>Public fixture</title>
<meta name="description" content="Public description"></head><body><main>
<h1>Public heading</h1><p id="copy">Visible public content</p>
<p hidden>PRIVATE_HIDDEN</p><div style="display:none">PRIVATE_CSS</div>
<form>PRIVATE_FORM<input value="PRIVATE_INPUT"></form>
<div contenteditable="true">PRIVATE_EDITABLE</div>
<div aria-hidden="true">PRIVATE_ARIA</div>
<a href="/guide?secret=PRIVATE_QUERY#PRIVATE_FRAGMENT">Public guide</a>
<a href="https://other.invalid/">External link</a>
</main><script defer src="${ asset }"></script></body></html>`;
}

async function startFixture() {
	const source = readFileSync( assetPath, 'utf8' );
	const hash = createHash( 'sha256' ).update( source ).digest( 'hex' );
	if ( expectedHash ) {
		assert.equal( hash, expectedHash, 'Requested asset identity differs.' );
	}
	receipt.assetSha256 = hash;
	const optimized = await minify( source );
	server = createServer( ( request, response ) => {
		const path = new URL( request.url, 'http://localhost' ).pathname;
		response.setHeader( 'Cache-Control', 'public, max-age=600' );
		if ( path.endsWith( '.js' ) ) {
			response.setHeader( 'Content-Type', 'text/javascript' );
			response.end( path === '/optimized.js' ? optimized.code : source );
		} else {
			response.setHeader( 'Content-Type', 'text/html' );
			response.end(
				path === '/away'
					? '<main>Other public page</main>'
					: fixture(
							path === '/optimized'
								? '/optimized.js'
								: '/webmcp.js'
					  )
			);
		}
	} );
	await new Promise( ( resolve ) =>
		server.listen( 0, '127.0.0.1', resolve )
	);
	return `http://127.0.0.1:${ server.address().port }`;
}

async function discover( page ) {
	await page.waitForFunction(
		async ( name ) => {
			if ( typeof document.modelContext?.getTools !== 'function' ) {
				return false;
			}
			return ( await document.modelContext.getTools() ).some(
				( tool ) => tool.name === name
			);
		},
		toolName,
		{ timeout: 5000 }
	);
	return bounded(
		page.evaluate( async ( name ) => {
			const tools = ( await document.modelContext.getTools() ).filter(
				( tool ) => tool.name === name
			);
			return { count: tools.length, annotations: tools[ 0 ].annotations };
		}, toolName )
	);
}

async function execute( page, input = {} ) {
	const result = await bounded(
		page.evaluate(
			async ( { name, args } ) => {
				const tool = ( await document.modelContext.getTools() ).find(
					( item ) => item.name === name
				);
				return document.modelContext.executeTool( tool, args );
			},
			{ name: toolName, args: input }
		)
	);
	const context = typeof result === 'string' ? JSON.parse( result ) : result;
	assert.equal( context.type, 'aculect_page_context' );
	assert.ok( JSON.stringify( context ).length <= 1500 );
	assert.ok( context.links.length <= 4 );
	for ( const link of context.links ) {
		const url = new URL( link.url );
		assert.equal( url.origin, new URL( page.url() ).origin );
		assert.equal( url.search, '' );
		assert.equal( url.hash, '' );
	}
	return context;
}

async function fixtureChecks( page, base ) {
	const initial = await execute( page );
	assert.match( initial.content, /Visible public content/ );
	assert.deepEqual( initial.links, [] );
	await page.evaluate( () => {
		const link = document.createElement( 'a' );
		link.href = URL.createObjectURL( new Blob( [ 'PRIVATE_BLOB' ] ) );
		link.textContent = 'Private blob';
		document.querySelector( 'main' ).append( link );
	} );
	const linked = await execute( page, { includeLinks: true } );
	assert.equal( linked.links.length, 1 );
	assert.ok( ! JSON.stringify( linked ).includes( 'PRIVATE_' ) );
	await page.locator( '#copy' ).evaluate( ( node ) => {
		node.textContent = 'Changed public content';
	} );
	assert.match( ( await execute( page ) ).content, /Changed public content/ );
	receipt.checks.push(
		'bounded-public-output',
		'private-content-excluded',
		'fresh-content'
	);
	await page.addScriptTag( { url: `${ base }/webmcp.js` } );
	assert.equal( ( await discover( page ) ).count, 1 );
	receipt.checks.push( 'duplicate-script' );
	receipt.stage = 'navigation-away';
	await page.goto( `${ base }/away` );
	assert.equal(
		await page.evaluate(
			async ( name ) =>
				( await document.modelContext.getTools() ).some(
					( tool ) => tool.name === name
				),
			toolName
		),
		false
	);
	receipt.stage = 'navigation-return';
	// A bfcache restoration has no new load event.
	await page.goBack( { waitUntil: 'commit' } );
	assert.equal( ( await discover( page ) ).count, 1 );
	await execute( page );
	receipt.checks.push( 'navigation-return' );
	receipt.stage = 'optimized-script';
	await page.goto( `${ base }/optimized` );
	assert.equal( ( await discover( page ) ).count, 1 );
	assert.match( ( await execute( page ) ).content, /Visible public content/ );
	await page.reload();
	assert.equal( ( await discover( page ) ).count, 1 );
	receipt.checks.push( 'minified-deferred-script', 'cached-reload' );
}

async function unsupportedCheck( base ) {
	const unsupported = await chromium.launch( {
		...( process.env.ACULECT_WEBMCP_BROWSER_CHANNEL
			? { channel: process.env.ACULECT_WEBMCP_BROWSER_CHANNEL }
			: {} ),
		args: [ '--disable-blink-features=WebMCP,WebMCPTesting' ],
	} );
	try {
		const page = await unsupported.newPage();
		const errors = [];
		page.on( 'pageerror', () => errors.push( 'page-error' ) );
		await page.goto( base );
		assert.equal(
			await page.evaluate( () => typeof document.modelContext ),
			'undefined'
		);
		assert.equal(
			await page.locator( '#copy' ).textContent(),
			'Visible public content'
		);
		assert.deepEqual( errors, [] );
		receipt.checks.push( 'unsupported-browser-fallback' );
	} finally {
		await unsupported.close();
	}
}

try {
	const target = external ? publicTarget( external ) : await startFixture();
	browser = await chromium.launch( {
		...( process.env.ACULECT_WEBMCP_BROWSER_CHANNEL
			? { channel: process.env.ACULECT_WEBMCP_BROWSER_CHANNEL }
			: {} ),
		args: [ '--enable-blink-features=WebMCP,WebMCPTesting' ],
		ignoreDefaultArgs: [ '--disable-back-forward-cache' ],
	} );
	receipt.browser = browser.version();
	receipt.experimentalFlags = [ 'WebMCP', 'WebMCPTesting' ];
	const context = await browser.newContext( { serviceWorkers: 'block' } );
	const page = await context.newPage();
	page.setDefaultTimeout( 5000 );
	const errors = [];
	const assetResponses = [];
	page.on( 'pageerror', () => errors.push( 'page-error' ) );
	page.on( 'response', ( response ) => {
		const url = new URL( response.url() );
		if (
			external &&
			assetResponses.length === 0 &&
			url.origin === new URL( target ).origin &&
			url.pathname.endsWith( '/assets/js/webmcp.js' )
		) {
			assetResponses.push( assetDigest( url.href ).catch( () => null ) );
		}
	} );
	if ( external ) {
		await context.route( '**/*', ( route ) => {
			const request = route.request();
			return request.method() === 'GET' &&
				new URL( request.url() ).origin === new URL( target ).origin
				? route.continue()
				: route.abort();
		} );
	}
	receipt.stage = 'page-delivery';
	const response = await page.goto( target, {
		waitUntil: 'load',
		timeout: 15000,
	} );
	assert.equal( response.status(), 200 );
	assert.equal( new URL( page.url() ).origin, new URL( target ).origin );
	receipt.nativeApiAvailable = await page.evaluate(
		() => typeof document.modelContext?.getTools === 'function'
	);
	receipt.stage = 'native-discovery';
	const discovered = await discover( page );
	assert.equal( discovered.count, 1 );
	assert.equal( discovered.annotations.readOnlyHint, true );
	assert.equal( discovered.annotations.untrustedContentHint, true );
	assert.equal( discovered.annotations.consequentialHint, false );
	receipt.stage = 'native-execution';
	await execute( page );
	receipt.checks.push(
		'native-discovery',
		'native-execution',
		'read-only-annotations'
	);
	if ( ! external ) {
		await fixtureChecks( page, target );
		await unsupportedCheck( target );
		assert.deepEqual( errors, [] );
	} else {
		const hashes = await Promise.all( assetResponses );
		receipt.assetVerification = 'bounded-anonymous-refetch';
		receipt.assetSha256 = hashes.find( Boolean ) || null;
		if ( expectedHash ) {
			assert.ok(
				hashes.includes( expectedHash ),
				'Anonymous asset re-fetch does not match the requested artifact.'
			);
			receipt.checks.push( 'anonymous-refetched-asset-identity' );
		}
	}
	receipt.pageErrorCount = errors.length;
	receipt.status = 'passed';
} catch ( error ) {
	// Never emit page content, URLs, cookies, or browser error strings from a site probe.
	receipt.status = 'failed';
	if ( ! external ) {
		receipt.failure = error.message;
	}
	process.exitCode = 1;
} finally {
	await browser?.close();
	await new Promise( ( resolve ) =>
		server ? server.close( resolve ) : resolve()
	);
	process.stdout.write( JSON.stringify( receipt, null, 2 ) + '\n' );
}

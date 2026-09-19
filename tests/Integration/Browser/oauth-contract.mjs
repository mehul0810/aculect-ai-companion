import crypto from 'node:crypto';
import process from 'node:process';
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { chromium } from '@playwright/test';
import { tokenCacheDisposition } from './oauth-token-cache-contract.mjs';

const DEFAULT_BASE_URL = 'http://localhost:8880';
const DEFAULT_ADMIN_USER = 'workflow-admin';
const DEFAULT_ADMIN_PASSWORD = 'workflow-admin-password';
const CALLBACK_URI = 'http://127.0.0.1:49123/oauth/callback';
const PUBLIC_WEB_CALLBACK_URI =
	'https://chatgpt.com/connector_platform_oauth_redirect';
const MCP_PROTOCOL_VERSION = '2025-06-18';
const CLIENT_NAME = 'Aculect OAuth Contract Test';
const SCOPE = 'content:read offline_access';
const LOOPBACK_HOSTS = new Set( [ 'localhost', '127.0.0.1', '::1' ] );

let currentStage = 'configuration';
let lastHttpStatus = null;
let deferredTokenHeaders = 0;
const packageVersion = JSON.parse(
	readFileSync( new URL( '../../../package.json', import.meta.url ), 'utf8' )
).version;

function stage( name ) {
	currentStage = name;
	lastHttpStatus = null;
}

function check( condition, code = 'assertion' ) {
	if ( ! condition ) {
		throw new Error( `contract_${ code }` );
	}
}

function readConfig() {
	check( process.env.ACULECT_OAUTH_FIXTURE_OPT_IN === '1' );
	const configuredBase = String(
		process.env.ACULECT_WP_BASE_URL || DEFAULT_BASE_URL
	).trim();
	const parsedBase = new URL( configuredBase );
	const hostname = parsedBase.hostname
		.replace( /^\[|\]$/g, '' )
		.toLowerCase();
	check( parsedBase.protocol === 'http:' );
	check( LOOPBACK_HOSTS.has( hostname ) );
	check( parsedBase.username === '' && parsedBase.password === '' );
	check( parsedBase.search === '' && parsedBase.hash === '' );

	return {
		baseUrl: configuredBase.replace( /\/+$/, '' ),
		baseOrigin: parsedBase.origin,
		username: String(
			process.env.ACULECT_WP_ADMIN_USER || DEFAULT_ADMIN_USER
		),
		password: String(
			process.env.ACULECT_WP_ADMIN_PASSWORD || DEFAULT_ADMIN_PASSWORD
		),
	};
}

function assertLoopbackEndpoint( endpoint, expectedOrigin ) {
	const parsed = new URL( endpoint );
	const hostname = parsed.hostname.replace( /^\[|\]$/g, '' ).toLowerCase();
	check( parsed.protocol === 'http:' );
	check( LOOPBACK_HOSTS.has( hostname ) );
	check( parsed.username === '' && parsed.password === '' );
	check( parsed.origin === expectedOrigin );

	return parsed;
}

function siteUrl( baseUrl, path ) {
	return new URL( path.replace( /^\/+/, '' ), `${ baseUrl }/` ).toString();
}

function responseLocation( response, baseUrl ) {
	const location = response.headers().location;
	return location ? new URL( location, `${ baseUrl }/` ) : null;
}

function assertPrivateResponse( response ) {
	const cacheControl = response.headers()[ 'cache-control' ] || '';
	check( /(?:no-store|private)/i.test( cacheControl ), 'private-cache' );
}

async function responseJson( response ) {
	lastHttpStatus = response.status();
	try {
		return await response.json();
	} catch {
		throw new Error( 'invalid_json' );
	}
}

async function responseTextJson( response ) {
	lastHttpStatus = response.status;
	const text = await response.text();
	if ( '' === text.trim() ) {
		return null;
	}

	try {
		return JSON.parse( text );
	} catch {
		throw new Error( 'invalid_json' );
	}
}

function createPkce() {
	const verifier = crypto.randomBytes( 32 ).toString( 'base64url' );
	const challenge = crypto
		.createHash( 'sha256' )
		.update( verifier )
		.digest( 'base64url' );

	return { verifier, challenge };
}

function createClientPayload(
	applicationType = 'native',
	redirectUri = CALLBACK_URI
) {
	return {
		client_name: CLIENT_NAME,
		application_type: applicationType,
		token_endpoint_auth_method: 'none',
		redirect_uris: [ redirectUri ],
	};
}

async function registerClient(
	request,
	registrationEndpoint,
	payload = createClientPayload()
) {
	const response = await request.post( registrationEndpoint, {
		data: payload,
		headers: { accept: 'application/json' },
		maxRedirects: 0,
	} );
	check( response.status() === 201 );
	const data = await responseJson( response );
	check( typeof data?.client_id === 'string' );
	check( data.application_type === payload.application_type );
	check( data.token_endpoint_auth_method === 'none' );
	check( ! Object.prototype.hasOwnProperty.call( data, 'client_secret' ) );

	return {
		clientId: data.client_id,
		redirectUri: payload.redirect_uris[ 0 ],
	};
}

function authorizationUrl(
	endpoint,
	client,
	resource,
	pkce,
	state,
	overrides = {}
) {
	const url = new URL( endpoint );
	const params = {
		response_type: 'code',
		client_id: client.clientId,
		redirect_uri: client.redirectUri,
		scope: SCOPE,
		state,
		code_challenge: pkce.challenge,
		code_challenge_method: 'S256',
		resource,
		...overrides,
	};
	url.search = new URLSearchParams( params ).toString();

	return url.toString();
}

function assertLoginRedirect( response, baseUrl ) {
	lastHttpStatus = response.status();
	assertPrivateResponse( response );
	check( [ 301, 302, 303, 307, 308 ].includes( response.status() ) );
	const location = responseLocation( response, baseUrl );
	check( location instanceof URL );
	assertLoopbackEndpoint( location, new URL( baseUrl ).origin );
	check( location.pathname.endsWith( '/wp-login.php' ) );
	const redirectTo = location.searchParams.get( 'redirect_to' ) || '';
	assertLoopbackEndpoint( redirectTo, new URL( baseUrl ).origin );
	check( redirectTo.includes( 'admin.php' ) );
	check( redirectTo.includes( 'page=aculect-ai-companion-oauth-consent' ) );
	check(
		! redirectTo.includes(
			'/wp-json/aculect-ai-companion/v1/oauth/authorize'
		)
	);
}

function assertConsentRedirect( response, baseUrl ) {
	lastHttpStatus = response.status();
	assertPrivateResponse( response );
	check( [ 301, 302, 303, 307, 308 ].includes( response.status() ) );
	const location = responseLocation( response, baseUrl );
	check( location instanceof URL );
	assertLoopbackEndpoint( location, new URL( baseUrl ).origin );
	check(
		location.pathname.endsWith( '/wp-admin/admin.php' ),
		'consent-path'
	);
	check(
		location.searchParams.get( 'page' ) ===
			'aculect-ai-companion-oauth-consent'
	);
	check(
		/^[a-f0-9]{32}$/.test(
			location.searchParams.get( 'request_token' ) || ''
		)
	);
}

async function login( page, authorizeUrl, username, password ) {
	await page.goto( authorizeUrl, {
		waitUntil: 'domcontentloaded',
	} );
	await page.fill( '#user_login', username );
	await page.fill( '#user_pass', password );
	await Promise.all( [
		page.waitForURL( '**/wp-admin/**' ),
		page.click( '#wp-submit' ),
	] );
	check( page.url().includes( '/wp-admin/' ) );
	check(
		( await page
			.locator( '#aculect-ai-companion-oauth-title' )
			.count() ) === 1
	);
}

async function runLifecycleFixture( page, baseUrl ) {
	stage( 'lifecycle-nonce' );
	const nonceResponse = await page.request.post(
		siteUrl( baseUrl, 'wp-admin/admin-ajax.php' ),
		{
			form: { action: 'aculect_oauth_fixture_nonce' },
			maxRedirects: 0,
		}
	);
	check( nonceResponse.status() === 200 );
	const nonceData = await responseJson( nonceResponse );
	const nonce = nonceData?.data?.nonce;
	check(
		nonceData?.success === true && typeof nonce === 'string' && nonce !== ''
	);

	stage( 'lifecycle-cycle' );
	const response = await page.request.post(
		siteUrl( baseUrl, 'wp-admin/admin-ajax.php' ),
		{
			form: {
				action: 'aculect_oauth_fixture_lifecycle',
				operation: 'cycle',
				_ajax_nonce: nonce,
			},
			maxRedirects: 0,
		}
	);
	check( response.status() === 200 );
	const data = await responseJson( response );
	check( data?.success === true );
	check( data?.data?.status === 'cycle_complete' );
}

async function openConsent(
	page,
	authorizeEndpoint,
	client,
	resource,
	pkce,
	state
) {
	await page.goto(
		authorizationUrl( authorizeEndpoint, client, resource, pkce, state ),
		{ waitUntil: 'domcontentloaded' }
	);
	check(
		( await page
			.locator( '#aculect-ai-companion-oauth-title' )
			.count() ) === 1
	);
	check(
		( await page.locator( 'input[name="request_token"]' ).count() ) === 1
	);

	return {
		requestToken: await page
			.locator( 'input[name="request_token"]' )
			.inputValue(),
		nonce: await page.locator( 'input[name="_wpnonce"]' ).inputValue(),
	};
}

async function callbackForDecision(
	page,
	authorizeEndpoint,
	client,
	resource,
	pkce,
	state,
	decision
) {
	const callbackPattern = ( url ) =>
		url.origin === new URL( CALLBACK_URI ).origin &&
		url.pathname === new URL( CALLBACK_URI ).pathname;
	await openConsent( page, authorizeEndpoint, client, resource, pkce, state );
	const button = page.locator(
		`button[name="decision"][value="${ decision }"]`
	);
	check( ( await button.count() ) === 1 );
	const previousStage = currentStage;
	stage( `${ previousStage }-callback` );
	const [ decisionResponse ] = await Promise.all( [
		page
			.waitForResponse(
				( response ) =>
					new URL( response.url() ).pathname.endsWith(
						'/wp-admin/admin-post.php'
					) && response.request().method() === 'POST'
			)
			.then( ( response ) => {
				lastHttpStatus = response.status();
				check( response.status() === 302, 'decision-status' );
				const destination = new URL( response.headers().location );
				check(
					destination.origin === new URL( CALLBACK_URI ).origin,
					'callback-origin'
				);
				check(
					destination.pathname === new URL( CALLBACK_URI ).pathname,
					'callback-path'
				);
				return response;
			} ),
		page.waitForURL( callbackPattern ),
		button.click(),
	] );
	lastHttpStatus = decisionResponse.status();
	check( decisionResponse.status() === 302 );
	check(
		/no-store/i.test( decisionResponse.headers()[ 'cache-control' ] || '' ),
		'callback-cache'
	);
	stage( previousStage );

	const callback = new URL( page.url() );
	check( callback.origin === new URL( CALLBACK_URI ).origin );
	check( callback.pathname === new URL( CALLBACK_URI ).pathname );
	return callback;
}

async function tokenRequest( page, tokenEndpoint, fields ) {
	const response = await page.request.post( tokenEndpoint, {
		form: fields,
		headers: { accept: 'application/json' },
		maxRedirects: 0,
	} );
	lastHttpStatus = response.status();
	const data = await responseJson( response );
	if (
		tokenCacheDisposition(
			packageVersion,
			response.status(),
			response.headers(),
			data
		) === 'deferred-531'
	) {
		deferredTokenHeaders += 1;
	}

	return { status: response.status(), data };
}

async function mcpRequest( endpoint, token, id, method, params = {} ) {
	const body = {
		jsonrpc: '2.0',
		method,
		params,
	};
	if ( undefined !== id ) {
		body.id = id;
	}

	const response = await fetch( endpoint, {
		method: 'POST',
		redirect: 'error',
		signal: AbortSignal.timeout( 15000 ),
		headers: {
			accept: 'application/json',
			authorization: `Bearer ${ token }`,
			'content-type': 'application/json',
			'mcp-protocol-version': MCP_PROTOCOL_VERSION,
		},
		body: JSON.stringify( body ),
	} );
	lastHttpStatus = response.status;

	return {
		status: response.status,
		data: await responseTextJson( response ),
	};
}

async function exerciseMcp( endpoint, token ) {
	const initialized = await mcpRequest(
		endpoint,
		token,
		'initialize',
		'initialize',
		{
			protocolVersion: MCP_PROTOCOL_VERSION,
			capabilities: {},
			clientInfo: { name: 'aculect-oauth-contract', version: '1' },
		}
	);
	check( initialized.status === 200 );
	check( initialized.data?.result?.protocolVersion === MCP_PROTOCOL_VERSION );

	const notification = await mcpRequest(
		endpoint,
		token,
		undefined,
		'notifications/initialized'
	);
	check( notification.status === 202 );

	const names = new Set();
	let cursor = '';
	for ( let page = 0; page < 20; page += 1 ) {
		const listed = await mcpRequest(
			endpoint,
			token,
			`tools-${ page }`,
			'tools/list',
			cursor ? { cursor } : {}
		);
		check( listed.status === 200 );
		check( Array.isArray( listed.data?.result?.tools ) );
		for ( const tool of listed.data.result.tools ) {
			check( typeof tool?.name === 'string' && tool.name !== '' );
			check( ! names.has( tool.name ) );
			names.add( tool.name );
		}

		const nextCursor = listed.data?.result?.nextCursor || '';
		if ( ! nextCursor ) {
			break;
		}
		check( nextCursor !== cursor );
		cursor = nextCursor;
		if ( page === 19 ) {
			throw new Error( 'pagination_limit' );
		}
	}
	check( names.has( 'site_get_info' ) );

	const read = await mcpRequest( endpoint, token, 'read', 'tools/call', {
		name: 'site_get_info',
		arguments: {},
	} );
	check( read.status === 200 );
	check( typeof read.data?.result === 'object' && read.data.result !== null );
	check( Array.isArray( read.data.result.content ) );
	check( read.data.result.isError !== true );
}

async function discover( config, loggedOutPage ) {
	stage( 'discovery' );
	const metadataResponse = await loggedOutPage.request.get(
		siteUrl( config.baseUrl, '.well-known/oauth-authorization-server' ),
		{ maxRedirects: 0 }
	);
	check( metadataResponse.status() === 200 );
	const metadata = await responseJson( metadataResponse );
	check( typeof metadata?.authorization_endpoint === 'string' );
	check( typeof metadata?.token_endpoint === 'string' );
	check( typeof metadata?.registration_endpoint === 'string' );
	check(
		new URL( metadata.authorization_endpoint ).pathname.endsWith(
			'/aculect-ai-companion/oauth/authorize'
		)
	);
	check( Array.isArray( metadata?.protected_resources ) );
	const resource = String( metadata.protected_resources[ 0 ] || '' );
	check( resource !== '' );
	for ( const endpoint of [
		metadata.issuer,
		metadata.authorization_endpoint,
		metadata.token_endpoint,
		metadata.registration_endpoint,
		resource,
	] ) {
		assertLoopbackEndpoint( endpoint, config.baseOrigin );
	}
	const mcpEndpoint = resource;
	const restAuthorizeEndpoint = siteUrl(
		config.baseUrl,
		'wp-json/aculect-ai-companion/v1/oauth/authorize'
	);
	const competitorResponse = await loggedOutPage.request.get(
		siteUrl( config.baseUrl, 'oauth/authorize?fixture_probe=1' ),
		{ maxRedirects: 0 }
	);
	check( competitorResponse.status() === 200 );
	check(
		( await competitorResponse.text() ).trim() ===
			'oauth-competitor-fixture'
	);
	process.stdout.write(
		'PASS discovery owned metadata and generic route isolation\n'
	);
	return { metadata, resource, mcpEndpoint, restAuthorizeEndpoint };
}

async function registerClients( loggedOutPage, metadata ) {
	stage( 'dcr' );
	const clientA = await registerClient(
		loggedOutPage.request,
		metadata.registration_endpoint
	);
	const clientB = await registerClient(
		loggedOutPage.request,
		metadata.registration_endpoint
	);
	check( clientA.clientId !== clientB.clientId );
	process.stdout.write(
		'PASS repeated public DCR preserved independent client IDs\n'
	);
	const webClient = await registerClient(
		loggedOutPage.request,
		metadata.registration_endpoint,
		createClientPayload( 'web', PUBLIC_WEB_CALLBACK_URI )
	);
	return { clientA, clientB, webClient };
}

async function proveLifecycle( context ) {
	const {
		config,
		loggedInPage,
		loggedOutPage,
		metadata,
		resource,
		clientA,
		clientB,
		webClient,
	} = context;
	stage( 'login' );
	await login(
		loggedInPage,
		authorizationUrl(
			metadata.authorization_endpoint,
			webClient,
			resource,
			createPkce(),
			'web-login-return'
		),
		config.username,
		config.password
	);
	process.stdout.write(
		'PASS web client logged-out browser login returns to consent\n'
	);

	stage( 'lifecycle' );
	await runLifecycleFixture( loggedInPage, config.baseUrl );
	stage( 'lifecycle-competitor' );
	const postCycleCompetitor = await loggedOutPage.request.get(
		siteUrl( config.baseUrl, 'oauth/authorize?fixture_probe=2' ),
		{ maxRedirects: 0 }
	);
	check( postCycleCompetitor.status() === 200 );
	check(
		( await postCycleCompetitor.text() ).trim() ===
			'oauth-competitor-fixture'
	);
	for ( const client of [ clientA, clientB, webClient ] ) {
		stage(
			client === webClient
				? 'lifecycle-web-client'
				: 'lifecycle-native-client'
		);
		const persisted = await loggedInPage.request.get(
			authorizationUrl(
				metadata.authorization_endpoint,
				client,
				resource,
				createPkce(),
				`cycle-${ client === clientA ? 'a' : 'b' }`
			),
			{ maxRedirects: 0 }
		);
		assertConsentRedirect( persisted, config.baseUrl );
	}
	process.stdout.write(
		'PASS bounded deactivate/reactivate preserved clients and rewrites\n'
	);
}

async function proveRedirects( context ) {
	const {
		config,
		loggedInPage,
		loggedOutPage,
		metadata,
		resource,
		clientA,
		restAuthorizeEndpoint,
	} = context;
	stage( 'redirects' );
	for ( const [ label, endpoint ] of [
		[ 'owned', metadata.authorization_endpoint ],
		[ 'REST', restAuthorizeEndpoint ],
	] ) {
		const pkce = createPkce();
		const url = authorizationUrl(
			endpoint,
			clientA,
			resource,
			pkce,
			`redirect-${ label }`
		);
		const loggedOut = await loggedOutPage.request.get( url, {
			maxRedirects: 0,
		} );
		assertLoginRedirect( loggedOut, config.baseUrl );
		const loggedIn = await loggedInPage.request.get( url, {
			maxRedirects: 0,
		} );
		assertConsentRedirect( loggedIn, config.baseUrl );
	}
	process.stdout.write(
		'PASS owned and REST login-to-consent redirect contract\n'
	);
}

async function proveWrongPkce( context ) {
	const { loggedInPage, metadata, resource, clientB } = context;
	stage( 'wrong-pkce' );
	const wrongPkce = createPkce();
	const wrongPkceCallback = await callbackForDecision(
		loggedInPage,
		metadata.authorization_endpoint,
		clientB,
		resource,
		wrongPkce,
		'wrong-pkce-code',
		'approve'
	);
	check( wrongPkceCallback.searchParams.has( 'code' ) );
	const wrongVerifier = createPkce().verifier;
	const wrongPkceResponse = await tokenRequest(
		loggedInPage,
		metadata.token_endpoint,
		{
			grant_type: 'authorization_code',
			client_id: clientB.clientId,
			redirect_uri: clientB.redirectUri,
			code: wrongPkceCallback.searchParams.get( 'code' ),
			code_verifier: wrongVerifier,
			resource,
		}
	);
	check( wrongPkceResponse.status === 400 );
	process.stdout.write(
		'PASS wrong-PKCE code rejection used a separate code\n'
	);
}

async function proveInvalidConsent( context ) {
	const { config, loggedInPage, loggedOutPage, metadata, resource, clientA } =
		context;
	stage( 'invalid-nonce-session' );
	const invalidContext = await openConsent(
		loggedInPage,
		metadata.authorization_endpoint,
		clientA,
		resource,
		createPkce(),
		'invalid-nonce'
	);
	const invalidNonce = await loggedInPage.request.post(
		siteUrl( config.baseUrl, 'wp-admin/admin-post.php' ),
		{
			form: {
				action: 'aculect_ai_companion_oauth_consent',
				request_token: invalidContext.requestToken,
				_wpnonce: 'invalid-contract-nonce',
				decision: 'approve',
			},
			maxRedirects: 0,
		}
	);
	check( invalidNonce.status() === 400 );
	assertPrivateResponse( invalidNonce );
	const invalidSession = await loggedOutPage.request.post(
		siteUrl( config.baseUrl, 'wp-admin/admin-post.php' ),
		{
			form: {
				action: 'aculect_ai_companion_oauth_consent',
				request_token: invalidContext.requestToken,
				decision: 'approve',
			},
			maxRedirects: 0,
		}
	);
	assertLoginRedirect( invalidSession, config.baseUrl );
	const badPkce = await loggedOutPage.request.get(
		authorizationUrl(
			metadata.authorization_endpoint,
			clientA,
			resource,
			{ challenge: 'short', verifier: 'unused' },
			'bad-pkce',
			{ code_challenge_method: 'plain' }
		),
		{ maxRedirects: 0 }
	);
	check( badPkce.status() === 400 );
	assertPrivateResponse( badPkce );
	process.stdout.write(
		'PASS invalid nonce/session and bad-PKCE rejection\n'
	);
}

async function proveExchange( context ) {
	const { loggedInPage, metadata, resource, clientA } = context;
	stage( 'exchange' );
	const successPkce = createPkce();
	const successCallback = await callbackForDecision(
		loggedInPage,
		metadata.authorization_endpoint,
		clientA,
		resource,
		successPkce,
		'success-state',
		'approve'
	);
	const code = successCallback.searchParams.get( 'code' );
	check( typeof code === 'string' && code !== '' );
	check( successCallback.searchParams.get( 'state' ) === 'success-state' );
	const exchanged = await tokenRequest(
		loggedInPage,
		metadata.token_endpoint,
		{
			grant_type: 'authorization_code',
			client_id: clientA.clientId,
			redirect_uri: clientA.redirectUri,
			code,
			code_verifier: successPkce.verifier,
			resource,
		}
	);
	check( exchanged.status === 200 );
	check( typeof exchanged.data?.access_token === 'string' );
	check( typeof exchanged.data?.refresh_token === 'string' );
	process.stdout.write( 'PASS consent approval and PKCE exchange\n' );
	return { exchanged, code, successPkce };
}

async function proveAuthenticatedAccess( context ) {
	const {
		loggedInPage,
		metadata,
		resource,
		clientA,
		mcpEndpoint,
		exchanged,
		code,
		successPkce,
	} = context;
	stage( 'mcp' );
	await exerciseMcp( mcpEndpoint, exchanged.data.access_token );
	process.stdout.write( 'PASS authenticated MCP initialize/list/read\n' );

	stage( 'refresh' );
	const originalRefresh = exchanged.data.refresh_token;
	const refreshed = await tokenRequest(
		loggedInPage,
		metadata.token_endpoint,
		{
			grant_type: 'refresh_token',
			client_id: clientA.clientId,
			refresh_token: originalRefresh,
			resource,
		}
	);
	check( refreshed.status === 200 );
	check( typeof refreshed.data?.access_token === 'string' );
	check( typeof refreshed.data?.refresh_token === 'string' );
	check( refreshed.data.refresh_token !== originalRefresh );
	await exerciseMcp( mcpEndpoint, refreshed.data.access_token );
	const replayedRefresh = await tokenRequest(
		loggedInPage,
		metadata.token_endpoint,
		{
			grant_type: 'refresh_token',
			client_id: clientA.clientId,
			refresh_token: originalRefresh,
			resource,
		}
	);
	check( replayedRefresh.status === 400 );
	process.stdout.write( 'PASS refresh rotation and replay rejection\n' );
	stage( 'code-replay' );
	const reusedCode = await tokenRequest(
		loggedInPage,
		metadata.token_endpoint,
		{
			grant_type: 'authorization_code',
			client_id: clientA.clientId,
			redirect_uri: clientA.redirectUri,
			code,
			code_verifier: successPkce.verifier,
			resource,
		}
	);
	check( reusedCode.status === 400 );
	process.stdout.write( 'PASS code replay rejection\n' );
}

async function proveDenial( context ) {
	const { loggedInPage, metadata, resource, clientA } = context;
	stage( 'deny' );
	const denyPkce = createPkce();
	const denied = await callbackForDecision(
		loggedInPage,
		metadata.authorization_endpoint,
		clientA,
		resource,
		denyPkce,
		'deny-state',
		'deny'
	);
	check( denied.searchParams.get( 'error' ) === 'access_denied' );
	check( denied.searchParams.get( 'state' ) === 'deny-state' );
	check( ! denied.searchParams.has( 'code' ) );
	process.stdout.write( 'PASS consent denial callback\n' );
}

async function run() {
	const config = readConfig();
	stage( 'browser-setup' );
	const callbackServer = createServer( ( request, response ) => {
		response.writeHead(
			request.url?.startsWith( '/oauth/callback?' ) ? 200 : 404,
			{ 'Content-Type': 'text/plain', 'Cache-Control': 'no-store' }
		);
		response.end( 'Disposable OAuth callback' );
	} );
	await new Promise( ( resolve, reject ) => {
		callbackServer.once( 'error', reject );
		callbackServer.listen( 49123, '127.0.0.1', resolve );
	} );
	let browser;
	try {
		browser = await chromium.launch();
		const loggedOutContext = await browser.newContext();
		const loggedInContext = await browser.newContext();
		const loggedOutPage = await loggedOutContext.newPage();
		const loggedInPage = await loggedInContext.newPage();
		loggedOutPage.setDefaultTimeout( 15000 );
		loggedInPage.setDefaultTimeout( 15000 );
		const context = {
			config,
			loggedOutPage,
			loggedInPage,
			...( await discover( config, loggedOutPage ) ),
		};
		Object.assign(
			context,
			await registerClients( loggedOutPage, context.metadata )
		);
		await proveLifecycle( context );
		await proveRedirects( context );
		await proveInvalidConsent( context );
		Object.assign( context, await proveExchange( context ) );
		await proveAuthenticatedAccess( context );
		await proveDenial( context );
		await proveWrongPkce( context );
		if ( deferredTokenHeaders > 0 ) {
			process.stdout.write(
				`DEFERRED #531: cache headers on ${ deferredTokenHeaders } rejected token responses; owner-approved for 0.8.0 only\n`
			);
		}
	} finally {
		await browser?.close();
		await new Promise( ( resolve ) => callbackServer.close( resolve ) );
	}
}

try {
	await run();
} catch ( error ) {
	const line =
		/oauth-contract\.mjs:(\d+):\d+/.exec( error?.stack || '' )?.[ 1 ] ||
		'unknown';
	const failure = /^contract_[a-z-]+$/.test( error?.message || '' )
		? error.message
		: 'runtime';
	process.stderr.write(
		`FAIL ${ currentStage } http=${
			lastHttpStatus ?? 'unknown'
		} check=${ failure } line=${ line }\n`
	);
	process.exitCode = 1;
}

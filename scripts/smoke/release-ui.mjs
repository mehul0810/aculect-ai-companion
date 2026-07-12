#!/usr/bin/env node
/* eslint-disable no-console -- Smoke harness output is its CLI contract. */

import { createHash } from 'node:crypto';
import { mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const HELP = `
Aculect AI Companion release UI smoke

Required environment:
  ACULECT_SMOKE_BASE_URL      WordPress site URL.
  ACULECT_SMOKE_USERNAME      WordPress admin username or email.
  ACULECT_SMOKE_PASSWORD      WordPress admin password.

Optional environment:
  ACULECT_SMOKE_ADMIN_PATH    Defaults to /wp-admin/options-general.php?page=aculect-ai-companion
  ACULECT_SMOKE_ARTIFACT_DIR  Defaults to artifacts/smoke/release-ui
  ACULECT_SMOKE_HEADLESS      Defaults to true. Set to false to watch the browser.
  ACULECT_SMOKE_CONNECT_PROOF_URL
                                Optional public-safe artifact URL for manual Connect approval proof.
  ACULECT_SMOKE_OAUTH_CONSENT_PROOF_URL
                                Optional public-safe artifact URL for manual OAuth consent proof.
  ACULECT_SMOKE_OAUTH_REVOKE_PROOF_URL
                                Optional public-safe artifact URL for manual OAuth revoke proof.
  ACULECT_SMOKE_MCP_BEARER_TOKEN
                                Optional OAuth access token for authenticated MCP tools/list smoke.
  ACULECT_SMOKE_MCP_PATH       Defaults to /wp-json/aculect-ai-companion/v1/mcp
  ACULECT_SMOKE_TIMEOUT_MS    Defaults to 30000.

Artifacts:
  Screenshots and summary JSON are written to ACULECT_SMOKE_ARTIFACT_DIR/latest.
`;

const TABS = [
	{ name: 'overview', label: 'Overview' },
	{ name: 'connect', label: 'Connect' },
	{ name: 'connections', label: 'Connections' },
	{ name: 'abilities', label: 'Abilities' },
	{ name: 'activity', label: 'Activity' },
	{ name: 'learning', label: 'Learning' },
	{ name: 'diagnostics', label: 'Diagnostics' },
	{ name: 'advanced', label: 'Advanced' },
	{ name: 'changelog', label: 'Changelog' },
];

const VIEWPORTS = [
	{ name: 'desktop', width: 1440, height: 1100 },
	{ name: 'constrained', width: 782, height: 1000 },
];

function learningSectionHeading( page, label ) {
	return page
		.locator( '.aculect-ai-companion-learning-section-heading h3' )
		.filter( { hasText: label } );
}

function shouldShowHelp() {
	return process.argv.includes( '--help' ) || process.argv.includes( '-h' );
}

function envValue( name, fallback = '' ) {
	return String( process.env[ name ] || fallback ).trim();
}

function boolEnv( name, fallback ) {
	const value = envValue( name );

	if ( value === '' ) {
		return fallback;
	}

	return ! [ '0', 'false', 'no', 'off' ].includes( value.toLowerCase() );
}

function intEnv( name, fallback ) {
	const value = Number.parseInt( envValue( name ), 10 );

	return Number.isFinite( value ) && value > 0 ? value : fallback;
}

function requiredConfig() {
	const config = {
		baseUrl: envValue( 'ACULECT_SMOKE_BASE_URL' ),
		username: envValue( 'ACULECT_SMOKE_USERNAME' ),
		password: envValue( 'ACULECT_SMOKE_PASSWORD' ),
		adminPath: envValue(
			'ACULECT_SMOKE_ADMIN_PATH',
			'/wp-admin/options-general.php?page=aculect-ai-companion'
		),
		artifactDir: envValue(
			'ACULECT_SMOKE_ARTIFACT_DIR',
			'artifacts/smoke/release-ui'
		),
		headless: boolEnv( 'ACULECT_SMOKE_HEADLESS', true ),
		manualProof: {
			connectApproval: envValue( 'ACULECT_SMOKE_CONNECT_PROOF_URL' ),
			oauthConsent: envValue( 'ACULECT_SMOKE_OAUTH_CONSENT_PROOF_URL' ),
			oauthRevoke: envValue( 'ACULECT_SMOKE_OAUTH_REVOKE_PROOF_URL' ),
		},
		mcpBearerToken: envValue( 'ACULECT_SMOKE_MCP_BEARER_TOKEN' ),
		mcpPath: envValue(
			'ACULECT_SMOKE_MCP_PATH',
			'/wp-json/aculect-ai-companion/v1/mcp'
		),
		timeoutMs: intEnv( 'ACULECT_SMOKE_TIMEOUT_MS', 30000 ),
	};
	const missing = [ 'baseUrl', 'username', 'password' ].filter(
		( key ) => ! config[ key ]
	);

	if ( missing.length > 0 ) {
		throw new Error(
			`Missing required environment: ${ missing
				.map( ( key ) => {
					if ( key === 'baseUrl' ) {
						return 'ACULECT_SMOKE_BASE_URL';
					}
					if ( key === 'username' ) {
						return 'ACULECT_SMOKE_USERNAME';
					}
					return 'ACULECT_SMOKE_PASSWORD';
				} )
				.join( ', ' ) }\n${ HELP }`
		);
	}

	return config;
}

function manualProofSummary( config ) {
	const flows = [
		{
			key: 'connectApproval',
			label: 'Connect approval flow',
			url: config.manualProof.connectApproval,
		},
		{
			key: 'oauthConsent',
			label: 'OAuth consent flow',
			url: config.manualProof.oauthConsent,
		},
		{
			key: 'oauthRevoke',
			label: 'OAuth revoke flow',
			url: config.manualProof.oauthRevoke,
		},
	];

	return flows.reduce(
		( summary, flow ) => {
			const status = flow.url ? 'manual-proof-recorded' : 'deferred';
			summary.flows[ flow.key ] = {
				status,
				proofUrl: flow.url || null,
			};

			if ( status === 'deferred' ) {
				summary.deferred.push( flow.label );
			}

			return summary;
		},
		{ flows: {}, deferred: [] }
	);
}

function siteUrl( baseUrl, requestPath ) {
	const url = new URL(
		requestPath,
		baseUrl.endsWith( '/' ) ? baseUrl : `${ baseUrl }/`
	);

	return url.toString();
}

function tabUrl( config, tabName ) {
	const url = new URL( siteUrl( config.baseUrl, config.adminPath ) );

	if ( tabName === 'overview' ) {
		url.searchParams.delete( 'tab' );
	} else {
		url.searchParams.set( 'tab', tabName );
	}

	return url.toString();
}

function canonicalJson( value ) {
	if ( Array.isArray( value ) ) {
		return `[${ value.map( canonicalJson ).join( ',' ) }]`;
	}

	if ( value && typeof value === 'object' ) {
		return `{${ Object.keys( value )
			.sort()
			.map(
				( key ) =>
					`${ JSON.stringify( key ) }:${ canonicalJson(
						value[ key ]
					) }`
			)
			.join( ',' ) }}`;
	}

	return JSON.stringify( value );
}

function fingerprint( value ) {
	return createHash( 'sha256' )
		.update( canonicalJson( value ) )
		.digest( 'hex' );
}

async function login( page, config ) {
	await page.goto( siteUrl( config.baseUrl, '/wp-login.php' ), {
		waitUntil: 'domcontentloaded',
	} );
	await page.locator( '#user_login' ).fill( config.username );
	await page.locator( '#user_pass' ).fill( config.password );
	await Promise.all( [
		page.waitForLoadState( 'domcontentloaded' ),
		page.locator( '#wp-submit' ).click(),
	] );

	if (
		await page
			.locator( '#login_error' )
			.isVisible()
			.catch( () => false )
	) {
		throw new Error(
			'WordPress login failed; check the smoke username/password.'
		);
	}

	await page.goto( siteUrl( config.baseUrl, '/wp-admin/' ), {
		waitUntil: 'domcontentloaded',
	} );
	await page.locator( '#wpadminbar, #adminmenuwrap' ).first().waitFor();
}

async function maskSensitiveFields( page ) {
	await page.addStyleTag( {
		content: `
			input[type="password"],
			input[name*="secret" i],
			input[name*="token" i],
			input[name*="key" i],
			textarea[name*="secret" i],
			textarea[name*="token" i],
			textarea[name*="key" i] {
				color: transparent !important;
				text-shadow: 0 0 8px #1d2327 !important;
			}
		`,
	} );
}

async function waitForSettingsApp( page, tab ) {
	const root = page.locator( '#aculect-ai-companion-settings-app-root' );
	await root.waitFor( { state: 'visible' } );
	await page.locator( '.aculect-ai-companion-tabs' ).waitFor( {
		state: 'visible',
	} );
	await page
		.locator( '.aculect-ai-companion-tab.is-active' )
		.filter( { hasText: tab.label } )
		.waitFor( { state: 'visible' } );
}

async function verifyLearningSurfaces( page ) {
	const nav = page.locator( '.aculect-ai-companion-learning-surface-nav' );
	await nav.waitFor( { state: 'visible' } );

	const surfaces = [
		'Learning Suggestions',
		'Memory Records',
		'Incident Reports',
	];

	for ( const label of surfaces ) {
		const button = page.getByRole( 'tab', {
			name: new RegExp( label, 'i' ),
		} );
		await button.waitFor( { state: 'visible' } );
		await button.click();
		await learningSectionHeading( page, label ).waitFor( {
			state: 'visible',
		} );

		const selected = await button.getAttribute( 'aria-selected' );
		if ( selected !== 'true' ) {
			throw new Error(
				`${ label } does not expose a selected Learning surface state.`
			);
		}
	}

	await page.getByRole( 'tab', { name: /Learning Suggestions/i } ).click();
	const navBox = await nav.boundingBox();
	const headingBox = await learningSectionHeading(
		page,
		'Learning Suggestions'
	).boundingBox();

	if ( ! navBox || ! headingBox ) {
		throw new Error(
			'Could not measure the Learning surface navigation and heading.'
		);
	}

	const verticalGap = headingBox.y - ( navBox.y + navBox.height );
	if ( verticalGap > 180 ) {
		throw new Error(
			`Learning surface has a large blank vertical gap (${ Math.round(
				verticalGap
			) }px).`
		);
	}
}

async function captureTab( page, config, runDir, viewport, tab ) {
	await page.setViewportSize( {
		width: viewport.width,
		height: viewport.height,
	} );
	await page.goto( tabUrl( config, tab.name ), {
		waitUntil: 'domcontentloaded',
	} );
	await waitForSettingsApp( page, tab );
	await maskSensitiveFields( page );

	if ( tab.name === 'learning' ) {
		await verifyLearningSurfaces( page );
	}

	const screenshotPath = path.join(
		runDir,
		`${ viewport.name }-${ tab.name }.png`
	);
	await page.screenshot( {
		path: screenshotPath,
		fullPage: true,
	} );

	return path.relative( process.cwd(), screenshotPath );
}

async function mcpRpc( request, config, id, method, params = {} ) {
	const response = await request.post(
		siteUrl( config.baseUrl, config.mcpPath ),
		{
			data: {
				jsonrpc: '2.0',
				id,
				method,
				params,
			},
			headers: {
				accept: 'application/json',
				authorization: `Bearer ${ config.mcpBearerToken }`,
				'content-type': 'application/json',
			},
		}
	);
	const body = await response.json().catch( () => null );

	if ( ! response.ok() ) {
		throw new Error(
			`MCP ${ method } returned HTTP ${ response.status() }.`
		);
	}

	if ( ! body || body.jsonrpc !== '2.0' || body.id !== id ) {
		throw new Error(
			`MCP ${ method } did not return a JSON-RPC response.`
		);
	}

	if ( body.error ) {
		throw new Error(
			`MCP ${ method } returned ${ body.error.code || 'error' }: ${
				body.error.message || 'Unknown error'
			}`
		);
	}

	if ( ! body.result || typeof body.result !== 'object' ) {
		throw new Error( `MCP ${ method } did not return a result object.` );
	}

	return body.result;
}

async function collectMcpTools( request, config ) {
	const tools = [];
	const cursors = [];
	let cursor = '';
	let pages = 0;

	do {
		const params = cursor ? { cursor } : {};
		const result = await mcpRpc(
			request,
			config,
			`tools-list-${ pages + 1 }`,
			'tools/list',
			params
		);

		if ( ! Array.isArray( result.tools ) ) {
			throw new Error( 'MCP tools/list result did not include tools.' );
		}

		tools.push( ...result.tools );
		cursor = typeof result.nextCursor === 'string' ? result.nextCursor : '';
		if ( cursor ) {
			cursors.push( cursor );
		}

		pages += 1;
		if ( pages > 20 ) {
			throw new Error( 'MCP tools/list pagination exceeded 20 pages.' );
		}
	} while ( cursor );

	return {
		tools,
		pages,
		cursors,
	};
}

async function verifyMcpToolsList( request, config ) {
	if ( ! config.mcpBearerToken ) {
		return {
			status: 'skipped',
			reason: 'ACULECT_SMOKE_MCP_BEARER_TOKEN was not provided.',
		};
	}

	await mcpRpc( request, config, 'initialize-1', 'initialize', {
		protocolVersion: '2025-03-26',
		capabilities: {},
		clientInfo: {
			name: 'aculect-release-ui-smoke',
			version: '0.1.0',
		},
	} );

	const first = await collectMcpTools( request, config );
	const second = await collectMcpTools( request, config );
	const firstNames = first.tools.map( ( tool ) => String( tool.name || '' ) );
	const secondNames = second.tools.map( ( tool ) =>
		String( tool.name || '' )
	);
	const invalidToolNames = firstNames.filter(
		( name ) => ! /^[a-zA-Z0-9_-]{1,64}$/.test( name )
	);
	const duplicateToolNames = firstNames.filter(
		( name, index ) => firstNames.indexOf( name ) !== index
	);
	const firstFingerprint = fingerprint( first.tools );
	const secondFingerprint = fingerprint( second.tools );

	if ( first.tools.length === 0 ) {
		throw new Error( 'MCP tools/list returned no tools.' );
	}

	if ( firstFingerprint !== secondFingerprint ) {
		throw new Error( 'MCP tools/list returned non-deterministic results.' );
	}

	if ( firstNames.join( '\n' ) !== secondNames.join( '\n' ) ) {
		throw new Error(
			'MCP tools/list returned tools in a different order.'
		);
	}

	if ( invalidToolNames.length > 0 ) {
		throw new Error(
			`MCP tools/list returned invalid tool names: ${ invalidToolNames.join(
				', '
			) }`
		);
	}

	if ( duplicateToolNames.length > 0 ) {
		throw new Error(
			`MCP tools/list returned duplicate tool names: ${ duplicateToolNames.join(
				', '
			) }`
		);
	}

	return {
		status: 'passed',
		path: config.mcpPath,
		toolCount: first.tools.length,
		pages: first.pages,
		paginated: first.cursors.length > 0,
		fingerprint: `sha256:${ firstFingerprint }`,
		invalidToolNames: invalidToolNames.length,
		duplicateToolNames: duplicateToolNames.length,
	};
}

async function main() {
	if ( shouldShowHelp() ) {
		console.log( HELP.trim() );
		return;
	}

	const config = requiredConfig();
	const { chromium } = await import( '@playwright/test' );
	const runDir = path.resolve( process.cwd(), config.artifactDir, 'latest' );
	await rm( runDir, { force: true, recursive: true } );
	await mkdir( runDir, { recursive: true } );

	const browser = await chromium.launch( { headless: config.headless } );
	const context = await browser.newContext( {
		viewport: {
			width: VIEWPORTS[ 0 ].width,
			height: VIEWPORTS[ 0 ].height,
		},
		ignoreHTTPSErrors: true,
	} );
	context.setDefaultTimeout( config.timeoutMs );
	const page = await context.newPage();
	const screenshots = [];

	try {
		await login( page, config );

		for ( const viewport of VIEWPORTS ) {
			for ( const tab of TABS ) {
				screenshots.push(
					await captureTab( page, config, runDir, viewport, tab )
				);
			}
		}

		const mcpToolsList = await verifyMcpToolsList(
			context.request,
			config
		);
		const manualProof = manualProofSummary( config );
		const deferred = [ ...manualProof.deferred ];
		if ( mcpToolsList.status !== 'passed' ) {
			deferred.push( 'Authenticated MCP tools/list discovery' );
		}

		const summary = {
			status: 'passed',
			baseUrl: config.baseUrl,
			adminPath: config.adminPath,
			viewports: VIEWPORTS,
			tabs: TABS.map( ( tab ) => tab.name ),
			screenshots,
			manualProof: manualProof.flows,
			mcpToolsList,
			deferred,
		};
		await writeFile(
			path.join( runDir, 'summary.json' ),
			`${ JSON.stringify( summary, null, 2 ) }\n`
		);
		console.log(
			`Release UI smoke passed. Artifacts: ${ path.relative(
				process.cwd(),
				runDir
			) }`
		);
	} finally {
		await context.close();
		await browser.close();
	}
}

main().catch( ( error ) => {
	console.error( error.message );
	process.exitCode = 1;
} );

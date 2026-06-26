#!/usr/bin/env node
/* eslint-disable no-console -- Smoke harness output is its CLI contract. */

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

		const summary = {
			status: 'passed',
			baseUrl: config.baseUrl,
			adminPath: config.adminPath,
			viewports: VIEWPORTS,
			tabs: TABS.map( ( tab ) => tab.name ),
			screenshots,
			deferred: [
				'Connect approval flow',
				'OAuth consent and revoke flow',
				'Authenticated MCP tools/list discovery',
			],
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

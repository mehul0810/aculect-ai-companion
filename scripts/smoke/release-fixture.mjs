#!/usr/bin/env node
/* eslint-disable no-console -- Smoke harness output is its CLI contract. */

import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const HELP = `
Aculect AI Companion no-secret release fixture smoke

Usage:
  npm run smoke:release-fixture
  npm run smoke:release-fixture -- --scenario invalid-tool-name
  npm run smoke:release-fixture -- --scenario duplicate-tool-name

Optional environment:
  ACULECT_FIXTURE_SMOKE_ARTIFACT_DIR  Defaults to artifacts/smoke/release-fixture
  ACULECT_FIXTURE_SMOKE_SCENARIO      Defaults to valid.

Artifacts:
  A public-safe summary JSON is written to ACULECT_FIXTURE_SMOKE_ARTIFACT_DIR/latest.
  This fixture smoke does not use ACULECT_SMOKE_* or ACULECT_MCP_SMOKE_* secrets.
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

const LEARNING_SURFACES = [
	'Learning Suggestions',
	'Memory Records',
	'Incident Reports',
];

const PACKAGE_VERSION = JSON.parse(
	readFileSync( new URL( '../../package.json', import.meta.url ), 'utf8' )
).version;

const BASE_TOOLS = [
	{
		name: 'aculect_get_activity',
		description: 'Read recent Aculect activity.',
		inputSchema: { type: 'object', properties: {} },
	},
	{
		name: 'aculect_list_abilities',
		description: 'List available Aculect MCP abilities.',
		inputSchema: { type: 'object', properties: {} },
	},
	{
		name: 'aculect_read_learning',
		description: 'Read Aculect learning review metadata.',
		inputSchema: { type: 'object', properties: {} },
	},
];

function envValue( name, fallback = '' ) {
	return String( process.env[ name ] || fallback ).trim();
}

function shouldShowHelp() {
	return process.argv.includes( '--help' ) || process.argv.includes( '-h' );
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

function argValue( name ) {
	const equalsPrefix = `--${ name }=`;
	const equalsValue = process.argv.find( ( arg ) =>
		arg.startsWith( equalsPrefix )
	);

	if ( equalsValue ) {
		return equalsValue.slice( equalsPrefix.length );
	}

	const index = process.argv.indexOf( `--${ name }` );
	if ( index !== -1 ) {
		return process.argv[ index + 1 ] || '';
	}

	return '';
}

function configFromArgs() {
	return {
		artifactDir:
			argValue( 'artifact-dir' ) ||
			envValue(
				'ACULECT_FIXTURE_SMOKE_ARTIFACT_DIR',
				'artifacts/smoke/release-fixture'
			),
		scenario:
			argValue( 'scenario' ) ||
			envValue( 'ACULECT_FIXTURE_SMOKE_SCENARIO', 'valid' ),
	};
}

function fixtureForScenario( scenario ) {
	const tools =
		scenario === 'duplicate-tool-name'
			? [ BASE_TOOLS[ 0 ], BASE_TOOLS[ 1 ], BASE_TOOLS[ 1 ] ]
			: [ ...BASE_TOOLS ];

	if ( scenario === 'invalid-tool-name' ) {
		tools[ 1 ] = {
			...tools[ 1 ],
			name: 'aculect invalid tool',
		};
	}

	if (
		! [ 'valid', 'duplicate-tool-name', 'invalid-tool-name' ].includes(
			scenario
		)
	) {
		throw new Error(
			`Unknown fixture scenario "${ scenario }". Expected valid, duplicate-tool-name, or invalid-tool-name.`
		);
	}

	return {
		initialize: {
			protocolVersion: '2025-03-26',
			serverInfo: {
				name: 'aculect-ai-companion-fixture',
				version: PACKAGE_VERSION,
			},
			capabilities: {
				tools: {
					listChanged: false,
				},
			},
		},
		pages: [
			{
				tools: tools.slice( 0, 2 ),
				nextCursor: 'fixture-page-2',
			},
			{
				cursor: 'fixture-page-2',
				tools: tools.slice( 2 ),
			},
		],
	};
}

async function fixtureRpc( fixture, method, params = {} ) {
	if ( method === 'initialize' ) {
		return fixture.initialize;
	}

	if ( method !== 'tools/list' ) {
		throw new Error( `Fixture MCP method ${ method } is not supported.` );
	}

	const cursor = typeof params.cursor === 'string' ? params.cursor : '';
	const page = fixture.pages.find(
		( candidate ) => ( candidate.cursor || '' ) === cursor
	);

	if ( ! page ) {
		throw new Error(
			`Fixture MCP tools/list cursor ${ cursor } was not found.`
		);
	}

	return {
		tools: page.tools,
		nextCursor: page.nextCursor || '',
	};
}

async function collectTools( fixture ) {
	const tools = [];
	const cursors = [];
	let cursor = '';
	let pages = 0;

	do {
		const result = await fixtureRpc(
			fixture,
			'tools/list',
			cursor ? { cursor } : {}
		);

		if ( ! Array.isArray( result.tools ) ) {
			throw new Error(
				'Fixture MCP tools/list result did not include tools.'
			);
		}

		tools.push( ...result.tools );
		cursor = typeof result.nextCursor === 'string' ? result.nextCursor : '';
		if ( cursor ) {
			cursors.push( cursor );
		}

		pages += 1;
		if ( pages > 20 ) {
			throw new Error(
				'Fixture MCP tools/list pagination exceeded 20 pages.'
			);
		}
	} while ( cursor );

	return {
		tools,
		pages,
		cursors,
	};
}

function discoverySummary( discovery ) {
	const names = discovery.tools.map( ( tool ) => String( tool.name || '' ) );
	const invalidToolNames = names.filter(
		( name ) => ! /^[a-zA-Z0-9_-]{1,64}$/.test( name )
	);
	const duplicateToolNames = names.filter(
		( name, index ) => names.indexOf( name ) !== index
	);

	return {
		toolCount: discovery.tools.length,
		pages: discovery.pages,
		paginated: discovery.cursors.length > 0,
		invalidToolNames,
		duplicateToolNames,
		fingerprint: fingerprint( discovery.tools ),
	};
}

function assertDiscoveryStable( expected, actual ) {
	if ( actual.toolCount === 0 ) {
		throw new Error( 'Fixture MCP tools/list returned no tools.' );
	}

	if ( actual.fingerprint !== expected.fingerprint ) {
		throw new Error(
			'Fixture MCP tools/list returned non-deterministic results.'
		);
	}
}

function assertToolNamesValid( summary ) {
	if ( summary.invalidToolNames.length > 0 ) {
		throw new Error(
			`Fixture MCP tools/list returned invalid tool names: ${ summary.invalidToolNames.join(
				', '
			) }`
		);
	}

	if ( summary.duplicateToolNames.length > 0 ) {
		throw new Error(
			`Fixture MCP tools/list returned duplicate tool names: ${ summary.duplicateToolNames.join(
				', '
			) }`
		);
	}
}

function manualProofSummary() {
	const flows = {
		connectApproval: {
			status: 'deferred',
			proofUrl: null,
		},
		oauthConsent: {
			status: 'deferred',
			proofUrl: null,
		},
		oauthRevoke: {
			status: 'deferred',
			proofUrl: null,
		},
	};

	return {
		flows,
		deferred: [
			'Connect approval flow',
			'OAuth consent flow',
			'OAuth revoke flow',
		],
	};
}

async function fixtureMcpSummary( fixture ) {
	const firstInitialize = await fixtureRpc( fixture, 'initialize' );
	const secondInitialize = await fixtureRpc( fixture, 'initialize' );
	const firstInitializeFingerprint = fingerprint( firstInitialize );
	const secondInitializeFingerprint = fingerprint( secondInitialize );

	if ( firstInitializeFingerprint !== secondInitializeFingerprint ) {
		throw new Error(
			'Fixture MCP initialize returned non-deterministic metadata.'
		);
	}

	const first = discoverySummary( await collectTools( fixture ) );
	const second = discoverySummary( await collectTools( fixture ) );

	assertDiscoveryStable( first, second );
	assertToolNamesValid( first );

	return {
		initialize: {
			firstFingerprint: `sha256:${ firstInitializeFingerprint }`,
			secondFingerprint: `sha256:${ secondInitializeFingerprint }`,
			stable: true,
		},
		toolsList: {
			toolCount: first.toolCount,
			pages: first.pages,
			paginated: first.paginated,
			fingerprint: `sha256:${ first.fingerprint }`,
			invalidToolNames: first.invalidToolNames.length,
			duplicateToolNames: first.duplicateToolNames.length,
			repeatedDiscoveryStable: true,
		},
	};
}

function releaseUiContractSummary() {
	const manualProof = manualProofSummary();

	return {
		requiredEnvironment: [
			'ACULECT_SMOKE_BASE_URL',
			'ACULECT_SMOKE_USERNAME',
			'ACULECT_SMOKE_PASSWORD',
		],
		adminPathDefault:
			'/wp-admin/options-general.php?page=aculect-ai-companion',
		viewports: VIEWPORTS,
		tabs: TABS,
		learningSurfaces: {
			required: LEARNING_SURFACES,
			activeState: 'Each surface must expose an aria-selected tab state.',
			maxBlankVerticalGapPx: 180,
		},
		manualProof: manualProof.flows,
		screenshots: {
			status: 'not-run',
			reason: 'Fixture mode validates the release-gate contract only; live browser screenshots still require ACULECT_SMOKE_BASE_URL, ACULECT_SMOKE_USERNAME, and ACULECT_SMOKE_PASSWORD.',
		},
		deferred: [
			...manualProof.deferred,
			'Desktop and constrained-width browser screenshots',
			'Authenticated WordPress admin tab navigation',
		],
	};
}

async function main() {
	if ( shouldShowHelp() ) {
		console.log( HELP.trim() );
		return;
	}

	const config = configFromArgs();
	const fixture = fixtureForScenario( config.scenario );
	const runDir = path.resolve( process.cwd(), config.artifactDir, 'latest' );
	await rm( runDir, { force: true, recursive: true } );
	await mkdir( runDir, { recursive: true } );

	const summary = {
		status: 'passed',
		mode: 'fixture-no-secret',
		scenario: config.scenario,
		releaseUiContract: releaseUiContractSummary(),
		mcpFixture: await fixtureMcpSummary( fixture ),
		liveProofStillRequired: [
			'ACULECT_SMOKE_BASE_URL',
			'ACULECT_SMOKE_USERNAME',
			'ACULECT_SMOKE_PASSWORD',
			'ACULECT_SMOKE_MCP_BEARER_TOKEN',
			'ACULECT_MCP_SMOKE_BASE_URL',
			'ACULECT_MCP_SMOKE_BEARER_TOKEN',
		],
	};

	await writeFile(
		path.join( runDir, 'summary.json' ),
		`${ JSON.stringify( summary, null, 2 ) }\n`
	);
	console.log(
		`Release fixture smoke passed. Artifacts: ${ path.relative(
			process.cwd(),
			runDir
		) }`
	);
}

main().catch( ( error ) => {
	console.error( error.message );
	process.exitCode = 1;
} );

#!/usr/bin/env node
/* eslint-disable no-console -- Smoke harness output is its CLI contract. */

import { createHash } from 'node:crypto';
import { mkdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const HELP = `
Aculect AI Companion MCP live-client discovery smoke

Required environment:
  ACULECT_MCP_SMOKE_BASE_URL       WordPress site URL.
  ACULECT_MCP_SMOKE_BEARER_TOKEN   OAuth access token for the MCP endpoint.

Optional environment:
  ACULECT_MCP_SMOKE_ARTIFACT_DIR   Defaults to artifacts/smoke/mcp-live-client
  ACULECT_MCP_SMOKE_PATH           Defaults to /wp-json/aculect-ai-companion/v1/mcp
  ACULECT_MCP_SMOKE_RECONNECT_PROOF_URL
                                   Public-safe proof URL for an external ChatGPT/Codex/Claude reconnect or refresh.
  ACULECT_MCP_SMOKE_RECONNECT_WAIT_MS
                                   Wait before post-reconnect discovery. Defaults to 0.

Artifacts:
  A public-safe summary JSON is written to ACULECT_MCP_SMOKE_ARTIFACT_DIR/latest.
  The bearer token and raw tool payloads are never written.
`;

function shouldShowHelp() {
	return process.argv.includes( '--help' ) || process.argv.includes( '-h' );
}

function envValue( name, fallback = '' ) {
	return String( process.env[ name ] || fallback ).trim();
}

function intEnv( name, fallback ) {
	const value = Number.parseInt( envValue( name ), 10 );

	return Number.isFinite( value ) && value > 0 ? value : fallback;
}

function requiredConfig() {
	const config = {
		baseUrl: envValue( 'ACULECT_MCP_SMOKE_BASE_URL' ),
		bearerToken: envValue( 'ACULECT_MCP_SMOKE_BEARER_TOKEN' ),
		artifactDir: envValue(
			'ACULECT_MCP_SMOKE_ARTIFACT_DIR',
			'artifacts/smoke/mcp-live-client'
		),
		mcpPath: envValue(
			'ACULECT_MCP_SMOKE_PATH',
			'/wp-json/aculect-ai-companion/v1/mcp'
		),
		reconnectProofUrl: envValue( 'ACULECT_MCP_SMOKE_RECONNECT_PROOF_URL' ),
		reconnectWaitMs: intEnv( 'ACULECT_MCP_SMOKE_RECONNECT_WAIT_MS', 0 ),
	};
	const missing = [];

	if ( ! config.baseUrl ) {
		missing.push( 'ACULECT_MCP_SMOKE_BASE_URL' );
	}
	if ( ! config.bearerToken ) {
		missing.push( 'ACULECT_MCP_SMOKE_BEARER_TOKEN' );
	}

	if ( missing.length > 0 ) {
		throw new Error(
			`Missing required environment: ${ missing.join( ', ' ) }\n${ HELP }`
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

function wait( ms ) {
	return new Promise( ( resolve ) => {
		setTimeout( resolve, ms );
	} );
}

async function mcpRpc( config, id, method, params = {} ) {
	const response = await fetch( siteUrl( config.baseUrl, config.mcpPath ), {
		method: 'POST',
		headers: {
			accept: 'application/json',
			authorization: `Bearer ${ config.bearerToken }`,
			'content-type': 'application/json',
		},
		body: JSON.stringify( {
			jsonrpc: '2.0',
			id,
			method,
			params,
		} ),
	} );
	const body = await response.json().catch( () => null );

	if ( ! response.ok ) {
		throw new Error(
			`MCP ${ method } returned HTTP ${ response.status }.`
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

async function initialize( config, id, clientName ) {
	return mcpRpc( config, id, 'initialize', {
		protocolVersion: '2025-03-26',
		capabilities: {},
		clientInfo: {
			name: clientName,
			version: '0.1.0',
		},
	} );
}

async function collectTools( config, idPrefix ) {
	const tools = [];
	const cursors = [];
	let cursor = '';
	let pages = 0;

	do {
		const params = cursor ? { cursor } : {};
		const result = await mcpRpc(
			config,
			`${ idPrefix }-${ pages + 1 }`,
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
		names,
		invalidToolNames,
		duplicateToolNames,
		fingerprint: fingerprint( discovery.tools ),
	};
}

function assertDiscoveryStable( label, expected, actual ) {
	if ( actual.toolCount === 0 ) {
		throw new Error( `MCP ${ label } discovery returned no tools.` );
	}

	if ( actual.fingerprint !== expected.fingerprint ) {
		throw new Error(
			`MCP ${ label } discovery returned a different tools/list fingerprint.`
		);
	}

	if ( actual.names.join( '\n' ) !== expected.names.join( '\n' ) ) {
		throw new Error(
			`MCP ${ label } discovery returned tools in a different order.`
		);
	}
}

function assertToolNamesValid( summary ) {
	if ( summary.invalidToolNames.length > 0 ) {
		throw new Error(
			`MCP tools/list returned invalid tool names: ${ summary.invalidToolNames.join(
				', '
			) }`
		);
	}

	if ( summary.duplicateToolNames.length > 0 ) {
		throw new Error(
			`MCP tools/list returned duplicate tool names: ${ summary.duplicateToolNames.join(
				', '
			) }`
		);
	}
}

async function main() {
	if ( shouldShowHelp() ) {
		console.log( HELP.trim() );
		return;
	}

	const config = requiredConfig();
	const runDir = path.resolve( process.cwd(), config.artifactDir, 'latest' );
	await rm( runDir, { force: true, recursive: true } );
	await mkdir( runDir, { recursive: true } );

	const firstInitialize = await initialize(
		config,
		'initialize-1',
		'aculect-mcp-live-client-smoke'
	);
	const secondInitialize = await initialize(
		config,
		'initialize-2',
		'aculect-mcp-live-client-smoke'
	);
	const first = discoverySummary(
		await collectTools( config, 'tools-list-first' )
	);
	const second = discoverySummary(
		await collectTools( config, 'tools-list-second' )
	);
	const firstInitializeFingerprint = fingerprint( firstInitialize );
	const secondInitializeFingerprint = fingerprint( secondInitialize );

	if ( firstInitializeFingerprint !== secondInitializeFingerprint ) {
		throw new Error(
			'MCP initialize returned non-deterministic metadata.'
		);
	}

	assertDiscoveryStable( 'repeated', first, second );
	assertToolNamesValid( first );

	let reconnectRefresh = {
		status: 'deferred',
		reason: 'ACULECT_MCP_SMOKE_RECONNECT_PROOF_URL was not provided.',
	};

	if ( config.reconnectProofUrl ) {
		if ( config.reconnectWaitMs > 0 ) {
			await wait( config.reconnectWaitMs );
		}

		await initialize(
			config,
			'initialize-after-reconnect',
			'aculect-mcp-live-client-reconnect-proof'
		);
		const afterReconnect = discoverySummary(
			await collectTools( config, 'tools-list-after-reconnect' )
		);

		assertDiscoveryStable( 'post-reconnect', first, afterReconnect );

		reconnectRefresh = {
			status: 'passed',
			proofUrl: config.reconnectProofUrl,
			waitedMs: config.reconnectWaitMs,
			toolCount: afterReconnect.toolCount,
			pages: afterReconnect.pages,
			paginated: afterReconnect.paginated,
			fingerprint: `sha256:${ afterReconnect.fingerprint }`,
		};
	}

	const summary = {
		status: 'passed',
		baseUrl: config.baseUrl,
		path: config.mcpPath,
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
		reconnectRefresh,
		deferred:
			reconnectRefresh.status === 'passed'
				? []
				: [ 'External client reconnect/cache refresh proof' ],
	};

	await writeFile(
		path.join( runDir, 'summary.json' ),
		`${ JSON.stringify( summary, null, 2 ) }\n`
	);
	console.log(
		`MCP live-client smoke passed. Artifacts: ${ path.relative(
			process.cwd(),
			runDir
		) }`
	);
}

main().catch( ( error ) => {
	console.error( error.message );
	process.exitCode = 1;
} );

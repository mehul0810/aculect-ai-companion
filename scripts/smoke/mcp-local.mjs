#!/usr/bin/env node
/* eslint-disable no-console -- Local smoke output is the diagnostic CLI contract. */

import { mkdir, rm, writeFile } from 'node:fs/promises';
import net from 'node:net';
import path from 'node:path';
import process from 'node:process';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { probe } from './mcp-sdk-client.mjs';

const REPO_ROOT = path.resolve(
	fileURLToPath( new URL( '../..', import.meta.url ) )
);
const ROUTER = path.join( REPO_ROOT, 'scripts/smoke/mcp-local-router.php' );
const ENDPOINT_PATH = '/wp-json/aculect-ai-companion/v1/mcp';
const FIXTURE_TOKEN = 'aculect-local-fixture-token';
const HELP = `
Aculect AI Companion local MCP wire smoke

Usage:
  npm run smoke:mcp-local

What it proves:
  - A real PHP process loads the repository test runtime and MCP controller.
  - The pinned MCP SDK completes Streamable HTTP initialization.
  - The SDK discovers the real PHP tool manifest and calls site_get_info.
  - A wrong bearer is rejected before any tool execution.

This is a deterministic no-secret fixture. It does not contact WordPress,
OAuth, Cloudflare, or an external MCP client.

Artifacts:
  ACULECT_MCP_LOCAL_ARTIFACT_DIR defaults to artifacts/smoke/mcp-local/latest.
`;

function envValue( name, fallback = '' ) {
	return String( process.env[ name ] || fallback ).trim();
}

function showHelp() {
	return process.argv.includes( '--help' ) || process.argv.includes( '-h' );
}

function artifactDir() {
	return path.resolve(
		REPO_ROOT,
		envValue(
			'ACULECT_MCP_LOCAL_ARTIFACT_DIR',
			'artifacts/smoke/mcp-local/latest'
		)
	);
}

function freePort() {
	return new Promise( ( resolve, reject ) => {
		const server = net.createServer();
		server.once( 'error', reject );
		server.listen( 0, '127.0.0.1', () => {
			const address = server.address();
			const port =
				typeof address === 'object' && address ? address.port : 0;
			server.close( () => resolve( port ) );
		} );
	} );
}

async function waitForServer( url, child ) {
	for ( let attempt = 0; attempt < 50; attempt++ ) {
		if ( child.exitCode !== null ) {
			throw new Error(
				'Local PHP fixture server exited before becoming ready.'
			);
		}
		try {
			await fetch( url, {
				headers: { authorization: `Bearer ${ FIXTURE_TOKEN }` },
			} );
			return;
		} catch ( error ) {
			if ( attempt === 49 ) {
				throw error;
			}
			await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
		}
	}
}

async function authorizationProbe( url ) {
	const response = await fetch( url, {
		method: 'POST',
		headers: {
			accept: 'application/json',
			authorization: 'Bearer intentionally-invalid-local-token',
			'content-type': 'application/json',
		},
		body: JSON.stringify( {
			jsonrpc: '2.0',
			id: 'auth-check',
			method: 'initialize',
			params: {
				protocolVersion: '2025-11-25',
				capabilities: {},
				clientInfo: { name: 'aculect-local-auth-check', version: '1' },
			},
		} ),
	} );
	const body = await response.json().catch( () => null );
	return {
		status: response.status,
		challenge: response.headers.has( 'www-authenticate' ),
		rpcErrorCode:
			typeof body?.error?.code === 'number' ? body.error.code : null,
	};
}

function parseServerEvents( output ) {
	return output
		.split( '\n' )
		.filter( ( line ) => line.startsWith( 'ACULECT_LOCAL_MCP ' ) )
		.map( ( line ) => {
			try {
				return JSON.parse( line.slice( 'ACULECT_LOCAL_MCP '.length ) );
			} catch {
				return { parseError: true };
			}
		} );
}

async function run() {
	if ( showHelp() ) {
		console.log( HELP.trim() );
		return { status: 'passed', help: true };
	}

	const port = await freePort();
	const endpoint = `http://127.0.0.1:${ port }${ ENDPOINT_PATH }`;
	const php = envValue( 'ACULECT_MCP_LOCAL_PHP', 'php' );
	const child = spawn( php, [ '-S', `127.0.0.1:${ port }`, ROUTER ], {
		cwd: REPO_ROOT,
		stdio: [ 'ignore', 'pipe', 'pipe' ],
		env: { ...process.env },
	} );
	let serverOutput = '';
	child.stdout.on( 'data', ( chunk ) => {
		serverOutput += chunk.toString();
	} );
	child.stderr.on( 'data', ( chunk ) => {
		serverOutput += chunk.toString();
	} );

	try {
		await waitForServer( endpoint, child );
		const auth = await authorizationProbe( endpoint );
		const sdk = await probe( endpoint, FIXTURE_TOKEN );
		const summary = {
			mode: 'fixture-local-http',
			status:
				sdk.status === 'passed' && auth.status === 401
					? 'passed'
					: 'failed',
			endpoint: ENDPOINT_PATH,
			transport: 'streamable-http',
			server: 'php-test-bootstrap',
			auth_boundary: auth,
			sdk,
			server_events: parseServerEvents( serverOutput ),
		};
		const dir = artifactDir();
		await rm( dir, { recursive: true, force: true } );
		await mkdir( dir, { recursive: true } );
		await writeFile(
			path.join( dir, 'summary.json' ),
			`${ JSON.stringify( summary, null, 2 ) }\n`
		);
		return summary;
	} finally {
		child.kill( 'SIGTERM' );
		await new Promise( ( resolve ) => {
			child.once( 'exit', resolve );
			setTimeout( resolve, 1000 );
		} );
	}
}

if ( import.meta.url === new URL( process.argv[ 1 ], 'file:' ).href ) {
	try {
		const result = await run();
		console.log( JSON.stringify( result, null, 2 ) );
		process.exitCode = result.status === 'passed' ? 0 : 1;
	} catch ( error ) {
		console.error(
			error instanceof Error ? error.message : String( error )
		);
		process.exitCode = 1;
	}
}

export { run };

#!/usr/bin/env node
/* eslint-disable no-console -- Redacted diagnostics are the CLI contract. */
// eslint-disable-next-line import/no-unresolved -- Node resolves SDK conditional exports; exercised by the SDK tests.
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
// eslint-disable-next-line import/no-unresolved -- Node resolves SDK conditional exports; exercised by the SDK tests.
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';
import { pathToFileURL } from 'node:url';
import process from 'node:process';

/**
 * Exercise the supported transport with an actual SDK, without logging payloads.
 *
 * @param {string|URL} url     MCP endpoint.
 * @param {string}     token   OAuth bearer, used only in the request header.
 * @param {number}     timeout Maximum connection duration in milliseconds.
 */
export async function probe( url, token, timeout = 15000 ) {
	const events = [];
	const errors = [];
	const deadline = AbortSignal.timeout( timeout );
	const client = new Client( {
		name: 'aculect-sdk-proof',
		version: '1.0.0',
	} );
	let stage = 'initialize';
	const transport = new StreamableHTTPClientTransport( new URL( url ), {
		requestInit: { headers: { Authorization: `Bearer ${ token }` } },
		reconnectionOptions: {
			maxRetries: 1,
			initialReconnectionDelay: 1000,
			maxReconnectionDelay: 1000,
			reconnectionDelayGrowFactor: 1,
		},
		fetch: async ( input, init = {} ) => {
			// No redirects: never forward a diagnostic bearer to a different endpoint.
			const response = await fetch( input, {
				...init,
				redirect: 'error',
				signal: AbortSignal.any( [
					deadline,
					...( init.signal ? [ init.signal ] : [] ),
				] ),
			} );
			const headers = new Headers( init.headers );
			const method = init.body ? JSON.parse( init.body ).method : null;
			const event = {
				method: init.method || 'GET',
				rpc: method,
				protocol: headers.get( 'mcp-protocol-version' ),
				status: response.status,
				contentType: response.headers.get( 'content-type' ),
			};
			if ( event.contentType?.includes( 'application/json' ) ) {
				const payload = await response
					.clone()
					.json()
					.catch( () => null );
				const rpcError = payload?.error;
				const rpcData = rpcError?.data;
				const result = payload?.result;
				if ( rpcError && typeof rpcError === 'object' ) {
					event.rpcErrorCode =
						typeof rpcError.code === 'number'
							? rpcError.code
							: null;
					event.rpcErrorDataCode =
						typeof rpcData?.code === 'string' ? rpcData.code : null;
				}
				if ( result?.isError === true ) {
					event.resultIsError = true;
				}
			}
			events.push( event );
			return response;
		},
	} );
	// SDK error messages may embed complete server responses. Log types/codes only.
	client.onerror = ( error ) =>
		errors.push( {
			stage,
			type: error.name,
			code: typeof error.code === 'number' ? error.code : null,
		} );
	try {
		await client.connect( transport, { timeout } );
		stage = 'tools/list';
		let cursor;
		const names = new Set();
		const cursors = new Set();
		for ( let page = 0; ; page++ ) {
			if ( page >= 20 ) {
				throw new Error( 'PaginationLimit' );
			}
			const result = await client.listTools( cursor ? { cursor } : {}, {
				timeout,
			} );
			for ( const tool of result.tools ) {
				if ( names.has( tool.name ) ) {
					throw new Error( 'DuplicateTool' );
				}
				names.add( tool.name );
			}
			cursor = result.nextCursor;
			if ( ! cursor ) {
				break;
			}
			if ( cursors.has( cursor ) ) {
				throw new Error( 'RepeatedCursor' );
			}
			cursors.add( cursor );
		}
		stage = 'tools/call';
		if ( ! names.has( 'site_get_info' ) ) {
			throw new Error( 'ReadToolUnavailable' );
		}
		const result = await client.callTool(
			{ name: 'site_get_info', arguments: {} },
			undefined,
			{ timeout }
		);
		if ( result.isError ) {
			throw new Error( 'ToolReportedError' );
		}
		return {
			status: errors.length ? 'failed' : 'passed',
			stage,
			toolCount: names.size,
			events,
			errors,
		};
	} catch ( error ) {
		return {
			status: 'failed',
			stage,
			events,
			errors,
			failure: {
				type: error.name,
				code: typeof error.code === 'number' ? error.code : null,
			},
		};
	} finally {
		await client.close();
	}
}

if (
	process.argv[ 1 ] &&
	import.meta.url === pathToFileURL( process.argv[ 1 ] ).href
) {
	const url = process.env.ACULECT_MCP_SMOKE_BASE_URL;
	const token = process.env.ACULECT_MCP_SMOKE_BEARER_TOKEN;
	if ( ! url || ! token ) {
		console.error(
			'Configure ACULECT_MCP_SMOKE_BASE_URL and ACULECT_MCP_SMOKE_BEARER_TOKEN locally; never paste credentials into chat.'
		);
		process.exitCode = 1;
	} else {
		const endpoint = new URL(
			process.env.ACULECT_MCP_SMOKE_PATH ||
				'/wp-json/aculect-ai-companion/v1/mcp',
			url
		);
		const result = await probe( endpoint, token );
		console.log( JSON.stringify( result, null, 2 ) );
		process.exitCode = result.status === 'passed' ? 0 : 1;
	}
}

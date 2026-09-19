import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import test from 'node:test';
import { probe } from '../../scripts/smoke/mcp-sdk-client.mjs';

async function fixture( t, failure = '', sse = false ) {
	const messages = [];
	const server = createServer( async ( req, res ) => {
		if ( req.method === 'GET' ) {
			if ( sse ) {
				res.writeHead( 200, {
					'content-type': 'text/event-stream',
				} ).end( 'id: fixture-event\nretry: 1000\ndata:\n\n' );
			} else {
				res.writeHead( 405 ).end();
			}
			return;
		}
		let body = '';
		for await ( const chunk of req ) {
			body += chunk;
		}
		const rpc = JSON.parse( body );
		messages.push( rpc.method );
		if ( failure === rpc.method ) {
			res.writeHead( 400, { 'content-type': 'application/json' } ).end(
				'PRIVATE_RESPONSE_SENTINEL'
			);
			return;
		}
		if ( rpc.method === 'notifications/initialized' ) {
			res.writeHead( 202 ).end();
			return;
		}
		let result;
		if ( rpc.method === 'initialize' ) {
			result = {
				protocolVersion: '2025-11-25',
				serverInfo: { name: 'fixture', version: '1' },
				capabilities: { tools: {} },
			};
		} else if ( rpc.method === 'tools/list' ) {
			assert.equal( req.headers[ 'mcp-protocol-version' ], '2025-11-25' );
			result = {
				tools: [
					{
						name: 'site_get_info',
						inputSchema: { type: 'object', properties: {} },
					},
				],
			};
		} else {
			result = {
				content: [ { type: 'text', text: 'PRIVATE_SITE_SENTINEL' } ],
			};
		}
		res.writeHead( 200, { 'content-type': 'application/json' } ).end(
			JSON.stringify( { jsonrpc: '2.0', id: rpc.id, result } )
		);
	} );
	server.listen( 0, '127.0.0.1' );
	await once( server, 'listening' );
	t.after( () => {
		server.closeAllConnections();
		server.close();
	} );
	return { url: `http://127.0.0.1:${ server.address().port }/mcp`, messages };
}

test( 'SDK completes initialization, discovery and call when optional GET returns 405', async ( t ) => {
	const { url, messages } = await fixture( t );
	const result = await probe( url, 'PRIVATE_TOKEN_SENTINEL' );
	assert.equal( result.status, 'passed' );
	assert.deepEqual( messages, [
		'initialize',
		'notifications/initialized',
		'tools/list',
		'tools/call',
	] );
	assert.equal( result.warnings.length, 0 );
	assert.equal(
		result.events.at( -1 ).responseHeaders.wwwAuthenticate,
		false
	);
	assert.doesNotMatch( JSON.stringify( result ), /PRIVATE_/ );
} );

for ( const stage of [ 'initialize', 'tools/list', 'tools/call' ] ) {
	test( `SDK identifies ${ stage } failure without disclosing response or bearer`, async ( t ) => {
		const { url } = await fixture( t, stage );
		const result = await probe( url, 'PRIVATE_TOKEN_SENTINEL' );
		assert.equal( result.status, 'failed' );
		assert.equal( result.stage, stage );
		assert.doesNotMatch( JSON.stringify( result ), /PRIVATE_/ );
	} );
}

test( 'SDK tolerates an empty priming GET event without treating it as legacy HTTP+SSE', async ( t ) => {
	const { url } = await fixture( t, '', true );
	const result = await probe( url, 'PRIVATE_TOKEN_SENTINEL' );
	assert.equal( result.status, 'passed' );
} );

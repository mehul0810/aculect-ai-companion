import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = ( name ) =>
	readFileSync(
		new URL( `../../widgets/runtime/v1/${ name }`, import.meta.url ),
		'utf8'
	);

function loadState() {
	const context = {};
	runInNewContext( source( 'state.js' ), context );
	return context.AculectWidgetState;
}

function hostFixture() {
	const sent = [];
	const listeners = new Set();
	const events = [];
	const parent = { postMessage: ( message ) => sent.push( message ) };
	const view = {
		parent,
		setTimeout,
		clearTimeout,
		addEventListener: ( name, listener ) => {
			assert.equal( name, 'message' );
			listeners.add( listener );
		},
		removeEventListener: ( name, listener ) => {
			assert.equal( name, 'message' );
			listeners.delete( listener );
		},
	};
	const context = { URL };
	runInNewContext( source( 'bridge.js' ), context );
	const bridge = context.AculectWidgetBridge.createBridge( {
		windowRef: view,
		appInfo: { name: 'Test', version: '1' },
		readOnlyTools: [ 'site_get_info' ],
		onEvent: ( event ) => events.push( event ),
	} );
	const deliver = ( data, sourceWindow = parent ) => {
		for ( const listener of listeners ) {
			listener( { source: sourceWindow, data } );
		}
	};
	const respond = ( request, result ) =>
		deliver( {
			jsonrpc: '2.0',
			id: request.id,
			result,
		} );
	return { sent, events, parent, view, bridge, deliver, respond, listeners };
}

test( 'state reducer handles structured, text, malformed and lifecycle states', () => {
	const State = loadState();
	let current = State.state( 'loading' );
	current = State.reduce( current, {
		type: 'result',
		result: {
			structuredContent: { name: '<script>alert(1)</script>' },
		},
	} );
	assert.equal( current.kind, 'completed' );
	assert.equal( current.data.name, '<script>alert(1)</script>' );
	current = State.reduce( current, { type: 'stale' } );
	assert.equal( current.kind, 'stale' );
	assert.equal( current.data.name, '<script>alert(1)</script>' );
	current = State.reduce( current, { type: 'approval-pending' } );
	assert.equal( current.kind, 'approval-pending' );
	assert.equal(
		State.reduce( current, {
			type: 'result',
			result: {
				content: [ { type: 'text', text: '{"name":"Fixture"}' } ],
			},
		} ).data.name,
		'Fixture'
	);
	assert.equal(
		State.reduce( current, {
			type: 'result',
			result: {
				content: [ { type: 'text', text: '{bad' } ],
			},
		} ).kind,
		'empty'
	);
	assert.equal(
		State.reduce( current, {
			type: 'result',
			result: {
				isError: true,
			},
		} ).kind,
		'error'
	);
	assert.equal(
		State.reduce( current, { type: 'disabled' } ).kind,
		'disabled'
	);
	assert.equal(
		State.reduce( current, { type: 'cancelled' } ).kind,
		'error'
	);
	assert.throws(
		() => State.state( 'invented' ),
		/Unsupported widget state/
	);
} );

test( 'bridge handshakes, ignores unrelated frames, and calls only allowlisted tools', async () => {
	const host = hostFixture();
	const start = host.bridge.start();
	assert.equal( host.sent[ 0 ].method, 'ui/initialize' );
	assert.equal( host.sent[ 0 ].params.protocolVersion, '2026-01-26' );
	host.respond( host.sent[ 0 ], {
		protocolVersion: '2026-01-26',
		hostContext: {
			theme: 'dark',
			availableDisplayModes: [ 'inline', 'fullscreen' ],
		},
	} );
	await start;
	assert.equal( host.sent[ 1 ].method, 'ui/notifications/initialized' );
	assert.equal( host.bridge.isConnected(), true );
	host.deliver(
		{
			jsonrpc: '2.0',
			method: 'ui/notifications/tool-result',
			params: { structuredContent: { name: 'Spoofed' } },
		},
		{}
	);
	assert.equal(
		host.events.some( ( event ) => event.type === 'tool-result' ),
		false
	);
	host.deliver( {
		jsonrpc: '2.0',
		method: 'ui/notifications/tool-input',
		params: { arguments: {} },
	} );
	assert.equal( host.events.at( -1 ).type, 'tool-input' );
	await assert.rejects(
		host.bridge.callReadOnlyTool( 'post_delete' ),
		/not permitted/
	);
	const call = host.bridge.callReadOnlyTool( 'site_get_info', {} );
	assert.equal( host.sent.at( -1 ).method, 'tools/call' );
	assert.equal( host.sent.at( -1 ).params.name, 'site_get_info' );
	host.respond( host.sent.at( -1 ), { structuredContent: { name: 'Site' } } );
	assert.equal( ( await call ).structuredContent.name, 'Site' );
	host.bridge.close();
	assert.equal( host.listeners.size, 0 );
} );

test( 'bridge supports standard context, link and display requests without OpenAI globals', async () => {
	const host = hostFixture();
	assert.equal( host.bridge.optionalOpenAI().openExternal, null );
	const start = host.bridge.start();
	host.respond( host.sent[ 0 ], {
		protocolVersion: '2026-01-26',
		hostContext: { availableDisplayModes: [ 'inline', 'fullscreen' ] },
	} );
	await start;
	await assert.rejects(
		host.bridge.openLink( 'javascript:alert(1)' ),
		/HTTPS/
	);
	const link = host.bridge.openLink( 'https://example.test/path' );
	assert.equal( host.sent.at( -1 ).method, 'ui/open-link' );
	host.respond( host.sent.at( -1 ), {} );
	await link;
	const update = host.bridge.updateModelContext( {
		structuredContent: { site_name: 'Fixture' },
	} );
	assert.equal( host.sent.at( -1 ).method, 'ui/update-model-context' );
	host.respond( host.sent.at( -1 ), {} );
	await update;
	const expand = host.bridge.requestDisplayMode( 'fullscreen' );
	assert.equal( host.sent.at( -1 ).method, 'ui/request-display-mode' );
	host.respond( host.sent.at( -1 ), { mode: 'fullscreen' } );
	await expand;
	host.deliver( {
		jsonrpc: '2.0',
		id: 'teardown-71',
		method: 'ui/resource-teardown',
		params: {},
	} );
	assert.equal( host.sent.at( -1 ).id, 'teardown-71' );
	assert.equal( host.bridge.isConnected(), false );
} );

test( 'unsupported protocol fails closed and removes listener', async () => {
	const host = hostFixture();
	const start = host.bridge.start();
	host.respond( host.sent[ 0 ], { protocolVersion: 'unsupported' } );
	await assert.rejects( start, /Unsupported MCP Apps host/ );
	assert.equal( host.bridge.isConnected(), false );
	assert.equal( host.listeners.size, 0 );
} );

test( 'versioned fixture is deterministic, self-contained and deny-by-default', () => {
	const template = source( 'fixture.template.html' );
	const css = source( 'fixture.css' );
	const js = [ 'state.js', 'bridge.js', 'fixture.js' ]
		.map( source )
		.join( '\n' );
	const artifact = readFileSync(
		new URL(
			'../../assets/mcp-apps/runtime/v1/fixture.html',
			import.meta.url
		),
		'utf8'
	);
	assert.equal(
		artifact,
		template
			.replace( '__ACULECT_WIDGET_CSS__', css )
			.replace( '__ACULECT_WIDGET_JS__', js )
	);
	assert.match( artifact, /connect-src 'none'/ );
	assert.match( artifact, /frame-src 'none'/ );
	assert.match( artifact, /img-src 'none'/ );
	assert.doesNotMatch(
		artifact,
		/<script[^>]+src=|<link[^>]+href=|sourceMappingURL/i
	);
	assert.doesNotMatch(
		js,
		/@wordpress\/|wp-admin|wp\.data|localStorage|sessionStorage/
	);
	assert.ok( Buffer.byteLength( artifact ) <= 24 * 1024 );
	assert.ok( Buffer.byteLength( js ) <= 16 * 1024 );
} );

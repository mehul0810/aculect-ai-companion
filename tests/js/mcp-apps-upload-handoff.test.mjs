import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const widget = readFileSync(
	new URL( '../../widgets/image-upload/v1/widget.js', import.meta.url ),
	'utf8'
);
const uploadUrl =
	'https://example.com/site/wp-admin/admin.php?page=aculect-mcp-image-upload';

async function fixture( { url = uploadUrl, unavailable = false, open } = {} ) {
	const elements = Object.fromEntries(
		[
			'open',
			'status',
			'handoff-url',
			'manual-handoff',
			'upload-address',
		].map( ( id ) => [ id, { textContent: '', value: '', hidden: true } ] )
	);
	elements.open.disabled = true;
	elements.open.addEventListener = ( name, callback ) => {
		assert.equal( name, 'click' );
		elements.open.click = callback;
	};
	elements[ 'handoff-url' ].textContent = JSON.stringify( url );
	const requests = [];
	let options;
	let started;
	let connected = false;
	const bridge = {
		isConnected: () => connected,
		start: () => {
			started = ( async () => {
				if ( unavailable ) {
					throw new Error( 'Host unavailable.' );
				}
				connected = true;
				options.onEvent( {
					type: 'ready',
					context: { theme: 'light' },
				} );
			} )();
			return started;
		},
		openLink: async ( urlToOpen ) => {
			requests.push( urlToOpen );
			return open?.();
		},
	};
	const context = {
		URL,
		document: {
			getElementById: ( id ) => elements[ id ],
			documentElement: { dataset: {} },
		},
		AculectWidgetBridge: {
			createBridge: ( configuration ) => {
				options = configuration;
				assert.deepEqual( Array.from( options.readOnlyTools ), [] );
				return bridge;
			},
		},
	};
	runInNewContext( widget, context );
	await started.catch( () => {} );
	return {
		elements,
		requests,
		emit: ( event ) => options.onEvent( event ),
		close: () => {
			connected = false;
			options.onEvent( { type: 'closed' } );
		},
	};
}

test( 'manual upload address remains available when the host bridge cannot start', async () => {
	const view = await fixture( { unavailable: true } );

	assert.equal( view.elements[ 'manual-handoff' ].hidden, false );
	assert.equal( view.elements[ 'upload-address' ].value, uploadUrl );
	assert.equal( view.elements.open.disabled, true );
	assert.match(
		view.elements.status.textContent,
		/Copy the WordPress upload address/
	);
	assert.deepEqual( view.requests, [] );
} );

test( 'opening is explicit and stays disabled through host-context changes', async () => {
	let finish;
	const view = await fixture( {
		open: () =>
			new Promise( ( resolve ) => {
				finish = resolve;
			} ),
	} );
	assert.equal( view.elements.open.disabled, false );
	assert.deepEqual( view.requests, [] );

	const opening = view.elements.open.click();
	assert.equal( view.elements.open.disabled, true );
	view.emit( { type: 'host-context', context: { theme: 'dark' } } );
	assert.equal( view.elements.open.disabled, true );
	finish();
	await opening;
	assert.deepEqual( view.requests, [ uploadUrl ] );
	assert.equal( view.elements.open.disabled, false );
	assert.match(
		view.elements.status.textContent,
		/No image was uploaded by this widget/
	);
} );

test( 'declined link opening provides the manual address and a retry', async () => {
	const view = await fixture( {
		open: async () => {
			throw new Error( 'Host declined.' );
		},
	} );
	await view.elements.open.click();

	assert.equal( view.elements.open.disabled, false );
	assert.equal( view.elements[ 'manual-handoff' ].hidden, false );
	assert.equal( view.elements[ 'upload-address' ].value, uploadUrl );
	assert.match(
		view.elements.status.textContent,
		/Copy the WordPress upload address/
	);
	assert.match( view.elements.status.textContent, /No file was uploaded/ );
	await view.elements.open.click();
	assert.equal( view.requests.length, 2 );
} );

test( 'a closed host leaves only the manual upload path enabled', async () => {
	const view = await fixture();
	view.close();

	assert.equal( view.elements.open.disabled, true );
	assert.equal( view.elements[ 'manual-handoff' ].hidden, false );
	assert.match(
		view.elements.status.textContent,
		/Copy the WordPress upload address/
	);
	await view.elements.open.click();
	assert.deepEqual( view.requests, [] );
} );

test( 'malformed insecure and credential-bearing addresses fail closed', async () => {
	for ( const url of [
		'not a URL',
		'http://example.com/wp-admin/',
		'javascript:alert(1)',
		'https://person:secret@example.com/wp-admin/',
		null,
		{},
	] ) {
		const view = await fixture( { url } );
		assert.equal( view.elements.open.disabled, true );
		assert.equal( view.elements[ 'manual-handoff' ].hidden, true );
		assert.equal( view.elements[ 'upload-address' ].value, '' );
		await view.elements.open.click();
		assert.deepEqual( view.requests, [] );
	}
} );

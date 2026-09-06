import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(
	new URL( '../../assets/js/webmcp.js', import.meta.url ),
	'utf8'
);

const createElement = ( overrides = {} ) => ( {
	innerText: '',
	getAttribute: () => '',
	querySelectorAll: () => [],
	...overrides,
} );

const boot = async ( { supported = true } = {} ) => {
	const registrations = [];
	const root = createElement( {
		innerText: 'Public body content '.repeat( 100 ),
		querySelectorAll: ( selector ) =>
			selector.startsWith( 'h1' )
				? [ createElement( { innerText: 'Overview' } ) ]
				: [
						createElement( {
							innerText: 'About',
							href: 'https://reader:password@example.com/about?nonce=secret#team',
						} ),
						createElement( {
							innerText: 'External',
							href: 'https://outside.example/docs',
						} ),
				  ],
	} );
	const document = {
		body: root,
		documentElement: { lang: 'en-US' },
		location: {
			href: 'https://viewer:password@example.com/page?code=secret#fragment',
			origin: 'https://example.com',
		},
		title: 'Example page',
		querySelector: ( selector ) =>
			selector.startsWith( 'meta' )
				? createElement( {
						getAttribute: () => 'A useful public page.',
				  } )
				: root,
	};
	if ( supported ) {
		document.modelContext = {
			registerTool: async ( definition, options ) =>
				registrations.push( { definition, options } ),
		};
	}

	const window = {
		addEventListener: () => {},
	};
	const context = vm.createContext( {
		AbortController,
		document,
		URL,
		window,
	} );
	vm.runInContext( source, context );
	await new Promise( ( resolve ) => setImmediate( resolve ) );

	return { registrations, window };
};

test( 'registers one bounded read-only page-context tool when WebMCP is supported', async () => {
	const { registrations } = await boot();

	assert.equal( registrations.length, 1 );
	const tool = registrations[ 0 ].definition;
	assert.equal( tool.name, 'aculect_get_page_context' );
	assert.deepEqual(
		{ ...tool.annotations },
		{
			readOnlyHint: true,
			untrustedContentHint: true,
			consequentialHint: false,
		}
	);

	const result = await tool.execute( { includeLinks: true, maxLinks: 4 } );
	assert.ok( JSON.stringify( result ).length <= 1500 );
	assert.equal( result.url, 'https://example.com/page' );
	assert.equal( result.links.length, 1 );
	assert.equal( result.links[ 0 ].url, 'https://example.com/about' );
} );

test( 'degrades without side effects when WebMCP is unavailable', async () => {
	const { registrations, window } = await boot( { supported: false } );

	assert.equal( registrations.length, 0 );
	assert.equal( window.aculectWebMcp, undefined );
} );

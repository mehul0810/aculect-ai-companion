import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(
	new URL( '../../assets/js/webmcp.js', import.meta.url ),
	'utf8'
);

const createElement = ( overrides = {} ) => ( {
	nodeType: 1,
	tagName: 'DIV',
	innerText: '',
	getAttribute: () => '',
	querySelectorAll: () => [],
	...overrides,
} );

const append = ( parent, children ) => {
	parent.firstChild = children[ 0 ];
	children.forEach( ( node, index ) => {
		node.parentNode = parent;
		node.nextSibling = children[ index + 1 ] || null;
	} );
	return parent;
};

const textElement = ( tagName, text, extra = {} ) =>
	append( createElement( { tagName, ...extra } ), [
		{ nodeType: 3, nodeValue: text },
	] );

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
	append( root, [
		textElement( 'H1', 'Overview' ),
		textElement( 'P', 'Public body content '.repeat( 100 ) ),
		textElement( 'A', 'About', {
			href: 'https://reader:password@example.com/about?nonce=secret#team',
		} ),
		textElement( 'A', 'External', {
			href: 'https://outside.example/docs',
		} ),
	] );
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

	return { registrations, window, document, root, context };
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

test( 'escaped content and headings cannot stall output budgeting', async () => {
	const { context, document, root } = await boot();
	document.title = '\\'.repeat( 120 );
	append( root, [
		...Array.from( { length: 6 }, () =>
			textElement( 'H1', '\\'.repeat( 80 ) )
		),
		textElement( 'P', '\\'.repeat( 600 ) ),
	] );
	const result = vm.runInContext(
		'window.aculectWebMcp.collectPageContext(null)',
		context,
		{ timeout: 200 }
	);
	assert.ok( JSON.stringify( result ).length <= 1500 );
} );

test( 'traversal is bounded and excludes hidden/form/script content', async () => {
	const { root, window } = await boot();
	append( root, [
		textElement( 'SCRIPT', 'secret script' ),
		textElement( 'FORM', 'secret form' ),
		textElement( 'TEXTAREA', 'secret input' ),
		textElement( 'P', 'secret hidden', { hidden: true } ),
		...Array.from( { length: 500 }, () => textElement( 'SPAN', '' ) ),
		textElement( 'H2', 'beyond traversal budget' ),
	] );
	const result = window.aculectWebMcp.collectPageContext( {} );
	assert.equal( result.content, '' );
	assert.equal( result.headings.length, 0 );
} );

test( 'selected main content remains private under a hidden ancestor', async () => {
	const { root, window } = await boot();
	append( createElement( { hidden: true } ), [ root ] );
	assert.equal( window.aculectWebMcp.collectPageContext().content, '' );
	root.parentNode.hidden = false;
	root.parentNode.getAttribute = () => 'true';
	assert.equal(
		window.aculectWebMcp.collectPageContext().headings.length,
		0
	);
	root.parentNode.getAttribute = () => '';
	window.getComputedStyle = ( element ) => ( {
		display: element === root.parentNode ? 'none' : 'block',
	} );
	assert.equal( window.aculectWebMcp.collectPageContext().content, '' );
} );

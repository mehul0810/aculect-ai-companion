import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = ( name ) =>
	readFileSync(
		new URL( `../../widgets/pattern-picker/v1/${ name }`, import.meta.url ),
		'utf8'
	);
const context = {};
runInNewContext( source( 'contract.js' ), context );
const contract = context.AculectPatternPickerContract;
const result = ( items, extra = {} ) => ( {
	structuredContent: { schema: contract.SCHEMA, items, ...extra },
} );

test( 'accepts stable names and projects bounded metadata without pattern markup', () => {
	const parsed = contract.parse(
		result( [
			{
				id: 'theme/hero',
				title: '<script>alert(1)</script>',
				category: 'Hero',
				source: 'theme',
				compatibility: 'compatible',
				supported_blocks: [ 'core/heading', 'core/image' ],
				content: '<script>evil()</script>',
			},
		] )
	);
	assert.equal( parsed.state, 'ready' );
	assert.equal( parsed.items[ 0 ].id, 'theme/hero' );
	assert.equal( parsed.items[ 0 ].title, '<script>alert(1)</script>' );
	assert.equal( Object.hasOwn( parsed.items[ 0 ], 'content' ), false );
	assert.equal( parsed.items[ 0 ].source, 'theme' );
	assert.equal( parsed.items[ 0 ].compatibility, 'compatible' );
} );

test( 'rejects invalid or duplicate IDs and marks unsupported compatibility unknown', () => {
	const parsed = contract.parse(
		result( [
			{ id: 'javascript:alert(1)' },
			{
				id: 'plugin/gallery',
				source: 'remote',
				compatibility: 'perfect',
			},
			{ id: 'plugin/gallery', title: 'duplicate' },
		] )
	);
	assert.equal( parsed.items.length, 1 );
	assert.equal( parsed.items[ 0 ].source, 'remote' );
	assert.equal( parsed.items[ 0 ].compatibility, 'unknown' );
} );

test( 'accepts existing WordPress pattern discovery field names without inventing compatibility', () => {
	const parsed = contract.parse( {
		structuredContent: {
			items: [
				{
					name: 'theme/feature',
					title: 'Feature',
					categories: [ 'featured' ],
					category_labels: { featured: 'Featured' },
					source: 'registered',
					content_blocks: [ 'core/heading' ],
					post_types: [ 'page' ],
				},
			],
		},
	} );
	assert.equal( parsed.items[ 0 ].id, 'theme/feature' );
	assert.equal( parsed.items[ 0 ].category, 'Featured' );
	assert.equal( parsed.items[ 0 ].source, 'registered' );
	assert.equal( parsed.items[ 0 ].blocks[ 0 ], 'core/heading' );
	assert.equal( parsed.items[ 0 ].contentType, 'page' );
	assert.equal( parsed.items[ 0 ].compatibility, 'unknown' );
} );

test( 'bounds oversized result and strings', () => {
	const items = Array.from( { length: 150 }, ( _, index ) => ( {
		id: `core/pattern-${ index }`,
		title: 'X'.repeat( 1000 ),
		supported_blocks: Array( 100 ).fill( 'core/paragraph' ),
	} ) );
	const parsed = contract.parse( result( items, { total: 10000 } ) );
	assert.equal( parsed.items.length, contract.MAX_ITEMS );
	assert.equal( parsed.truncated, true );
	assert.equal( parsed.total, 10000 );
	assert.equal( parsed.items[ 0 ].title.length, 180 );
	assert.equal( parsed.items[ 0 ].blocks.length, 8 );
} );

test( 'stale, unavailable, errored and malformed responses fail closed', () => {
	for ( const status of [ 'stale', 'unavailable' ] ) {
		assert.equal(
			contract.parse( result( [], { status } ) ).state,
			status
		);
	}
	assert.equal( contract.parse( {} ).state, 'error' );
	assert.equal( contract.parse( { isError: true } ).state, 'error' );
	assert.equal( contract.parse( result( null ) ).state, 'error' );
	assert.equal(
		contract.parse( { structuredContent: { schema: 'wrong', items: [] } } )
			.state,
		'error'
	);
} );

test( 'built widget is deterministic and cannot load remote code or images', () => {
	const template = source( 'pattern-picker.template.html' );
	const css = source( 'pattern-picker.css' );
	const bridge = readFileSync(
		new URL( '../../widgets/runtime/v1/bridge.js', import.meta.url ),
		'utf8'
	);
	const js = [ bridge, source( 'contract.js' ), source( 'widget.js' ) ].join(
		'\n'
	);
	const artifact = readFileSync(
		new URL(
			'../../assets/mcp-apps/pattern-picker/v1/pattern-picker.html',
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
	assert.match( artifact, /img-src 'none'/ );
	assert.doesNotMatch( artifact, /<script\s+src=/i );
	assert.ok( Buffer.byteLength( artifact ) < 24 * 1024 );
} );

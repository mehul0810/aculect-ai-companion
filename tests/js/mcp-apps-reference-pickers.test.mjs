import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import { minify } from 'terser';

const source = ( path ) =>
	readFileSync( new URL( `../../${ path }`, import.meta.url ), 'utf8' );
const scope = { URL };
runInNewContext( source( 'widgets/reference-picker/v1/contract.js' ), scope );
const { parse } = scope.AculectReferencePickerContract;
const result = ( data ) => ( { structuredContent: data } );

test( 'projects compact media metadata without arbitrary source URLs or markup fields', () => {
	const output = parse(
		result( {
			items: [
				{
					id: 21,
					type: 'attachment',
					title: '<svg onload=evil()>',
					mime_type: 'image/jpeg',
					alt_text: 'A bridge',
					source_url: 'https://evil.example/original.jpg',
					thumbnail: {
						url: 'https://site.example/medium.jpg',
						width: 300,
						height: 200,
					},
					content: '<script>evil()</script>',
				},
			],
		} ),
		'media'
	);
	assert.equal( output.state, 'ready' );
	assert.equal( output.items[ 0 ].title, '<svg onload=evil()>' );
	assert.equal( output.items[ 0 ].thumbnail.width, 300 );
	assert.equal( Object.hasOwn( output.items[ 0 ], 'source_url' ), false );
	assert.equal( Object.hasOwn( output.items[ 0 ], 'content' ), false );
} );

test( 'accepts compact, get, indexed, and degraded search content shapes', () => {
	for ( const item of [
		{
			id: 1,
			type: 'post',
			title: 'Compact',
			excerpt: 'Short',
			link: 'https://site.example/p',
		},
		{ id: 2, type: 'page', title: 'Full', content: '<p>not projected</p>' },
		{
			id: 3,
			type: 'post',
			title: 'Indexed',
			summary: 'Summary',
			permalink: 'https://site.example/i',
		},
		{ id: 4, type: 'post', title: 'Degraded', stale: true, degraded: true },
	] ) {
		const output = parse( result( item ), 'content' );
		assert.equal( output.state, 'ready' );
		assert.equal( output.items.length, 1 );
		assert.equal( Object.hasOwn( output.items[ 0 ], 'content' ), false );
		if ( item.stale ) {
			assert.equal( output.items[ 0 ].stale, true );
		}
	}
	assert.equal(
		parse(
			result( {
				items: [
					{
						id: 3,
						type: 'post',
						summary: 'Summary',
						permalink: 'https://site.example/i',
					},
				],
			} ),
			'content'
		).items[ 0 ].link,
		'https://site.example/i'
	);
} );

test( 'rejects errors, malformed data, duplicates, invalid IDs, and unsafe URLs', () => {
	for ( const value of [
		{},
		{ isError: true, structuredContent: { items: [] } },
		result( { error: 'forbidden', items: [] } ),
		result( { items: null } ),
	] ) {
		assert.equal( parse( value, 'content' ).state, 'error' );
	}
	const output = parse(
		result( {
			items: [
				{ id: -1, type: 'post' },
				{ id: '1', type: 'post' },
				{ id: 1, type: 'javascript:' },
				{ id: 1, type: 'post', link: 'javascript:evil()' },
				{ id: 1, type: 'post', title: 'Duplicate' },
				{
					id: 2,
					type: 'attachment',
					thumbnail: {
						url: 'https://site.example/i',
						width: -1,
						height: 2,
					},
				},
			],
		} ),
		'content'
	);
	assert.equal( output.items.length, 2 );
	assert.equal( output.items[ 0 ].link, '' );
	assert.equal( output.items[ 1 ].thumbnail, null );
	assert.equal(
		parse( result( { items: [ { id: 1, type: 'post' } ] } ), 'media' ).items
			.length,
		0
	);
} );

test( 'rejects coercible types, statuses, URLs, and credential-bearing links', () => {
	const output = parse(
		result( {
			items: [
				{ id: 1, type: true },
				{ id: 2, type: [ 'post' ] },
				{
					id: 3,
					type: 'post',
					status: [ 'publish' ],
					link: [ 'https://site.example/p' ],
				},
				{
					id: 4,
					type: 'post',
					status: true,
					link: 'https://user:pass@site.example/p',
				},
				{ id: 5, type: 'post', link: 123 },
			],
		} ),
		'content'
	);
	assert.deepEqual(
		Array.from( output.items, ( item ) => item.id ),
		[ 3, 4, 5 ]
	);
	for ( const item of output.items ) {
		assert.equal( item.status, '' );
		assert.equal( item.link, '' );
	}
} );

test( 'indexed pagination uses strict has_more without inventing a total', () => {
	const item = { id: 7, type: 'post', title: 'Indexed' };
	const output = parse(
		result( {
			items: [ item ],
			total: 1,
			page: 1,
			per_page: 1,
			has_more: true,
		} ),
		'content'
	);
	assert.equal( output.total, 1 );
	assert.equal( output.hasMore, true );
	assert.equal(
		parse( result( { items: [ item ] } ), 'content' ).total,
		null
	);
	assert.equal(
		parse( result( { items: [ item ], has_more: 'true' } ), 'content' )
			.hasMore,
		false
	);
} );

test( 'bounds response and preserves server page metadata', () => {
	const items = Array.from( { length: 150 }, ( _, n ) => ( {
		id: n + 1,
		type: 'post',
		title: 'X'.repeat( 500 ),
	} ) );
	const output = parse(
		result( { items, total: 700, page: 2, per_page: 100 } ),
		'content'
	);
	assert.equal( output.items.length, 100 );
	assert.equal( output.items[ 0 ].title.length, 180 );
	assert.equal( output.truncated, true );
	assert.equal( output.total, 700 );
	assert.equal( output.page, 2 );
	assert.equal( output.perPage, 100 );
} );

test( 'both generated widgets stay deterministic, bounded, and selection-only', async () => {
	const bridge = source( 'widgets/runtime/v1/bridge.js' );
	const contract = source( 'widgets/reference-picker/v1/contract.js' );
	const widget = source( 'widgets/reference-picker/v1/widget.js' );
	const css = source( 'widgets/reference-picker/v1/picker.css' );
	const js = (
		await minify( [ bridge, contract, widget ].join( '\n' ), {
			compress: true,
			mangle: true,
			format: { comments: false },
		} )
	).code;
	for ( const kind of [ 'media', 'content' ] ) {
		const template = source(
			`widgets/${ kind }-picker/v1/${ kind }-picker.template.html`
		);
		const artifact = source(
			`assets/mcp-apps/${ kind }-picker/v1/${ kind }-picker.html`
		);
		assert.equal(
			artifact,
			template
				.replace( '__ACULECT_WIDGET_CSS__', css )
				.replace( '__ACULECT_WIDGET_JS__', js )
		);
		assert.ok( Buffer.byteLength( artifact ) < 24 * 1024 );
		assert.doesNotMatch( artifact, /<script\s+src=/i );
		assert.match( widget, /readOnlyTools: \[\]/ );
	}
	assert.match(
		source( 'assets/mcp-apps/media-picker/v1/media-picker.html' ),
		/window\.ACULECT_MEDIA_ORIGIN=__ACULECT_MEDIA_ORIGIN_JSON__/
	);
	assert.match(
		source( 'assets/mcp-apps/content-picker/v1/content-picker.html' ),
		/img-src 'none'/
	);
} );

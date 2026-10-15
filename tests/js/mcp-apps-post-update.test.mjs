import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const source = ( name ) =>
	readFileSync(
		new URL( `../../widgets/post-update/v1/${ name }`, import.meta.url ),
		'utf8'
	);
const context = { URL, Date };
runInNewContext( source( 'contract.js' ), context );
const contract = context.AculectPostUpdateContract;

function result( override = {} ) {
	return {
		structuredContent: {
			schema: contract.SCHEMA,
			outcome: 'success',
			content: {
				title: 'Example draft',
				type: 'Page',
				status: 'draft',
				completed_at: '2026-09-25T10:30:00Z',
				revision: 'Revision 42',
			},
			links: {
				view: 'https://site.example/example-draft/',
				edit: 'https://site.example/wp-admin/post.php?post=123',
				compare:
					'https://site.example/wp-admin/revision.php?revision=42',
				undo: 'https://site.example/wp-admin/revision.php?revision=40',
			},
			undo: {
				available: true,
				safe_snapshot: true,
				post_id: 123,
				revision_id: 40,
				reason: 'wordpress_revision_review',
			},
			...override,
		},
	};
}

test( 'projects a complete server-authored update result', () => {
	const data = contract.parse( result() );
	assert.equal( data.outcome, 'success' );
	assert.equal( data.title, 'Example draft' );
	assert.equal( data.status, 'draft' );
	assert.equal( data.completedAt, '2026-09-25T10:30:00Z' );
	assert.equal( data.links.view, 'https://site.example/example-draft/' );
	assert.equal( data.undo, true );
	assert.equal(
		data.links.undo,
		'https://site.example/wp-admin/revision.php?revision=40'
	);
} );

test( 'projects a bounded pending-approval result without exposing decision credentials', () => {
	const data = contract.parse( {
		structuredContent: {
			schema: contract.SCHEMA,
			outcome: 'approval_pending',
			approval_target: 'Page #123: Example',
			risk_level: 'update',
			risk_categories: [ 'content', 'taxonomy' ],
			approval: {
				expires_at: '2026-09-29T10:10:00Z',
				queue: 'https://site.example/wp-admin/options-general.php?page=approvals',
				decision: 'wordpress_admin',
			},
			changes: [ { label: 'title', before: 'Old', after: 'New' } ],
		},
	} );
	assert.equal( data.outcome, 'approval_pending' );
	assert.equal( data.target, 'Page #123: Example' );
	assert.equal( data.riskLevel, 'update' );
	assert.deepEqual( data.riskCategories, [ 'content', 'taxonomy' ] );
	assert.equal( data.changedFields, 'title' );
	assert.equal(
		data.links.approval_queue,
		'https://site.example/wp-admin/options-general.php?page=approvals'
	);
	assert.equal( 'token' in data, false );
	assert.equal(
		contract.parse( {
			structuredContent: {
				schema: contract.SCHEMA,
				outcome: 'approval_pending',
			},
		} ).outcome,
		'error'
	);
} );

test( 'fails closed on malformed or errored tool results', () => {
	assert.equal( contract.parse( {} ).outcome, 'error' );
	assert.equal( contract.parse( { isError: true } ).outcome, 'error' );
	assert.equal(
		contract.parse( { structuredContent: { schema: 'wrong' } } ).outcome,
		'error'
	);
	assert.equal(
		contract.parse( result( { outcome: 'invented' } ) ).outcome,
		'error'
	);
} );

test( 'incomplete success becomes partial; stale and unavailable remain explicit', () => {
	assert.equal(
		contract.parse( result( { content: { title: 'Partial' } } ) ).outcome,
		'partial'
	);
	for ( const outcome of [ 'partial', 'stale', 'unavailable', 'error' ] ) {
		assert.equal(
			contract.parse( result( { outcome } ) ).outcome,
			outcome
		);
	}
	assert.equal(
		contract.parse(
			result( {
				content: {
					title: 'Test',
					type: 'Post',
					status: 'draft',
					completed_at: 'not-a-time',
				},
			} )
		).outcome,
		'partial'
	);
} );

test( 'accepts only supplied HTTPS links without embedded credentials', () => {
	assert.equal( contract.safeLink( 'javascript:alert(1)' ), '' );
	assert.equal( contract.safeLink( 'http://site.example/' ), '' );
	assert.equal( contract.safeLink( '//site.example/' ), '' );
	assert.equal( contract.safeLink( 'https://user:pass@site.example/' ), '' );
	assert.equal(
		contract.safeLink( 'https://site.example/' ),
		'https://site.example/'
	);
	const data = contract.parse(
		result( { links: { view: 'javascript:alert(1)' } } )
	);
	assert.equal( data.links.view, '' );
	assert.equal( data.links.edit, '' );
	assert.equal( data.links.compare, '' );
} );

test( 'safe-snapshot guidance requires two explicit server flags', () => {
	assert.equal(
		contract.parse( result( { undo: { available: true } } ) ).undo,
		false
	);
	assert.equal(
		contract.parse( result( { undo: { available: true } } ) ).links.undo,
		''
	);
	assert.equal(
		contract.parse( result( { undo: { safe_snapshot: true } } ) ).undo,
		false
	);
	assert.equal( contract.parse( result( { undo: null } ) ).undo, false );
	for ( const invalid of [
		{ available: true, safe_snapshot: true, post_id: 123, revision_id: 40 },
		{
			available: true,
			safe_snapshot: true,
			post_id: 123,
			revision_id: 0,
			reason: 'wordpress_revision_review',
		},
		{
			available: true,
			safe_snapshot: true,
			post_id: 123,
			revision_id: 40,
			reason: 'restore_now',
		},
	] ) {
		assert.equal(
			contract.parse( result( { undo: invalid } ) ).undo,
			false
		);
	}
	assert.equal(
		contract.parse( result( { links: { undo: '' } } ) ).undo,
		false
	);
	for ( const outcome of [ 'partial', 'stale', 'unavailable', 'error' ] ) {
		assert.equal( contract.parse( result( { outcome } ) ).undo, false );
	}
} );

test( 'unavailable and error results cannot expose stale content links', () => {
	for ( const outcome of [ 'unavailable', 'error' ] ) {
		const data = contract.parse( result( { outcome } ) );
		assert.deepEqual( Object.values( data.links ), [ '', '', '', '' ] );
	}
} );

test( 'packaged HTML is deterministic, inline, and denies remote resources', () => {
	const template = source( 'post-update.template.html' );
	const css = source( 'post-update.css' );
	const bridge = readFileSync(
		new URL( '../../widgets/runtime/v1/bridge.js', import.meta.url ),
		'utf8'
	);
	const js = [ bridge, source( 'contract.js' ), source( 'widget.js' ) ].join(
		'\n'
	);
	const artifact = readFileSync(
		new URL(
			'../../assets/mcp-apps/post-update/v1/post-update.html',
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
	assert.doesNotMatch( artifact, /<script\s+src=/i );
	assert.ok( Buffer.byteLength( artifact ) < 24 * 1024 );
} );

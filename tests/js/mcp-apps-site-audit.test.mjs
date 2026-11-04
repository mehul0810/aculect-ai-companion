import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';
import { minify } from 'terser';

const read = ( path ) =>
	readFileSync( new URL( `../../${ path }`, import.meta.url ), 'utf8' );
const widget = read( 'widgets/site-audit/v1/widget.js' );
function fixture() {
	const element = () => ( {
		textContent: '',
		children: [],
		dataset: {},
		appendChild( child ) {
			this.children.push( child );
		},
		replaceChildren() {
			this.children = [];
		},
	} );
	const nodes = Object.fromEntries(
		[ 'status', 'findings', 'next-actions' ].map( ( id ) => [
			id,
			element(),
		] )
	);
	let options;
	runInNewContext( widget, {
		document: {
			getElementById: ( id ) => nodes[ id ],
			createElement: element,
		},
		AculectWidgetBridge: {
			createBridge( value ) {
				options = value;
				return { start: () => Promise.resolve() };
			},
		},
	} );
	return {
		nodes,
		send: ( type, params ) => options.onEvent( { type, params } ),
		tools: options.readOnlyTools,
	};
}
const result = {
	structuredContent: {
		workflow: 'site_workflow_audit',
		status: 'ready',
		summary: { overall_severity: 'warning' },
		findings: [
			{
				title: '<img src=x>',
				severity: 'warning',
				evidence: { counts_available: false, freshness: 'unknown' },
				recommended_action: 'Inspect update status',
				required_tool: 'site_get_health',
			},
		],
		next_actions: [
			{ action: 'Inspect update status', tool: 'site_get_health' },
		],
	},
};

test( 'audit renders existing evidence and next steps as text and cannot call tools', () => {
	const view = fixture();
	assert.equal( view.tools.length, 0 );
	view.send( 'tool-result', result );
	const card = view.nodes.findings.children[ 0 ];
	assert.equal( card.children[ 0 ].textContent, '<img src=x> — warning' );
	assert.equal( card.children[ 1 ].children[ 1 ].textContent, 'no' );
	assert.match(
		view.nodes[ 'next-actions' ].children[ 0 ].textContent,
		/site_get_health/
	);
	assert.doesNotMatch(
		widget,
		/innerHTML|fetch\(|callReadOnlyTool|openLink|updateModelContext/
	);
} );

test( 'streamed requests, malformed results, cancellation and closed hosts clear stale findings', () => {
	const view = fixture();
	for ( const type of [
		'tool-input',
		'tool-input-partial',
		'tool-cancelled',
		'closed',
	] ) {
		view.send( 'tool-result', result );
		view.send( type );
		assert.equal( view.nodes.findings.children.length, 0 );
		assert.equal( view.nodes[ 'next-actions' ].children.length, 0 );
	}
	view.send( 'tool-result', { ...result, isError: true } );
	assert.equal( view.nodes.findings.children.length, 0 );
	view.send( 'tool-result', {
		structuredContent: { status: 'ready', findings: {} },
	} );
	assert.match( view.nodes.status.textContent, /unavailable/ );
} );

test( 'packaged audit is deterministic and network isolated within existing budgets', async () => {
	const html = read( 'assets/mcp-apps/site-audit/v1/site-audit.html' );
	const js = (
		await minify( read( 'widgets/runtime/v1/bridge.js' ) + '\n' + widget, {
			compress: true,
			mangle: true,
			format: { comments: false },
		} )
	).code;
	assert.equal(
		html,
		read( 'widgets/site-audit/v1/site-audit.template.html' )
			.replace(
				'__ACULECT_WIDGET_CSS__',
				read( 'widgets/site-audit/v1/site-audit.css' )
			)
			.replace( '__ACULECT_WIDGET_JS__', js )
	);
	assert.ok( Buffer.byteLength( html ) <= 24 * 1024 );
	assert.ok( Buffer.byteLength( js ) <= 16 * 1024 );
	assert.match( html, /connect-src 'none'/ );
	assert.match( html, /form-action 'none'/ );
} );

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import {
	AGENT_READINESS_STATUSES,
	agentReadinessEvidence,
	agentReadinessExportText,
	agentReadinessRows,
	agentReadinessStatusLabel,
} from '../../src/Admin/diagnostics/agent-readiness-ui.mjs';

test( 'maps all six statuses and plugin, site, and not-applicable ownership', () => {
	const statuses = AGENT_READINESS_STATUSES.map( ( item ) => item.id );
	const rows = agentReadinessRows( {
		items: [
			{ id: 'oauth_discovery', status: 'pass' },
			{ id: 'oauth_authorization_metadata', status: 'fail' },
			{
				id: 'mcp_authentication_challenge',
				status: 'externally_blocked',
			},
			{ id: 'robots_txt', status: 'warning' },
			{ id: 'content_signals', status: 'owner_decision_required' },
			{ id: 'a2a_agent_card', status: 'not_applicable' },
		],
	} );

	assert.deepEqual( statuses, [
		'pass',
		'warning',
		'fail',
		'externally_blocked',
		'owner_decision_required',
		'not_applicable',
	] );
	assert.deepEqual(
		new Set( rows.map( ( row ) => row.status ) ),
		new Set( statuses )
	);
	assert.equal(
		rows.find( ( row ) => row.id === 'oauth_discovery' ).owner,
		'Aculect'
	);
	assert.equal(
		rows.find( ( row ) => row.id === 'robots_txt' ).owner,
		'Site owner'
	);
	assert.equal(
		rows.find( ( row ) => row.id === 'a2a_agent_card' ).owner,
		'Not applicable'
	);
	assert.equal(
		agentReadinessStatusLabel( 'externally_blocked' ),
		'Externally blocked'
	);
} );

test( 'keeps empty, malformed, and unknown results safe', () => {
	assert.deepEqual( agentReadinessRows( null ), [] );
	assert.deepEqual( agentReadinessRows( { items: 'not an array' } ), [] );
	assert.deepEqual(
		agentReadinessRows( {
			items: [ null, { id: 'unknown', status: 'pass' } ],
		} ),
		[]
	);
	assert.equal( agentReadinessStatusLabel( 'not_a_status' ), 'Needs review' );
} );

test( 'redacted export omits messages, probe evidence, unknown checks, and malformed timestamps', () => {
	const report = agentReadinessExportText( {
		ranAt: '2026-09-25 08:00:00\nBearer secret-token',
		items: [
			{
				id: 'oauth_discovery',
				status: 'pass',
				message: `Bearer secret-token ${ 'x'.repeat( 10000 ) }`,
				evidence: {
					client_secret: 'secret-token',
					httpStatus: 200,
					jsonContentType: true,
					expectedShape: true,
					url: 'https://private.example',
				},
			},
			{ id: 'unknown', status: 'fail', message: 'secret-token' },
		],
	} );

	assert.match( report, /Last checked \(UTC\): Not run/ );
	assert.match( report, /OAuth resource discovery.*Pass.*Aculect/ );
	assert.match(
		report,
		/HTTP status: 200; JSON content type: Yes; Expected JSON shape: Yes/
	);
	assert.doesNotMatch(
		report,
		/secret-token|unknown|private\.example|client_secret|evidence/
	);
	assert.ok( report.length < 5000 );
} );

test( 'only allowlisted scalar evidence is displayed', () => {
	assert.deepEqual(
		agentReadinessEvidence( 'robots_txt', {
			httpStatus: 200,
			body: 'secret',
			token: 'secret',
			url: 'https://private.example',
		} ),
		[ 'HTTP status: 200' ]
	);
	assert.deepEqual(
		agentReadinessEvidence( 'oauth_discovery', {
			httpStatus: '200',
			jsonContentType: 'true',
			expectedShape: true,
		} ),
		[ 'Expected JSON shape: Yes' ]
	);
	assert.deepEqual(
		agentReadinessEvidence( 'unknown', { httpStatus: 200 } ),
		[]
	);
} );

test( 'UI renders only fixed guidance, a disabled empty export, and responsive layout', () => {
	const source = readFileSync(
		new URL(
			'../../src/Admin/diagnostics/AgentReadinessPanel.js',
			import.meta.url
		),
		'utf8'
	);
	const css = readFileSync(
		new URL(
			'../../src/Admin/diagnostics/agent-readiness.scss',
			import.meta.url
		),
		'utf8'
	);

	assert.match( source, /aria-labelledby="aculect-agent-readiness-title"/ );
	assert.match( source, /aria-busy=\{\s*isRunning\s*\}/ );
	assert.match( source, /disabled=\{\s*rows.length === 0\s*\}/ );
	assert.match( source, /row\.guidance/ );
	assert.doesNotMatch( source, /row\.message/ );
	assert.match( css, /overflow-wrap: anywhere/ );
	assert.match( css, /@media \(max-width: 782px\)/ );
} );

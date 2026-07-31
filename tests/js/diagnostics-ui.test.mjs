import test from 'node:test';
import assert from 'node:assert/strict';

import {
	diagnosticCounts,
	diagnosticFreshness,
	diagnosticGuidanceSteps,
	diagnosticOverallStatus,
	diagnosticResultText,
	filteredDiagnosticItems,
	safeDiagnosticEvidence,
} from '../../src/diagnostics-ui.mjs';

const checks = [
	{
		id: 'https_url',
		status: 'pass',
		message: 'Connection URL uses HTTPS.',
	},
	{
		id: 'secret_storage',
		status: 'warn',
		message: 'Legacy material needs review.',
		remediation: 'Reconnect the assistant.',
	},
	{
		id: 'mcp_auth_challenge',
		status: 'fail',
		message: 'Authorization challenge failed.',
	},
];

test( 'counts diagnostic states and derives an honest overall status', () => {
	const counts = diagnosticCounts( checks );

	assert.deepEqual( counts, { total: 3, pass: 1, warn: 1, fail: 1 } );
	assert.equal( diagnosticOverallStatus( counts ), 'fail' );
	assert.equal(
		diagnosticOverallStatus( {
			total: 2,
			pass: 1,
			warn: 1,
			fail: 0,
		} ),
		'warn'
	);
	assert.equal(
		diagnosticOverallStatus( {
			total: 2,
			pass: 2,
			warn: 0,
			fail: 0,
		} ),
		'pass'
	);
	assert.equal(
		diagnosticOverallStatus( {
			total: 0,
			pass: 0,
			warn: 0,
			fail: 0,
		} ),
		'unavailable'
	);
} );

test( 'prioritizes errors and warnings while filtering and searching', () => {
	assert.deepEqual(
		filteredDiagnosticItems( checks, 'all', '' ).map( ( item ) => item.id ),
		[ 'mcp_auth_challenge', 'secret_storage', 'https_url' ]
	);
	assert.deepEqual(
		filteredDiagnosticItems( checks, 'warn', '' ).map(
			( item ) => item.id
		),
		[ 'secret_storage' ]
	);
	assert.deepEqual(
		filteredDiagnosticItems( checks, 'all', 'authorization' ).map(
			( item ) => item.id
		),
		[ 'mcp_auth_challenge' ]
	);
	assert.deepEqual( filteredDiagnosticItems( checks, 'all', 'missing' ), [] );
} );

test( 'classifies fresh, stale, missing, and invalid saved runs', () => {
	const now = Date.parse( '2026-07-31T12:00:00Z' );

	assert.equal( diagnosticFreshness( '2026-07-31 11:30:00', now ), 'fresh' );
	assert.equal( diagnosticFreshness( '2026-07-29 11:30:00', now ), 'stale' );
	assert.equal( diagnosticFreshness( '', now ), 'never' );
	assert.equal( diagnosticFreshness( 'not-a-date', now ), 'stale' );
} );

test( 'redacts sensitive evidence and bounds copied diagnostic results', () => {
	const safe = safeDiagnosticEvidence( {
		url: 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
		access_token: 'must-not-copy',
		nested: {
			client_secret: 'must-not-copy',
			raw_body: 'must-not-copy',
			status: 'reachable',
		},
		token: 'must-not-copy',
	} );
	const copied = diagnosticResultText( {
		id: 'mcp_auth_challenge',
		status: 'warn',
		message: 'Review the challenge.',
		remediation: 'Reconnect.',
		details: {
			url: 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
			refresh_token: 'must-not-copy',
		},
	} );

	assert.deepEqual( safe, {
		url: 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
		nested: {
			status: 'reachable',
		},
	} );
	assert.match( copied, /Identifier: mcp_auth_challenge/ );
	assert.match( copied, /Reconnect\./ );
	assert.doesNotMatch( copied, /must-not-copy|refresh_token/ );
	assert.ok( copied.length <= 5000 );
} );

test( 'builds numbered recovery guidance without changing check data', () => {
	const item = checks[ 1 ];
	const guidance = diagnosticGuidanceSteps( item );

	assert.equal( guidance.length, 3 );
	assert.equal( guidance[ 0 ].description, 'Reconnect the assistant.' );
	assert.match( guidance[ 2 ].description, /Secret Storage/ );
	assert.deepEqual( item, checks[ 1 ] );
} );

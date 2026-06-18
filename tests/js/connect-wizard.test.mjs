import test from 'node:test';
import assert from 'node:assert/strict';
import {
	clampWizardStepIndex,
	normalizeConnectionRequests,
	preferredWizardProviderId,
	shouldShowPendingRequests,
	wizardStepsForProvider,
} from '../../src/connect-wizard.mjs';

test( 'prefers ChatGPT for the default assistant wizard selection', () => {
	assert.equal(
		preferredWizardProviderId( [
			{ id: 'claude', label: 'Claude' },
			{ id: 'chatgpt', label: 'ChatGPT' },
			{ id: 'cursor', label: 'Cursor' },
		] ),
		'chatgpt'
	);
} );

test( 'falls back to the first provider when ChatGPT is unavailable', () => {
	assert.equal(
		preferredWizardProviderId( [
			{ id: 'gemini', label: 'Gemini' },
			{ id: 'mcp', label: 'MCP Client' },
		] ),
		'gemini'
	);
	assert.equal( preferredWizardProviderId( [] ), '' );
} );

test( 'uses server wizard steps when providers expose wizard metadata', () => {
	const steps = wizardStepsForProvider( {
		id: 'chatgpt',
		label: 'ChatGPT',
		wizard: {
			steps: [
				{ id: 'open', title: 'Open ChatGPT' },
				{ id: 'add', title: 'Add connector' },
			],
		},
	} );

	assert.deepEqual(
		steps.map( ( step ) => step.title ),
		[ 'Open ChatGPT', 'Add connector' ]
	);
} );

test( 'builds a four-step fallback wizard for providers without metadata', () => {
	const steps = wizardStepsForProvider( {
		id: 'external',
		label: 'External Assistant',
	} );

	assert.equal( steps.length, 4 );
	assert.equal( steps[ 0 ].title, 'Open External Assistant' );
	assert.equal( steps[ 1 ].title, 'Add connector' );
	assert.equal( steps[ 2 ].title, 'Review and approve' );
	assert.equal( steps[ 3 ].title, 'Complete' );
} );

test( 'clamps wizard step indexes to the available step range', () => {
	const provider = {
		label: 'ChatGPT',
		wizard: {
			steps: [
				{ id: 'open', title: 'Open' },
				{ id: 'add', title: 'Add' },
				{ id: 'complete', title: 'Complete' },
			],
		},
	};

	assert.equal( clampWizardStepIndex( provider, -2 ), 0 );
	assert.equal( clampWizardStepIndex( provider, 1 ), 1 );
	assert.equal( clampWizardStepIndex( provider, 12 ), 2 );
	assert.equal( clampWizardStepIndex( provider, 'bad' ), 0 );
} );

test( 'normalizes pending connection request payloads', () => {
	assert.deepEqual( normalizeConnectionRequests( undefined ), {
		items: [],
		pendingCount: 0,
		approvalModeEnabled: false,
	} );

	assert.deepEqual(
		normalizeConnectionRequests( {
			approvalMode: 'admin_review',
			approvalModeEnabled: true,
			items: [ { id: 'request-1' } ],
		} ),
		{
			approvalMode: 'admin_review',
			approvalModeEnabled: true,
			items: [ { id: 'request-1' } ],
			pendingCount: 1,
		}
	);
} );

test( 'hides pending requests until enabled or real requests exist', () => {
	assert.equal(
		shouldShowPendingRequests( {
			approvalModeEnabled: false,
			pendingCount: 0,
			items: [],
		} ),
		false
	);
	assert.equal(
		shouldShowPendingRequests( {
			approvalModeEnabled: true,
			pendingCount: 0,
			items: [],
		} ),
		true
	);
	assert.equal(
		shouldShowPendingRequests( {
			approvalModeEnabled: false,
			pendingCount: 1,
			items: [],
		} ),
		true
	);
} );

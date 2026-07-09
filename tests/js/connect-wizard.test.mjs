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
		queueAvailable: false,
		refreshUrl: '',
		status: 'disabled',
	} );

	assert.deepEqual(
		normalizeConnectionRequests( {
			approvalMode: 'admin_review',
			approvalModeEnabled: true,
			queueAvailable: true,
			items: [
				{ id: 'request-1', reviewUrl: 'https://example.com/review-1' },
				{ id: 'request-2' },
			],
		} ),
		{
			approvalMode: 'admin_review',
			approvalModeEnabled: true,
			queueAvailable: true,
			items: [
				{ id: 'request-1', reviewUrl: 'https://example.com/review-1' },
			],
			pendingCount: 1,
			refreshUrl: '',
			status: 'ready',
		}
	);
} );

test( 'hides pending requests when the approval queue is unavailable', () => {
	assert.equal(
		shouldShowPendingRequests( {
			approvalModeEnabled: false,
			queueAvailable: false,
			pendingCount: 0,
			items: [],
		} ),
		false
	);
} );

test( 'hides empty pending requests without a working refresh action', () => {
	assert.equal(
		shouldShowPendingRequests( {
			queueAvailable: true,
			status: 'empty',
			pendingCount: 0,
			items: [],
		} ),
		false
	);
} );

test( 'shows empty pending requests when the queue is live and refreshable', () => {
	assert.equal(
		shouldShowPendingRequests( {
			queueAvailable: true,
			status: 'empty',
			refreshUrl: 'https://example.com/refresh',
			pendingCount: 0,
			items: [],
		} ),
		true
	);
} );

test( 'shows loading and error states only when the queue has a working refresh action', () => {
	assert.equal(
		shouldShowPendingRequests( {
			queueAvailable: true,
			status: 'loading',
			refreshUrl: 'https://example.com/refresh',
			items: [],
		} ),
		true
	);

	assert.equal(
		shouldShowPendingRequests( {
			queueAvailable: true,
			status: 'error',
			refreshUrl: 'https://example.com/refresh',
			error: 'Request queue timed out.',
			items: [],
		} ),
		true
	);

	assert.equal(
		shouldShowPendingRequests( {
			queueAvailable: true,
			status: 'error',
			error: 'Request queue timed out.',
			items: [],
		} ),
		false
	);
} );

test( 'shows populated pending requests only when review actions exist', () => {
	assert.equal(
		shouldShowPendingRequests( {
			queueAvailable: true,
			status: 'ready',
			items: [ { id: 'request-1' } ],
			pendingCount: 1,
		} ),
		false
	);

	assert.equal(
		shouldShowPendingRequests( {
			queueAvailable: true,
			status: 'ready',
			items: [
				{
					id: 'request-1',
					reviewUrl: 'https://example.com/review-1',
				},
			],
			pendingCount: 1,
		} ),
		true
	);
} );

import test from 'node:test';
import assert from 'node:assert/strict';
import {
	clampWizardStepIndex,
	connectWizardCompletionState,
	connectWizardRecoveryStepIndex,
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

test( 'uses the approval step as the recovery target when available', () => {
	assert.equal(
		connectWizardRecoveryStepIndex( {
			wizard: {
				steps: [
					{ id: 'open' },
					{ id: 'add' },
					{ id: 'approve' },
					{ id: 'complete' },
				],
			},
		} ),
		2
	);
} );

test( 'does not report success when no verified active session exists', () => {
	const state = connectWizardCompletionState(
		{ id: 'chatgpt', label: 'ChatGPT' },
		[],
		{},
		Date.parse( '2026-07-10T10:00:00Z' )
	);

	assert.equal( state.key, 'idle' );
	assert.equal( state.tone, 'warning' );
	assert.match(
		state.description,
		/No verified ChatGPT session is active\./
	);
} );

test( 'surfaces pending authorization with a recovery action', () => {
	const state = connectWizardCompletionState(
		{ id: 'chatgpt', label: 'ChatGPT' },
		[],
		{
			items: [
				{
					id: 'request-1',
					provider: 'chatgpt',
					status: 'pending',
					requestedAt: '2026-07-10 09:30:00',
				},
			],
		},
		Date.parse( '2026-07-10T10:00:00Z' )
	);

	assert.equal( state.key, 'pending' );
	assert.equal( state.actionLabel, 'Review approval step' );
	assert.deepEqual( state.details[ 0 ], {
		label: 'Status',
		value: 'Awaiting approval',
	} );
} );

test( 'surfaces failed authorization with retry guidance', () => {
	const state = connectWizardCompletionState(
		{ id: 'claude', label: 'Claude' },
		[],
		{
			items: [
				{
					id: 'request-2',
					provider: 'claude',
					status: 'failed',
					updatedAt: '2026-07-10 09:40:00',
					message: 'OAuth callback did not complete.',
				},
			],
		},
		Date.parse( '2026-07-10T10:00:00Z' )
	);

	assert.equal( state.key, 'failed' );
	assert.equal( state.actionLabel, 'Retry authorization' );
	assert.equal( state.details[ 1 ].value, '2026-07-10 09:40:00' );
} );

test( 'treats expired authorization payloads as not connected', () => {
	const state = connectWizardCompletionState(
		{ id: 'codex', label: 'Codex' },
		[],
		{
			items: [
				{
					id: 'request-3',
					provider: 'codex',
					status: 'expired',
				},
			],
		},
		Date.parse( '2026-07-10T10:00:00Z' )
	);

	assert.equal( state.key, 'expired' );
	assert.equal( state.actionLabel, 'Reconnect assistant' );
} );

test( 'reports matching verified sessions with the last verified activity', () => {
	const state = connectWizardCompletionState(
		{ id: 'chatgpt', label: 'ChatGPT' },
		[
			{
				id: 12,
				provider: 'chatgpt',
				client_name: 'Editorial Copilot',
				status: 'active',
				last_used_at: '2026-07-10 09:55:00',
				expires_at: '2026-07-11 09:55:00',
			},
			{
				id: 10,
				provider: 'claude',
				client_name: 'Other Assistant',
				status: 'active',
				last_used_at: '2026-07-10 09:59:00',
				expires_at: '2026-07-11 09:59:00',
			},
		],
		{},
		Date.parse( '2026-07-10T10:00:00Z' )
	);

	assert.equal( state.key, 'active' );
	assert.equal( state.session.client_name, 'Editorial Copilot' );
	assert.deepEqual( state.details[ 2 ], {
		label: 'Last verified activity',
		value: '2026-07-10 09:55:00',
	} );
} );

test( 'ignores expired sessions so stale payloads cannot keep success visible', () => {
	const state = connectWizardCompletionState(
		{ id: 'chatgpt', label: 'ChatGPT' },
		[
			{
				id: 22,
				provider: 'chatgpt',
				client_name: 'Stale Session',
				status: 'active',
				last_used_at: '2026-07-10 08:00:00',
				expires_at: '2026-07-10 08:30:00',
			},
		],
		{},
		Date.parse( '2026-07-10T10:00:00Z' )
	);

	assert.equal( state.key, 'idle' );
} );

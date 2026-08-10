import test from 'node:test';
import assert from 'node:assert/strict';
import {
	clampWizardStepIndex,
	connectAppOptionForProvider,
	connectWizardCompletionStep,
	connectWizardCompletionState,
	connectWizardRecoveryStepIndex,
	normalizeConnectionRequests,
	preferredWizardProviderId,
	shouldShowPendingRequests,
	wizardStepsForProvider,
} from '../../src/connect-wizard.mjs';

test( 'uses the stable generic app option for unknown providers', () => {
	const options = [
		{ id: 'chatgpt', providerId: 'chatgpt' },
		{ id: 'claude', providerId: 'claude' },
		{ id: 'grok', providerId: 'grok' },
		{ id: 'other', providerId: 'mcp' },
	];

	assert.equal(
		connectAppOptionForProvider( 'external', options )?.id,
		'other'
	);
	assert.equal( connectAppOptionForProvider( 'grok', options )?.id, 'grok' );
} );

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

test( 'hides pending requests when approval mode is disabled or the queue is unavailable', () => {
	assert.equal(
		shouldShowPendingRequests( {
			approvalModeEnabled: false,
			queueAvailable: true,
			status: 'ready',
			items: [
				{
					id: 'request-1',
					reviewUrl: 'https://example.com/review-1',
				},
			],
		} ),
		false
	);

	assert.equal(
		shouldShowPendingRequests( {
			approvalModeEnabled: true,
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
			approvalModeEnabled: true,
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
			approvalModeEnabled: true,
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
			approvalModeEnabled: true,
			queueAvailable: true,
			status: 'loading',
			refreshUrl: 'https://example.com/refresh',
			items: [],
		} ),
		true
	);

	assert.equal(
		shouldShowPendingRequests( {
			approvalModeEnabled: true,
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
			approvalModeEnabled: true,
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
			approvalModeEnabled: true,
			queueAvailable: true,
			status: 'ready',
			items: [ { id: 'request-1' } ],
			pendingCount: 1,
		} ),
		false
	);

	assert.equal(
		shouldShowPendingRequests( {
			approvalModeEnabled: true,
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

	const step = connectWizardCompletionStep(
		{
			title: 'Complete',
			subtitle: 'Your AI assistant is connected and ready to use.',
			instructions: [ { title: 'Connection active' } ],
		},
		state
	);

	assert.equal( step.title, 'Authorization pending' );
	assert.match( step.subtitle, /Waiting for a verified ChatGPT session\./ );
	assert.doesNotMatch( step.subtitle, /connected and ready|active/i );
	assert.equal( step.instructions[ 0 ].title, 'Approval still required' );
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

	const completeStep = {
		title: 'Complete',
		subtitle: 'Your AI assistant is connected and ready to use.',
		instructions: [ { title: 'Connection active' } ],
	};

	assert.strictEqual(
		connectWizardCompletionStep( completeStep, state ),
		completeStep
	);
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

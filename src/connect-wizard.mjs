const FALLBACK_STEP_IDS = [ 'open', 'add', 'approve', 'complete' ];
const PENDING_REQUEST_STATUSES = new Set( [
	'pending',
	'requested',
	'awaiting_approval',
	'awaiting_authorization',
	'authorizing',
] );
const FAILED_REQUEST_STATUSES = new Set( [ 'failed', 'error', 'denied' ] );
const EXPIRED_REQUEST_STATUSES = new Set( [
	'expired',
	'revoked',
	'timed_out',
] );

export function preferredWizardProviderId( providers ) {
	const items = Array.isArray( providers ) ? providers : [];

	return (
		items.find( ( provider ) => provider.id === 'chatgpt' )?.id ||
		items[ 0 ]?.id ||
		''
	);
}

export function wizardStepsForProvider( provider ) {
	const wizard =
		provider?.wizard && typeof provider.wizard === 'object'
			? provider.wizard
			: {};
	const steps = Array.isArray( wizard.steps ) ? wizard.steps : [];

	if ( steps.length > 0 ) {
		return steps;
	}

	return FALLBACK_STEP_IDS.map( ( id ) => ( {
		id,
		title:
			id === 'open'
				? `Open ${ provider?.label || 'assistant' }`
				: fallbackStepTitle( id ),
		subtitle: fallbackStepSubtitle( id, provider?.label || 'assistant' ),
		description: fallbackStepDescription(
			id,
			provider?.label || 'assistant'
		),
		instructions: [],
	} ) );
}

export function clampWizardStepIndex( provider, index ) {
	const steps = wizardStepsForProvider( provider );
	const numericIndex = Number.isFinite( Number( index ) )
		? Number( index )
		: 0;

	return Math.min(
		Math.max( 0, numericIndex ),
		Math.max( 0, steps.length - 1 )
	);
}

export function normalizeConnectionRequests( requests ) {
	const payload = requests && typeof requests === 'object' ? requests : {};
	const items = connectionRequestItems( payload );
	const reviewableItems = items.filter(
		( item ) => item && typeof item.reviewUrl === 'string' && item.reviewUrl
	);
	const refreshUrl =
		typeof payload.refreshUrl === 'string' && payload.refreshUrl
			? payload.refreshUrl
			: '';
	const queueAvailable = Boolean( payload.queueAvailable );
	const status =
		typeof payload.status === 'string' && payload.status
			? payload.status
			: deriveConnectionRequestStatus(
					queueAvailable,
					reviewableItems.length,
					payload
			  );

	return {
		...payload,
		items: reviewableItems,
		pendingCount: Number(
			payload.pendingCount || reviewableItems.length || 0
		),
		approvalModeEnabled: Boolean( payload.approvalModeEnabled ),
		queueAvailable,
		refreshUrl,
		status,
	};
}

export function shouldShowPendingRequests( requests ) {
	const normalized = normalizeConnectionRequests( requests );

	if ( ! normalized.approvalModeEnabled || ! normalized.queueAvailable ) {
		return false;
	}

	if ( normalized.status === 'ready' ) {
		return normalized.items.length > 0;
	}

	return (
		[ 'empty', 'loading', 'error' ].includes( normalized.status ) &&
		Boolean( normalized.refreshUrl )
	);
}

function deriveConnectionRequestStatus( queueAvailable, itemCount, payload ) {
	if ( ! queueAvailable ) {
		return 'disabled';
	}

	if ( payload.loading ) {
		return 'loading';
	}

	if ( payload.error ) {
		return 'error';
	}

	if ( itemCount > 0 || Number( payload.pendingCount || 0 ) > 0 ) {
		return 'ready';
	}

	return 'empty';
}

export function connectWizardRecoveryStepIndex( provider ) {
	const steps = wizardStepsForProvider( provider );
	const approveStepIndex = steps.findIndex(
		( step ) => step.id === 'approve'
	);

	if ( approveStepIndex >= 0 ) {
		return approveStepIndex;
	}

	return Math.max( 0, steps.length - 2 );
}

export function connectWizardCompletionState(
	provider,
	sessions,
	requests,
	now = Date.now()
) {
	const normalizedProvider = normalizeProviderKey( provider );
	const matchingSessions = normalizedSessions( sessions ).filter(
		( session ) => sessionMatchesProvider( session, normalizedProvider )
	);
	const verifiedSession = matchingSessions
		.filter( ( session ) => sessionIsVerified( session, now ) )
		.sort( compareSessionsByRecency )[ 0 ];

	if ( verifiedSession ) {
		return {
			key: 'active',
			tone: 'success',
			title: 'Connected and verified',
			description: `A verified ${ providerLabel(
				provider
			) } session is active.`,
			actionLabel: '',
			session: verifiedSession,
			details: [
				{
					label: 'Provider',
					value: sessionProviderLabel( verifiedSession, provider ),
				},
				{
					label: 'Session',
					value: verifiedSession.client_name || 'AI assistant',
				},
				{
					label: 'Last verified activity',
					value: sessionLastVerifiedLabel( verifiedSession ),
				},
			],
		};
	}

	const latestRequest = latestMatchingRequest( provider, requests );
	if ( latestRequest ) {
		const status = normalizeRequestStatus( latestRequest.status );
		if ( EXPIRED_REQUEST_STATUSES.has( status ) ) {
			return {
				key: 'expired',
				tone: 'warning',
				title: 'Authorization expired',
				description: `The previous ${ providerLabel(
					provider
				) } authorization is no longer valid.`,
				actionLabel: 'Reconnect assistant',
				session: null,
				details: requestDetails( latestRequest ),
			};
		}

		if ( FAILED_REQUEST_STATUSES.has( status ) ) {
			return {
				key: 'failed',
				tone: 'error',
				title: 'Authorization failed',
				description: `A verified ${ providerLabel(
					provider
				) } session was not created.`,
				actionLabel: 'Retry authorization',
				session: null,
				details: requestDetails( latestRequest ),
			};
		}

		if ( PENDING_REQUEST_STATUSES.has( status ) ) {
			return {
				key: 'pending',
				tone: 'warning',
				title: 'Authorization pending',
				description: `Waiting for a verified ${ providerLabel(
					provider
				) } session.`,
				actionLabel: 'Review approval step',
				session: null,
				details: requestDetails( latestRequest ),
			};
		}
	}

	return {
		key: 'idle',
		tone: 'warning',
		title: 'Connection not verified yet',
		description: `No verified ${ providerLabel(
			provider
		) } session is active.`,
		actionLabel: 'Review approval step',
		session: null,
		details: [
			{
				label: 'Status',
				value: 'Waiting for an active session after authorization',
			},
		],
	};
}

/**
 * Keep the final wizard task truthful when provider metadata is optimistic.
 * Provider metadata describes the normal flow, while completion state reflects
 * the current verified OAuth session for the selected provider.
 *
 * @param {Object} step Provider-supplied completion step.
 * @param {Object} completionState Current provider completion state.
 * @return {Object} Step safe to render for the current connection state.
 */
export function connectWizardCompletionStep( step, completionState ) {
	if ( ! completionState || completionState.key === 'active' ) {
		return step;
	}

	const pending = completionState.key === 'pending';

	return {
		...step,
		title: pending ? 'Authorization pending' : 'Verify connection',
		subtitle: completionState.description,
		description: pending
			? 'Complete the pending authorization in WordPress, then return here to confirm the verified session.'
			: 'Complete authorization in WordPress, then return here to confirm the verified session.',
		instructions: [
			{
				title: pending
					? 'Approval still required'
					: 'Verified session required',
				description:
					'Connection status updates after a matching verified session is active.',
			},
		],
	};
}

function fallbackStepTitle( id ) {
	return {
		add: 'Add connector',
		approve: 'Review and approve',
		complete: 'Complete',
	}[ id ];
}

function fallbackStepSubtitle( id, label ) {
	return {
		open: `Open ${ label } and find its MCP settings.`,
		add: 'Add Aculect AI Companion as a remote MCP server.',
		approve: 'Authorize the connection securely in WordPress.',
		complete: 'Your AI assistant is connected and ready to use.',
	}[ id ];
}

function fallbackStepDescription( id, label ) {
	return {
		open: `Start in ${ label } and choose the custom MCP connection flow.`,
		add: 'Use the Aculect connection URL when the assistant asks for the server URL.',
		approve:
			'The assistant will redirect you to WordPress to review and approve the connection request.',
		complete:
			'Return to your assistant and confirm the Aculect tools are available.',
	}[ id ];
}

function normalizeProviderKey( provider ) {
	if ( typeof provider === 'string' ) {
		return provider.trim().toLowerCase();
	}

	return String( provider?.provider || provider?.id || '' )
		.trim()
		.toLowerCase();
}

function providerLabel( provider ) {
	if ( typeof provider === 'string' ) {
		return provider || 'assistant';
	}

	return provider?.label || provider?.id || 'assistant';
}

function normalizedSessions( sessions ) {
	const items = Array.isArray( sessions ) ? sessions : [];

	return items.filter( Boolean ).map( ( session ) => ( {
		...session,
		provider: normalizeProviderKey( session ),
		status: String( session?.status || 'active' )
			.trim()
			.toLowerCase(),
	} ) );
}

function sessionMatchesProvider( session, provider ) {
	return provider && session.provider === provider;
}

function sessionIsVerified( session, now ) {
	if ( session.status !== 'active' ) {
		return false;
	}

	const expiresAt = timestampValue( session.expires_at );

	return expiresAt === 0 || expiresAt > now;
}

function compareSessionsByRecency( left, right ) {
	return sessionRecency( right ) - sessionRecency( left );
}

function sessionRecency( session ) {
	return Math.max(
		timestampValue( session.last_used_at ),
		timestampValue( session.created_at )
	);
}

function sessionProviderLabel( session, provider ) {
	return providerLabel( {
		id: session.provider,
		label: provider?.label || session.provider,
	} );
}

function sessionLastVerifiedLabel( session ) {
	return (
		session.last_used_at ||
		session.created_at ||
		session.expires_at ||
		'Unknown'
	);
}

function latestMatchingRequest( provider, requests ) {
	const normalizedProvider = normalizeProviderKey( provider );
	const payload = requests && typeof requests === 'object' ? requests : {};
	const items = connectionRequestItems( payload )
		.filter( Boolean )
		.filter( ( item ) =>
			requestMatchesProvider( item, normalizedProvider )
		)
		.sort( compareRequestsByRecency );

	return items[ 0 ] || null;
}

function requestMatchesProvider( request, normalizedProvider ) {
	if ( ! normalizedProvider ) {
		return false;
	}

	return (
		normalizeProviderKey( request.provider || request.providerId ) ===
		normalizedProvider
	);
}

function connectionRequestItems( payload ) {
	return Array.isArray( payload.items ) ? payload.items : [];
}

function compareRequestsByRecency( left, right ) {
	return requestRecency( right ) - requestRecency( left );
}

function requestRecency( request ) {
	return Math.max(
		timestampValue( request.updatedAt ),
		timestampValue( request.requestedAt ),
		timestampValue( request.createdAt )
	);
}

function normalizeRequestStatus( status ) {
	return String( status || 'pending' )
		.trim()
		.toLowerCase();
}

function requestDetails( request ) {
	const details = [
		{
			label: 'Status',
			value: requestStatusLabel( request.status ),
		},
	];

	const lastActivity =
		request.updatedAt || request.requestedAt || request.createdAt || '';
	if ( lastActivity ) {
		details.push( {
			label: 'Last update',
			value: lastActivity,
		} );
	}

	if ( request.message ) {
		details.push( {
			label: 'Details',
			value: request.message,
		} );
	}

	return details;
}

function requestStatusLabel( status ) {
	const normalizedStatus = normalizeRequestStatus( status );

	return (
		{
			pending: 'Awaiting approval',
			requested: 'Awaiting approval',
			awaiting_approval: 'Awaiting approval',
			awaiting_authorization: 'Awaiting authorization',
			authorizing: 'Authorizing',
			failed: 'Authorization failed',
			error: 'Authorization failed',
			denied: 'Authorization denied',
			expired: 'Authorization expired',
			revoked: 'Authorization revoked',
			timed_out: 'Authorization timed out',
		}[ normalizedStatus ] || normalizedStatus
	);
}

function timestampValue( value ) {
	const timestamp = Date.parse(
		String( value || '' )
			.trim()
			.replace( ' ', 'T' )
	);

	return Number.isFinite( timestamp ) ? timestamp : 0;
}

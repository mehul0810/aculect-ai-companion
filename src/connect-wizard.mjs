const FALLBACK_STEP_IDS = [ 'open', 'add', 'approve', 'complete' ];

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
	const items = Array.isArray( payload.items ) ? payload.items : [];
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

	if ( ! normalized.queueAvailable ) {
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

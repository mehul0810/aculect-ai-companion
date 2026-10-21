( ( scope ) => {
	const doc = scope.document;
	const contract = scope.AculectPostUpdateContract;
	const bridge = scope.AculectWidgetBridge.createBridge( {
		windowRef: scope,
		appInfo: { name: 'Aculect Post-Update Result', version: '1.0.0' },
		readOnlyTools: [],
		onEvent: receive,
	} );
	const elements = {
		status: doc.getElementById( 'status' ),
		message: doc.getElementById( 'message' ),
		content: doc.getElementById( 'content' ),
		title: doc.getElementById( 'title' ),
		type: doc.getElementById( 'content-type' ),
		postStatus: doc.getElementById( 'post-status' ),
		completed: doc.getElementById( 'completed' ),
		revision: doc.getElementById( 'revision' ),
		actions: doc.getElementById( 'actions' ),
		undo: doc.getElementById( 'undo' ),
		undoHint: doc.getElementById( 'undo-hint' ),
		approval: doc.getElementById( 'approval' ),
		approvalRisk: doc.getElementById( 'approval-risk' ),
		approvalTarget: doc.getElementById( 'approval-target' ),
		approvalChanges: doc.getElementById( 'approval-changes' ),
		approvalExpiry: doc.getElementById( 'approval-expiry' ),
	};
	const descriptions = {
		loading: 'Waiting for the update result.',
		success: 'Update completed. Check WordPress status before sharing.',
		partial:
			'The update may have completed, but details are missing. Check WordPress before changing it again.',
		stale: 'This result is out of date. Check WordPress before changing the content again.',
		unavailable:
			'Updated content is unavailable or your access has changed.',
		error: 'This result could not be displayed. Check WordPress before retrying.',
		approval_pending:
			'Waiting for a WordPress decision; no update has run.',
		cancelled: 'Cancelled. Check WordPress before retrying.',
		disabled: 'This view is unavailable. Use the host text result.',
	};
	let result = { outcome: 'loading' };
	let context = {};
	let linkBusy = false;
	let linkError = '';

	function render() {
		const outcome = result.outcome;
		doc.documentElement.dataset.theme = [ 'light', 'dark' ].includes(
			context.theme
		)
			? context.theme
			: 'auto';
		elements.status.dataset.state = outcome;
		elements.status.textContent =
			outcome === 'success' ? 'Updated' : outcome.replace( /-/g, ' ' );
		elements.message.textContent =
			linkError || descriptions[ outcome ] || descriptions.error;
		const isApproval = outcome === 'approval_pending';
		const hasContent =
			! isApproval && ( outcome === 'success' || !! result.title );
		elements.content.hidden = ! hasContent;
		elements.approval.hidden = ! isApproval;
		elements.approvalRisk.textContent = isApproval
			? `Risk: ${ result.riskLevel || 'update' }${
					result.riskCategories.length
						? ` (${ result.riskCategories.join( ', ' ) })`
						: ''
			  }`
			: '';
		elements.approvalTarget.textContent = isApproval
			? `Target: ${ result.target }`
			: '';
		elements.approvalExpiry.textContent = isApproval
			? `Expires: ${ new Date(
					result.approvalExpiresAt
			  ).toLocaleString() }`
			: '';
		elements.approvalChanges.textContent = isApproval
			? result.changedFields
			: '';
		elements.title.textContent = hasContent
			? result.title || 'Untitled'
			: '';
		const fields = {
			type: result.contentType,
			postStatus: result.status,
			revision: result.revision,
		};
		for ( const [ field, value ] of Object.entries( fields ) ) {
			elements[ field ].textContent = value || 'Not available';
		}
		elements.completed.dateTime = result.completedAt || '';
		elements.completed.textContent = result.completedAt
			? new Date( result.completedAt ).toLocaleString( undefined, {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
					hour: 'numeric',
					minute: '2-digit',
					timeZoneName: 'short',
			  } )
			: 'Not available';
		elements.actions.hidden = ! Object.values( result.links || {} ).some(
			Boolean
		);
		for ( const button of elements.actions.querySelectorAll( 'button' ) ) {
			button.hidden = ! result.links?.[ button.dataset.link ];
			button.disabled = linkBusy || ! bridge.isConnected();
		}
		elements.undo.hidden = ! hasContent;
		elements.undoHint.textContent = result.undo
			? 'Open the prior revision in WordPress; opening it does not restore anything. Recheck current content first. Native Restore can change revisioned metadata, not status, terms, or featured images. Aculect content-only recovery needs fresh comparison and separate confirmation. This card changes nothing.'
			: 'A verified prior-revision handoff is unavailable. Review current content and revisions in WordPress first.';
	}

	function receive( event ) {
		if ( event.type === 'ready' || event.type === 'host-context' ) {
			context = { ...context, ...event.context };
			render();
			return;
		}
		const outcome = {
			'tool-input': 'loading',
			'tool-input-partial': 'loading',
			'tool-cancelled': 'cancelled',
			closed: 'disabled',
		}[ event.type ];
		if ( ! outcome && event.type !== 'tool-result' ) {
			return;
		}
		linkBusy = false;
		linkError = '';
		result = outcome ? { outcome } : contract.parse( event.params );
		render();
	}

	async function open( button ) {
		if ( linkBusy || ! bridge.isConnected() ) {
			return;
		}
		const url = result.links?.[ button.dataset.link ];
		if ( ! url ) {
			return;
		}
		linkBusy = true;
		const openedForResult = result;
		linkError = '';
		render();
		try {
			await bridge.openLink( url );
		} catch {
			if ( openedForResult === result ) {
				linkError =
					'The host could not open that link. Use the text result or WordPress directly.';
			}
		} finally {
			if ( openedForResult === result ) {
				linkBusy = false;
				render();
			}
		}
	}

	elements.actions.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( 'button[data-link]' );
		if ( button ) {
			open( button );
		}
	} );
	// Test fixture only.
	scope.AculectPostUpdateFixture = Object.freeze( {
		showResult( value ) {
			result = contract.parse( value );
			linkBusy = false;
			linkError = '';
			render();
		},
	} );
	render();
	bridge.start().catch( () => {
		result = { outcome: 'disabled' };
		render();
	} );
} )( globalThis );

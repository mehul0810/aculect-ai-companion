( ( scope ) => {
	const SCHEMA = 'aculect.post-update.v1';
	const OUTCOMES = new Set( [
		'success',
		'partial',
		'stale',
		'unavailable',
		'error',
		'approval_pending',
	] );
	const isRecord = ( value ) =>
		value !== null && typeof value === 'object' && ! Array.isArray( value );
	const bounded = ( value, maximum = 160 ) =>
		typeof value === 'string' ? value.trim().slice( 0, maximum ) : '';

	function safeLink( value ) {
		if ( typeof value !== 'string' || value.length > 2048 ) {
			return '';
		}
		try {
			const url = new URL( value );
			return url.protocol === 'https:' && ! url.username && ! url.password
				? url.href
				: '';
		} catch {
			return '';
		}
	}

	function parse( result ) {
		if ( ! isRecord( result ) || result.isError ) {
			return { outcome: 'error' };
		}
		const data = result.structuredContent;
		if ( ! isRecord( data ) || data.schema !== SCHEMA ) {
			return { outcome: 'error' };
		}
		const outcome = OUTCOMES.has( data.outcome ) ? data.outcome : 'error';
		const content = isRecord( data.content ) ? data.content : {};
		const links = isRecord( data.links ) ? data.links : {};
		const timestamp = bounded( content.completed_at, 40 );
		const completedAt =
			/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(
				timestamp
			) && ! Number.isNaN( Date.parse( timestamp ) )
				? timestamp
				: '';
		const projection = {
			outcome,
			title: bounded( content.title, 300 ),
			contentType: bounded( content.type, 80 ),
			status: bounded( content.status, 80 ),
			completedAt,
			revision: bounded( content.revision, 120 ),
			links: {
				view: safeLink( links.view ),
				edit: safeLink( links.edit ),
				compare: safeLink( links.compare ),
				undo: safeLink( links.undo ),
			},
			undo:
				isRecord( data.undo ) &&
				data.undo.available === true &&
				data.undo.safe_snapshot === true &&
				data.undo.reason === 'wordpress_revision_review' &&
				Number.isSafeInteger( data.undo.post_id ) &&
				data.undo.post_id > 0 &&
				Number.isSafeInteger( data.undo.revision_id ) &&
				data.undo.revision_id > 0,
			riskLevel: bounded( data.risk_level, 40 ),
			riskCategories: Array.isArray( data.risk_categories )
				? data.risk_categories
						.filter( ( value ) => typeof value === 'string' )
						.slice( 0, 8 )
						.map( ( value ) => bounded( value, 40 ) )
				: [],
			target: bounded( data.approval_target, 300 ),
			approvalExpiresAt: '',
			changedFields: '',
		};
		const approval = isRecord( data.approval ) ? data.approval : {};
		const expiry = bounded( approval.expires_at, 40 );
		projection.approvalExpiresAt =
			/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test( expiry ) &&
			! Number.isNaN( Date.parse( expiry ) )
				? expiry
				: '';
		projection.links.approval_queue = safeLink( approval.queue );
		projection.changedFields = Array.isArray( data.changes )
			? data.changes
					.slice( 0, 20 )
					.filter( isRecord )
					.map( ( change ) => bounded( change.label, 100 ) )
					.filter( Boolean )
					.join( ', ' )
			: '';
		if (
			outcome === 'approval_pending' &&
			( ! projection.approvalExpiresAt ||
				! projection.links.approval_queue ||
				! projection.target ||
				! projection.changedFields )
		) {
			projection.outcome = 'error';
		}
		if (
			outcome === 'success' &&
			( ! projection.title ||
				! projection.contentType ||
				! projection.status ||
				! projection.completedAt )
		) {
			projection.outcome = 'partial';
		}
		if ( [ 'unavailable', 'error' ].includes( projection.outcome ) ) {
			projection.links = { view: '', edit: '', compare: '', undo: '' };
		}
		projection.undo =
			projection.outcome === 'success' &&
			projection.undo &&
			Boolean( projection.links.undo );
		if ( ! projection.undo ) {
			projection.links.undo = '';
		}
		return projection;
	}

	scope.AculectPostUpdateContract = Object.freeze( {
		SCHEMA,
		parse,
		safeLink,
	} );
} )( globalThis );

( ( scope ) => {
	const doc = scope.document;
	const status = doc.getElementById( 'status' );
	const findings = doc.getElementById( 'findings' );
	const actions = doc.getElementById( 'next-actions' );
	const record = ( value ) =>
		value && typeof value === 'object' && ! Array.isArray( value );
	const text = ( value ) => {
		if ( typeof value === 'boolean' ) {
			return value ? 'yes' : 'no';
		}
		return [ 'string', 'number' ].includes( typeof value )
			? String( value ).slice( 0, 2000 )
			: 'unknown';
	};
	function add( parent, tag, value ) {
		const node = doc.createElement( tag );
		node.textContent = text( value );
		parent.appendChild( node );
		return node;
	}
	function clear( message ) {
		findings.replaceChildren();
		actions.replaceChildren();
		status.textContent = message;
	}
	function render( params ) {
		clear(
			'The audit result is unavailable. Use the tool’s text response.'
		);
		const data = params?.structuredContent;
		if (
			params?.isError ||
			! record( data ) ||
			data.status !== 'ready' ||
			data.error ||
			data.workflow !== 'site_workflow_audit' ||
			! Array.isArray( data.findings ) ||
			! data.findings.length
		) {
			return;
		}
		status.textContent = `Overall severity: ${ text(
			data.summary?.overall_severity
		) }. These are configuration signals only.`;
		data.findings
			.slice( 0, 20 )
			.filter( record )
			.forEach( ( finding ) => {
				const article = doc.createElement( 'article' );
				const severity = [ 'healthy', 'warning', 'critical' ].includes(
					finding.severity
				)
					? finding.severity
					: 'unknown';
				article.dataset.severity = severity;
				add(
					article,
					'h2',
					`${ text( finding.title ) } — ${ severity }`
				);
				const evidence = doc.createElement( 'dl' );
				Object.entries(
					record( finding.evidence ) ? finding.evidence : {}
				)
					.slice( 0, 20 )
					.forEach( ( [ key, value ] ) => {
						add( evidence, 'dt', key.replaceAll( '_', ' ' ) );
						add( evidence, 'dd', value );
					} );
				article.appendChild( evidence );
				add(
					article,
					'p',
					`Next step: ${ text( finding.recommended_action ) }`
				);
				add(
					article,
					'p',
					`Suggested read tool: ${ text( finding.required_tool ) }`
				);
				findings.appendChild( article );
			} );
		( Array.isArray( data.next_actions ) ? data.next_actions : [] )
			.slice( 0, 20 )
			.filter( record )
			.forEach( ( action ) => {
				add(
					actions,
					'li',
					`${ text( action.action ) } (${ text( action.tool ) })`
				);
			} );
	}
	const bridge = scope.AculectWidgetBridge.createBridge( {
		windowRef: scope,
		appInfo: { name: 'Aculect Site Audit', version: '1.0.0' },
		readOnlyTools: [],
		onEvent( event ) {
			if ( event.type === 'tool-result' ) {
				render( event.params );
			} else if (
				[ 'tool-input', 'tool-input-partial' ].includes( event.type )
			) {
				clear( 'Waiting for the audit result…' );
			} else if (
				[ 'closed', 'tool-cancelled' ].includes( event.type )
			) {
				clear(
					'The audit view is unavailable. Use the tool’s text response.'
				);
			}
		},
	} );
	bridge
		.start()
		.catch( () =>
			clear(
				'This host could not show the audit. Use the tool’s text response.'
			)
		);
} )( globalThis );

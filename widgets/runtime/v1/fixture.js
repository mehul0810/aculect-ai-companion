/* Fixture widget: deterministic, read-only Site Information exercise surface. */
( ( scope ) => {
	const document = scope.document;
	const State = scope.AculectWidgetState;
	const bridge = scope.AculectWidgetBridge.createBridge( {
		windowRef: scope,
		appInfo: { name: 'Aculect Widget Fixture', version: '1.0.0' },
		readOnlyTools: [ 'site_get_info' ],
		onEvent: receive,
	} );
	const elements = {
		status: document.getElementById( 'status' ),
		title: document.getElementById( 'title' ),
		url: document.getElementById( 'site-url' ),
		details: document.getElementById( 'details' ),
		refresh: document.getElementById( 'refresh' ),
		open: document.getElementById( 'open-site' ),
		expand: document.getElementById( 'expand' ),
		share: document.getElementById( 'share' ),
		about: document.getElementById( 'about' ),
		dialog: document.getElementById( 'about-dialog' ),
		closeDialog: document.getElementById( 'close-dialog' ),
	};
	let current = State.state( 'loading' );
	let context = {};
	let busy = false;

	function text( value ) {
		return typeof value === 'string' || typeof value === 'number'
			? String( value ).slice( 0, 500 )
			: '';
	}

	function siteUrl() {
		const value = text( current.data?.home_url );
		try {
			const url = new URL( value );
			return url.protocol === 'https:' ? url.href : '';
		} catch {
			return '';
		}
	}

	function render() {
		const complete = current.kind === 'completed';
		document.documentElement.dataset.theme = [ 'light', 'dark' ].includes(
			context.theme
		)
			? context.theme
			: 'auto';
		document.documentElement.dataset.displayMode =
			context.displayMode === 'fullscreen' ? 'fullscreen' : 'inline';
		elements.status.dataset.state = current.kind;
		elements.status.textContent = current.message;
		elements.details.hidden = ! complete;
		elements.title.textContent = complete
			? text( current.data.name ) || 'Connected site'
			: 'Connected site';
		const url = complete ? siteUrl() : '';
		elements.url.textContent = url;
		elements.url.hidden = ! url;
		const fields = {
			wordpress: current.data?.wordpress?.version,
			theme: current.data?.active_theme?.name,
			locale: current.data?.locale,
			timezone: current.data?.timezone,
		};
		for ( const [ key, value ] of Object.entries( fields ) ) {
			document.querySelector( `[data-field="${ key }"]` ).textContent =
				text( value ) || 'Not available';
		}
		elements.refresh.disabled = ! bridge.isConnected() || busy;
		elements.open.hidden = ! url;
		elements.share.hidden = ! complete;
		elements.expand.hidden =
			! context.availableDisplayModes?.includes( 'fullscreen' ) ||
			context.displayMode === 'fullscreen';
	}

	function receive( event ) {
		switch ( event.type ) {
			case 'ready':
			case 'host-context':
				context = { ...context, ...event.context };
				break;
			case 'tool-input':
				current = State.reduce( current, { type: 'loading' } );
				break;
			case 'tool-result':
				current = State.reduce( current, {
					type: 'result',
					result: event.params,
				} );
				break;
			case 'tool-cancelled':
				current = State.reduce( current, { type: 'cancelled' } );
				break;
			case 'closed':
				current = State.reduce( current, { type: 'disabled' } );
				break;
		}
		render();
	}

	async function refresh() {
		busy = true;
		current = State.reduce( current, { type: 'loading' } );
		render();
		try {
			const result = await bridge.callReadOnlyTool( 'site_get_info' );
			current = State.reduce( current, { type: 'result', result } );
		} catch {
			current = State.reduce( current, { type: 'error' } );
		} finally {
			busy = false;
			render();
		}
	}

	elements.refresh.addEventListener( 'click', refresh );
	elements.open.addEventListener( 'click', async () => {
		try {
			await bridge.openLink( siteUrl() );
		} catch {
			current = State.reduce( current, { type: 'error' } );
			render();
		}
	} );
	elements.expand.addEventListener( 'click', async () => {
		try {
			await bridge.requestDisplayMode( 'fullscreen' );
		} catch {
			current = State.reduce( current, { type: 'error' } );
			render();
		}
	} );
	elements.share.addEventListener( 'click', async () => {
		try {
			await bridge.updateModelContext( {
				structuredContent: { site_name: text( current.data?.name ) },
			} );
			elements.status.textContent =
				'Site name shared with the assistant.';
		} catch {
			current = State.reduce( current, { type: 'error' } );
			render();
		}
	} );
	elements.about.addEventListener( 'click', () => {
		elements.dialog.showModal();
		elements.closeDialog.focus();
	} );
	elements.closeDialog.addEventListener( 'click', () =>
		elements.dialog.close()
	);

	// Fixture-only deterministic state mounting for browser and visual regression tests.
	scope.AculectWidgetFixture = Object.freeze( {
		showState( kind, data = null ) {
			current = State.state( kind, data );
			render();
		},
	} );
	render();
	bridge.start().catch( () => {
		current = State.reduce( current, { type: 'disabled' } );
		render();
	} );
} )( globalThis );

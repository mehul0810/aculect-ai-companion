( ( scope ) => {
	const button = scope.document.getElementById( 'open' );
	const status = scope.document.getElementById( 'status' );
	const address = scope.document.getElementById( 'upload-address' );
	const manual = scope.document.getElementById( 'manual-handoff' );
	const guidance =
		'Copy the WordPress upload address below into your browser. No file was uploaded.';
	let url = '';
	let opening = false;

	try {
		const value = JSON.parse(
			scope.document.getElementById( 'handoff-url' ).textContent
		);
		const parsed = typeof value === 'string' ? new URL( value ) : null;
		if (
			parsed?.protocol === 'https:' &&
			! parsed.username &&
			! parsed.password
		) {
			url = parsed.href;
			address.value = url;
			manual.hidden = false;
		}
	} catch {
		// An invalid packaged address cannot offer either navigation path.
	}
	if ( ! url ) {
		status.textContent =
			'A secure WordPress upload address is unavailable. No file was uploaded.';
	}

	const bridge = scope.AculectWidgetBridge.createBridge( {
		windowRef: scope,
		appInfo: { name: 'Aculect Image Upload Handoff', version: '1.0.0' },
		readOnlyTools: [],
		onEvent( event ) {
			if ( event.type === 'ready' || event.type === 'host-context' ) {
				const theme = event.context?.theme;
				scope.document.documentElement.dataset.theme = [
					'light',
					'dark',
				].includes( theme )
					? theme
					: 'auto';
				button.disabled = ! url || opening || ! bridge.isConnected();
			} else if ( event.type === 'closed' ) {
				button.disabled = true;
				if ( url ) {
					status.textContent = `The host connection closed. ${ guidance }`;
				}
			}
		},
	} );

	button.addEventListener( 'click', async () => {
		if ( ! url || opening || ! bridge.isConnected() ) {
			return;
		}
		opening = true;
		button.disabled = true;
		status.textContent = 'Opening the WordPress upload screen…';
		try {
			await bridge.openLink( url );
			status.textContent =
				'WordPress upload screen opened. Upload there, then return with the attachment ID WordPress shows. No image was uploaded by this widget.';
		} catch {
			status.textContent = `The host could not open the WordPress upload screen. ${ guidance }`;
		} finally {
			opening = false;
			button.disabled = ! url || ! bridge.isConnected();
		}
	} );

	bridge.start().catch( () => {
		button.disabled = true;
		if ( url ) {
			status.textContent = `This host could not start the secure WordPress handoff. ${ guidance }`;
		}
	} );
} )( globalThis );

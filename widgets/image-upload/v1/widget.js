( ( scope ) => {
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
				scope.document.getElementById( 'open' ).disabled = false;
			}
		},
	} );
	const button = scope.document.getElementById( 'open' );
	const status = scope.document.getElementById( 'status' );

	button.addEventListener( 'click', async () => {
		button.disabled = true;
		status.textContent = 'Opening the WordPress upload screen…';
		try {
			const url = JSON.parse(
				scope.document.getElementById( 'handoff-url' ).textContent
			);
			await bridge.openLink( url );
			status.textContent =
				'WordPress upload screen opened. Upload there, then return with the attachment ID WordPress shows. No image was uploaded by this widget.';
		} catch {
			status.textContent =
				'The host could not open the WordPress upload screen. No file was uploaded.';
		} finally {
			button.disabled = ! bridge.isConnected();
		}
	} );

	bridge.start().catch( () => {
		status.textContent =
			'This host could not start the secure WordPress handoff. No file was uploaded.';
	} );
} )( globalThis );

/* MCP Apps bridge. */
( ( scope ) => {
	const PROTOCOL = '2026-01-26';
	const RPC = '2.0';
	const MAX_PENDING = 16;
	const isRecord = ( value ) =>
		value !== null && typeof value === 'object' && ! Array.isArray( value );

	function createBridge( options ) {
		const view = options.windowRef;
		const parent = view.parent;
		const allowedTools = new Set( options.readOnlyTools ?? [] );
		const pending = new Map();
		let nextId = 1;
		let connected = false;
		let closed = false;
		let started = false;
		let hostContext = {};
		const timeoutMs = Math.min(
			30000,
			Math.max( 1000, options.timeoutMs ?? 10000 )
		);

		function notify( method, params = {} ) {
			parent.postMessage( { jsonrpc: RPC, method, params }, '*' );
		}

		function request( method, params ) {
			if ( closed || pending.size >= MAX_PENDING ) {
				return Promise.reject(
					new Error( 'Widget bridge is unavailable.' )
				);
			}
			const id = nextId++;
			return new Promise( ( resolve, reject ) => {
				const timer = view.setTimeout( () => {
					pending.delete( id );
					reject( new Error( 'Host did not respond.' ) );
				}, timeoutMs );
				pending.set( id, { resolve, reject, timer } );
				parent.postMessage( { jsonrpc: RPC, id, method, params }, '*' );
			} );
		}

		function finishResponse( message ) {
			const item = pending.get( message.id );
			if ( ! item ) {
				return;
			}
			pending.delete( message.id );
			view.clearTimeout( item.timer );
			if ( isRecord( message.error ) ) {
				item.reject( new Error( 'The host declined the request.' ) );
			} else if ( Object.hasOwn( message, 'result' ) ) {
				item.resolve( message.result );
			} else {
				item.reject( new Error( 'Invalid host response.' ) );
			}
		}

		function receive( event ) {
			if (
				closed ||
				event.source !== parent ||
				! isRecord( event.data )
			) {
				return;
			}
			const message = event.data;
			if ( message.jsonrpc !== RPC ) {
				return;
			}
			if (
				Number.isSafeInteger( message.id ) &&
				! Object.hasOwn( message, 'method' )
			) {
				finishResponse( message );
				return;
			}
			if ( ! connected || typeof message.method !== 'string' ) {
				return;
			}
			if ( message.method === 'ui/resource-teardown' ) {
				if (
					Number.isSafeInteger( message.id ) ||
					( typeof message.id === 'string' &&
						message.id.length <= 128 )
				) {
					parent.postMessage(
						{ jsonrpc: RPC, id: message.id, result: {} },
						'*'
					);
				}
				close();
				return;
			}
			if (
				message.method === 'ui/notifications/host-context-changed' &&
				isRecord( message.params )
			) {
				hostContext = { ...hostContext, ...message.params };
				options.onEvent( {
					type: 'host-context',
					context: { ...hostContext },
				} );
				return;
			}
			const eventTypes = {
				'ui/notifications/tool-input': 'tool-input',
				'ui/notifications/tool-input-partial': 'tool-input-partial',
				'ui/notifications/tool-result': 'tool-result',
				'ui/notifications/tool-cancelled': 'tool-cancelled',
			};
			if ( Object.hasOwn( eventTypes, message.method ) ) {
				options.onEvent( {
					type: eventTypes[ message.method ],
					params: message.params,
				} );
			}
		}

		function close() {
			if ( closed ) {
				return;
			}
			closed = true;
			connected = false;
			view.removeEventListener( 'message', receive );
			for ( const item of pending.values() ) {
				view.clearTimeout( item.timer );
				item.reject( new Error( 'Widget bridge closed.' ) );
			}
			pending.clear();
			options.onEvent( { type: 'closed' } );
		}

		async function start() {
			if ( started || parent === view ) {
				throw new Error( 'Widget needs a host iframe.' );
			}
			started = true;
			view.addEventListener( 'message', receive );
			try {
				const result = await request( 'ui/initialize', {
					protocolVersion: PROTOCOL,
					appInfo: options.appInfo,
					appCapabilities: {
						availableDisplayModes: [ 'inline', 'fullscreen' ],
					},
				} );
				if (
					! isRecord( result ) ||
					result.protocolVersion !== PROTOCOL
				) {
					throw new Error( 'Unsupported MCP Apps host.' );
				}
				hostContext = isRecord( result.hostContext )
					? result.hostContext
					: {};
				connected = true;
				notify( 'ui/notifications/initialized' );
				options.onEvent( {
					type: 'ready',
					context: { ...hostContext },
				} );
			} catch ( error ) {
				close();
				throw error;
			}
		}

		function callReadOnlyTool( name, args = {} ) {
			if (
				! connected ||
				! allowedTools.has( name ) ||
				! isRecord( args )
			) {
				return Promise.reject(
					new Error( 'Tool call is not permitted by this widget.' )
				);
			}
			return request( 'tools/call', { name, arguments: args } );
		}

		function openLink( url ) {
			let parsed;
			try {
				parsed = new URL( url );
			} catch {
				/* Invalid URL. */
			}
			if ( ! connected || ! parsed || parsed.protocol !== 'https:' ) {
				return Promise.reject(
					new Error( 'Only HTTPS links are supported.' )
				);
			}
			return request( 'ui/open-link', { url: parsed.href } );
		}

		function updateModelContext( content ) {
			if (
				! connected ||
				! isRecord( content ) ||
				JSON.stringify( content ).length > 16384
			) {
				return Promise.reject(
					new Error( 'Invalid model context update.' )
				);
			}
			return request( 'ui/update-model-context', content );
		}

		function requestDisplayMode( mode ) {
			if (
				! connected ||
				! [ 'inline', 'fullscreen' ].includes( mode ) ||
				! hostContext.availableDisplayModes?.includes( mode )
			) {
				return Promise.reject(
					new Error( 'Display mode is unavailable.' )
				);
			}
			return request( 'ui/request-display-mode', { mode } );
		}

		// ChatGPT additions are optional and never part of baseline transport.
		function optionalOpenAI() {
			const api = view.openai;
			return Object.freeze( {
				openExternal:
					typeof api?.openExternal === 'function'
						? ( url ) => api.openExternal( { href: url } )
						: null,
			} );
		}

		return Object.freeze( {
			start,
			close,
			callReadOnlyTool,
			openLink,
			updateModelContext,
			requestDisplayMode,
			optionalOpenAI,
			isConnected: () => connected,
			getHostContext: () => ( { ...hostContext } ),
		} );
	}

	scope.AculectWidgetBridge = Object.freeze( { createBridge, PROTOCOL } );
} )( globalThis );

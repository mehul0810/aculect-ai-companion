/* Ephemeral, host-independent widget state. No storage or WordPress globals. */
( ( scope ) => {
	const kinds = Object.freeze( [
		'loading',
		'empty',
		'error',
		'stale',
		'disabled',
		'approval-pending',
		'completed',
	] );
	const messages = Object.freeze( {
		loading: 'Loading site information…',
		empty: 'No site information is available.',
		error: 'Site information could not be loaded.',
		stale: 'This information may be out of date.',
		disabled: 'This view is unavailable.',
		'approval-pending': 'Waiting for approval in the host.',
		completed: 'Site information loaded.',
	} );

	function state( kind, data = null ) {
		if ( ! kinds.includes( kind ) ) {
			throw new TypeError( 'Unsupported widget state.' );
		}
		return { kind, message: messages[ kind ], data };
	}

	function isRecord( value ) {
		return (
			value !== null &&
			typeof value === 'object' &&
			! Array.isArray( value )
		);
	}

	function resultData( result ) {
		if ( ! isRecord( result ) ) {
			return null;
		}
		if ( isRecord( result.structuredContent ) ) {
			return result.structuredContent;
		}
		if ( ! Array.isArray( result.content ) ) {
			return null;
		}
		const block = result.content.find(
			( item ) =>
				isRecord( item ) &&
				item.type === 'text' &&
				typeof item.text === 'string'
		);
		if ( ! block ) {
			return null;
		}
		try {
			const parsed = JSON.parse( block.text );
			return isRecord( parsed ) ? parsed : null;
		} catch {
			return null;
		}
	}

	function reduce( previous, event ) {
		if ( ! isRecord( event ) ) {
			return previous;
		}
		switch ( event.type ) {
			case 'loading':
				return state( 'loading' );
			case 'result': {
				if ( event.result?.isError ) {
					return state( 'error' );
				}
				const data = resultData( event.result );
				return data && Object.keys( data ).length
					? state( 'completed', data )
					: state( 'empty' );
			}
			case 'cancelled':
			case 'error':
				return state( 'error' );
			case 'stale':
			case 'disabled':
			case 'approval-pending':
			case 'empty':
				return state( event.type, previous?.data ?? null );
			default:
				return previous;
		}
	}

	scope.AculectWidgetState = Object.freeze( {
		kinds,
		state,
		reduce,
		resultData,
	} );
} )( globalThis );

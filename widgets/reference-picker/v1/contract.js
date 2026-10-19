/* Strict, text-only projection of read results for selection. */
( ( scope ) => {
	const record = ( value ) =>
		value !== null && typeof value === 'object' && ! Array.isArray( value );
	const text = ( value, max = 180 ) =>
		typeof value === 'string' ? value.trim().slice( 0, max ) : '';
	const positive = ( value ) => Number.isSafeInteger( value ) && value > 0;
	const type = ( value ) =>
		typeof value === 'string' && /^[a-z][a-z0-9_-]{0,63}$/.test( value )
			? value
			: '';
	const status = ( value ) =>
		typeof value === 'string' && /^[a-z][a-z0-9_-]{0,31}$/.test( value )
			? value
			: '';
	function safeLink( value ) {
		if ( typeof value !== 'string' ) {
			return '';
		}
		try {
			const url = new URL( value );
			return [ 'https:', 'http:' ].includes( url.protocol ) &&
				! url.username &&
				! url.password
				? url.href.slice( 0, 2048 )
				: '';
		} catch {
			return '';
		}
	}
	function parse( result, kind ) {
		if (
			! record( result ) ||
			result.isError ||
			! record( result.structuredContent )
		) {
			return { state: 'error', items: [] };
		}
		const data = result.structuredContent;
		if ( data.error ) {
			return { state: 'error', items: [] };
		}
		if ( [ 'stale', 'unavailable' ].includes( data.status ) ) {
			return { state: data.status, items: [] };
		}
		let raw = null;
		if ( Array.isArray( data.items ) ) {
			raw = data.items;
		} else if ( positive( data.id ) ) {
			raw = [ data ];
		}
		if ( ! raw ) {
			return { state: 'error', items: [] };
		}
		const seen = new Set();
		const items = [];
		for ( const source of raw.slice( 0, 100 ) ) {
			if ( ! record( source ) || ! positive( source.id ) ) {
				continue;
			}
			const itemType = type( source.type );
			if (
				! itemType ||
				( kind === 'media' && itemType !== 'attachment' )
			) {
				continue;
			}
			const key = `${ itemType }:${ source.id }`;
			if ( seen.has( key ) ) {
				continue;
			}
			seen.add( key );
			const thumbnail =
				record( source.thumbnail ) &&
				positive( source.thumbnail.width ) &&
				positive( source.thumbnail.height )
					? {
							url: safeLink( source.thumbnail.url ),
							width: source.thumbnail.width,
							height: source.thumbnail.height,
					  }
					: null;
			items.push( {
				id: source.id,
				type: itemType,
				title: text( source.title ) || `Untitled #${ source.id }`,
				status: status( source.status ),
				stale: source.stale === true,
				link: safeLink( source.link || source.permalink ),
				excerpt: text( source.excerpt || source.summary, 260 ),
				alt: text( source.alt_text, 160 ),
				mime: text( source.mime_type, 80 ),
				family: [ 'image', 'audio', 'video' ].includes(
					text( source.mime_type, 80 ).split( '/' )[ 0 ]
				)
					? text( source.mime_type, 80 ).split( '/' )[ 0 ]
					: 'document',
				thumbnail: thumbnail?.url ? thumbnail : null,
			} );
		}
		return {
			state: 'ready',
			items,
			truncated: raw.length > 100,
			total:
				Number.isSafeInteger( data.total ) && data.total >= 0
					? data.total
					: null,
			hasMore: data.has_more === true,
			page: positive( data.page ) ? data.page : 1,
			perPage: positive( data.per_page ) ? data.per_page : raw.length,
		};
	}
	scope.AculectReferencePickerContract = Object.freeze( { parse } );
} )( globalThis );

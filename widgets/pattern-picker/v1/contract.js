/* Strict projection of server-provided pattern metadata. Never accepts markup. */
( ( scope ) => {
	const SCHEMA = 'aculect.pattern-picker.v1';
	const MAX_ITEMS = 100;
	const ID = /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_./-]{1,159}$/;
	const STATES = new Set( [ 'compatible', 'incompatible', 'unknown' ] );
	const record = ( value ) =>
		value !== null && typeof value === 'object' && ! Array.isArray( value );
	const text = ( value, max = 160 ) =>
		typeof value === 'string' ? value.trim().slice( 0, max ) : '';
	const list = ( value, max = 8 ) =>
		Array.isArray( value )
			? value
					.slice( 0, max )
					.map( ( item ) => text( item, 64 ) )
					.filter( Boolean )
			: [];
	function parse( result ) {
		if ( ! record( result ) || result.isError ) {
			return { state: 'error', items: [] };
		}
		const data = result.structuredContent;
		if ( ! record( data ) || ( data.schema && data.schema !== SCHEMA ) ) {
			return { state: 'error', items: [] };
		}
		if ( [ 'stale', 'unavailable' ].includes( data.status ) ) {
			return { state: data.status, items: [] };
		}
		if ( ! Array.isArray( data.items ) ) {
			return { state: 'error', items: [] };
		}
		const seen = new Set();
		const items = [];
		for ( const item of data.items.slice( 0, MAX_ITEMS ) ) {
			const id = record( item ) ? item.id || item.name : '';
			if ( typeof id !== 'string' || ! ID.test( id ) || seen.has( id ) ) {
				continue;
			}
			seen.add( id );
			const categoryLabels = record( item.category_labels )
				? Object.values( item.category_labels )
				: [];
			const category =
				text( item.category, 80 ) ||
				text( categoryLabels[ 0 ], 80 ) ||
				list( item.categories, 1 )[ 0 ];
			const blocks = list(
				item.supported_blocks || item.content_blocks || item.block_types
			);
			items.push( {
				id,
				title: text( item.title, 180 ) || id,
				category: category || 'Uncategorized',
				source: text( item.source, 48 ) || 'Not specified',
				compatibility: STATES.has( item.compatibility )
					? item.compatibility
					: 'unknown',
				blocks,
				contentType:
					text( item.content_type, 64 ) ||
					list( item.post_types, 1 )[ 0 ] ||
					'',
				message: text( item.compatibility_message, 220 ),
			} );
		}
		return {
			state: 'ready',
			items,
			truncated: data.items.length > MAX_ITEMS,
			total:
				Number.isSafeInteger( data.total ) && data.total >= items.length
					? data.total
					: items.length,
		};
	}
	scope.AculectPatternPickerContract = Object.freeze( {
		SCHEMA,
		MAX_ITEMS,
		parse,
	} );
} )( globalThis );

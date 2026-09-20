export function isPlainObject( value ) {
	return value && typeof value === 'object' && ! Array.isArray( value );
}
export function safeExternalUrl( value ) {
	try {
		const url = new URL( String( value || '' ) );

		return [ 'https:', 'http:' ].includes( url.protocol )
			? url.toString()
			: '';
	} catch {
		return '';
	}
}
export function connectionDateValue( value, fallback = 'Never' ) {
	const rawValue = String( value || '' ).trim();

	if ( ! rawValue ) {
		return fallback;
	}

	const parsedDate = new Date( rawValue.replace( ' ', 'T' ) );
	if ( Number.isNaN( parsedDate.getTime() ) ) {
		return rawValue;
	}

	return new Intl.DateTimeFormat( undefined, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} ).format( parsedDate );
}

function normalizeDimension( value ) {
	return Number.isFinite( value ) && value > 0 ? value : 0;
}

export function tabOverflowState( {
	clientWidth = 0,
	scrollLeft = 0,
	scrollWidth = 0,
} = {} ) {
	const safeClientWidth = normalizeDimension( clientWidth );
	const safeScrollWidth = normalizeDimension( scrollWidth );
	const maxScrollLeft = Math.max( safeScrollWidth - safeClientWidth, 0 );
	const safeScrollLeft = Math.min(
		Math.max( Number.isFinite( scrollLeft ) ? scrollLeft : 0, 0 ),
		maxScrollLeft
	);
	const hasOverflow = maxScrollLeft > 1;

	return {
		hasOverflow,
		canScrollBackward: hasOverflow && safeScrollLeft > 1,
		canScrollForward: hasOverflow && maxScrollLeft - safeScrollLeft > 1,
		maxScrollLeft,
		scrollLeft: safeScrollLeft,
	};
}

export function tabScrollTarget( metrics = {}, direction = 'forward' ) {
	const state = tabOverflowState( metrics );

	if ( ! state.hasOverflow ) {
		return 0;
	}

	const step = Math.max(
		160,
		Math.min( normalizeDimension( metrics.clientWidth ) * 0.75, 320 )
	);

	if ( direction === 'backward' ) {
		return Math.max( 0, state.scrollLeft - step );
	}

	return Math.min( state.maxScrollLeft, state.scrollLeft + step );
}

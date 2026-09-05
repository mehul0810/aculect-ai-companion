const SURFACES = [ 'suggestions', 'memory', 'incidents' ];

/**
 * Return the requested Learning surface, or the safe default.
 *
 * @param {string} search Location query string.
 */
export function initialLearningSurface( search = '' ) {
	const requested = new URLSearchParams( search ).get( 'learning_surface' );
	return SURFACES.includes( requested ) ? requested : 'suggestions';
}

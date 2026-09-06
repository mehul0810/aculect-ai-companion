/**
 * Return the next focus index for an ARIA tab-list navigation key.
 *
 * @param {string} key    Keyboard event key.
 * @param {number} index  Current tab index.
 * @param {number} length Number of tabs.
 */
export function nextTabIndex( key, index, length ) {
	if ( length < 1 ) {
		return index;
	}
	if ( key === 'Home' ) {
		return 0;
	}
	if ( key === 'End' ) {
		return length - 1;
	}
	if ( key === 'ArrowRight' ) {
		return ( index + 1 ) % length;
	}
	if ( key === 'ArrowLeft' ) {
		return ( index - 1 + length ) % length;
	}
	return index;
}

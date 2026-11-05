import { normalizeTabName } from './admin-tab-hydration.mjs';

export function tabUrl( tabName, adminPageUrl = '' ) {
	const normalizedTabName = normalizeTabName( tabName );

	try {
		const url = new URL( adminPageUrl || window.location.href );
		url.searchParams.set( 'page', 'aculect-ai-companion' );
		if ( normalizedTabName === 'overview' ) {
			url.searchParams.delete( 'tab' );
		} else {
			url.searchParams.set( 'tab', normalizedTabName );
		}

		return url.toString();
	} catch {
		return normalizedTabName === 'overview'
			? 'options-general.php?page=aculect-ai-companion'
			: `options-general.php?page=aculect-ai-companion&tab=${ normalizedTabName }`;
	}
}

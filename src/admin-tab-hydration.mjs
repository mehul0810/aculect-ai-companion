export const TAB_ALIASES = {
	about: 'overview',
	connectors: 'connect',
};

const TAB_SCOPED_PAYLOAD_KEYS = {
	connections: [ 'sessions', 'revokedSessions' ],
	abilities: [ 'roleAbilityPolicy' ],
	activity: [ 'activity' ],
	learning: [ 'learningSuggestions' ],
	brand: [ 'brandProfile' ],
	changelog: [ 'changelog' ],
};

const TAB_QUERY_PARAM = 'tab';

function hasOwn( object, key ) {
	return Object.prototype.hasOwnProperty.call( object, key );
}

function isPlainDataObject( value ) {
	return value && typeof value === 'object' && ! Array.isArray( value );
}

export function normalizeTabName( tabName ) {
	return TAB_ALIASES[ tabName ] || tabName;
}

export function hydratedTabsFromData( data, fallbackTabs ) {
	if ( data && Array.isArray( data.hydratedTabs ) ) {
		return data.hydratedTabs;
	}

	return fallbackTabs;
}

export function tabNameIsHydrated( tabName, data, fallbackTabs ) {
	return hydratedTabsFromData( data, fallbackTabs ).includes(
		normalizeTabName( tabName )
	);
}

export function settingsPayloadFetchUrl(
	settingsPayloadUrl,
	tabName,
	currentLocation = globalThis?.location?.href || 'https://example.com/'
) {
	const currentUrl = new URL( currentLocation );
	const url = new URL( settingsPayloadUrl, currentUrl.origin );

	if ( url.origin !== currentUrl.origin ) {
		url.protocol = currentUrl.protocol;
		url.host = currentUrl.host;
	}

	url.searchParams.set( TAB_QUERY_PARAM, normalizeTabName( tabName ) );

	return url.toString();
}

export function mergeSettingsPayload(
	currentData = {},
	payload = {},
	tabName = 'overview'
) {
	const normalizedPayloadTab = normalizeTabName(
		typeof payload.payloadTab === 'string' && payload.payloadTab
			? payload.payloadTab
			: tabName
	);
	const merged = {
		...currentData,
		...payload,
	};

	for ( const [ scopedTab, keys ] of Object.entries(
		TAB_SCOPED_PAYLOAD_KEYS
	) ) {
		if ( scopedTab === normalizedPayloadTab ) {
			continue;
		}

		for ( const key of keys ) {
			if ( hasOwn( currentData, key ) && hasOwn( payload, key ) ) {
				merged[ key ] = currentData[ key ];
			}
		}
	}

	if (
		normalizedPayloadTab !== 'logs' &&
		isPlainDataObject( currentData.diagnostics ) &&
		isPlainDataObject( payload.diagnostics ) &&
		hasOwn( currentData.diagnostics, 'logs' ) &&
		hasOwn( payload.diagnostics, 'logs' )
	) {
		merged.diagnostics = {
			...currentData.diagnostics,
			...payload.diagnostics,
			logs: currentData.diagnostics.logs,
		};
	}

	const currentHydratedTabs = Array.isArray( currentData.hydratedTabs )
		? currentData.hydratedTabs
		: [];
	const payloadHydratedTabs = Array.isArray( payload.hydratedTabs )
		? payload.hydratedTabs
		: [];

	return {
		...merged,
		hydratedTabs: Array.from(
			new Set( [
				...currentHydratedTabs,
				...payloadHydratedTabs,
				normalizedPayloadTab,
			] )
		),
	};
}

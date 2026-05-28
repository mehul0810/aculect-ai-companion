export const TAB_ALIASES = {
	about: 'overview',
	connectors: 'connect',
};

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

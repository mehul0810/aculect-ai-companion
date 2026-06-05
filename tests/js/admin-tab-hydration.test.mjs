import test from 'node:test';
import assert from 'node:assert/strict';
import {
	hydratedTabsFromData,
	mergeSettingsPayload,
	normalizeTabName,
	settingsPayloadFetchUrl,
	tabNameIsHydrated,
} from '../../src/admin-tab-hydration.mjs';

test( 'normalizes legacy tab aliases', () => {
	assert.equal( normalizeTabName( 'about' ), 'overview' );
	assert.equal( normalizeTabName( 'connectors' ), 'connect' );
	assert.equal( normalizeTabName( 'activity' ), 'activity' );
} );

test( 'uses server-provided hydrated tabs when present', () => {
	const data = { hydratedTabs: [ 'overview', 'connect', 'connections' ] };
	const fallbackTabs = [ 'overview', 'connect', 'activity' ];

	assert.deepEqual( hydratedTabsFromData( data, fallbackTabs ), [
		'overview',
		'connect',
		'connections',
	] );
	assert.equal(
		tabNameIsHydrated( 'connections', data, fallbackTabs ),
		true
	);
	assert.equal( tabNameIsHydrated( 'activity', data, fallbackTabs ), false );
} );

test( 'normalizes settings payload fetches to the current admin origin', () => {
	assert.equal(
		settingsPayloadFetchUrl(
			'https://example.com/wp-json/aculect-ai-companion/v1/settings-payload',
			'abilities',
			'https://admin.example.test/wp-admin/options-general.php?page=aculect-ai-companion'
		),
		'https://admin.example.test/wp-json/aculect-ai-companion/v1/settings-payload?tab=abilities'
	);
} );

test( 'falls back to all local tabs when old payloads omit hydration metadata', () => {
	const fallbackTabs = [ 'overview', 'connect', 'activity' ];

	assert.deepEqual( hydratedTabsFromData( {}, fallbackTabs ), fallbackTabs );
	assert.equal( tabNameIsHydrated( 'activity', {}, fallbackTabs ), true );
	assert.equal( tabNameIsHydrated( 'about', {}, fallbackTabs ), true );
} );

test( 'merges lazy tab payloads without clearing previously hydrated tab data', () => {
	const currentData = {
		hydratedTabs: [ 'overview', 'connections' ],
		sessions: [ { id: 1 } ],
		revokedSessions: [ { id: 2 } ],
		activity: { total: 0, items: [] },
	};
	const payload = {
		payloadTab: 'activity',
		hydratedTabs: [ 'overview', 'activity' ],
		sessions: [],
		revokedSessions: [],
		activity: { total: 3, items: [ { id: 3 } ] },
	};

	assert.deepEqual(
		mergeSettingsPayload( currentData, payload, 'activity' ),
		{
			hydratedTabs: [ 'overview', 'connections', 'activity' ],
			payloadTab: 'activity',
			sessions: [ { id: 1 } ],
			revokedSessions: [ { id: 2 } ],
			activity: { total: 3, items: [ { id: 3 } ] },
		}
	);
} );

test( 'keeps learning suggestions scoped to the learning tab', () => {
	const currentData = {
		hydratedTabs: [ 'overview', 'learning' ],
		learningSuggestions: {
			summary: { total: 1 },
			items: [ { id: 'learn_1' } ],
		},
	};
	const payload = {
		payloadTab: 'activity',
		hydratedTabs: [ 'overview', 'activity' ],
		learningSuggestions: {
			summary: { total: 0 },
			items: [],
		},
		activity: { total: 2, items: [ { id: 2 } ] },
	};

	assert.deepEqual(
		mergeSettingsPayload( currentData, payload, 'activity' ),
		{
			hydratedTabs: [ 'overview', 'learning', 'activity' ],
			payloadTab: 'activity',
			learningSuggestions: {
				summary: { total: 1 },
				items: [ { id: 'learn_1' } ],
			},
			activity: { total: 2, items: [ { id: 2 } ] },
		}
	);
} );

test( 'merges diagnostic updates without clearing loaded logs', () => {
	const currentData = {
		hydratedTabs: [ 'overview', 'logs' ],
		diagnostics: {
			loggingEnabled: true,
			retentionDays: 30,
			logs: { total: 5, items: [ { id: 5 } ] },
		},
	};
	const payload = {
		payloadTab: 'advanced',
		hydratedTabs: [ 'overview', 'advanced' ],
		diagnostics: {
			loggingEnabled: false,
			retentionDays: 14,
			logs: { total: 0, items: [] },
		},
	};

	assert.deepEqual(
		mergeSettingsPayload( currentData, payload, 'advanced' ),
		{
			hydratedTabs: [ 'overview', 'logs', 'advanced' ],
			payloadTab: 'advanced',
			diagnostics: {
				loggingEnabled: false,
				retentionDays: 14,
				logs: { total: 5, items: [ { id: 5 } ] },
			},
		}
	);
} );

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
	hydratedTabsFromData,
	mergeSettingsPayload,
	normalizeTabName,
	settingsPayloadFetchUrl,
	tabNameIsHydrated,
} from '../../src/admin-tab-hydration.mjs';

const ADMIN_APP_SOURCE = readFileSync(
	new URL( '../../src/index.js', import.meta.url ),
	'utf8'
);

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

test( 'keeps learning payloads scoped to the learning tab', () => {
	const currentData = {
		hydratedTabs: [ 'overview', 'learning' ],
		learningSuggestions: {
			summary: { total: 1 },
			items: [ { id: 'learn_1' } ],
		},
		memoryRecords: {
			summary: { total: 1 },
			items: [ { key: 'memory_1' } ],
		},
		incidentReports: {
			summary: { total: 1 },
			items: [ { report_id: 'incident_1' } ],
		},
	};
	const payload = {
		payloadTab: 'activity',
		hydratedTabs: [ 'overview', 'activity' ],
		learningSuggestions: {
			summary: { total: 0 },
			items: [],
		},
		memoryRecords: {
			summary: { total: 0 },
			items: [],
		},
		incidentReports: {
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
			memoryRecords: {
				summary: { total: 1 },
				items: [ { key: 'memory_1' } ],
			},
			incidentReports: {
				summary: { total: 1 },
				items: [ { report_id: 'incident_1' } ],
			},
			activity: { total: 2, items: [ { id: 2 } ] },
		}
	);
} );

test( 'keeps internal-link map payloads scoped to the internal links tab', () => {
	const currentData = {
		hydratedTabs: [ 'overview', 'links-map' ],
		internalLinksMap: {
			total: 1,
			items: [ { post_id: 42, title: 'Indexed page' } ],
		},
	};
	const payload = {
		payloadTab: 'activity',
		hydratedTabs: [ 'overview', 'activity' ],
		internalLinksMap: {
			total: 0,
			items: [],
		},
		activity: { total: 2, items: [ { id: 2 } ] },
	};

	assert.deepEqual(
		mergeSettingsPayload( currentData, payload, 'activity' ),
		{
			hydratedTabs: [ 'overview', 'links-map', 'activity' ],
			payloadTab: 'activity',
			internalLinksMap: {
				total: 1,
				items: [ { post_id: 42, title: 'Indexed page' } ],
			},
			activity: { total: 2, items: [ { id: 2 } ] },
		}
	);
} );

test( 'internal links admin view renders filters, status states, and action links', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/{ name: 'links-map', title: 'Internal Links', icon: category }/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/function InternalLinksDashboard\( \{ data, internalLinksMap \} \)/
	);
	assert.match( ADMIN_APP_SOURCE, /name="links_state"/ );
	assert.match( ADMIN_APP_SOURCE, /name="links_post_type"/ );
	assert.match( ADMIN_APP_SOURCE, /name="links_min_inbound"/ );
	assert.match(
		ADMIN_APP_SOURCE,
		/<InternalLinksTable items=\{ items \} \/>/
	);
	assert.match( ADMIN_APP_SOURCE, /item\.editUrl/ );
	assert.match( ADMIN_APP_SOURCE, /item\.viewUrl/ );
	assert.match( ADMIN_APP_SOURCE, /item\.suggestionsUrl/ );
	assert.match( ADMIN_APP_SOURCE, /caption className="screen-reader-text"/ );
} );

test( 'learning review surfaces render behind explicit active-state checks', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/const \[ activeSurface, setActiveSurface \] = useState\( 'suggestions' \)/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/activeSurface === 'suggestions' && \(\s*<section className="aculect-ai-companion-learning-section">/s
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/activeSurface === 'memory' && \(\s*<section className="aculect-ai-companion-memory-section">/s
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/activeSurface === 'incidents' && \(\s*<section className="aculect-ai-companion-incident-section">/s
	);
	assert.doesNotMatch(
		ADMIN_APP_SOURCE,
		/Review MCP suggestions before they influence Aculect\s*Intelligence, and inspect incident reports/
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

test( 'sample notice copy is explicit preview language', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/Preview data - these are examples, not real connections or activity\./
	);
} );

test( 'sample connection rows render preview badges and hide real action controls', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/session\.isSample && <SampleBadge label="Preview" \/>/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/if \( session\.isSample \) \{\s*return renderUnavailableAction\( 'Preview only' \);\s*\}/s
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/const canManage =\s*! session\.isSample &&\s*session\.status !== 'revoked'/s
	);
} );

test( 'real connection state stays separate from sample rows in the connections dashboard', () => {
	assert.match(
		ADMIN_APP_SOURCE,
		/const hasRealActiveConnections = activeSessionCount > 0;/
	);
	assert.match(
		ADMIN_APP_SOURCE,
		/let accessStatusLabel = 'No active AI access';[\s\S]*else if \( hasRealActiveConnections \) \{\s*accessStatusLabel = 'AI access is active';/s
	);
	assert.match( ADMIN_APP_SOURCE, /\{ hasRealActiveConnections && \(/ );
} );

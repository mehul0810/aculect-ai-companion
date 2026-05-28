import test from 'node:test';
import assert from 'node:assert/strict';
import {
	hydratedTabsFromData,
	normalizeTabName,
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

test( 'falls back to all local tabs when old payloads omit hydration metadata', () => {
	const fallbackTabs = [ 'overview', 'connect', 'activity' ];

	assert.deepEqual( hydratedTabsFromData( {}, fallbackTabs ), fallbackTabs );
	assert.equal( tabNameIsHydrated( 'activity', {}, fallbackTabs ), true );
	assert.equal( tabNameIsHydrated( 'about', {}, fallbackTabs ), true );
} );

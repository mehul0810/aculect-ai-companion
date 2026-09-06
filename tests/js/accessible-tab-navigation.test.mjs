import test from 'node:test';
import assert from 'node:assert/strict';
import { nextTabIndex } from '../../src/accessible-tab-navigation.mjs';

test( 'tab navigation wraps and honors Home and End', () => {
	assert.equal( nextTabIndex( 'ArrowRight', 2, 3 ), 0 );
	assert.equal( nextTabIndex( 'ArrowLeft', 0, 3 ), 2 );
	assert.equal( nextTabIndex( 'Home', 2, 3 ), 0 );
	assert.equal( nextTabIndex( 'End', 0, 3 ), 2 );
	assert.equal( nextTabIndex( 'Enter', 1, 3 ), 1 );
} );

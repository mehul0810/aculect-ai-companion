import test from 'node:test';
import assert from 'node:assert/strict';

import {
	tabOverflowState,
	tabScrollTarget,
} from '../../src/tab-navigation.mjs';

test( 'reports when the primary tabs overflow and which direction can scroll', () => {
	assert.deepEqual(
		tabOverflowState( {
			clientWidth: 320,
			scrollLeft: 0,
			scrollWidth: 760,
		} ),
		{
			hasOverflow: true,
			canScrollBackward: false,
			canScrollForward: true,
			maxScrollLeft: 440,
			scrollLeft: 0,
		}
	);

	assert.deepEqual(
		tabOverflowState( {
			clientWidth: 320,
			scrollLeft: 440,
			scrollWidth: 760,
		} ),
		{
			hasOverflow: true,
			canScrollBackward: true,
			canScrollForward: false,
			maxScrollLeft: 440,
			scrollLeft: 440,
		}
	);
} );

test( 'clamps invalid scroll values before computing overflow state', () => {
	assert.deepEqual(
		tabOverflowState( {
			clientWidth: 0,
			scrollLeft: -50,
			scrollWidth: Number.NaN,
		} ),
		{
			hasOverflow: false,
			canScrollBackward: false,
			canScrollForward: false,
			maxScrollLeft: 0,
			scrollLeft: 0,
		}
	);
} );

test( 'computes bounded tab scroll targets for both directions', () => {
	const metrics = {
		clientWidth: 320,
		scrollLeft: 120,
		scrollWidth: 760,
	};

	assert.equal( tabScrollTarget( metrics, 'forward' ), 360 );
	assert.equal( tabScrollTarget( metrics, 'backward' ), 0 );
	assert.equal(
		tabScrollTarget(
			{
				clientWidth: 320,
				scrollLeft: 430,
				scrollWidth: 760,
			},
			'forward'
		),
		440
	);
} );

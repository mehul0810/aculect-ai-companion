import test from 'node:test';
import assert from 'node:assert/strict';
import { initialLearningSurface } from '../../src/learning-surface.mjs';

test( 'learning surface restores valid deep links and rejects unknown values', () => {
	assert.equal(
		initialLearningSurface( '?learning_surface=memory' ),
		'memory'
	);
	assert.equal(
		initialLearningSurface( '?learning_surface=incidents' ),
		'incidents'
	);
	assert.equal(
		initialLearningSurface( '?learning_surface=unknown' ),
		'suggestions'
	);
	assert.equal( initialLearningSurface(), 'suggestions' );
} );

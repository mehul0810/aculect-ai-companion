import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(
	new URL( '../../src/index.js', import.meta.url ),
	'utf8'
);

test( 'abilities UI keeps the complete catalog separate from configurable form state', () => {
	assert.match( source, /data\.abilityCatalog/ );
	assert.match( source, /ability\.configurable \? \(/ );
	assert.match( source, /name="enabled_abilities\[\]"/ );
	assert.match( source, /label: 'Surface type'/ );
	assert.match( source, /label: 'Policy state'/ );
	assert.doesNotMatch(
		source,
		/name="enabled_abilities\[\]"[\s\S]{0,180}abilityCatalog\.map/
	);
} );

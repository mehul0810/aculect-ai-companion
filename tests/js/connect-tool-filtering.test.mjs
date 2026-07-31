import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const testDirectory = path.dirname( fileURLToPath( import.meta.url ) );
const source = fs.readFileSync(
	path.resolve( testDirectory, '../../src/index.js' ),
	'utf8'
);

test( 'Connect renders advanced tool filtering as a collapsed native disclosure', () => {
	assert.match( source, /function ConnectToolFilteringGuidance/ );
	assert.match( source, /<details>/ );
	assert.match( source, /Optional tool filtering/ );
	assert.match( source, /Explicit approval required/ );
} );

test( 'Connect keeps the client filtering authorization boundary visible', () => {
	assert.match( source, /guidance\.warning/ );
	assert.match( source, /toolFiltering/ );
	assert.match( source, /providerGuidance\.map/ );
} );

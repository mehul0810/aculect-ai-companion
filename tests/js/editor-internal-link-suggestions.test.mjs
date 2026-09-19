import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(
	new URL(
		'../../assets/js/editor-internal-link-suggestions.js',
		import.meta.url
	),
	'utf8'
);

test( 'editor internal-link panel registers a document settings panel', () => {
	assert.match(
		source,
		/registerPlugin\( 'aculect-ai-companion-internal-link-suggestions'/
	);
	assert.match( source, /wp\.editor\.PluginDocumentSettingPanel/ );
	assert.match( source, /wp\.editPost\.PluginDocumentSettingPanel/ );
	assert.match( source, /Aculect internal links/ );
} );

test( 'editor internal-link panel keeps apply unavailable in the no-write slice', () => {
	assert.match(
		source,
		/Safe insertion is waiting on the reviewed apply workflow/
	);
	assert.match( source, /Apply unavailable/ );
	assert.doesNotMatch(
		source,
		/insertBlocks|savePost|updateBlockAttributes|dispatch\(/i
	);
} );
